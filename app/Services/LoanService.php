<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Response;
use App\Core\Uuid;
use PDO;
use DateTimeImmutable;
use DateTimeZone;

class LoanService {
    public static function create(string $userId, array $data): array {
        $memberId = trim($data['member_id'] ?? '');
        $items = $data['items'] ?? [];

        if (empty($memberId)) {
            Response::error('VALIDATION_ERROR', 'El id del socio es obligatorio.', 422);
        }

        // CA-06.1: de 1 a 3 líneas
        if (count($items) < 1 || count($items) > 3) {
            Response::error('VALIDATION_ERROR', 'Un préstamo debe contener entre 1 y 3 libros.', 422);
        }

        // CA-06.2: No se repite el mismo libro en un préstamo (422 DUPLICATE_BOOK_IN_LOAN)
        $bookIds = [];
        foreach ($items as $idx => $it) {
            $bId = trim($it['book_id'] ?? '');
            if (empty($bId)) {
                Response::error('VALIDATION_ERROR', "El libro en la posición $idx es inválido.", 422);
            }
            if (in_array($bId, $bookIds)) {
                Response::error('DUPLICATE_BOOK_IN_LOAN', 'No se permite repetir el mismo libro en un préstamo.', 422);
            }
            $bookIds[] = $bId;
        }

        $db = Database::getConnection();

        // Verificar socio
        $stmtM = $db->prepare("SELECT id, full_name FROM library.member WHERE id = :id");
        $stmtM->execute(['id' => $memberId]);
        $member = $stmtM->fetch();
        if (!$member) {
            Response::error('NOT_FOUND', 'El socio indicado no existe.', 404);
        }

        // CA-06.5: Un socio con algún libro vencido no puede pedir otro (409 MEMBER_HAS_OVERDUE)
        $stmtOverdue = $db->prepare("
            SELECT COUNT(li.id)
            FROM library.loan l
            JOIN library.loan_item li ON l.id = li.loan_id
            WHERE l.member_id = :mid AND li.returned_at IS NULL AND li.due_at < NOW()
        ");
        $stmtOverdue->execute(['mid' => $memberId]);
        if ((int)$stmtOverdue->fetchColumn() > 0) {
            Response::error('MEMBER_HAS_OVERDUE', 'El socio tiene libros vencidos pendientes por devolver.', 409);
        }

        // CA-06.4: Máximo 3 libros sin devolver en total (422 MEMBER_LIMIT_EXCEEDED)
        $stmtActive = $db->prepare("
            SELECT COUNT(li.id)
            FROM library.loan l
            JOIN library.loan_item li ON l.id = li.loan_id
            WHERE l.member_id = :mid AND li.returned_at IS NULL
        ");
        $stmtActive->execute(['mid' => $memberId]);
        $currentActive = (int)$stmtActive->fetchColumn();

        if ($currentActive + count($bookIds) > 3) {
            Response::error('MEMBER_LIMIT_EXCEEDED', "El socio excede el límite de 3 libros sin devolver (actualmente tiene $currentActive).", 422, [
                ['field' => 'member_id', 'issue' => 'límite de 3 libros activos excedido']
            ]);
        }

        // Iniciar Transacción atómica
        $db->beginTransaction();
        try {
            $loanId = Uuid::v4();
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $nowIso = $now->format('Y-m-d\TH:i:s\Z');
            $dueAt = $now->modify('+14 days');
            $dueAtIso = $dueAt->format('Y-m-d\TH:i:s\Z');

            // 1. Validar y descontar libros
            $createdItems = [];
            foreach ($bookIds as $bId) {
                // Bloqueo de fila para concurrencia segura
                $stmtB = $db->prepare("
                    SELECT b.id, b.title, b.isbn, b.available_copies, c.name as category_name
                    FROM library.book b
                    JOIN library.category c ON b.category_id = c.id
                    WHERE b.id = :id AND b.deleted_at IS NULL
                    FOR UPDATE
                ");
                $stmtB->execute(['id' => $bId]);
                $book = $stmtB->fetch();

                if (!$book) {
                    $db->rollBack();
                    Response::error('NOT_FOUND', "El libro $bId no existe o fue dado de baja.", 404);
                }

                // CA-06.3: Sin ejemplares disponibles: 409 BOOK_NOT_AVAILABLE
                if ((int)$book['available_copies'] <= 0) {
                    $db->rollBack();
                    Response::error('BOOK_NOT_AVAILABLE', "No quedan ejemplares disponibles de «{$book['title']}».", 409, [
                        ['field' => 'items', 'issue' => 'sin ejemplares disponibles']
                    ]);
                }

                // Descontar disponible
                $stmtDec = $db->prepare("
                    UPDATE library.book
                    SET available_copies = available_copies - 1
                    WHERE id = :id
                ");
                $stmtDec->execute(['id' => $bId]);

                $itemId = Uuid::v4();
                $createdItems[] = [
                    'id' => $itemId,
                    'book_id' => $bId,
                    'book_title' => $book['title'],
                    'isbn' => $book['isbn'],
                    'category_name' => $book['category_name'],
                    'due_at' => $dueAtIso,
                    'returned_at' => null
                ];
            }

            // 2. Crear préstamo
            $stmtLoan = $db->prepare("
                INSERT INTO library.loan (id, member_id, loaned_by_user_id, loaned_at)
                VALUES (:id, :mid, :uid, :loaned_at)
            ");
            $stmtLoan->execute([
                'id' => $loanId,
                'mid' => $memberId,
                'uid' => $userId,
                'loaned_at' => $nowIso
            ]);

            // 3. Crear líneas congeladas
            $stmtItem = $db->prepare("
                INSERT INTO library.loan_item (id, loan_id, book_id, book_title, isbn, category_name, due_at, returned_at)
                VALUES (:id, :lid, :bid, :btitle, :isbn, :cname, :due, NULL)
            ");
            foreach ($createdItems as $item) {
                $stmtItem->execute([
                    'id' => $item['id'],
                    'lid' => $loanId,
                    'bid' => $item['book_id'],
                    'btitle' => $item['book_title'],
                    'isbn' => $item['isbn'],
                    'cname' => $item['category_name'],
                    'due' => $item['due_at']
                ]);
            }

            $db->commit();

            return [
                'id' => $loanId,
                'member_id' => $memberId,
                'loaned_at' => $nowIso,
                'status' => 'open',
                'items' => $createdItems
            ];
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('VALIDATION_ERROR', 'Error al procesar el préstamo: ' . $e->getMessage(), 500);
        }
    }

    public static function returnItem(string $loanId, string $itemId): array {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT li.id, li.loan_id, li.book_id, li.returned_at
            FROM library.loan_item li
            WHERE li.id = :item_id AND li.loan_id = :loan_id
            LIMIT 1
        ");
        $stmt->execute(['item_id' => $itemId, 'loan_id' => $loanId]);
        $item = $stmt->fetch();

        if (!$item) {
            Response::error('NOT_FOUND', 'Línea de préstamo no encontrada.', 404);
        }

        // CA-07.2: Devolver una línea ya devuelta: 409 ITEM_ALREADY_RETURNED
        if ($item['returned_at'] !== null) {
            Response::error('ITEM_ALREADY_RETURNED', 'Esta línea de préstamo ya fue devuelta anteriormente.', 409);
        }

        $db->beginTransaction();
        try {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

            // 1. Marcar fecha de devolución
            $stmtUp = $db->prepare("UPDATE library.loan_item SET returned_at = :ret WHERE id = :id");
            $stmtUp->execute(['ret' => $now, 'id' => $itemId]);

            // 2. Sumar 1 a ejemplares disponibles
            $stmtBook = $db->prepare("UPDATE library.book SET available_copies = available_copies + 1 WHERE id = :bid");
            $stmtBook->execute(['bid' => $item['book_id']]);

            $db->commit();

            return [
                'id' => $itemId,
                'loan_id' => $loanId,
                'book_id' => $item['book_id'],
                'returned_at' => $now
            ];
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            Response::error('VALIDATION_ERROR', 'Error al registrar devolución.', 500);
        }
    }

    public static function get(string $id): array {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT l.id, l.member_id, l.loaned_at, m.full_name as member_name
            FROM library.loan l
            JOIN library.member m ON l.member_id = m.id
            WHERE l.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $loan = $stmt->fetch();
        if (!$loan) {
            Response::error('NOT_FOUND', 'El préstamo no existe.', 404);
        }

        $stmtItems = $db->prepare("
            SELECT id, book_id, book_title, isbn, category_name, due_at, returned_at
            FROM library.loan_item
            WHERE loan_id = :id
            ORDER BY book_title ASC
        ");
        $stmtItems->execute(['id' => $id]);
        $items = $stmtItems->fetchAll();

        // Calcular estado (CA-08.3: se calcula, no se almacena)
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $allReturned = true;
        $hasOverdue = false;

        foreach ($items as $it) {
            if ($it['returned_at'] === null) {
                $allReturned = false;
                $dueDate = new DateTimeImmutable($it['due_at']);
                if ($dueDate < $now) {
                    $hasOverdue = true;
                }
            }
        }

        $status = $allReturned ? 'closed' : ($hasOverdue ? 'overdue' : 'open');

        return [
            'id' => $loan['id'],
            'member_id' => $loan['member_id'],
            'member_name' => $loan['member_name'],
            'loaned_at' => $loan['loaned_at'],
            'status' => $status,
            'items' => $items
        ];
    }

    public static function list(?string $from, ?string $to, ?string $status, ?string $memberId, int $page = 1, int $size = 20): array {
        $size = max(1, min(50, $size));
        $offset = max(0, ($page - 1) * $size);

        $db = Database::getConnection();
        $where = [];
        $params = [];

        if (!empty($memberId)) {
            $where[] = "l.member_id = :mid";
            $params['mid'] = $memberId;
        }

        if (!empty($from)) {
            $where[] = "l.loaned_at >= :from";
            $params['from'] = $from;
        }

        if (!empty($to)) {
            $where[] = "l.loaned_at <= :to";
            $params['to'] = $to;
        }

        $whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        $stmtCount = $db->prepare("SELECT COUNT(*) FROM library.loan l $whereSql");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        $sql = "
            SELECT l.id, l.member_id, l.loaned_at, m.full_name as member_name
            FROM library.loan l
            JOIN library.member m ON l.member_id = m.id
            $whereSql
            ORDER BY l.loaned_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $size, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $loans = $stmt->fetchAll();

        $items = [];
        foreach ($loans as $l) {
            $items[] = self::get($l['id']);
        }

        // Filtrar por status si se solicitó
        if (!empty($status)) {
            $items = array_values(array_filter($items, fn($it) => $it['status'] === $status));
        }

        return [
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total
        ];
    }

    public static function getOverdue(): array {
        $db = Database::getConnection();

        $sql = "
            SELECT li.id as item_id, li.loan_id, li.book_title, m.full_name as member_name,
                   li.due_at,
                   EXTRACT(DAY FROM (NOW() - li.due_at))::INT as days_late
            FROM library.loan_item li
            JOIN library.loan l ON li.loan_id = l.id
            JOIN library.member m ON l.member_id = m.id
            WHERE li.returned_at IS NULL AND li.due_at < NOW()
            ORDER BY days_late DESC, li.due_at ASC
        ";
        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll();

        return [
            'items' => array_map(function ($r) {
                return [
                    'loan_id' => $r['loan_id'],
                    'item_id' => $r['item_id'],
                    'book_title' => $r['book_title'],
                    'member_name' => $r['member_name'],
                    'due_at' => $r['due_at'],
                    'days_late' => max(1, (int)$r['days_late'])
                ];
            }, $rows)
        ];
    }
}


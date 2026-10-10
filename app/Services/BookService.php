<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Response;
use App\Core\Uuid;
use PDO;

class BookService {
    public static function list(?string $q, ?string $categoryId, int $page = 1, int $size = 20): array {
        $size = max(1, min(50, $size));
        $offset = max(0, ($page - 1) * $size);

        $db = Database::getConnection();
        $where = ["b.deleted_at IS NULL"];
        $params = [];

        if (!empty($q)) {
            $where[] = "(b.title ILIKE :q OR b.author ILIKE :q)";
            $params['q'] = "%$q%";
        }

        if (!empty($categoryId)) {
            $where[] = "b.category_id = :cat";
            $params['cat'] = $categoryId;
        }

        $whereSql = implode(' AND ', $where);

        // Conteo total
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM library.book b WHERE $whereSql");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        // Consulta de libros
        $sql = "
            SELECT b.id, b.title, b.author, b.isbn, b.total_copies, b.available_copies, b.cover_key,
                   c.id as cat_id, c.name as cat_name
            FROM library.book b
            JOIN library.category c ON b.category_id = c.id
            WHERE $whereSql
            ORDER BY b.title ASC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $size, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $items = array_map(function ($r) {
            return [
                'id' => $r['id'],
                'title' => $r['title'],
                'author' => $r['author'],
                'isbn' => $r['isbn'],
                'category' => [
                    'id' => $r['cat_id'],
                    'name' => $r['cat_name']
                ],
                'total_copies' => (int) $r['total_copies'],
                'available_copies' => (int) $r['available_copies'],
                'cover_key' => $r['cover_key']
            ];
        }, $rows);

        return [
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total
        ];
    }

    public static function get(string $id): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT b.id, b.title, b.author, b.isbn, b.total_copies, b.available_copies, b.cover_key,
                   c.id as cat_id, c.name as cat_name
            FROM library.book b
            JOIN library.category c ON b.category_id = c.id
            WHERE b.id = :id AND b.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch();

        if (!$r) {
            Response::error('NOT_FOUND', 'El libro solicitado no existe o fue dado de baja.', 404);
        }

        return [
            'id' => $r['id'],
            'title' => $r['title'],
            'author' => $r['author'],
            'isbn' => $r['isbn'],
            'category' => [
                'id' => $r['cat_id'],
                'name' => $r['cat_name']
            ],
            'total_copies' => (int) $r['total_copies'],
            'available_copies' => (int) $r['available_copies'],
            'cover_key' => $r['cover_key']
        ];
    }

    public static function create(array $data): array {
        $title = trim($data['title'] ?? '');
        $author = trim($data['author'] ?? '');
        $isbn = trim($data['isbn'] ?? '');
        $categoryId = trim($data['category_id'] ?? '');
        $totalCopies = (int) ($data['total_copies'] ?? 0);

        if (empty($title) || empty($author)) {
            Response::error('VALIDATION_ERROR', 'El título y el autor son obligatorios.', 422);
        }

        // CA-03.2: 13 dígitos
        if (!preg_match('/^\d{13}$/', $isbn)) {
            Response::error('VALIDATION_ERROR', 'El ISBN debe contener exactamente 13 dígitos numéricos.', 422, [
                ['field' => 'isbn', 'issue' => 'debe contener 13 dígitos numéricos']
            ]);
        }

        if ($totalCopies < 1) {
            Response::error('VALIDATION_ERROR', 'El total de ejemplares debe ser mayor o igual a 1.', 422);
        }

        $db = Database::getConnection();

        // Verificar categoría
        $stmtCat = $db->prepare("SELECT id, name FROM library.category WHERE id = :id");
        $stmtCat->execute(['id' => $categoryId]);
        $cat = $stmtCat->fetch();
        if (!$cat) {
            Response::error('VALIDATION_ERROR', 'La categoría especificada no existe.', 422);
        }

        // CA-03.2: ISBN único entre activos
        $stmtCheck = $db->prepare("SELECT id FROM library.book WHERE isbn = :isbn AND deleted_at IS NULL LIMIT 1");
        $stmtCheck->execute(['isbn' => $isbn]);
        if ($stmtCheck->fetch()) {
            Response::error('ISBN_TAKEN', "El ISBN {$isbn} ya se encuentra asignado a un libro activo.", 409);
        }

        $id = Uuid::v4();
        $stmt = $db->prepare("
            INSERT INTO library.book (id, title, author, isbn, category_id, total_copies, available_copies, cover_key, deleted_at)
            VALUES (:id, :title, :author, :isbn, :cat_id, :total, :total, NULL, NULL)
        ");
        $stmt->execute([
            'id' => $id,
            'title' => $title,
            'author' => $author,
            'isbn' => $isbn,
            'cat_id' => $categoryId,
            'total' => $totalCopies
        ]);

        return self::get($id);
    }

    public static function update(string $id, array $data): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM library.book WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute(['id' => $id]);
        $book = $stmt->fetch();
        if (!$book) {
            Response::error('NOT_FOUND', 'El libro no existe o fue dado de baja.', 404);
        }

        $title = trim($data['title'] ?? $book['title']);
        $author = trim($data['author'] ?? $book['author']);
        $categoryId = trim($data['category_id'] ?? $book['category_id']);
        $newTotal = isset($data['total_copies']) ? (int) $data['total_copies'] : (int) $book['total_copies'];

        if ($newTotal < 1) {
            Response::error('VALIDATION_ERROR', 'El total de ejemplares debe ser mayor o igual a 1.', 422);
        }

        // CA-03.3: Ajusta disponibles en la misma diferencia; nunca deja disponibles < 0 (422)
        $diff = $newTotal - (int)$book['total_copies'];
        $newAvailable = (int)$book['available_copies'] + $diff;

        if ($newAvailable < 0) {
            Response::error('VALIDATION_ERROR', 'No se puede reducir el total de ejemplares porque dejaría disponibles negativos (ejemplares actualmente en préstamo).', 422);
        }

        $stmtUp = $db->prepare("
            UPDATE library.book
            SET title = :title, author = :author, category_id = :cat, total_copies = :total, available_copies = :avail
            WHERE id = :id
        ");
        $stmtUp->execute([
            'id' => $id,
            'title' => $title,
            'author' => $author,
            'cat' => $categoryId,
            'total' => $newTotal,
            'avail' => $newAvailable
        ]);

        return self::get($id);
    }

    public static function delete(string $id): void {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM library.book WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            Response::error('NOT_FOUND', 'El libro no existe o ya fue dado de baja.', 404);
        }

        // CA-03.5: Un libro con préstamos abiertos no se da de baja (409 BOOK_HAS_OPEN_LOANS)
        $stmtLoans = $db->prepare("
            SELECT COUNT(*) FROM library.loan_item
            WHERE book_id = :id AND returned_at IS NULL
        ");
        $stmtLoans->execute(['id' => $id]);
        if ((int)$stmtLoans->fetchColumn() > 0) {
            Response::error('BOOK_HAS_OPEN_LOANS', 'No se puede dar de baja el libro porque posee ejemplares en préstamo activo.', 409);
        }

        // Baja lógica
        $stmtDel = $db->prepare("UPDATE library.book SET deleted_at = NOW() WHERE id = :id");
        $stmtDel->execute(['id' => $id]);
    }

    public static function uploadCover(string $id, array $file): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM library.book WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            Response::error('NOT_FOUND', 'Libro no encontrado.', 404);
        }

        // Validar tamaño máx. 2 MB
        if ($file['size'] > 2 * 1024 * 1024) {
            Response::error('VALIDATION_ERROR', 'La portada no debe superar los 2 MB de tamaño.', 422);
        }

        // Validar tipo MIME
        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png'])) {
            Response::error('VALIDATION_ERROR', 'Solo se permiten imágenes en formato JPG y PNG.', 422);
        }

        $coversDir = __DIR__ . '/../../var/covers';
        if (!is_dir($coversDir)) {
            mkdir($coversDir, 0777, true);
        }

        $ext = ($mime === 'image/png') ? 'png' : 'jpg';
        $coverKey = Uuid::v4() . '.' . $ext;
        $targetPath = $coversDir . '/' . $coverKey;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            Response::error('VALIDATION_ERROR', 'Error al almacenar el archivo en disco.', 500);
        }

        $stmtUp = $db->prepare("UPDATE library.book SET cover_key = :key WHERE id = :id");
        $stmtUp->execute(['key' => $coverKey, 'id' => $id]);

        return self::get($id);
    }
}


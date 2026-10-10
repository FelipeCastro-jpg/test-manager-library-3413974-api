<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Response;
use App\Core\Uuid;
use PDO;

class MemberService {
    public static function list(?string $q, int $page = 1, int $size = 20): array {
        $size = max(1, min(50, $size));
        $offset = max(0, ($page - 1) * $size);

        $db = Database::getConnection();
        $where = [];
        $params = [];

        if (!empty($q)) {
            $where[] = "(m.full_name ILIKE :q OR m.document_number ILIKE :q)";
            $params['q'] = "%$q%";
        }

        $whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        // Total
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM library.member m $whereSql");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        // Con conteo de active_loans (préstamos sin devolver)
        $sql = "
            SELECT m.id, m.full_name, m.document_number,
                   (
                       SELECT COUNT(li.id)
                       FROM library.loan l
                       JOIN library.loan_item li ON l.id = li.loan_id
                       WHERE l.member_id = m.id AND li.returned_at IS NULL
                   ) as active_loans
            FROM library.member m
            $whereSql
            ORDER BY m.full_name ASC
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
                'full_name' => $r['full_name'],
                'document_number' => $r['document_number'],
                'active_loans' => (int) $r['active_loans']
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
            SELECT m.id, m.full_name, m.document_number,
                   (
                       SELECT COUNT(li.id)
                       FROM library.loan l
                       JOIN library.loan_item li ON l.id = li.loan_id
                       WHERE l.member_id = m.id AND li.returned_at IS NULL
                   ) as active_loans
            FROM library.member m
            WHERE m.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch();
        if (!$r) {
            Response::error('NOT_FOUND', 'El socio solicitado no existe.', 404);
        }

        return [
            'id' => $r['id'],
            'full_name' => $r['full_name'],
            'document_number' => $r['document_number'],
            'active_loans' => (int) $r['active_loans']
        ];
    }

    public static function create(array $data): array {
        $fullName = trim($data['full_name'] ?? '');
        $doc = trim($data['document_number'] ?? '');

        if (empty($fullName) || empty($doc)) {
            Response::error('VALIDATION_ERROR', 'El nombre completo y el número de documento son obligatorios.', 422);
        }

        $db = Database::getConnection();

        // CA-05.1: Documento único
        $stmtCheck = $db->prepare("SELECT id FROM library.member WHERE document_number = :doc LIMIT 1");
        $stmtCheck->execute(['doc' => $doc]);
        if ($stmtCheck->fetch()) {
            Response::error('DOCUMENT_TAKEN', "El documento de identidad {$doc} ya está registrado.", 409);
        }

        $id = Uuid::v4();
        $stmt = $db->prepare("
            INSERT INTO library.member (id, full_name, document_number)
            VALUES (:id, :name, :doc)
        ");
        $stmt->execute([
            'id' => $id,
            'name' => $fullName,
            'doc' => $doc
        ]);

        return self::get($id);
    }
}


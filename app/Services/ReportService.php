<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Response;
use PDO;

class ReportService {
    public static function getMostBorrowed(string $from, string $to): array {
        if (empty($from) || empty($to)) {
            Response::error('VALIDATION_ERROR', 'Las fechas «from» y «to» son obligatorias.', 422);
        }

        // CA-10.2: Fin anterior al inicio: 422 INVALID_DATE_RANGE
        if ($to < $from) {
            Response::error('INVALID_DATE_RANGE', 'La fecha final (to) no puede ser anterior a la inicial (from).', 422, [
                ['field' => 'to', 'issue' => 'fecha fin anterior al inicio']
            ]);
        }

        $db = Database::getConnection();

        // ADR-004: Agrupa por valores congelados (book_id, book_title, category_name)
        // CA-10.3: No expone socios ni bibliotecarios
        $sql = "
            SELECT li.book_id, li.book_title, li.category_name, COUNT(li.id) as times_borrowed
            FROM library.loan_item li
            JOIN library.loan l ON li.loan_id = l.id
            WHERE l.loaned_at::DATE >= :from AND l.loaned_at::DATE <= :to
            GROUP BY li.book_id, li.book_title, li.category_name
            ORDER BY times_borrowed DESC, li.book_title ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            'from' => $from,
            'to' => $to
        ]);
        $rows = $stmt->fetchAll();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => array_map(function ($r) {
                return [
                    'book_id' => $r['book_id'],
                    'book_title' => $r['book_title'],
                    'category_name' => $r['category_name'],
                    'times_borrowed' => (int) $r['times_borrowed']
                ];
            }, $rows)
        ];
    }
}


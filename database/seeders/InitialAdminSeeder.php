<?php

use App\Core\Database;
use App\Core\Uuid;

class InitialAdminSeeder {
    public static function run(): void {
        $db = Database::getConnection();

        $adminUser = strtolower(trim(getenv('ADMIN_USERNAME') ?: 'admin'));
        $adminPass = getenv('ADMIN_PASSWORD') ?: 'admin1234';

        // Verificar si ya existe
        $stmt = $db->prepare('SELECT id FROM library."user" WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $adminUser]);
        if ($stmt->fetch()) {
            return;
        }

        $id = Uuid::v4();
        $hash = password_hash($adminPass, PASSWORD_BCRYPT);

        $stmtIns = $db->prepare('
            INSERT INTO library."user" (id, username, password_hash, role)
            VALUES (:id, :u, :p, :r)
        ');
        $stmtIns->execute([
            'id' => $id,
            'u' => $adminUser,
            'p' => $hash,
            'r' => 'admin'
        ]);

        echo " [✓] Initial Administrator '$adminUser' seeded successfully.\n";
    }
}


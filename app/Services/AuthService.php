<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Jwt;
use App\Core\Response;
use App\Core\Uuid;

class AuthService {
    public static function login(string $username, string $password): array {
        $usernameClean = strtolower(trim($username));
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT id, username, password_hash, role FROM library."user" WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $usernameClean]);
        $user = $stmt->fetch();

        // CA-01.2: No revelar si falló el usuario o la clave
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Response::error('INVALID_CREDENTIALS', 'Credenciales inválidas. Verifique sus datos.', 401);
        }

        $token = Jwt::createToken($user['id'], $user['role'], 28800);

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 28800,
            'role' => $user['role']
        ];
    }

    public static function createLibrarian(string $username, string $password, string $role): array {
        // CA-02.3: Pedir el rol admin por API responde 422
        if (strtolower(trim($role)) !== 'librarian') {
            Response::error('VALIDATION_ERROR', 'Solo es posible crear usuarios con el rol «librarian». El rol admin se provisiona por entorno.', 422, [
                ['field' => 'role', 'issue' => 'rol no permitido']
            ]);
        }

        $usernameClean = strtolower(trim($username));
        if (empty($usernameClean)) {
            Response::error('VALIDATION_ERROR', 'El nombre de usuario es obligatorio.', 422);
        }

        if (strlen($password) < 6) {
            Response::error('VALIDATION_ERROR', 'La contraseña debe contener al menos 6 caracteres.', 422);
        }

        $db = Database::getConnection();

        // CA-02.2: Repetido 409 USERNAME_TAKEN
        $stmtCheck = $db->prepare('SELECT id FROM library."user" WHERE username = :u LIMIT 1');
        $stmtCheck->execute(['u' => $usernameClean]);
        if ($stmtCheck->fetch()) {
            Response::error('USERNAME_TAKEN', "El nombre de usuario «{$usernameClean}» ya se encuentra registrado.", 409);
        }

        $id = Uuid::v4();
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $db->prepare('
            INSERT INTO library."user" (id, username, password_hash, role)
            VALUES (:id, :u, :p, :r)
        ');
        $stmt->execute([
            'id' => $id,
            'u' => $usernameClean,
            'p' => $passwordHash,
            'r' => 'librarian'
        ]);

        return [
            'id' => $id,
            'username' => $usernameClean,
            'role' => 'librarian'
        ];
    }
}


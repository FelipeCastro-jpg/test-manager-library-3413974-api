<?php

namespace App\Core;

class Response {
    public static function setCorsHeaders(): void {
        $origin = getenv('CORS_ORIGIN') ?: '*';
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
    }

    public static function json($data, int $status = 200): void {
        self::setCorsHeaders();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(0);
    }

    public static function noContent(): void {
        self::setCorsHeaders();
        http_response_code(204);
        exit(0);
    }

    public static function error(string $code, string $message, int $status = 400, array $details = []): void {
        self::setCorsHeaders();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode([
            'code' => $code,
            'message' => $message,
            'details' => $details
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(0);
    }
}


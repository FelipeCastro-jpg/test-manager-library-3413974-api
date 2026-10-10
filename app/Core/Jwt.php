<?php

namespace App\Core;

use Exception;

class Jwt {
    private static function getSecret(): string {
        return getenv('JWT_SECRET') ?: 'library_secret_jwt_key_3413974_super_secure';
    }

    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function createToken(string $userId, string $role, int $expiresIn = 28800): string {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $now = time();
        $payload = [
            'sub' => $userId,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + $expiresIn
        ];

        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", self::getSecret(), true);
        $signatureEncoded = self::base64UrlEncode($signature);

        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }

    public static function verifyToken(string $jwt): ?array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
        $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", self::getSecret(), true);
        if (!hash_equals($expectedSignature, self::base64UrlDecode($signatureEncoded))) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null; // Expirado
        }

        return $payload;
    }
}


<?php

namespace App\Core;

class Request {
    private array $jsonBody = [];
    private ?array $user = null;

    public function __construct() {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $this->jsonBody = $parsed;
            }
        }
    }

    public function getMethod(): string {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function getPath(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return rtrim($path, '/') ?: '/';
    }

    public function getQuery(string $key, $default = null) {
        return $_GET[$key] ?? $default;
    }

    public function getBody(): array {
        return !empty($this->jsonBody) ? $this->jsonBody : $_POST;
    }

    public function get(string $key, $default = null) {
        $body = $this->getBody();
        return $body[$key] ?? $_GET[$key] ?? $default;
    }

    public function getFile(string $key): ?array {
        return $_FILES[$key] ?? null;
    }

    public function getBearerToken(): ?string {
        $headers = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }

        if (!empty($headers) && preg_match('/Bearer\s(\S+)/i', $headers, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function setUser(array $user): void {
        $this->user = $user;
    }

    public function getUser(): ?array {
        return $this->user;
    }
}


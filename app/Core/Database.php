<?php

namespace App\Core;

use PDO;
use Exception;

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: 'db';
            $port = getenv('DB_PORT') ?: '5432';
            $dbname = getenv('DB_DATABASE') ?: 'library';
            $user = getenv('DB_USERNAME') ?: 'postgres';
            $pass = getenv('DB_PASSWORD') ?: 'postgres';

            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Establecer search_path a esquema library
            self::$instance->exec("CREATE SCHEMA IF NOT EXISTS library;");
            self::$instance->exec("SET search_path TO library, public;");
        }
        return self::$instance;
    }
}


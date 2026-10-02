<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\DatabaseException;
use PDO;
use PDOException;

final class Database
{
    public static function connect(): PDO
    {
        $host = $_ENV['DB_HOST'] ?? '';
        $database = $_ENV['DB_NAME'] ?? '';
        $username = $_ENV['DB_USER'] ?? '';
        $password = $_ENV['DB_PASS'] ?? '';

        $dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";

        try {
            return new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ]);
        } catch (PDOException) {
            // Do not expose the DSN, credentials, or the original exception.
            throw new DatabaseException('Database connection failed.');
        }
    }
}

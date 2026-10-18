<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

final class IntegrationDatabase
{
    public static function connect(IntegrationConfig $config, string $database): PDO
    {
        if ($database !== 'information_schema' && $database !== $config->database) {
            throw new RuntimeException('Unexpected database target.');
        }

        $dsn = "mysql:host={$config->host};port={$config->port};dbname={$database};charset=utf8mb4";
        $connection = new PDO($dsn, $config->user, $config->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);

        if ($connection->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new RuntimeException('Database identity mismatch.');
        }

        return $connection;
    }
}

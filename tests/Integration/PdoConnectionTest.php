<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Exceptions\DatabaseException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\IntegrationConfig;
use Tests\Support\IntegrationDatabase;
use Tests\Support\TemporaryDatabaseEnvironment;

require_once dirname(__DIR__) . '/Support/IntegrationConfig.php';
require_once dirname(__DIR__) . '/Support/IntegrationDatabase.php';
require_once dirname(__DIR__) . '/Support/TemporaryDatabaseEnvironment.php';

final class PdoConnectionTest extends TestCase
{
    private function environmentSnapshot(): array
    {
        $snapshot = [];
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
            $snapshot[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null];
        }
        return $snapshot;
    }

    private function fixtureRow(PDO $db, int $id): array
    {
        $statement = $db->prepare(
            'SELECT id, confirmado, admin FROM usuarios_devwebcamp WHERE id = :id LIMIT :limit'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':limit', 1, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();
        self::assertIsArray($row);
        return $row;
    }

    public function testConnectionOptionsAndSyntheticUserScalars(): void
    {
        $config = IntegrationConfig::fromEnvironment();
        $verifier = IntegrationDatabase::connect($config, $config->database);
        self::assertSame(0, (int) $verifier->query('SELECT COUNT(*) FROM usuarios_devwebcamp')->fetchColumn());
        $original = $this->environmentSnapshot();

        TemporaryDatabaseEnvironment::run($config, function () use ($config, $verifier): void {
            $db = Database::connect();
            self::assertInstanceOf(PDO::class, $db);
            self::assertSame($config->database, $db->query('SELECT DATABASE()')->fetchColumn());
            self::assertSame(PDO::ERRMODE_EXCEPTION, $db->getAttribute(PDO::ATTR_ERRMODE));
            self::assertSame(PDO::FETCH_ASSOC, $db->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
            self::assertFalse((bool) $db->getAttribute(PDO::ATTR_EMULATE_PREPARES));
            self::assertTrue($db->getAttribute(PDO::ATTR_STRINGIFY_FETCHES));
            self::assertSame('utf8mb4', $db->query('SELECT @@character_set_connection')->fetchColumn());

            $db->beginTransaction();
            try {
                $db->exec('INSERT INTO usuarios_devwebcamp (confirmado, admin) VALUES (1, 0)');
                $id = (int) $db->lastInsertId();
                self::assertGreaterThan(0, $id);
                $stringified = $this->fixtureRow($db, $id);
                self::assertSame(['id' => (string) $id, 'confirmado' => '1', 'admin' => '0'], $stringified);
                self::assertSame(
                    ['id' => 'string', 'confirmado' => 'string', 'admin' => 'string'],
                    array_map('get_debug_type', $stringified)
                );

                $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
                try {
                    self::assertFalse($db->getAttribute(PDO::ATTR_STRINGIFY_FETCHES));
                    $native = $this->fixtureRow($db, $id);
                    self::assertSame(['id' => $id, 'confirmado' => 1, 'admin' => 0], $native);
                    self::assertSame(
                        ['id' => 'int', 'confirmado' => 'int', 'admin' => 'int'],
                        array_map('get_debug_type', $native)
                    );
                } finally {
                    $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
                }

                try {
                    $db->query('SELECT FROM');
                    self::fail('Invalid SQL must throw PDOException.');
                } catch (PDOException) {
                    self::assertTrue(true);
                }
            } finally {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
            }

            self::assertSame(0, (int) $verifier->query('SELECT COUNT(*) FROM usuarios_devwebcamp')->fetchColumn());
        });

        self::assertTrue($original === $this->environmentSnapshot());
    }

    public function testMissingDisposableDatabaseHasGenericError(): void
    {
        $config = IntegrationConfig::fromEnvironment();
        $server = IntegrationDatabase::connect($config, 'information_schema');
        $missing = 'devwebcamp_port6_test_' . bin2hex(random_bytes(4));
        self::assertNotSame($config->database, $missing);
        $exists = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$missing]);
        self::assertFalse($exists->fetchColumn());
        $original = $this->environmentSnapshot();

        TemporaryDatabaseEnvironment::run($config, static function () use ($config, $missing): void {
            $_ENV['DB_NAME'] = $missing;
            try {
                Database::connect();
                self::fail('Connecting to a missing test database must fail.');
            } catch (DatabaseException $error) {
                $message = $error->getMessage();
                self::assertTrue($message === 'Database connection failed.');
                self::assertTrue($error->getPrevious() === null);
                foreach (['mysql:', $missing, $config->host, $config->user] as $sensitive) {
                    self::assertFalse(str_contains($message, $sensitive));
                }
                if ($config->password !== '') {
                    self::assertFalse(str_contains($message, $config->password));
                }
            }
        });

        self::assertTrue($original === $this->environmentSnapshot());
    }

    public function testTemporaryDatabaseEnvironmentRestoresOriginalKeysOnSuccessAndFailure(): void
    {
        $config = IntegrationConfig::fromEnvironment();
        $original = $this->environmentSnapshot();

        TemporaryDatabaseEnvironment::run($config, static function () use ($config): void {
            self::assertTrue($_ENV['DB_HOST'] === $config->host);
            self::assertTrue($_ENV['DB_NAME'] === $config->database);
            self::assertTrue($_ENV['DB_USER'] === $config->user);
            self::assertTrue($_ENV['DB_PASS'] === $config->password);
        });
        self::assertTrue($original === $this->environmentSnapshot());

        try {
            TemporaryDatabaseEnvironment::run($config, static function (): never {
                throw new RuntimeException('Expected bridge failure.');
            });
            self::fail('The bridge must propagate callback failures.');
        } catch (RuntimeException $error) {
            self::assertSame('Expected bridge failure.', $error->getMessage());
        }
        self::assertTrue($original === $this->environmentSnapshot());
    }
}

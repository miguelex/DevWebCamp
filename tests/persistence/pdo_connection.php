<?php

declare(strict_types=1);

// Exercise the isolated PDO connection without booting the mysqli application.
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Core\Database;
use App\Exceptions\DatabaseException;
use Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

function check(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }

    echo "PASS: {$description}", PHP_EOL;
}

function readUserScalarTypes(PDO $connection): array
{
    $statement = $connection->prepare(
        'SELECT id, confirmado, admin FROM usuarios_devwebcamp ORDER BY id LIMIT :limit'
    );
    $statement->bindValue(':limit', 1, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch();
    check(is_array($row), 'Prepared read-only SELECT returns a development user');

    return array_map('get_debug_type', $row);
}

try {
    $envPath = is_file($root . '/.env') ? $root : $root . '/includes';
    Dotenv::createImmutable($envPath)->safeLoad();

    $connection = Database::connect();
    check($connection instanceof PDO, 'Database::connect() returns PDO');
    check($connection->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION, 'PDO throws query errors');
    check($connection->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE) === PDO::FETCH_ASSOC, 'PDO defaults to associative rows');
    check(in_array($connection->getAttribute(PDO::ATTR_EMULATE_PREPARES), [false, 0], true), 'PDO uses native prepared statements');
    check($connection->getAttribute(PDO::ATTR_STRINGIFY_FETCHES) === true, 'PDO stringifies fetched scalars by default');
    check($connection->query('SELECT @@character_set_connection')->fetchColumn() === 'utf8mb4', 'Connection charset is utf8mb4');

    $stringTypes = readUserScalarTypes($connection);
    check($stringTypes === ['id' => 'string', 'confirmado' => 'string', 'admin' => 'string'], 'Stringified user scalars match mysqli types');

    $connection->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    try {
        check($connection->getAttribute(PDO::ATTR_STRINGIFY_FETCHES) === false, 'PDO can disable scalar stringification');
        $nativeTypes = readUserScalarTypes($connection);
        check($nativeTypes === ['id' => 'int', 'confirmado' => 'int', 'admin' => 'int'], 'Native PDO user scalars are integers');
    } finally {
        $connection->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    }

    try {
        $connection->query('SELECT FROM');
        throw new RuntimeException('Invalid read-only query unexpectedly succeeded');
    } catch (PDOException) {
        check(true, 'Direct PDO query errors remain PDOException');
    }

    $originalDatabase = $_ENV['DB_NAME'] ?? null;
    $missingDatabase = 'port3_missing_' . bin2hex(random_bytes(8));
    $_ENV['DB_NAME'] = $missingDatabase;
    try {
        try {
            Database::connect();
            throw new RuntimeException('Invalid database connection unexpectedly succeeded');
        } catch (DatabaseException $error) {
            $message = $error->getMessage();
            check($message === 'Database connection failed.', 'Connection failure has a generic message');
            check($error->getPrevious() === null, 'Connection failure does not retain a sensitive PDOException');
            check(!str_contains($message, $missingDatabase) && !str_contains($message, 'mysql:'), 'Connection failure does not expose the DSN');
            $password = $_ENV['DB_PASS'] ?? '';
            check($password === '' || !str_contains($message, $password), 'Connection failure does not expose credentials');
        }
    } finally {
        if ($originalDatabase === null) {
            unset($_ENV['DB_NAME']);
        } else {
            $_ENV['DB_NAME'] = $originalDatabase;
        }
    }

    echo 'PDO connection checks passed.', PHP_EOL;
} catch (Throwable $error) {
    // Runtime messages may contain local configuration. Never print them here.
    fwrite(STDERR, 'PDO connection checks failed (' . get_class($error) . ").\n");
    exit(1);
}

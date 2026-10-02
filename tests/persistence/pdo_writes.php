<?php

declare(strict_types=1);

// Run real CRUD only against an explicitly selected disposable database.
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Core\ActiveRecord;
use App\Core\Database;
use App\Models\Categoria;
use Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

function checkWrite(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException("Failed: {$description}");
    }

    echo "PASS: {$description}\n";
}

function categorySnapshot(PDO $connection): array
{
    $rows = $connection->query('SELECT id, nombre FROM categorias ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $count = (int) $connection->query('SELECT COUNT(*) FROM categorias')->fetchColumn();
    checkWrite(count($rows) === $count, 'category count agrees with row snapshot');

    return [
        'count' => $count,
        'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
    ];
}

function categoryRow(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare('SELECT id, nombre FROM categorias WHERE id = ?');
    $statement->execute([$id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

$selectedDatabase = $argv[1] ?? '';
if (!preg_match('/\Adevwebcamp_port3_test(?:_[a-zA-Z0-9_]+)?\z/D', $selectedDatabase)
    || $selectedDatabase === 'devwebcamp') {
    fwrite(STDERR, "Usage: php tests/persistence/pdo_writes.php devwebcamp_port3_test[_suffix]\n");
    exit(2);
}

$envPath = is_file($root . '/.env') ? $root : $root . '/includes';
Dotenv::createImmutable($envPath)->safeLoad();
$sourceDatabase = $_ENV['DB_NAME'] ?? null;
if ($sourceDatabase !== 'devwebcamp' || $selectedDatabase === $sourceDatabase) {
    fwrite(STDERR, "Refusing to use a database other than the explicitly selected disposable test database.\n");
    exit(2);
}

// Database::connect() reads the existing DB_* keys. Override only this CLI process.
$_ENV['DB_NAME'] = $selectedDatabase;
$connection = null;
$before = null;
$after = null;
$failure = null;

try {
    $connection = Database::connect();
    checkWrite($connection->query('SELECT DATABASE()')->fetchColumn() === $selectedDatabase, 'connected to the selected disposable database');

    $tables = $connection->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    checkWrite($tables === ['categorias'], 'disposable database contains only categorias');

    ActiveRecord::setDB($connection);
    $property = new ReflectionProperty(ActiveRecord::class, 'db');
    checkWrite($property->getValue() === $connection, 'ActiveRecord and transaction share the same PDO instance');

    $before = categorySnapshot($connection);
    echo 'Before: count=' . $before['count'] . ', sha256=' . $before['sha256'] . "\n";

    // Session-only strict mode makes overlength data a deterministic real SQL error.
    $connection->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
    $connection->beginTransaction();
    checkWrite($connection->inTransaction(), 'transaction started');

    $name = 'port3-synthetic-' . bin2hex(random_bytes(6));
    $created = new Categoria();
    $created->nombre = $name;
    $createResult = $created->crear();
    $firstId = $createResult['id'] ?? null;
    checkWrite($createResult['resultado'] === true && is_int($firstId) && $firstId > 0, 'crear() returns the legacy result array and an integer id');
    checkWrite($created->id === null, 'crear() does not assign the inserted id to the model');
    checkWrite((int) $connection->lastInsertId() === $firstId, 'crear() id matches PDO lastInsertId()');
    checkWrite(categoryRow($connection, $firstId) === ['id' => (string) $firstId, 'nombre' => " {$name} "], 'crear() persists the legacy leading and trailing spaces');

    $saved = new Categoria();
    $saved->nombre = $name . '-saved';
    $saveInsertResult = $saved->guardar();
    $secondId = $saveInsertResult['id'] ?? null;
    checkWrite(is_array($saveInsertResult) && $saveInsertResult['resultado'] === true && is_int($secondId) && $secondId > $firstId, 'guardar() INSERT returns the legacy array');
    checkWrite((int) $connection->lastInsertId() === $secondId && $saved->id === null, 'guardar() INSERT preserves lastInsertId() and model id behavior');
    checkWrite(categoryRow($connection, $secondId) === ['id' => (string) $secondId, 'nombre' => " {$name}-saved "], 'guardar() INSERT persists the expected row');

    $saved->id = $secondId;
    $saved->nombre = $name . '-updated';
    checkWrite($saved->guardar() === true, 'guardar() UPDATE returns true');
    checkWrite(categoryRow($connection, $secondId) === ['id' => (string) $secondId, 'nombre' => $saved->nombre], 'guardar() UPDATE persists the new value without INSERT padding');

    $saved->nombre = $name . '-updated-again';
    checkWrite($saved->actualizar() === true, 'actualizar() returns true');
    checkWrite(categoryRow($connection, $secondId) === ['id' => (string) $secondId, 'nombre' => $saved->nombre], 'actualizar() persists the new value');

    $duplicate = $connection->prepare('INSERT INTO categorias (id, nombre) VALUES (?, ?)');
    try {
        $duplicate->execute([$secondId, $name . '-duplicate']);
        throw new RuntimeException('Duplicate primary key unexpectedly succeeded.');
    } catch (PDOException) {
        checkWrite(true, 'duplicate primary key produces a real PDOException');
    }

    $tooLong = new Categoria();
    $tooLong->nombre = str_repeat('x', 100);
    try {
        $tooLong->crear();
        throw new RuntimeException('Overlength insert unexpectedly succeeded in strict mode.');
    } catch (PDOException) {
        checkWrite(true, 'strict SQL mode rejects an overlength ActiveRecord INSERT');
    }

    checkWrite($saved->eliminar() === true, 'eliminar() returns true');
    checkWrite(categoryRow($connection, $secondId) === null, 'eliminar() removes the selected row');
    checkWrite(categoryRow($connection, $firstId) !== null, 'unrelated inserted row remains visible before rollback');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($connection instanceof PDO) {
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
                echo "PASS: transaction rolled back\n";
            }
            if ($before !== null) {
                $after = categorySnapshot($connection);
                echo 'After: count=' . $after['count'] . ', sha256=' . $after['sha256'] . "\n";
                checkWrite($after === $before, 'count and row fingerprint match after rollback');
            }
        } catch (Throwable $cleanupError) {
            $failure = $cleanupError;
        }
    }
    $_ENV['DB_NAME'] = $sourceDatabase;
}

if ($failure !== null) {
    // Never print SQL, connection details, row data, or driver messages.
    fwrite(STDERR, 'PDO write contract failed (' . get_class($failure) . ").\n");
    exit(1);
}

echo "PDO write contract passed (real writes rolled back; disposable database only).\n";

<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\ActiveRecord;
use App\Models\Categoria;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\IntegrationConfig;
use Tests\Support\IntegrationDatabase;

require_once dirname(__DIR__) . '/Support/IntegrationConfig.php';
require_once dirname(__DIR__) . '/Support/IntegrationDatabase.php';

final class CategoriaWritesTest extends TestCase
{
    private function snapshot(PDO $db): array
    {
        $rows = $db->query('SELECT id, nombre FROM categorias ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return [count($rows), hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }

    private function row(PDO $db, int $id): ?array
    {
        $statement = $db->prepare('SELECT id, nombre FROM categorias WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function testPdoWriteContractsRollbackWithoutResidualRows(): void
    {
        $config = IntegrationConfig::fromEnvironment();
        $db = IntegrationDatabase::connect($config, $config->database);
        self::assertSame(['categorias'], $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $before = $this->snapshot($db);
        self::assertSame(0, $before[0]);

        $db->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
        ActiveRecord::setDB($db);
        $db->beginTransaction();
        try {
            $name = 'port6-synthetic-' . bin2hex(random_bytes(4));
            $first = new Categoria();
            $first->nombre = $name;
            $insert = $first->crear();
            $firstId = $insert['id'];
            self::assertTrue($insert['resultado']);
            self::assertIsInt($firstId);
            self::assertGreaterThan(0, $firstId);
            self::assertNull($first->id);
            self::assertSame($firstId, (int) $db->lastInsertId());
            self::assertSame(['id' => (string) $firstId, 'nombre' => " {$name} "], $this->row($db, $firstId));

            $second = new Categoria();
            $second->nombre = $name . '-saved';
            $saved = $second->guardar();
            $secondId = $saved['id'];
            self::assertTrue($saved['resultado']);
            self::assertGreaterThan($firstId, $secondId);
            self::assertSame($secondId, (int) $db->lastInsertId());
            self::assertNull($second->id);
            self::assertSame(['id' => (string) $secondId, 'nombre' => " {$name}-saved "], $this->row($db, $secondId));

            $second->id = $secondId;
            $second->nombre = $name . '-updated';
            self::assertTrue($second->guardar());
            self::assertSame($second->nombre, $this->row($db, $secondId)['nombre']);
            $second->nombre .= '-again';
            self::assertTrue($second->actualizar());
            self::assertSame($second->nombre, $this->row($db, $secondId)['nombre']);

            try {
                $db->prepare('INSERT INTO categorias (id, nombre) VALUES (?, ?)')
                    ->execute([$secondId, 'duplicate']);
                self::fail('A duplicate primary key must fail.');
            } catch (PDOException) {
                self::assertTrue(true);
            }

            $overlength = new Categoria();
            $overlength->nombre = str_repeat('x', 100);
            try {
                $overlength->crear();
                self::fail('Strict mode must reject overlength input.');
            } catch (PDOException) {
                self::assertTrue(true);
            }

            self::assertTrue($second->eliminar());
            self::assertNull($this->row($db, $secondId));
            self::assertNotNull($this->row($db, $firstId));
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            ActiveRecord::setDB(null);
        }

        self::assertSame($before, $this->snapshot($db));
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM categorias')->fetchColumn());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ActiveRecord;
use App\Models\Categoria;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\StubPdo;

require_once dirname(__DIR__) . '/Support/StubPdo.php';

final class ActiveRecordTest extends TestCase
{
    private StubPdo $db;

    protected function setUp(): void
    {
        $this->db = new StubPdo();
        ActiveRecord::setDB($this->db);
    }

    protected function tearDown(): void
    {
        ActiveRecord::setDB(null);
    }

    public function testTrustedQueryHydratesOnlyDeclaredProperties(): void
    {
        $this->db->rows = [['id' => '5', 'nombre' => 'First', 'unknown' => 'ignored']];

        $categories = Categoria::consultarSQL('SELECT * FROM categorias');

        self::assertCount(1, $categories);
        self::assertInstanceOf(Categoria::class, $categories[0]);
        self::assertSame('5', $categories[0]->id);
        self::assertFalse(property_exists($categories[0], 'unknown'));
        self::assertSame('SELECT * FROM categorias', $this->db->lastStatement->sql);
    }

    public function testWhereBindsValueWithoutInterpolatingIt(): void
    {
        $this->db->rows = [['id' => '5', 'nombre' => "O'Neil"]];

        $category = Categoria::where('nombre', "O'Neil");

        self::assertSame('5', $category->id);
        self::assertSame('SELECT * FROM categorias WHERE nombre = ?', $this->db->lastStatement->sql);
        self::assertSame(["O'Neil"], $this->db->lastStatement->values);
    }

    public function testPaginationBindsIntegers(): void
    {
        Categoria::paginar(10, 20);

        self::assertSame('SELECT * FROM categorias ORDER BY id DESC LIMIT ? OFFSET ?', $this->db->lastStatement->sql);
        self::assertSame([1 => [10, PDO::PARAM_INT], 2 => [20, PDO::PARAM_INT]], $this->db->lastStatement->bindings);
    }

    public function testRejectsUnsafeSortInput(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Categoria::all('DESC; DROP TABLE categorias');
    }
}

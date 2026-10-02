<?php

declare(strict_types=1);

// The legacy model emits known PHP 8.2 deprecations; they are recorded in the baseline.
error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Core\ActiveRecord;
use App\Models\Categoria;
use App\Models\Usuario;

final class FakeResult
{
    private int $offset = 0;
    public bool $freed = false;

    public function __construct(private array $rows)
    {
    }

    public function fetch_assoc(): ?array
    {
        return $this->rows[$this->offset++] ?? null;
    }

    public function fetch_array(): array|false
    {
        $row = $this->fetch_assoc();
        return $row === null ? false : array_values($row);
    }

    public function free(): void
    {
        $this->freed = true;
    }
}

final class FakeConnection
{
    public array $queries = [];
    public array $rows = [];
    public int $insert_id = 137;
    public bool $writeResult = true;
    public ?FakeResult $lastResult = null;

    public function query(string $query): FakeResult|bool
    {
        $this->queries[] = $query;

        if (str_starts_with(ltrim($query), 'SELECT COUNT(*)')) {
            return $this->lastResult = new FakeResult([['COUNT(*)' => '2']]);
        }

        if (str_starts_with(ltrim($query), 'SELECT')) {
            return $this->lastResult = new FakeResult($this->rows);
        }

        return $this->writeResult;
    }

    public function escape_string(mixed $value): string
    {
        return addslashes((string) $value);
    }

    public function lastQuery(): string
    {
        return $this->queries[array_key_last($this->queries)];
    }
}

$checks = 0;
function check(bool $condition, string $description): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException("Failed: {$description}");
    }
}

$db = new FakeConnection();
ActiveRecord::setDB($db);
$db->rows = [['id' => '5', 'nombre' => 'First', 'ignored' => 'not hydrated'], ['id' => '6', 'nombre' => 'Second']];

$items = Categoria::consultarSQL('SELECT * FROM categorias');
check(count($items) === 2 && $items[0] instanceof Categoria, 'consultarSQL returns hydrated model list');
check($items[0]->id === '5' && !property_exists($items[0], 'ignored'), 'hydration keeps scalar strings and ignores unknown fields');
check($db->lastResult->freed, 'consultarSQL frees the result');

check(array_map(fn($item) => $item->id, Categoria::all()) === ['5', '6'], 'all returns a list');
check($db->lastQuery() === 'SELECT * FROM categorias ORDER BY id DESC', 'all defaults to DESC');
Categoria::all('ASC');
check($db->lastQuery() === 'SELECT * FROM categorias ORDER BY id ASC', 'all accepts ASC');
Categoria::ordenar('nombre', 'ASC');
check($db->lastQuery() === 'SELECT * FROM categorias ORDER BY nombre ASC', 'ordenar keeps its SQL shape');
Categoria::paginar(10, 20);
check($db->lastQuery() === 'SELECT * FROM categorias ORDER BY id DESC LIMIT 10 OFFSET 20 ', 'paginar keeps order, limit and offset');
check(Categoria::total() === '2', 'total returns the driver scalar without casting');
check($db->lastQuery() === 'SELECT COUNT(*) FROM categorias', 'total without filter');
Categoria::total('id', 5);
check($db->lastQuery() === "SELECT COUNT(*) FROM categorias WHERE id = '5'", 'total with filter');

check(Categoria::find(5)?->id === '5', 'find returns the first object');
check($db->lastQuery() === 'SELECT * FROM categorias WHERE id = 5', 'find SQL');
check(Categoria::where('nombre', 'First')?->id === '5', 'where returns one object');
check($db->lastQuery() === "SELECT * FROM categorias WHERE nombre = 'First'", 'where SQL');
check(count(Categoria::whereArray(['id' => 5, 'nombre' => 'First'])) === 2, 'whereArray returns a list');
check($db->lastQuery() === "SELECT * FROM categorias WHERE id = '5' AND nombre = 'First' ", 'whereArray joins conditions');
$db->rows = [];
check(Categoria::find(999) === null && Categoria::where('id', 999) === null, 'missing first row yields null');
check(Categoria::whereArray(['id' => 999]) === [], 'missing multi-row result yields empty list');

$db->rows = [['id' => '5', 'nombre' => 'First'], ['id' => '6', 'nombre' => 'Second']];
check(Categoria::get(2)?->id === '5', 'get returns one object with fake results');
check($db->lastQuery() === 'SELECT * FROM categorias LIMIT 2 ORDER BY id DESC', 'get retains invalid legacy SQL order');

$item = new Categoria();
$item->id = null;
$item->nombre = "O'Neil";
check($item->atributos() === ['nombre' => "O'Neil"], 'atributos excludes id');
check($item->sanitizarAtributos() === ['nombre' => "O\\'Neil"], 'sanitizarAtributos delegates escaping');
$item->sincronizar(['id' => 9, 'nombre' => null, 'unknown' => 'ignored']);
check($item->id === 9 && $item->nombre === "O'Neil" && !property_exists($item, 'unknown'), 'sincronizar ignores null and unknown keys');

$item->id = null;
$created = $item->crear();
check($created === ['resultado' => true, 'id' => 137], 'crear returns result and insert id');
check($item->id === null, 'crear does not assign the inserted id to the object');
check(str_contains($db->lastQuery(), "VALUES (' O\\'Neil ')"), 'crear adds a leading space to the first value');
check(is_array($item->guardar()), 'guardar on new object returns the crear array');
$db->writeResult = false;
$failed = $item->crear();
check($failed['resultado'] === false && (bool) $failed === true, 'failed crear still returns a truthy array');
$db->writeResult = true;
$item->id = 9;
check($item->guardar() === true && str_starts_with($db->lastQuery(), 'UPDATE categorias SET '), 'guardar on existing object updates and returns bool');
check(str_contains($db->lastQuery(), "WHERE id = '9'  LIMIT 1 "), 'actualizar limits by escaped id');
check($item->actualizar() === true, 'actualizar is a public method');
check($item->eliminar() === true && $db->lastQuery() === 'DELETE FROM categorias WHERE id = 9 LIMIT 1', 'eliminar returns bool and limits the delete');

Usuario::setAlerta('error', 'legacy alert');
check(Usuario::getAlertas()['error'] === ['legacy alert'], 'static alerts are readable');
check((new Usuario())->validar() === [] && Usuario::getAlertas() === [], 'base validar clears alerts');

echo "Legacy ActiveRecord contract: {$checks} checks passed (all writes simulated; database untouched).\n";

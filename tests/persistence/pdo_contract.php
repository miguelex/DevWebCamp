<?php

declare(strict_types=1);

// Keep known transient-property deprecations separate from functional checks.
error_reporting(E_ALL & ~E_DEPRECATED);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Core\ActiveRecord;
use App\Models\Categoria;
use App\Models\Usuario;

final class SimulatedStatement extends PDOStatement
{
    public array $values = [];
    public array $bindings = [];
    public bool $success = true;
    public array $rows = [];
    public string $sql = '';

    public function execute(?array $params = null): bool
    {
        $this->values = $params ?? [];
        return $this->success;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = [$value, $type];
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->rows[0][0] ?? false;
    }
}

final class SimulatedPDO extends PDO
{
    public array $rows = [];
    public bool $success = true;
    public ?SimulatedStatement $lastStatement = null;

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, ' ORDER BY id DESC') && str_contains($query, ' LIMIT ') && strpos($query, ' LIMIT ') < strpos($query, ' ORDER BY')) {
            throw new PDOException('Legacy invalid SQL');
        }
        if (str_ends_with($query, ' WHERE ')) {
            throw new PDOException('Legacy empty WHERE');
        }
        if (str_ends_with($query, ' WHERE id = ')) {
            throw new PDOException('Legacy incomplete id predicate');
        }
        $statement = new SimulatedStatement();
        $statement->sql = $query;
        $statement->rows = $this->rows;
        $statement->success = $this->success;
        return $this->lastStatement = $statement;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $this->prepare($query);
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'" . addslashes($string) . "'";
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return '137';
    }
}

$checks = 0;
function check(bool $condition, string $description): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException("Failed: {$description}"); }
}

$db = new SimulatedPDO();
ActiveRecord::setDB($db);
$db->rows = [['id' => '5', 'nombre' => 'First', 'unknown' => 'ignored'], ['id' => '6', 'nombre' => 'Second']];
$items = Categoria::consultarSQL('SELECT * FROM categorias');
check(count($items) === 2 && $items[0] instanceof Categoria, 'trusted SQL hydrates list');
check($items[0]->id === '5' && !property_exists($items[0], 'unknown'), 'hydration retains string types and ignores unknown fields');
check(array_map(fn($item) => $item->id, Categoria::all()) === ['5', '6'], 'all returns list');
check($db->lastStatement->sql === 'SELECT * FROM categorias ORDER BY id DESC', 'default sort preserved');
Categoria::ordenar('nombre', 'ASC');
check(str_ends_with($db->lastStatement->sql, 'ORDER BY nombre ASC'), 'validated sort column');
Categoria::paginar(10, 20);
check($db->lastStatement->bindings === [1 => [10, PDO::PARAM_INT], 2 => [20, PDO::PARAM_INT]], 'pagination integers are bound as integers');
$db->rows = [[0 => '2']];
check(Categoria::total() === '2', 'count keeps string result');
check(Categoria::total('id', 5) === '2' && $db->lastStatement->values === [5], 'filtered count binds value');
foreach ([null, false] as $legacyEmpty) {
    check(Categoria::total('nombre', $legacyEmpty) === '2' && $db->lastStatement->values === [''], 'filtered count preserves legacy null/false interpolation');
}
$db->rows = [['id' => '5', 'nombre' => 'First']];
check(Categoria::find(5)?->id === '5' && $db->lastStatement->values === [5], 'find returns first and binds id');
check(Categoria::where('nombre', "O'Neil")?->id === '5' && $db->lastStatement->values === ["O'Neil"], 'where binds raw value');
foreach ([null, false] as $legacyEmpty) {
    check(Categoria::where('nombre', $legacyEmpty)?->id === '5' && $db->lastStatement->values === [''], 'where preserves legacy null/false interpolation');
}
check(count(Categoria::whereArray(['id' => 5, 'nombre' => 'First'])) === 1 && $db->lastStatement->values === [5, 'First'], 'whereArray binds every value');
check(count(Categoria::whereArray(['id' => null, 'nombre' => false])) === 1 && $db->lastStatement->values === ['', ''], 'whereArray preserves legacy null/false interpolation');
$db->rows = [];
check(Categoria::find(999) === null && Categoria::where('id', 999) === null, 'missing singular results become null');
check(Categoria::whereArray(['id' => 999]) === [], 'missing plural results become empty list');
foreach ([fn() => Categoria::get(2), fn() => Categoria::whereArray([]), fn() => Categoria::find(null)] as $legacyInvalid) {
    try { $legacyInvalid(); throw new RuntimeException('Invalid legacy query unexpectedly succeeded'); }
    catch (PDOException $error) { check(true, 'legacy invalid SQL remains PDOException'); }
}
foreach ([fn() => Categoria::all('DESC; DROP TABLE categorias'), fn() => Categoria::where('unknown', 1), fn() => Categoria::paginar('1 OR 1=1', 0)] as $invalidIdentifier) {
    try { $invalidIdentifier(); throw new RuntimeException('Unsafe identifier unexpectedly accepted'); }
    catch (InvalidArgumentException $error) { check(true, 'unsafe SQL input rejected'); }
}
$item = new Categoria();
$item->id = null;
$item->nombre = "O'Neil";
check($item->atributos() === ['nombre' => "O'Neil"], 'attributes exclude id');
check($item->sanitizarAtributos() === ['nombre' => "O\\'Neil"], 'public sanitization preserves escaped-string shape');
$item->sincronizar(['id' => 9, 'nombre' => null, 'unknown' => 1]);
check($item->id === 9 && $item->nombre === "O'Neil" && !property_exists($item, 'unknown'), 'sync ignores null and unknown');
$item->id = null;
check($item->crear() === ['resultado' => true, 'id' => 137], 'create return shape and insert id type');
check($db->lastStatement->values === [" O'Neil "], 'single-field insert preserves both legacy spaces and raw quote');
check($item->id === null && is_array($item->guardar()), 'create does not assign model id');
$db->success = false;
check($item->crear() === ['resultado' => false, 'id' => 137], 'failed execute preserves truthy result array');
$db->success = true;
$item->id = 9;
check($item->guardar() === true && $db->lastStatement->values === ["O'Neil", '9'], 'update binds raw values and id');
check($item->actualizar() === true, 'update remains public');
check($item->eliminar() === true && $db->lastStatement->values === ['9'], 'delete binds id and returns bool');
Usuario::setAlerta('error', 'legacy alert');
check(Usuario::getAlertas()['error'] === ['legacy alert'], 'alerts remain shared');
check((new Usuario())->validar() === [] && Usuario::getAlertas() === [], 'validation clears alerts');
echo "PDO ActiveRecord contract: {$checks} checks passed (all writes simulated; database untouched).\n";

<?php

declare(strict_types=1);

// Known ActiveRecord and transient-property deprecations are checked separately.
error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Core\ActiveRecord;
use App\Models\Evento;
use App\Models\Ponente;
use App\Models\Registro;

final class ModelFieldsResult
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

    public function free(): void
    {
        $this->freed = true;
    }
}

final class ModelFieldsConnection
{
    public array $rows = [];
    public ?ModelFieldsResult $lastResult = null;

    public function query(string $query): ModelFieldsResult
    {
        if (!str_starts_with($query, 'SELECT ')) {
            throw new RuntimeException('Only read-only queries are permitted in this contract.');
        }

        return $this->lastResult = new ModelFieldsResult($this->rows);
    }
}

$checks = 0;
function checkModelFields(bool $condition, string $description): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException("Failed: {$description}");
    }
}

$ponenteDefaults = [
    'id' => null,
    'nombre' => '',
    'apellido' => '',
    'ciudad' => '',
    'pais' => '',
    'imagen' => '',
    'tags' => '',
    'redes' => '',
];
$registroDefaults = [
    'id' => null,
    'paquete_id' => '',
    'pago_id' => '',
    'token' => '',
    'usuario_id' => '',
];

$ponente = new Ponente();
$registro = new Registro();
checkModelFields(get_object_vars($ponente) === $ponenteDefaults, 'Ponente constructor retains defaults, scalar types and property order');
checkModelFields(get_object_vars($registro) === $registroDefaults, 'Registro constructor retains defaults, scalar types and property order');
checkModelFields(json_decode(json_encode($ponente, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR) === $ponenteDefaults, 'Ponente JSON retains default fields and values');
checkModelFields(json_decode(json_encode($registro, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR) === $registroDefaults, 'Registro JSON retains default fields and values');

$ponenteArgs = ['id' => 7, 'nombre' => 'Ada', 'apellido' => 'Lovelace', 'ciudad' => 'London', 'pais' => 'UK', 'imagen' => 'portrait.png', 'tags' => 'math', 'redes' => '{}'];
$registroArgs = ['id' => 9, 'paquete_id' => 1, 'pago_id' => 'synthetic', 'token' => 'synthetic-token', 'usuario_id' => 2];
checkModelFields(get_object_vars(new Ponente($ponenteArgs)) === $ponenteArgs, 'Ponente constructor does not cast supplied scalars');
checkModelFields(get_object_vars(new Registro($registroArgs)) === $registroArgs, 'Registro constructor does not cast supplied scalars');

$db = new ModelFieldsConnection();
ActiveRecord::setDB($db);
$db->rows = [['id' => '7', 'nombre' => 'Ada', 'apellido' => 'Lovelace', 'ciudad' => 'London', 'pais' => 'UK', 'imagen' => 'portrait.png', 'tags' => 'math', 'redes' => '{}', 'imagen_actual' => 'ignored', 'unknown' => 'ignored']];
$ponentes = Ponente::consultarSQL('SELECT * FROM ponentes');
$ponente = $ponentes[0];
checkModelFields(count($ponentes) === 1 && $ponente instanceof Ponente && $db->lastResult->freed, 'Ponente hydration uses legacy ActiveRecord and frees the result');
checkModelFields(get_object_vars($ponente) === array_replace($ponenteDefaults, ['id' => '7', 'nombre' => 'Ada', 'apellido' => 'Lovelace', 'ciudad' => 'London', 'pais' => 'UK', 'imagen' => 'portrait.png', 'tags' => 'math', 'redes' => '{}']), 'Ponente hydration retains string scalars and ignores non-persisted columns');

$db->rows = [['id' => '9', 'paquete_id' => '1', 'pago_id' => 'synthetic', 'token' => 'synthetic-token', 'usuario_id' => '2', 'usuario' => 'ignored', 'paquete' => 'ignored', 'unknown' => 'ignored']];
$registros = Registro::consultarSQL('SELECT * FROM registros');
$registro = $registros[0];
checkModelFields(count($registros) === 1 && $registro instanceof Registro && $db->lastResult->freed, 'Registro hydration uses legacy ActiveRecord and frees the result');
checkModelFields(get_object_vars($registro) === ['id' => '9', 'paquete_id' => '1', 'pago_id' => 'synthetic', 'token' => 'synthetic-token', 'usuario_id' => '2'], 'Registro hydration retains string scalars and ignores non-persisted columns');

$ponente->sincronizar(['nombre' => 'Grace', 'ciudad' => null, 'imagen_actual' => 'ignored', 'unknown' => 'ignored']);
$registro->sincronizar(['paquete_id' => 2, 'token' => null, 'usuario' => 'ignored', 'paquete' => 'ignored', 'unknown' => 'ignored']);
checkModelFields($ponente->nombre === 'Grace' && $ponente->ciudad === 'London' && !property_exists($ponente, 'imagen_actual') && !property_exists($ponente, 'unknown'), 'Ponente sync accepts persisted fields, ignores null and unknown/transient fields');
checkModelFields($registro->paquete_id === 2 && $registro->token === 'synthetic-token' && !property_exists($registro, 'usuario') && !property_exists($registro, 'paquete') && !property_exists($registro, 'unknown'), 'Registro sync accepts persisted fields, ignores null and unknown/transient fields');
checkModelFields($ponente->atributos() === array_diff_key(get_object_vars($ponente), ['id' => null]), 'Ponente attributes omit only id');
checkModelFields($registro->atributos() === array_diff_key(get_object_vars($registro), ['id' => null]), 'Registro attributes omit only id');
checkModelFields(json_decode(json_encode($ponente, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR) === get_object_vars($ponente), 'Ponente JSON matches public properties after hydration and sync');
checkModelFields(json_decode(json_encode($registro, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR) === get_object_vars($registro), 'Registro JSON matches public properties after hydration and sync');

$ponente->imagen_actual = 'previous.png';
$registro->usuario = (object) ['id' => '2'];
$registro->paquete = (object) ['id' => '1'];
$evento = new Evento();
foreach (['categoria', 'dia', 'hora', 'ponente'] as $field) {
    checkModelFields(!property_exists($evento, $field), "Evento {$field} is absent before dynamic assignment");
    $evento->$field = (object) ['id' => '1'];
}
$ponente->sincronizar(['imagen_actual' => 'next.png']);
$registro->sincronizar(['usuario' => (object) ['id' => '3'], 'paquete' => (object) ['id' => '2']]);
$evento->sincronizar(['categoria' => (object) ['id' => '4'], 'dia' => (object) ['id' => '5'], 'hora' => (object) ['id' => '6'], 'ponente' => (object) ['id' => '7']]);
checkModelFields($ponente->imagen_actual === 'next.png' && property_exists($ponente, 'imagen_actual'), 'Ponente transient field becomes syncable only after dynamic assignment');
checkModelFields($registro->usuario->id === '3' && $registro->paquete->id === '2', 'Registro manual relations become syncable only after dynamic assignment');
checkModelFields($evento->categoria->id === '4' && $evento->dia->id === '5' && $evento->hora->id === '6' && $evento->ponente->id === '7', 'Evento manual relations retain legacy dynamic sync behavior');
checkModelFields(!array_key_exists('imagen_actual', $ponente->atributos()) && !array_key_exists('usuario', $registro->atributos()) && !array_key_exists('paquete', $registro->atributos()), 'Transient fields remain excluded from database attributes');
checkModelFields(array_key_exists('imagen_actual', get_object_vars($ponente)) && array_key_exists('usuario', get_object_vars($registro)) && array_key_exists('categoria', get_object_vars($evento)), 'Transient fields remain visible in object property enumeration');
checkModelFields(array_key_exists('imagen_actual', json_decode(json_encode($ponente, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)) && array_key_exists('usuario', json_decode(json_encode($registro, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)) && array_key_exists('categoria', json_decode(json_encode($evento, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)), 'Transient fields remain visible in JSON after assignment');

$registro->validar(); // The inherited method clears shared legacy alerts.
checkModelFields((new Ponente($ponenteArgs))->validar() === [], 'Complete Ponente passes legacy validation');
$registro->validar();
checkModelFields(count((new Ponente())->validar()['error'] ?? []) === 6, 'Empty Ponente keeps six required-field alerts');
checkModelFields($registro->validar() === [], 'Registro inherits alert reset without new validation rules');

echo "Model fields contract: {$checks} checks passed (synthetic data; no database writes).\n";

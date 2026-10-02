<?php

declare(strict_types=1);

// Compare PDO runtime against the immutable pre-migration mysqli baseline.
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Core\ActiveRecord;
use App\Core\Database;
use App\Models\Categoria;
use App\Models\Evento;
use App\Models\EventoHorario;
use App\Models\Ponente;
use App\Models\Registro;
use App\Models\Usuario;
use Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$mode = $argv[1] ?? null;
if ($mode !== '--verify') {
    fwrite(STDERR, "Usage: php tests/persistence/pdo_baseline.php --verify [base URL]\n");
    exit(2);
}

$baseUrl = rtrim($argv[2] ?? 'http://localhost:3000', '/');
if (!preg_match('~^https?://(?:localhost|127\.0\.0\.1)(?::[0-9]+)?$~', $baseUrl)) {
    fwrite(STDERR, "Only a local HTTP server is allowed.\n");
    exit(2);
}

function digest(array $rows): string
{
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}

function rows(PDO $db, string $sql): array
{
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function ids(array $models): array
{
    return array_map(static fn(object $model): mixed => $model->id, $models);
}

function httpSnapshot(string $baseUrl, string $path): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 10,
            'header' => "User-Agent: PORT-3-legacy-baseline\r\n",
        ],
    ]);

    $body = @file_get_contents($baseUrl . $path, false, $context);
    if ($body === false || empty($http_response_header)) {
        throw new RuntimeException("HTTP request failed: {$path}");
    }

    preg_match('~^HTTP/\S+\s+(\d+)~', $http_response_header[0], $match);
    $status = (int) ($match[1] ?? 0);
    $contentType = '';
    $location = null;

    foreach ($http_response_header as $header) {
        if (stripos($header, 'Content-Type:') === 0) {
            $contentType = strtolower(trim(explode(';', substr($header, 13), 2)[0]));
        }
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }

    $layout = null;
    if ($contentType === 'text/html') {
        $layout = str_contains($body, '<body class="dashboard">')
            ? 'admin'
            : (str_contains($body, '<body>') ? 'public' : null);
        // The legacy AOS helper chooses a random animation on each request.
        $body = preg_replace('/data-aos="[^"]*"/', 'data-aos="<random>"', $body);
    }

    $jsonShape = null;
    if (str_starts_with($path, '/api/')) {
        // Legacy API responses have a text/html Content-Type despite JSON bodies.
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $first = array_is_list($decoded) ? ($decoded[0] ?? []) : $decoded;
        $jsonShape = [
            'root' => array_is_list($decoded) ? 'list' : 'object',
            'count' => count($decoded),
            'first_field_types' => array_map('get_debug_type', $first),
        ];
    }

    return [
        'status' => $status,
        'content_type' => $contentType,
        'location' => $location,
        'layout' => $layout,
        'json_shape' => $jsonShape,
        'sha256' => hash('sha256', $body),
    ];
}

try {
    $envPath = is_file($root . '/.env') ? $root : $root . '/includes';
    Dotenv::createImmutable($envPath)->safeLoad();
    $db = Database::connect();
    ActiveRecord::setDB($db);

    $fingerprintQueries = [
        'usuarios_devwebcamp' => 'SELECT id, confirmado, admin FROM usuarios_devwebcamp ORDER BY id',
        'registros' => 'SELECT id, paquete_id, usuario_id FROM registros ORDER BY id',
        'eventos_registros' => 'SELECT id, evento_id, registro_id FROM eventos_registros ORDER BY id',
    ];
    $tables = ['categorias', 'dias', 'eventos', 'eventos_registros', 'horas', 'paquetes', 'ponentes', 'registros', 'usuarios_devwebcamp'];
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = (int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }

    $fingerprints = [];
    foreach ($fingerprintQueries as $table => $sql) {
        $fingerprints[$table] = digest(rows($db, $sql));
    }

    $firstUser = rows($db, 'SELECT id FROM usuarios_devwebcamp ORDER BY id LIMIT 1')[0] ?? null;
    $firstRegistration = rows($db, 'SELECT id FROM registros ORDER BY id LIMIT 1')[0] ?? null;
    $firstEvent = rows($db, 'SELECT id, dia_id, categoria_id FROM eventos ORDER BY id LIMIT 1')[0] ?? null;
    $firstSpeaker = rows($db, 'SELECT id FROM ponentes ORDER BY id LIMIT 1')[0] ?? null;

    $categories = Categoria::all('ASC');
    $events = Evento::ordenar('hora_id', 'ASC');
    $page = Evento::paginar(3, 0);
    $user = $firstUser ? Usuario::find($firstUser['id']) : null;
    $registration = $firstRegistration ? Registro::find($firstRegistration['id']) : null;
    $foundUser = $user ? Usuario::where('id', $user->id) : null;
    $schedule = $firstEvent
        ? EventoHorario::whereArray(['dia_id' => $firstEvent['dia_id'], 'categoria_id' => $firstEvent['categoria_id']])
        : [];

    try {
        Categoria::get(1);
        $getOutcome = 'returned';
    } catch (Throwable $error) {
        $getOutcome = get_class($error);
    }

    $modelSnapshot = [
        'categoria_all_asc_ids_sha256' => digest(ids($categories)),
        'evento_ordenar_hora_asc_ids_sha256' => digest(ids($events)),
        'evento_paginar_first_three_ids_sha256' => digest(ids($page)),
        'evento_total' => Evento::total(),
        'evento_total_by_categoria' => Evento::total('categoria_id', 1),
        'user_find_type' => $user ? get_debug_type($user->id) : null,
        'user_confirmed_type' => $user ? get_debug_type($user->confirmado) : null,
        'user_admin_type' => $user ? get_debug_type($user->admin) : null,
        'user_where_matches_find' => $user !== null && $foundUser?->id === $user->id,
        'registration_find_id_type' => $registration ? get_debug_type($registration->id) : null,
        'registration_package_id_type' => $registration ? get_debug_type($registration->paquete_id) : null,
        'event_schedule_count' => count($schedule),
        'event_schedule_first_id_type' => $schedule ? get_debug_type($schedule[0]->id) : null,
        'unused_get_outcome' => $getOutcome,
    ];

    $paths = [
        '/', '/devwebcamp', '/paquetes', '/workshops-conferencias', '/login',
        '/api/ponentes', '/api/eventos-horario?dia_id=1&categoria_id=1',
        '/404', '/port3-unregistered-route', '/admin/dashboard',
        '/admin/ponentes?page=1', '/admin/eventos?page=1',
        '/build/css/app.css', '/build/js/main.min.js',
    ];
    if ($firstSpeaker) {
        $paths[] = '/api/ponente?id=' . rawurlencode($firstSpeaker['id']);
    }

    $http = [];
    foreach ($paths as $path) {
        $http[$path] = httpSnapshot($baseUrl, $path);
    }

    $snapshot = [
        'database' => ['counts' => $counts, 'fixture_sha256' => $fingerprints],
        'models' => $modelSnapshot,
        'http' => $http,
    ];

    $file = __DIR__ . '/legacy_baseline.json';
    $expected = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    // Driver-specific exception is the only approved snapshot difference.
    if (($expected['models']['unused_get_outcome'] ?? null) !== 'mysqli_sql_exception'
        || $getOutcome !== PDOException::class) {
        throw new RuntimeException('Unexpected legacy get() outcome.');
    }
    $expected['models']['unused_get_outcome'] = PDOException::class;
    if ($snapshot !== $expected) {
        throw new RuntimeException('PDO baseline differs from the immutable mysqli snapshot.');
    }
    echo "PDO DB and HTTP baseline matches (" . count($paths) . " GET requests; no writes).\n";

    echo 'Fixture counts: users=' . $counts['usuarios_devwebcamp']
        . ', registrations=' . $counts['registros']
        . ', event links=' . $counts['eventos_registros'] . ".\n";
} catch (Throwable $error) {
    // Do not print SQL, environment values, database rows, or HTTP bodies.
    fwrite(STDERR, 'PDO baseline failed (' . get_class($error) . ").\n");
    exit(1);
}

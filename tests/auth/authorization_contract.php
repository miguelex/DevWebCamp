<?php

declare(strict_types=1);

// All writes in this contract are confined to an explicit disposable database transaction.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

use App\Controllers\DashboardController;
use App\Controllers\EventosController;
use App\Controllers\PonentesController;
use App\Controllers\RegalosController;
use App\Controllers\RegistradosController;
use App\Controllers\RegistroController;
use App\Core\AccessGuard;
use App\Core\ActiveRecord;
use App\Core\Database;
use App\Core\Router;
use Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
if (getenv('DB_NAME') !== 'devwebcamp_port4_guard_test') {
    fwrite(STDERR, "Set DB_NAME=devwebcamp_port4_guard_test explicitly.\n");
    exit(2);
}
require $root . '/vendor/autoload.php';

final class AuthorizationRouter extends Router
{
    public array $rendered = [];

    public function render($view, $datos = [])
    {
        $this->rendered[] = $view;
    }
}

function assertAuthorization(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "PASS: {$label}\n";
}

function fingerprint(PDO $db, array $tables): array
{
    $result = [];
    foreach ($tables as $table) {
        $rows = $db->query("SELECT * FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $result[$table] = [count($rows), hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    return $result;
}

function invokeAuthorization(string $controller, string $action, string $verb, array $get, array $post): AuthorizationRouter
{
    $_SERVER['REQUEST_METHOD'] = $verb;
    $_GET = $get;
    $_POST = $post;
    $_FILES = [];
    $router = new AuthorizationRouter();
    ob_start();
    try {
        $controller::$action($router);
        assertAuthorization(ob_get_contents() === '', "{$verb} {$controller}::{$action} emits no denied body");
    } finally {
        ob_end_clean();
    }
    return $router;
}

$envPath = is_file($root . '/.env') ? $root : $root . '/includes';
Dotenv::createImmutable($envPath)->safeLoad();
$_ENV['DB_NAME'] = 'devwebcamp_port4_guard_test';
ob_start();
session_start();
$db = null;
$before = null;
$failure = null;
$server = null;
$sessionDir = null;
$tables = ['usuarios_devwebcamp', 'paquetes', 'ponentes', 'categorias', 'dias', 'horas', 'registros', 'eventos'];
try {
    $db = Database::connect();
    assertAuthorization($db->query('SELECT DATABASE()')->fetchColumn() === 'devwebcamp_port4_guard_test',
        'disposable database selected');
    $actualTables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    sort($actualTables);
    $expectedTables = $tables;
    sort($expectedTables);
    assertAuthorization($actualTables === $expectedTables, 'only eight approved disposable tables exist');
    $before = fingerprint($db, $tables);
    assertAuthorization(array_sum(array_column($before, 0)) === 0, 'all disposable tables initially empty');
    $db->beginTransaction();
    ActiveRecord::setDB($db);

    foreach ([1 => 'Presencial', 3 => 'Gratis'] as $id => $name) {
        $db->prepare('INSERT INTO paquetes (id, nombre) VALUES (?, ?)')->execute([$id, $name]);
    }
    foreach ([11 => 0, 12 => 1, 13 => 1, 14 => 0] as $id => $admin) {
        $db->prepare('INSERT INTO usuarios_devwebcamp (id, nombre, apellido, email, password, confirmado, token, admin) VALUES (?, ?, ?, ?, ?, 1, ?, ?)')
            ->execute([$id, 'Test', 'User', "guard-{$id}@example.invalid", 'unused', '', $admin]);
    }
    $db->exec("INSERT INTO registros (id, paquete_id, pago_id, token, usuario_id) VALUES
        (21, 1, '', 'normal11', 11), (22, 3, '', 'admin012', 12), (23, 1, '', 'admin013', 13)");
    $db->exec("INSERT INTO ponentes (id, nombre, apellido, ciudad, pais, imagen, tags, redes)
        VALUES (31, 'Test', 'Speaker', 'Madrid', 'ES', 'fixture', 'PHP', '{}')");
    $db->exec("INSERT INTO categorias (id, nombre) VALUES (1, 'Conference')");
    $db->exec("INSERT INTO dias (id, nombre) VALUES (1, 'Friday')");
    $db->exec("INSERT INTO horas (id, hora) VALUES (1, '10:00')");
    $db->exec("INSERT INTO eventos (id, nombre, descripcion, disponibles, categoria_id, dia_id, hora_id, ponente_id)
        VALUES (41, 'Test event', 'Contract', 10, 1, 1, 1, 31)");
    $seed = fingerprint($db, $tables);

    $adminRoutes = [
        [DashboardController::class, 'index', 'GET', [], []],
        [PonentesController::class, 'index', 'GET', ['page' => '1'], []],
        [PonentesController::class, 'crear', 'GET', [], []],
        [PonentesController::class, 'crear', 'POST', [], ['id' => '31', 'redes' => []]],
        [PonentesController::class, 'editar', 'GET', ['id' => '31'], []],
        [PonentesController::class, 'editar', 'POST', ['id' => '31'], ['id' => '31', 'redes' => []]],
        [PonentesController::class, 'eliminar', 'POST', [], ['id' => '31']],
        [EventosController::class, 'index', 'GET', ['page' => '1'], []],
        [EventosController::class, 'crear', 'GET', [], []],
        [EventosController::class, 'crear', 'POST', [], ['id' => '41']],
        [EventosController::class, 'editar', 'GET', ['id' => '41'], []],
        [EventosController::class, 'editar', 'POST', ['id' => '41'], ['id' => '41']],
        [EventosController::class, 'eliminar', 'POST', [], ['id' => '41']],
        [RegistradosController::class, 'index', 'GET', [], []],
        [RegalosController::class, 'index', 'GET', [], []],
    ];
    foreach (['anonymous' => [], 'normal' => ['id' => 11, 'nombre' => 'Test', 'admin' => 0]] as $role => $identity) {
        $_SESSION = $identity;
        foreach ($adminRoutes as [$controller, $action, $verb, $get, $post]) {
            $router = invokeAuthorization($controller, $action, $verb, $get, $post);
            assertAuthorization($router->rendered === [], "{$role} denied {$verb} {$controller}::{$action} before render");
            assertAuthorization(fingerprint($db, $tables) === $seed, "{$role} denied {$verb} {$controller}::{$action} before DML");
        }
    }
    $_SESSION = ['admin' => 1];
    assertAuthorization(!AccessGuard::administrator(), 'admin flag without identity is denied');

    // Admin admission is verified for all fifteen actions without running payments, mail, or uploads.
    $_SESSION = ['id' => 13, 'nombre' => 'Test', 'admin' => 1];
    foreach ($adminRoutes as [$controller, $action, $verb]) {
        assertAuthorization(AccessGuard::administrator(), "administrator admitted to {$verb} {$controller}::{$action}");
    }
    foreach ($adminRoutes as [$controller, $action, $verb, $get, $post]) {
        if ($verb !== 'GET') {
            continue;
        }
        $router = invokeAuthorization($controller, $action, $verb, $get, $post);
        assertAuthorization(count($router->rendered) === 1, "administrator renders {$controller}::{$action}");
    }

    foreach ([['crear', 'GET'], ['gratis', 'POST'], ['pagar', 'POST'], ['conferencias', 'GET']] as [$action, $verb]) {
        $_SESSION = [];
        $router = invokeAuthorization(RegistroController::class, $action, $verb, [], []);
        assertAuthorization($router->rendered === [] && fingerprint($db, $tables) === $seed,
            "anonymous registration {$verb} {$action} denied without render or DML");
    }
    foreach ([11 => true, 12 => false, 13 => true, 14 => false] as $id => $hasPackage) {
        $_SESSION = ['id' => $id, 'nombre' => 'Test', 'admin' => ($id === 12 || $id === 13) ? 1 : 0];
        $router = invokeAuthorization(RegistroController::class, 'conferencias', 'GET', [], []);
        assertAuthorization(($router->rendered === ['registro/conferencias']) === $hasPackage,
            "conference entitlement is package-based for user {$id}");
    }
    assertAuthorization(fingerprint($db, $tables) === $seed, 'all guarded reads leave fixture rows unchanged');

    // HTTP proves status, Location, empty body, and that later redirects cannot win.
    session_write_close();
    $sessionDir = sys_get_temp_dir() . '/port4-guards-' . bin2hex(random_bytes(8));
    if (!mkdir($sessionDir, 0700)) {
        throw new RuntimeException('Cannot create isolated session directory.');
    }
    session_save_path($sessionDir);
    $cookies = ['anonymous' => null];
    session_id('p4' . bin2hex(random_bytes(12)));
    session_start();
    $_SESSION = ['id' => 11, 'nombre' => 'Test', 'admin' => 0];
    $cookies['normal'] = session_id();
    session_write_close();

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Cannot allocate HTTP port.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1);
    $base = "http://127.0.0.1:{$port}";
    $command = [PHP_BINARY, '-d', 'session.save_path=' . $sessionDir,
        '-S', '127.0.0.1:' . $port, '-t', $root . '/public'];
    $server = proc_open($command, [0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
    if (!is_resource($server)) {
        throw new RuntimeException('Cannot launch isolated HTTP server.');
    }
    $http = static function (string $path, string $verb, ?string $cookie) use ($base): array {
        $headers = "User-Agent: PORT-4-guard-contract\r\n";
        if ($cookie !== null) {
            $headers .= 'Cookie: ' . session_name() . '=' . $cookie . "\r\n";
        }
        if ($verb === 'POST') {
            $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
        }
        $context = stream_context_create(['http' => [
            'method' => $verb, 'header' => $headers, 'content' => $verb === 'POST' ? 'id=31' : '',
            'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 5,
        ]]);
        $body = @file_get_contents($base . $path, false, $context);
        if ($body === false || empty($http_response_header)) {
            throw new RuntimeException('Local HTTP server did not answer.');
        }
        preg_match('~^HTTP/\S+\s+(\d+)~', $http_response_header[0], $match);
        $location = null;
        foreach ($http_response_header as $header) {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }
        return [(int) ($match[1] ?? 0), $location, $body];
    };
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        try {
            $ready = $http('/', 'GET', null)[0] === 200;
            if ($ready) { break; }
        } catch (RuntimeException) {
            usleep(100_000);
        }
    }
    assertAuthorization($ready, 'isolated HTTP server ready');
    $paths = [
        '/admin/dashboard', '/admin/ponentes?page=1', '/admin/ponentes/crear',
        '/admin/ponentes/crear', '/admin/ponentes/editar?id=31', '/admin/ponentes/editar?id=31',
        '/admin/ponentes/eliminar', '/admin/eventos?page=1', '/admin/eventos/crear',
        '/admin/eventos/crear', '/admin/eventos/editar?id=41', '/admin/eventos/editar?id=41',
        '/admin/eventos/eliminar', '/admin/registrados', '/admin/regalos',
    ];
    foreach ($cookies as $role => $cookie) {
        foreach ($adminRoutes as $index => [, , $verb]) {
            [$status, $location, $body] = $http($paths[$index], $verb, $cookie);
            assertAuthorization($status === 302 && $location === '/login' && $body === '',
                "HTTP {$role} {$verb} {$paths[$index]} is 302 /login with no body");
        }
        foreach ([['/finalizar-registro', 'GET'], ['/finalizar-registro/gratis', 'POST'],
            ['/finalizar-registro/pagar', 'POST'], ['/finalizar-registro/conferencias', 'GET']] as [$path, $verb]) {
            if ($role !== 'anonymous') { continue; }
            [$status, $location, $body] = $http($path, $verb, $cookie);
            assertAuthorization($status === 302 && $location === '/login' && $body === '',
                "HTTP anonymous {$verb} {$path} is 302 /login with no body");
        }
    }
    assertAuthorization(fingerprint($db, $tables) === $seed, 'HTTP denials do not change rows');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($sessionDir !== null && is_dir($sessionDir)) {
        foreach (glob($sessionDir . '/sess_*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($sessionDir);
    }
    if ($db !== null && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($db !== null && $before !== null) {
        try {
            assertAuthorization(fingerprint($db, $tables) === $before, 'rollback restores counts and fingerprints');
        } catch (Throwable $error) {
            $failure ??= $error;
        }
    }
}
if ($failure !== null) {
    fwrite(STDERR, 'Authorization contract failed (' . $failure->getMessage() . ").\n");
    exit(1);
}
echo "Authorization contract passed.\n";
ob_end_flush();

<?php

declare(strict_types=1);

// Read-only HTTP characterization using isolated session files and safe POST data.
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Core\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
ob_start();

function assertHttp(bool $condition, string $case): void
{
    if (!$condition) {
        throw new RuntimeException("Failed: {$case}");
    }
    echo "PASS: {$case}\n";
}

function httpRequest(string $base, string $path, string $method = 'GET', ?string $cookie = null): array
{
    $headers = "User-Agent: PORT-4-legacy-contract\r\n";
    if ($cookie !== null) {
        $headers .= 'Cookie: ' . session_name() . '=' . $cookie . "\r\n";
    }
    if ($method === 'POST') {
        // id=0 cannot select a real row; this must never exercise an admin write.
        $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headers,
        'content' => $method === 'POST' ? 'id=0' : '',
        'follow_location' => 0,
        'ignore_errors' => true,
        'timeout' => 5,
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
    return [(int) ($match[1] ?? 0), $location, str_contains($body, '<body class="dashboard">')];
}

function makeSession(array $user): string
{
    $id = 'p4' . bin2hex(random_bytes(12));
    session_id($id);
    if (!session_start()) {
        throw new RuntimeException('Cannot create synthetic HTTP session.');
    }
    $_SESSION = [
        'id' => $user['id'],
        'nombre' => $user['nombre'],
        'apellido' => $user['apellido'],
        'email' => $user['email'],
        'admin' => $user['admin'],
    ];
    session_write_close();
    return $id;
}

$envPath = is_file($root . '/.env') ? $root : $root . '/includes';
Dotenv::createImmutable($envPath)->safeLoad();
if (($_ENV['DB_NAME'] ?? '') !== 'devwebcamp') {
    fwrite(STDERR, "Refusing to read an unexpected fixture database.\n");
    exit(2);
}

$sessionDir = sys_get_temp_dir() . '/port4-http-' . bin2hex(random_bytes(8));
$server = null;
$failure = null;
try {
    $db = Database::connect();
    assertHttp($db->query('SELECT DATABASE()')->fetchColumn() === 'devwebcamp', 'fixture database selected');
    $fingerprint = [];
    foreach (['usuarios_devwebcamp', 'registros', 'eventos_registros', 'ponentes', 'eventos'] as $table) {
        $rows = $db->query("SELECT * FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $fingerprint[$table] = [count($rows), hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    assertHttp($fingerprint['usuarios_devwebcamp'][0] === 2
        && $fingerprint['registros'][0] === 1
        && $fingerprint['eventos_registros'][0] === 0, 'fixture counts before HTTP: 2/1/0');

    $users = $db->query('SELECT id, nombre, apellido, email, admin, confirmado FROM usuarios_devwebcamp ORDER BY id')
        ->fetchAll(PDO::FETCH_ASSOC);
    $roles = [];
    foreach ($users as $user) {
        if (!str_ends_with($user['email'], '.invalid') || (string) $user['confirmado'] !== '1') {
            throw new RuntimeException('Expected only confirmed synthetic fixture users.');
        }
        $roles[(string) $user['admin'] === '1' ? 'admin' : 'normal'] = $user;
    }
    assertHttp(isset($roles['admin'], $roles['normal']), 'confirmed synthetic admin and normal fixtures available');

    if (!mkdir($sessionDir, 0700)) {
        throw new RuntimeException('Cannot create isolated session directory.');
    }
    session_save_path($sessionDir);
    $normalCookie = makeSession($roles['normal']);
    $adminCookie = makeSession($roles['admin']);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Cannot allocate local server port.');
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
        throw new RuntimeException('Cannot launch isolated local HTTP server.');
    }
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        try {
            $ready = httpRequest($base, '/')[0] === 200;
            if ($ready) { break; }
        } catch (RuntimeException) {
            usleep(100_000);
        }
    }
    assertHttp($ready, 'isolated local HTTP server ready');

    $cases = [
        ['anonymous dashboard defect', '/admin/dashboard', 'GET', null, 200, null, true],
        ['anonymous registered list defect', '/admin/registrados', 'GET', null, 200, null, true],
        ['anonymous gifts defect', '/admin/regalos', 'GET', null, 200, null, true],
        ['anonymous speakers list still renders admin body', '/admin/ponentes?page=1', 'GET', null, 302, '/login', true],
        ['anonymous events list still renders admin body', '/admin/eventos?page=1', 'GET', null, 302, '/login', true],
        ['normal dashboard defect', '/admin/dashboard', 'GET', $normalCookie, 200, null, true],
        ['normal speakers list still renders admin body', '/admin/ponentes?page=1', 'GET', $normalCookie, 302, '/login', true],
        ['normal events list still renders admin body', '/admin/eventos?page=1', 'GET', $normalCookie, 302, '/login', true],
        ['admin dashboard', '/admin/dashboard', 'GET', $adminCookie, 200, null, true],
        ['admin speakers list', '/admin/ponentes?page=1', 'GET', $adminCookie, 200, null, true],
        ['admin events list', '/admin/eventos?page=1', 'GET', $adminCookie, 200, null, true],
        ['anonymous POST redirect override defect', '/admin/ponentes/eliminar', 'POST', null, 302, '/admin/ponentes', false],
        ['normal POST redirect override defect', '/admin/ponentes/eliminar', 'POST', $normalCookie, 302, '/admin/ponentes', false],
        ['admin POST invalid id', '/admin/ponentes/eliminar', 'POST', $adminCookie, 302, '/admin/ponentes', false],
        ['anonymous event POST redirect override defect', '/admin/eventos/eliminar', 'POST', null, 302, '/admin/eventos', false],
        ['admin event POST invalid id', '/admin/eventos/eliminar', 'POST', $adminCookie, 302, '/admin/eventos', false],
        ['anonymous registration redirect', '/finalizar-registro', 'GET', null, 302, '/login', false],
        ['anonymous conference redirect defect', '/finalizar-registro/conferencias', 'GET', null, 302, '/', false],
        ['normal conferences', '/finalizar-registro/conferencias', 'GET', $normalCookie, 200, null, false],
        ['forgot-password form', '/olvide', 'GET', null, 200, null, false],
    ];
    foreach ($cases as [$label, $path, $method, $cookie, $status, $location, $adminLayout]) {
        [$actualStatus, $actualLocation, $actualLayout] = httpRequest($base, $path, $method, $cookie);
        if ($actualStatus !== $status || $actualLocation !== $location || $actualLayout !== $adminLayout) {
            throw new RuntimeException("HTTP case {$label}: expected {$status}/{$location}/"
                . (int) $adminLayout . ", got {$actualStatus}/{$actualLocation}/" . (int) $actualLayout);
        }
        assertHttp(true, $label . " ({$method} {$path}: {$status})");
    }
    [$status, $location] = httpRequest($base, '/finalizar-registro', 'GET', $normalCookie);
    assertHttp($status === 302 && $location !== null && str_starts_with($location, '/boleto?id='),
        'normal registration uses the existing session id and redirects to its ticket');
    [$status, $location] = httpRequest($base, '/reestablecer?token=invalid');
    assertHttp($status === 200 && $location === null, 'invalid reset token renders error without DB write');
    [$status, $location] = httpRequest($base, '/confirmar-cuenta?token=invalid');
    assertHttp($status === 200 && $location === null, 'invalid confirmation token renders error without DB write');
    [$status, $location] = httpRequest($base, '/logout', 'POST', $normalCookie);
    assertHttp($status === 302 && $location === '/', 'POST logout redirects to home');
    session_id($normalCookie);
    assertHttp(session_start() && $_SESSION === [], 'POST logout empties the HTTP session');
    session_write_close();

    foreach ($fingerprint as $table => $expected) {
        $rows = $db->query("SELECT * FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        assertHttp([count($rows), hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))] === $expected,
            "{$table} count and row fingerprint unchanged");
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach (glob($sessionDir . '/sess_*') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($sessionDir)) {
        rmdir($sessionDir);
    }
}

if ($failure !== null) {
    // No DB rows, tokens, HTTP bodies, credentials, or server logs in output.
    $detail = $failure instanceof RuntimeException ? $failure->getMessage() : get_class($failure);
    fwrite(STDERR, 'Legacy HTTP authorization contract failed (' . $detail . ").\n");
    exit(1);
}
echo "Legacy HTTP authorization contract passed (no database writes).\n";
ob_end_flush();

<?php

declare(strict_types=1);

// PORT-4 block 2: verify session lifecycle without persistent fixture writes.
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Controllers\AuthController;
use App\Core\ActiveRecord;
use App\Core\App;
use App\Core\Database;
use App\Core\Router;
use App\Core\Session;
use App\Models\Registro;
use App\Models\Usuario;

$root = dirname(__DIR__, 2);
define('BASE_PATH', $root);
require $root . '/vendor/autoload.php';
ob_start();

function checkSession(bool $condition, string $case): void
{
    if (!$condition) {
        throw new RuntimeException("Failed: {$case}");
    }
    echo "PASS: {$case}\n";
}

function invokeLogin(string $email, string $password): void
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['PATH_INFO'] = '/login';
    $_POST = ['email' => $email, 'password' => $password];
    (new Usuario())->validar();
    ob_start();
    try {
        AuthController::login(new Router());
    } finally {
        ob_end_clean();
    }
}

function requestSession(string $base, string $path, string $method = 'GET', ?string $cookie = null): array
{
    $headers = "User-Agent: PORT-4-session-contract\r\n";
    if ($cookie !== null) {
        $headers .= 'Cookie: ' . session_name() . '=' . $cookie . "\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => $headers, 'follow_location' => 0,
        'ignore_errors' => true, 'timeout' => 5,
    ]]);
    $body = @file_get_contents($base . $path, false, $context);
    if ($body === false || empty($http_response_header)) {
        throw new RuntimeException('Isolated HTTP server did not answer.');
    }
    preg_match('~^HTTP/\S+\s+(\d+)~', $http_response_header[0], $match);
    $location = null;
    $cookies = [];
    foreach ($http_response_header as $header) {
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
        if (stripos($header, 'Set-Cookie:') === 0) {
            $cookies[] = trim(substr($header, 11));
        }
    }
    return [(int) ($match[1] ?? 0), $location, $cookies, $body];
}

function fixtureFingerprint(PDO $db): array
{
    $result = [];
    foreach (['usuarios_devwebcamp', 'registros', 'eventos_registros'] as $table) {
        $rows = $db->query("SELECT * FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $result[$table] = [count($rows), hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    return $result;
}

$sessionDir = sys_get_temp_dir() . '/port4-session-contract-' . bin2hex(random_bytes(8));
$db = null;
$before = null;
$server = null;
$failure = null;
$warnings = [];
try {
    if (!mkdir($sessionDir, 0700)) {
        throw new RuntimeException('Cannot create isolated session directory.');
    }
    session_save_path($sessionDir);
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        if ($level === E_WARNING && (str_contains($message, 'session_') || str_contains($message, 'headers'))) {
            $warnings[] = $message;
            return true;
        }
        return false;
    });

    App::boot();
    checkSession(($_ENV['DB_NAME'] ?? '') === 'devwebcamp', 'expected local fixture database configured');
    checkSession(session_status() === PHP_SESSION_ACTIVE && !headers_sent(),
        'App boot starts session before any HTML or headers');
    $initialId = session_id();
    Session::start();
    checkSession(session_id() === $initialId && $warnings === [], 'second start is harmless and warning-free');

    $db = Database::connect();
    ActiveRecord::setDB($db);
    $before = fixtureFingerprint($db);
    checkSession($before['usuarios_devwebcamp'][0] === 2 && $before['registros'][0] === 1
        && $before['eventos_registros'][0] === 0, 'fixture counts before transaction: 2/1/0');

    $db->beginTransaction();
    $suffix = bin2hex(random_bytes(4));
    $password = 'Port4-' . bin2hex(random_bytes(12));
    $baseId = -random_int(100_000, 1_000_000_000);
    $insert = $db->prepare('INSERT INTO usuarios_devwebcamp
        (id, nombre, apellido, email, password, confirmado, token, admin)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?)');
    $normalEmail = "p4-normal-{$suffix}@example.invalid";
    $adminEmail = "p4-admin-{$suffix}@example.invalid";
    $insert->execute([$baseId, 'Port4', 'normal', $normalEmail,
        password_hash($password, PASSWORD_BCRYPT), '', 0]);
    $insert->execute([$baseId - 1, 'Port4', 'admin', $adminEmail,
        password_hash($password, PASSWORD_BCRYPT), '', 1]);
    $register = $db->prepare('INSERT INTO registros (id, paquete_id, pago_id, token, usuario_id)
        VALUES (?, 1, ?, ?, ?)');
    $register->execute([$baseId, '', substr(bin2hex(random_bytes(8)), 0, 8), $baseId]);

    invokeLogin($normalEmail, $password);
    checkSession(session_id() !== $initialId && session_status() === PHP_SESSION_ACTIVE,
        'valid normal login regenerates active session ID');
    checkSession(array_keys($_SESSION) === ['id', 'nombre', 'apellido', 'email', 'admin']
        && $_SESSION['id'] === (string) $baseId
        && $_SESSION['nombre'] === 'Port4'
        && $_SESSION['apellido'] === 'normal'
        && $_SESSION['email'] === $normalEmail
        && (string) $_SESSION['admin'] === '0', 'normal login keeps exactly five legacy keys and values');
    checkSession(is_auth() && !is_admin() && Registro::where('usuario_id', $_SESSION['id']) !== null,
        'legacy helpers and registration consumer use the same identity');

    Session::logout();
    checkSession($_SESSION === [] && session_status() !== PHP_SESSION_ACTIVE,
        'logout clears data and destroys active session');
    session_id('p4' . bin2hex(random_bytes(12)));
    Session::start();
    $anonymousId = session_id();
    invokeLogin($normalEmail, 'wrong-synthetic-password');
    checkSession($_SESSION === [] && session_id() === $anonymousId && !is_auth() && !is_admin(),
        'invalid login does not establish or regenerate identity');
    Session::logout();

    session_id('p4' . bin2hex(random_bytes(12)));
    Session::start();
    $preAdminId = session_id();
    invokeLogin($adminEmail, $password);
    checkSession(session_id() !== $preAdminId && (string) $_SESSION['admin'] === '1'
        && $_SESSION['id'] === (string) ($baseId - 1) && is_auth() && is_admin(),
        'valid admin login regenerates ID and preserves role');
    Session::logout();
    checkSession($warnings === [], 'CLI login/logout has no session or headers warnings');

    // HTTP verification uses only the two existing synthetic fixtures; no DB writes.
    $fixtureUser = $db->query('SELECT id, nombre, apellido, email, admin FROM usuarios_devwebcamp
        WHERE id > 0 AND admin = 0 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    checkSession($fixtureUser !== false && str_ends_with($fixtureUser['email'], '.invalid'),
        'existing synthetic normal fixture available for HTTP logout');
    $cookieId = 'p4' . bin2hex(random_bytes(12));
    session_id($cookieId);
    Session::start();
    $_SESSION = $fixtureUser;
    session_write_close();
    $sessionFile = $sessionDir . '/sess_' . $cookieId;
    checkSession(is_file($sessionFile), 'fixture HTTP session stored in isolated directory');

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
            $ready = requestSession($base, '/')[0] === 200;
            if ($ready) { break; }
        } catch (RuntimeException) {
            usleep(100_000);
        }
    }
    checkSession($ready, 'isolated local HTTP server ready');
    [$status, $location, $cookies, $body] = requestSession($base, '/');
    checkSession($status === 200 && $location === null
        && str_contains($body, '<body>') && str_contains($body, 'href="/login"'),
        'anonymous page keeps status, redirect and public content');
    checkSession(count($cookies) === 1 && str_starts_with($cookies[0], session_name() . '='),
        'anonymous page emits one session cookie, as in measured legacy baseline');
    checkSession(!str_contains($body, 'headers already sent')
        && !str_contains($body, 'session_start()'), 'public HTML has no session/header warning');

    [$status, $location, $cookies] = requestSession($base, '/logout', 'POST', $cookieId);
    checkSession($status === 302 && $location === '/', 'POST logout preserves redirect to home');
    $expired = false;
    foreach ($cookies as $cookie) {
        if (str_starts_with($cookie, session_name() . '=')) {
            $expired = (bool) preg_match('/(?:^|;)\s*expires=([^;]+)/i', $cookie, $match)
                && strtotime($match[1]) < time();
        }
    }
    checkSession($expired, 'logout expires the session cookie');
    // The built-in server can unlink the session file just after sending the response.
    // Poll for at most 500 ms; a file still present at the deadline is a failure.
    for ($attempt = 0; $attempt < 20; $attempt++) {
        clearstatcache(true, $sessionFile);
        if (!is_file($sessionFile)) {
            break;
        }
        usleep(25_000);
    }
    clearstatcache(true, $sessionFile);
    checkSession(!is_file($sessionFile), 'logout removes server-side session file within 500 ms');
    [$status, , , $body] = requestSession($base, '/', 'GET', $cookieId);
    checkSession($status === 200 && str_contains($body, 'href="/login"'),
        'old cookie no longer grants authenticated navigation');

    checkSession($warnings === [], 'no captured session/header warnings');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    if ($db instanceof PDO) {
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
                echo "PASS: synthetic rows rolled back\n";
            }
            if ($before !== null) {
                checkSession(fixtureFingerprint($db) === $before,
                    'fixture counts and row fingerprints unchanged');
            }
        } catch (Throwable $cleanupError) {
            $failure = $cleanupError;
        }
    }
    restore_error_handler();
    foreach (glob($sessionDir . '/sess_*') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($sessionDir)) {
        rmdir($sessionDir);
    }
}

if ($failure !== null) {
    // No credentials, tokens, database rows, session IDs or HTTP bodies in output.
    fwrite(STDERR, 'Session contract failed (' . get_class($failure) . ").\n");
    exit(1);
}
echo "Session contract passed (all synthetic writes rolled back).\n";
ob_end_flush();

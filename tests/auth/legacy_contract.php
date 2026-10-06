<?php

declare(strict_types=1);

// PORT-4 characterization: synthetic rows exist only inside this transaction.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

use App\Controllers\AuthController;
use App\Core\ActiveRecord;
use App\Core\Database;
use App\Core\Router;
use App\Models\Registro;
use App\Models\Usuario;
use Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
define('BASE_PATH', $root);
require $root . '/vendor/autoload.php';
require $root . '/src/Helpers/funciones.php';
// Keep CLI progress output buffered so it does not prevent session_start/header().
ob_start();

function assertLegacy(bool $condition, string $case): void
{
    if (!$condition) {
        throw new RuntimeException("Failed: {$case}");
    }
    echo "PASS: {$case}\n";
}

function snapshot(PDO $db): array
{
    $result = [];
    foreach (['usuarios_devwebcamp', 'registros', 'eventos_registros'] as $table) {
        $rows = $db->query("SELECT * FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $result[$table] = [count($rows), hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    return $result;
}

function invokeAuth(string $method, string $path, array $post = [], array $get = []): void
{
    $_SERVER['REQUEST_METHOD'] = $method === 'login' || $method === 'logout' || $method === 'reestablecer'
        ? 'POST' : 'GET';
    $_SERVER['PATH_INFO'] = $path;
    $_POST = $post;
    $_GET = $get;
    ob_start();
    try {
        AuthController::$method(new Router());
    } finally {
        ob_end_clean();
    }
}

$envPath = is_file($root . '/.env') ? $root : $root . '/includes';
Dotenv::createImmutable($envPath)->safeLoad();
if (($_ENV['DB_NAME'] ?? '') !== 'devwebcamp') {
    fwrite(STDERR, "Refusing to characterize against an unexpected fixture database.\n");
    exit(2);
}

$sessionDir = sys_get_temp_dir() . '/port4-session-' . bin2hex(random_bytes(8));
$db = null;
$before = null;
$failure = null;
try {
    if (!mkdir($sessionDir, 0700)) {
        throw new RuntimeException('Cannot create isolated session directory.');
    }
    session_save_path($sessionDir);
    $db = Database::connect();
    assertLegacy($db->query('SELECT DATABASE()')->fetchColumn() === 'devwebcamp', 'fixture database selected');
    ActiveRecord::setDB($db);
    $before = snapshot($db);
    assertLegacy($before['usuarios_devwebcamp'][0] === 2
        && $before['registros'][0] === 1
        && $before['eventos_registros'][0] === 0, 'fixture counts before transaction: 2/1/0');

    $db->beginTransaction();
    // The legacy email column is VARCHAR(40); keep synthetic addresses short.
    $secret = bin2hex(random_bytes(4));
    $password = 'Port4-' . bin2hex(random_bytes(12));
    // Explicit negative IDs avoid advancing the positive AUTO_INCREMENT counters.
    $baseId = -random_int(100_000, 1_000_000_000);
    $insert = $db->prepare('INSERT INTO usuarios_devwebcamp
        (id, nombre, apellido, email, password, confirmado, token, admin)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $users = [];
    $offset = 0;
    foreach (['normal' => [1, 0], 'admin' => [1, 1], 'pending' => [0, 0]] as $role => [$confirmed, $admin]) {
        $email = "p4-{$role}-{$secret}@example.invalid";
        $token = $role === 'pending' ? uniqid() : '';
        $id = $baseId - $offset++;
        $insert->execute([$id, 'Port4', $role, $email, password_hash($password, PASSWORD_BCRYPT), $confirmed, $token, $admin]);
        $users[$role] = ['id' => $id, 'email' => $email, 'token' => $token];
    }
    assertLegacy(count($users) === 3, 'synthetic users inserted only inside transaction');

    $package = $db->query('SELECT id FROM paquetes WHERE id = 1')->fetchColumn();
    assertLegacy($package !== false, 'package 1 exists for session-dependent registration');
    $registrationToken = substr(bin2hex(random_bytes(8)), 0, 8);
    $register = $db->prepare('INSERT INTO registros (id, paquete_id, pago_id, token, usuario_id) VALUES (?, 1, ?, ?, ?)');
    $registrationId = $baseId;
    $register->execute([$registrationId, '', $registrationToken, $users['normal']['id']]);

    (new Usuario())->validar();
    session_id('p4' . bin2hex(random_bytes(12)));
    invokeAuth('login', '/login', ['email' => $users['normal']['email'], 'password' => $password]);
    assertLegacy(is_auth() && !is_admin(), 'valid normal login authenticates without admin role');
    assertLegacy(array_keys($_SESSION) === ['id', 'nombre', 'apellido', 'email', 'admin'], 'legacy session keys and order');
    assertLegacy((int) $_SESSION['id'] === $users['normal']['id']
        && $_SESSION['nombre'] === 'Port4'
        && $_SESSION['apellido'] === 'normal'
        && $_SESSION['email'] === $users['normal']['email']
        && (string) $_SESSION['admin'] === '0', 'normal session values mirror persisted user');
    assertLegacy(Registro::where('usuario_id', $_SESSION['id'])?->id === (string) $registrationId,
        'registration lookup uses legacy session id');
    session_write_close();

    invokeAuth('logout', '/logout');
    assertLegacy($_SESSION === [] && !is_auth() && !is_admin(), 'POST logout clears session values');
    session_write_close();

    (new Usuario())->validar();
    session_id('p4' . bin2hex(random_bytes(12)));
    invokeAuth('login', '/login', ['email' => $users['normal']['email'], 'password' => 'incorrect-synthetic']);
    assertLegacy(!is_auth() && !is_admin() && isset(Usuario::getAlertas()['error']), 'invalid password does not authenticate');

    (new Usuario())->validar();
    invokeAuth('login', '/login', ['email' => $users['pending']['email'], 'password' => $password]);
    assertLegacy(!is_auth() && !is_admin(), 'unconfirmed account cannot log in');
    session_write_close();

    (new Usuario())->validar();
    session_id('p4' . bin2hex(random_bytes(12)));
    invokeAuth('login', '/login', ['email' => $users['admin']['email'], 'password' => $password]);
    assertLegacy(is_auth() && is_admin() && (int) $_SESSION['id'] === $users['admin']['id']
        && (string) $_SESSION['admin'] === '1', 'valid administrator login creates privileged session');
    session_write_close();

    $massAssignment = new Usuario();
    $massAssignment->sincronizar(['id' => 99, 'admin' => 1, 'confirmado' => 1,
        'token' => 'synthetic', 'password' => 'synthetic', 'unknown' => 'ignored']);
    assertLegacy($massAssignment->id === 99 && $massAssignment->admin === 1
        && $massAssignment->confirmado === 1 && $massAssignment->token === 'synthetic'
        && $massAssignment->password === 'synthetic' && !property_exists($massAssignment, 'unknown'),
        'DEFECT: sincronizar accepts sensitive submitted fields');

    $generated = new Usuario();
    $generated->crearToken();
    assertLegacy((bool) preg_match('/\A[0-9a-f]{13}\z/D', $generated->token),
        'legacy account token is a 13-character uniqid hex string');

    (new Usuario())->validar();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PATH_INFO'] = '/confirmar-cuenta';
    $_GET = ['token' => $users['pending']['token']];
    ob_start();
    try { AuthController::confirmar(new Router()); } finally { ob_end_clean(); }
    $confirmed = Usuario::find($users['pending']['id']);
    assertLegacy((string) $confirmed->confirmado === '1' && $confirmed->token === '',
        'confirmation consumes token and marks the account confirmed');

    $resetToken = uniqid();
    $setToken = $db->prepare('UPDATE usuarios_devwebcamp SET token = ? WHERE id = ?');
    $setToken->execute([$resetToken, $users['normal']['id']]);
    (new Usuario())->validar();
    invokeAuth('reestablecer', '/reestablecer', ['password' => 'Port4-new-' . $secret], ['token' => $resetToken]);
    $reset = Usuario::find($users['normal']['id']);
    assertLegacy(password_verify('Port4-new-' . $secret, $reset->password) && $reset->token === '',
        'password reset changes bcrypt hash and consumes token');

    assertLegacy($db->inTransaction(), 'all synthetic user and registration writes remain uncommitted');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($db instanceof PDO) {
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
                echo "PASS: transaction rolled back\n";
            }
            if ($before !== null) {
                assertLegacy(snapshot($db) === $before, 'fixture counts and row fingerprints unchanged after rollback');
            }
        } catch (Throwable $cleanupError) {
            $failure = $cleanupError;
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    foreach (glob($sessionDir . '/sess_*') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($sessionDir)) {
        rmdir($sessionDir);
    }
}

if ($failure !== null) {
    // Never print credentials, DB rows, tokens, or driver messages.
    fwrite(STDERR, 'Legacy authentication contract failed (' . get_class($failure) . ").\n");
    exit(1);
}
echo "Legacy authentication contract passed (all writes rolled back).\n";
ob_end_flush();

<?php

declare(strict_types=1);

namespace App\Services {
    // Loaded before Composer's autoloader: production SMTP must never be reachable here.
    final class Email
    {
        public static array $sent = [];

        public function __construct(private string $email, private string $name, private string $token) {}

        public function enviarConfirmacion(): void
        {
            self::$sent[] = ['confirmation', $this->email, $this->token];
        }

        public function enviarInstrucciones(): void
        {
            self::$sent[] = ['recovery', $this->email, $this->token];
        }
    }
}

namespace {
    use App\Controllers\AuthController;
    use App\Core\ActiveRecord;
    use App\Core\Database;
    use App\Core\Router;
    use App\Models\Usuario;
    use App\Services\Email;
    use Dotenv\Dotenv;

    error_reporting(E_ALL & ~E_DEPRECATED);
    $root = dirname(__DIR__, 2);
    define('BASE_PATH', $root);
    require $root . '/vendor/autoload.php';
    require $root . '/src/Helpers/funciones.php';
    ob_start();

    function checkAccount(bool $condition, string $case): void
    {
        if (!$condition) {
            throw new \RuntimeException("Failed: {$case}");
        }
        echo "PASS: {$case}\n";
    }

    function accountRows(\PDO $db): array
    {
        return $db->query('SELECT * FROM usuarios_devwebcamp ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    function accountFingerprint(\PDO $db): array
    {
        $rows = accountRows($db);
        return [count($rows), hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }

    function invokeAccount(string $method, string $verb, array $post = [], array $get = []): string
    {
        (new Usuario())->validar();
        $_SERVER['REQUEST_METHOD'] = $verb;
        $_SERVER['PATH_INFO'] = match ($method) {
            'registro' => '/registro', 'login' => '/login', 'olvide' => '/olvide',
            'confirmar' => '/confirmar-cuenta', default => '/reestablecer',
        };
        $_POST = $post;
        $_GET = $get;
        ob_start();
        try {
            AuthController::$method(new Router());
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    $expectedDB = 'devwebcamp_port4_test';
    if (getenv('DB_NAME') !== $expectedDB) {
        fwrite(STDERR, "Set DB_NAME=devwebcamp_port4_test explicitly before running this test.\n");
        exit(2);
    }
    $envPath = is_file($root . '/.env') ? $root : $root . '/includes';
    Dotenv::createImmutable($envPath)->safeLoad();
    $_ENV['DB_NAME'] = $expectedDB;
    $db = null;
    $before = null;
    $failure = null;
    try {
        checkAccount((new \ReflectionClass(Email::class))->getFileName() === __FILE__,
            'test-only Email double is active');
        $db = Database::connect();
        checkAccount($db->query('SELECT DATABASE()')->fetchColumn() === $expectedDB,
            'explicit disposable database selected');
        $tables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM);
        checkAccount(count($tables) === 1 && $tables[0][0] === 'usuarios_devwebcamp',
            'disposable database contains only the users table');
        ActiveRecord::setDB($db);
        $before = accountFingerprint($db);
        checkAccount($before[0] === 0, 'no rows copied into disposable database');
        $db->beginTransaction();

        $suffix = bin2hex(random_bytes(4));
        $email = "p4-{$suffix}@example.invalid";
        $password = 'Port4-' . bin2hex(random_bytes(8));
        $registration = [
            'nombre' => 'Account', 'apellido' => 'Contract', 'email' => $email,
            'password' => $password, 'password2' => $password,
            'id' => 99, 'admin' => 1, 'confirmado' => 1, 'token' => 'injected-token',
        ];
        invokeAccount('registro', 'POST', $registration);
        $rows = accountRows($db);
        checkAccount(count($rows) === 1 && (int) $rows[0]['id'] !== 99
            && (int) $rows[0]['admin'] === 0 && (int) $rows[0]['confirmado'] === 0
            && $rows[0]['token'] !== 'injected-token' && $rows[0]['token'] !== ''
            && password_verify($password, $rows[0]['password']),
            'registration allowlist ignores id/admin/confirmado/token and hashes password');
        $id = (int) $rows[0]['id'];
        $confirmToken = $rows[0]['token'];
        checkAccount(Email::$sent === [['confirmation', $email, $confirmToken]],
            'registration uses only the in-process Email double');

        // Malformed tokens must return before even touching the database handle.
        $malformed = [[], ['token' => ''], ['token' => ['nested']], ['token' => new \stdClass()]];
        ActiveRecord::setDB(null);
        foreach (['confirmar', 'reestablecer'] as $flow) {
            foreach ($malformed as $query) {
                invokeAccount($flow, 'GET', [], $query);
                invokeAccount($flow, 'POST', ['password' => 'attack-pass', 'id' => $id], $query);
            }
        }
        ActiveRecord::setDB($db);
        checkAccount(accountFingerprint($db)[0] === 1, 'malformed GET and POST tokens never query or write');

        $unchanged = accountFingerprint($db);
        invokeAccount('confirmar', 'GET', [], ['token' => 'invalid-token']);
        $badReset = invokeAccount('reestablecer', 'POST',
            ['password' => 'attack-pass', 'id' => $id, 'admin' => 1, 'confirmado' => 1,
                'token' => $confirmToken], ['token' => 'invalid-token']);
        checkAccount(!str_contains($badReset, 'name="password"')
            && accountFingerprint($db) === $unchanged,
            'unknown confirmation and reset tokens cause no writes or reset form');

        invokeAccount('confirmar', 'GET', [], ['token' => $confirmToken]);
        $confirmed = Usuario::find($id);
        checkAccount((int) $confirmed->confirmado === 1 && $confirmed->token === '',
            'valid confirmation consumes token');
        $unchanged = accountFingerprint($db);
        invokeAccount('confirmar', 'GET', [], ['token' => $confirmToken]);
        checkAccount(accountFingerprint($db) === $unchanged,
            'consumed confirmation token cannot write again');

        invokeAccount('olvide', 'POST', [
            'email' => $email, 'id' => 99, 'admin' => 1,
            'confirmado' => 0, 'token' => 'injected-token', 'password' => 'injected-password',
        ]);
        $recovered = Usuario::find($id);
        $resetToken = $recovered->token;
        checkAccount($resetToken !== '' && $resetToken !== 'injected-token'
            && (int) $recovered->admin === 0 && (int) $recovered->confirmado === 1
            && password_verify($password, $recovered->password)
            && Email::$sent[1] === ['recovery', $email, $resetToken],
            'recovery ignores injected sensitive fields and uses Email double');

        $newPassword = 'Changed-' . bin2hex(random_bytes(8));
        invokeAccount('reestablecer', 'POST', [
            'password' => $newPassword, 'id' => 99, 'admin' => 1,
            'confirmado' => 0, 'token' => 'injected-token', 'email' => 'attack@example.invalid',
        ], ['token' => $resetToken]);
        $reset = Usuario::find($id);
        checkAccount(password_verify($newPassword, $reset->password)
            && $reset->token === '' && $reset->email === $email
            && (int) $reset->admin === 0 && (int) $reset->confirmado === 1,
            'valid reset consumes token and ignores injected id/admin/confirmado/token/email');
        $unchanged = accountFingerprint($db);
        invokeAccount('reestablecer', 'POST', ['password' => 'another-pass'], ['token' => $resetToken]);
        checkAccount(accountFingerprint($db) === $unchanged,
            'consumed reset token cannot change the account');
    } catch (\Throwable $error) {
        $failure = $error;
    } finally {
        if ($db instanceof \PDO) {
            try {
                if ($db->inTransaction()) {
                    $db->rollBack();
                    echo "PASS: disposable transaction rolled back\n";
                }
                if ($before !== null) {
                    checkAccount(accountFingerprint($db) === $before,
                        'disposable counts and row fingerprints restored');
                }
            } catch (\Throwable $cleanupError) {
                $failure = $cleanupError;
            }
        }
    }
    $output = ob_get_clean();
    if ($failure !== null) {
        fwrite(STDERR, $output . 'FAIL: ' . $failure->getMessage() . "\n");
        exit(1);
    }
    echo $output;
}

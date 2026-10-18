<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\AuthController;
use App\Core\ActiveRecord;
use App\Core\Database;
use App\Core\Router;
use App\Models\Usuario;
use App\Services\Email;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\IntegrationConfig;
use Tests\Support\IntegrationDatabase;
use Tests\Support\TemporaryDatabaseEnvironment;

require_once dirname(__DIR__) . '/Support/IntegrationConfig.php';
require_once dirname(__DIR__) . '/Support/IntegrationDatabase.php';
require_once dirname(__DIR__) . '/Support/TemporaryDatabaseEnvironment.php';

final class RecordingRouter extends Router
{
    public array $renders = [];

    public function render($view, $datos = []): void
    {
        $this->renders[] = ['view' => $view, 'data' => $datos];
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AccountControllerTest extends TestCase
{
    private ?PDO $db = null;
    private ?PDO $verifier = null;
    private array $before = [];
    private array $originalEnvironment = [];
    private array $originalGlobals = [];

    private function environmentSnapshot(): array
    {
        $snapshot = [];
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
            $snapshot[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null];
        }
        return $snapshot;
    }

    private function rows(): array
    {
        return $this->db->query('SELECT * FROM usuarios_devwebcamp ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fingerprint(PDO $db): array
    {
        $rows = $db->query('SELECT * FROM usuarios_devwebcamp ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return [count($rows), hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::assertFalse(class_exists(Email::class, false));
        require_once dirname(__DIR__) . '/Support/RecordingEmail.php';
        self::assertSame(
            realpath(dirname(__DIR__) . '/Support/RecordingEmail.php'),
            (new ReflectionClass(Email::class))->getFileName()
        );
        require_once dirname(__DIR__, 2) . '/src/Helpers/funciones.php';

        $this->originalGlobals = [
            'post' => $_POST,
            'get' => $_GET,
            'method' => [array_key_exists('REQUEST_METHOD', $_SERVER), $_SERVER['REQUEST_METHOD'] ?? null],
            'path' => [array_key_exists('PATH_INFO', $_SERVER), $_SERVER['PATH_INFO'] ?? null],
        ];
        $this->originalEnvironment = $this->environmentSnapshot();
        $config = IntegrationConfig::fromEnvironment();
        $this->verifier = IntegrationDatabase::connect($config, $config->database);
        $this->before = $this->fingerprint($this->verifier);
        self::assertSame(0, $this->before[0]);

        $this->db = TemporaryDatabaseEnvironment::run($config, static function () use ($config): PDO {
            $db = Database::connect();
            if ($db->query('SELECT DATABASE()')->fetchColumn() !== $config->database) {
                throw new \RuntimeException('Database identity mismatch.');
            }
            return $db;
        });
        self::assertTrue($this->originalEnvironment === $this->environmentSnapshot());
        ActiveRecord::setDB($this->db);
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->db?->inTransaction()) {
                $this->db->rollBack();
            }
            ActiveRecord::setDB(null);
            if ($this->verifier instanceof PDO) {
                self::assertSame($this->before, $this->fingerprint($this->verifier));
                self::assertSame(0, (int) $this->verifier->query('SELECT COUNT(*) FROM usuarios_devwebcamp')->fetchColumn());
            }
            self::assertTrue($this->originalEnvironment === $this->environmentSnapshot());
        } finally {
            $_POST = $this->originalGlobals['post'];
            $_GET = $this->originalGlobals['get'];
            foreach (['REQUEST_METHOD' => 'method', 'PATH_INFO' => 'path'] as $key => $saved) {
                [$present, $value] = $this->originalGlobals[$saved];
                if ($present) {
                    $_SERVER[$key] = $value;
                } else {
                    unset($_SERVER[$key]);
                }
            }
            parent::tearDown();
        }
    }

    private function invoke(string $method, string $verb, array $post = [], array $get = []): RecordingRouter
    {
        (new Usuario())->validar();
        $_SERVER['REQUEST_METHOD'] = $verb;
        $_SERVER['PATH_INFO'] = match ($method) {
            'registro' => '/registro',
            'olvide' => '/olvide',
            'confirmar' => '/confirmar-cuenta',
            default => '/reestablecer',
        };
        $_POST = $post;
        $_GET = $get;
        $router = new RecordingRouter();
        AuthController::$method($router);
        return $router;
    }

    private function registerSyntheticAccount(): array
    {
        $email = 'port6-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $plain = 'Synthetic-' . bin2hex(random_bytes(8));
        $injectedId = random_int(100000, 999999);
        $this->invoke('registro', 'POST', [
            'nombre' => 'Synthetic',
            'apellido' => 'Account',
            'email' => $email,
            'password' => $plain,
            'password2' => $plain,
            'id' => $injectedId,
            'admin' => 1,
            'confirmado' => 1,
            'token' => 'injected-token',
        ]);

        $rows = $this->rows();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertGreaterThan(0, (int) $row['id']);
        self::assertNotSame($injectedId, (int) $row['id']);
        self::assertSame($email, $row['email']);
        self::assertSame(0, (int) $row['admin']);
        self::assertSame(0, (int) $row['confirmado']);
        self::assertNotSame('', $row['token']);
        self::assertNotSame('injected-token', $row['token']);
        self::assertTrue(password_verify($plain, $row['password']));
        self::assertSame([['confirmation', $email, 'Synthetic', $row['token']]], Email::$sent);
        return [$row, $plain];
    }

    public function testRegistrationAllowlistAndConfirmationConsumeToken(): void
    {
        [$row] = $this->registerSyntheticAccount();
        $this->invoke('confirmar', 'GET', [], ['token' => $row['token']]);

        $confirmed = Usuario::find($row['id']);
        self::assertSame(1, (int) $confirmed->confirmado);
        self::assertSame('', $confirmed->token);
        $unchanged = $this->fingerprint($this->db);
        $this->invoke('confirmar', 'GET', [], ['token' => $row['token']]);
        self::assertSame($unchanged, $this->fingerprint($this->db));
    }

    public function testMalformedAndUnknownTokensCannotReadOrWriteAccounts(): void
    {
        [$row] = $this->registerSyntheticAccount();
        $before = $this->fingerprint($this->db);
        ActiveRecord::setDB(null);
        try {
            foreach ([[], ['token' => ''], ['token' => ['nested']], ['token' => new \stdClass()]] as $query) {
                foreach (['confirmar', 'reestablecer'] as $flow) {
                    self::assertSame([], $this->invoke($flow, 'GET', [], $query)->renders);
                    self::assertSame([], $this->invoke($flow, 'POST', ['password' => 'attack-pass'], $query)->renders);
                }
            }
        } finally {
            ActiveRecord::setDB($this->db);
        }
        self::assertSame($before, $this->fingerprint($this->db));

        $unknown = 'unknown-' . bin2hex(random_bytes(8));
        $this->invoke('confirmar', 'GET', [], ['token' => $unknown]);
        $router = $this->invoke('reestablecer', 'POST', [
            'password' => 'attack-pass',
            'id' => $row['id'],
            'admin' => 1,
            'confirmado' => 1,
            'token' => $row['token'],
        ], ['token' => $unknown]);
        self::assertSame('auth/reestablecer', $router->renders[0]['view']);
        self::assertFalse($router->renders[0]['data']['token_valido']);
        self::assertSame($before, $this->fingerprint($this->db));
    }

    public function testRecoveryAndResetIgnoreInjectedFieldsAndConsumeToken(): void
    {
        [$row, $plain] = $this->registerSyntheticAccount();
        $injectedId = (int) $row['id'] + 1000000;
        $this->invoke('confirmar', 'GET', [], ['token' => $row['token']]);
        $this->invoke('olvide', 'POST', [
            'email' => $row['email'],
            'id' => $injectedId,
            'admin' => 1,
            'confirmado' => 0,
            'token' => 'injected-token',
            'password' => 'injected-password',
        ]);

        $recovered = Usuario::find($row['id']);
        $resetToken = $recovered->token;
        self::assertNotSame('', $resetToken);
        self::assertNotSame('injected-token', $resetToken);
        self::assertSame(0, (int) $recovered->admin);
        self::assertSame(1, (int) $recovered->confirmado);
        self::assertTrue(password_verify($plain, $recovered->password));
        self::assertSame(['recovery', $row['email'], $recovered->nombre, $resetToken], Email::$sent[1]);

        $newPassword = 'Changed-' . bin2hex(random_bytes(8));
        $this->invoke('reestablecer', 'POST', [
            'password' => $newPassword,
            'id' => $injectedId,
            'admin' => 1,
            'confirmado' => 0,
            'token' => 'injected-token',
            'email' => 'attack@example.invalid',
        ], ['token' => $resetToken]);

        $reset = Usuario::find($row['id']);
        self::assertTrue(password_verify($newPassword, $reset->password));
        self::assertFalse(password_verify($plain, $reset->password));
        self::assertSame('', $reset->token);
        self::assertSame($row['email'], $reset->email);
        self::assertSame(0, (int) $reset->admin);
        self::assertSame(1, (int) $reset->confirmado);
        self::assertCount(2, Email::$sent);

        $unchanged = $this->fingerprint($this->db);
        $this->invoke('reestablecer', 'POST', ['password' => 'another-pass'], ['token' => $resetToken]);
        self::assertSame($unchanged, $this->fingerprint($this->db));
    }
}

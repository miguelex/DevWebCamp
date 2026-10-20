<?php

declare(strict_types=1);

namespace Tests\Functional;

use PDO;
use RuntimeException;
use Tests\Support\IntegrationConfig;
use Tests\Support\IntegrationDatabase;

require_once dirname(__DIR__) . '/Support/IntegrationConfig.php';
require_once dirname(__DIR__) . '/Support/IntegrationDatabase.php';

final class HttpHarness
{
    private ?PDO $serverDb = null;
    private ?PDO $appDb = null;
    private bool $created = false;
    private $server = null;
    private string $temporaryDirectory = '';
    private string $baseUrl = '';

    public function __construct(private readonly IntegrationConfig $config) {}

    /** @param callable(self): void $checks */
    public function run(callable $checks): void
    {
        $failure = null;
        try {
            $this->prepareDatabase();
            $this->startServer();
            $checks($this);
            $this->assertDatabaseUnchanged();
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            try {
                $this->cleanup();
            } catch (\Throwable $error) {
                if ($failure !== null) {
                    fwrite(STDERR, 'Original failure: ' . $failure->getMessage() . "\n");
                }
                $failure = $error;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function prepareDatabase(): void
    {
        $this->serverDb = IntegrationDatabase::connect($this->config, 'information_schema');
        $exists = $this->serverDb->prepare(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
        );
        $exists->execute([$this->config->database]);
        if ($exists->fetchColumn() !== false) {
            throw new RuntimeException('The test database already exists; refusing to replace it.');
        }

        $this->serverDb->exec("CREATE DATABASE `{$this->config->database}` CHARACTER SET utf8mb4");
        $this->created = true;
        $this->appDb = IntegrationDatabase::connect($this->config, $this->config->database);
        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Functional schema is missing.');
        }
        foreach (explode(';', $schema) as $statement) {
            if (trim($statement) !== '') {
                $this->appDb->exec($statement);
            }
        }
        $this->assertSyntheticFixture();
    }

    private function assertSyntheticFixture(): void
    {
        if ($this->appDb === null
            || $this->appDb->query('SELECT DATABASE()')->fetchColumn() !== $this->config->database
            || $this->appDb->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) !== ['eventos']
            || $this->appDb->query('SELECT id, categoria_id, dia_id, hora_id FROM eventos')
                ->fetchAll(PDO::FETCH_NUM) !== [['1', '1', '1', '1']]) {
            throw new RuntimeException('Synthetic fixture identity mismatch.');
        }
    }

    private function startServer(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/port9-http-' . bin2hex(random_bytes(8));
        if (!mkdir($this->temporaryDirectory, 0700)
            || !mkdir($this->temporaryDirectory . '/sessions', 0700)) {
            throw new RuntimeException('Cannot create isolated temporary directory.');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        if ($socket === false) {
            throw new RuntimeException('Cannot allocate loopback port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($address === false) {
            throw new RuntimeException('Cannot identify loopback port.');
        }
        $port = (int) substr(strrchr($address, ':'), 1);
        $this->baseUrl = "http://127.0.0.1:{$port}";

        $environment = getenv();
        if (!is_array($environment)) {
            throw new RuntimeException('Cannot read process environment.');
        }
        foreach (array_keys($environment) as $key) {
            if (str_starts_with($key, 'DB_')) {
                unset($environment[$key]);
            }
        }
        $environment = array_replace($environment, [
            'APP_ENV_MODE' => 'isolated-test',
            'DB_HOST' => $this->config->host,
            'DB_PORT' => (string) $this->config->port,
            'DB_NAME' => $this->config->database,
            'DB_USER' => $this->config->user,
            'DB_PASS' => $this->config->password,
        ]);
        $root = dirname(__DIR__, 2);
        $command = [PHP_BINARY, '-d', 'variables_order=EGPCS',
            '-d', 'session.save_path=' . $this->temporaryDirectory . '/sessions',
            '-d', 'session.use_strict_mode=1', '-d', 'session.cookie_httponly=1',
            '-S', '127.0.0.1:' . $port, '-t', $root . '/public'];
        $this->server = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->temporaryDirectory . '/stdout.log', 'w'],
            2 => ['file', $this->temporaryDirectory . '/stderr.log', 'w'],
        ], $pipes, $root, $environment);
        if (!is_resource($this->server)) {
            throw new RuntimeException('Cannot launch isolated HTTP server.');
        }
        for ($attempt = 0; $attempt < 30; $attempt++) {
            if (!proc_get_status($this->server)['running']) {
                throw new RuntimeException('HTTP server exited before readiness.');
            }
            try {
                if ($this->request('GET', '/devwebcamp')['status'] === 200) {
                    return;
                }
            } catch (RuntimeException) {
                // The server has not bound the port yet.
            }
            usleep(100_000);
        }
        throw new RuntimeException('HTTP server did not become ready.');
    }

    /** @return array{method: string, path: string, status: int, location: ?string, body: string} */
    public function request(string $method, string $path, ?string $cookie = null): array
    {
        if (!in_array($method, ['GET', 'POST'], true) || !str_starts_with($path, '/')) {
            throw new RuntimeException('Invalid HTTP request.');
        }
        $headers = "User-Agent: PORT-9-functional-smoke\r\n";
        if ($cookie !== null) {
            $headers .= "Cookie: {$cookie}\r\n";
        }
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => $headers, 'follow_location' => 0,
            'ignore_errors' => true, 'timeout' => 2,
        ]]);
        $body = @file_get_contents($this->baseUrl . $path, false, $context);
        if ($body === false || empty($http_response_header)
            || !preg_match('~^HTTP/\S+\s+(\d+)~', $http_response_header[0], $match)) {
            throw new RuntimeException("HTTP request failed: {$method} {$path}");
        }
        $location = null;
        foreach ($http_response_header as $header) {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }
        return ['method' => $method, 'path' => $path, 'status' => (int) $match[1],
            'location' => $location, 'body' => $body];
    }

    private function assertDatabaseUnchanged(): void
    {
        $this->assertSyntheticFixture();
        echo "Synthetic database unchanged after HTTP checks.\n";
    }

    private function cleanup(): void
    {
        $errors = [];
        foreach ([$this->stopServer(...), $this->dropDatabase(...), $this->removeTemporaryFiles(...)] as $step) {
            try {
                $step();
            } catch (\Throwable $error) {
                $errors[] = $error->getMessage();
            }
        }
        if ($errors !== []) {
            throw new RuntimeException('Cleanup incomplete: ' . implode('; ', $errors));
        }
    }

    private function stopServer(): void
    {
        if (is_resource($this->server)) {
            if (proc_get_status($this->server)['running']) {
                if (!proc_terminate($this->server)) {
                    throw new RuntimeException('Cannot terminate HTTP server.');
                }
            }
            proc_close($this->server);
            $this->server = null;
            if (@file_get_contents($this->baseUrl . '/devwebcamp', false,
                stream_context_create(['http' => ['timeout' => 1]])) !== false) {
                throw new RuntimeException('HTTP server is still answering after termination.');
            }
            echo "HTTP server stopped.\n";
        }
    }

    private function dropDatabase(): void
    {
        $this->appDb = null;
        if ($this->created && $this->serverDb instanceof PDO) {
            if ($this->serverDb->query('SELECT DATABASE()')->fetchColumn() !== 'information_schema') {
                throw new RuntimeException('Cleanup database identity mismatch.');
            }
            $this->serverDb->exec("DROP DATABASE `{$this->config->database}`");
            $this->created = false;
            $check = $this->serverDb->prepare(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
            );
            $check->execute([$this->config->database]);
            if ($check->fetchColumn() !== false) {
                throw new RuntimeException('Disposable database remains after cleanup.');
            }
            echo "Disposable database removed.\n";
        }
        $this->serverDb = null;
    }

    private function removeTemporaryFiles(): void
    {
        if ($this->temporaryDirectory !== '') {
            $sessions = $this->temporaryDirectory . '/sessions';
            if (is_dir($sessions)) {
                foreach (glob($sessions . '/*') ?: [] as $file) {
                    if (!is_file($file) || !unlink($file)) {
                        throw new RuntimeException('Cannot remove isolated session file.');
                    }
                }
                if (!rmdir($sessions)) {
                    throw new RuntimeException('Cannot remove isolated session directory.');
                }
            }
            foreach (['stdout.log', 'stderr.log'] as $log) {
                if (is_file($this->temporaryDirectory . '/' . $log)
                    && !unlink($this->temporaryDirectory . '/' . $log)) {
                    throw new RuntimeException('Cannot remove HTTP server log.');
                }
            }
            if (!rmdir($this->temporaryDirectory) || is_dir($this->temporaryDirectory)) {
                throw new RuntimeException('Temporary directory remains after cleanup.');
            }
            echo "Isolated session files and logs removed.\n";
        }
    }
}

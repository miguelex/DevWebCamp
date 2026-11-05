<?php

declare(strict_types=1);

namespace Tests\Functional;

use PDO;
use RuntimeException;
use Tests\Support\IntegrationConfig;
use Tests\Support\IntegrationDatabase;

require_once dirname(__DIR__) . '/Support/IntegrationConfig.php';
require_once dirname(__DIR__) . '/Support/IntegrationDatabase.php';

/** Isolated public-page contracts; no production router or environment overrides. */
final class PublicHttpHarness
{
    private ?PDO $serverDb = null;
    private ?PDO $appDb = null;
    private bool $created = false;
    private string $temporary = '';
    private string $baseUrl = '';
    private array $processes = [];
    private array $initialRows = [];
    private array $ports = [];

    public function __construct(
        private readonly IntegrationConfig $config,
        public readonly string $backend,
    ) {
        if (!in_array($backend, ['php', 'apache'], true)) {
            throw new RuntimeException('Use --server=php or --server=apache.');
        }
        if ($backend === 'apache' && (!function_exists('posix_geteuid') || posix_geteuid() === 0)) {
            throw new RuntimeException('Run the isolated Apache contracts as a non-root POSIX user.');
        }
    }

    public function run(callable $checks): void
    {
        $failure = null;
        try {
            $this->prepareDatabase();
            $this->startServer();
            $checks($this);
            $this->agendaFixture([1, 2, 3, 4]);
            if ($this->snapshot() !== $this->initialRows) {
                throw new RuntimeException('Public HTTP contracts changed unrelated fixture rows.');
            }
            $this->assertNoDiagnostics();
            echo "Synthetic public-page database restored and unchanged.\n";
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
        $exists = $this->serverDb->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$this->config->database]);
        if ($exists->fetchColumn() !== false) {
            throw new RuntimeException('The test database already exists; refusing to replace it.');
        }
        $this->serverDb->exec("CREATE DATABASE `{$this->config->database}` CHARACTER SET utf8mb4");
        $this->created = true;
        $this->appDb = IntegrationDatabase::connect($this->config, $this->config->database);
        foreach (['schema.sql', 'crud-schema.sql'] as $file) {
            $schema = file_get_contents(__DIR__ . '/' . $file);
            if ($schema === false) {
                throw new RuntimeException('Missing public-page test schema.');
            }
            foreach (explode(';', $schema) as $statement) {
                if (trim($statement) !== '') {
                    $this->appDb->exec($statement);
                }
            }
        }
        $this->appDb->exec("INSERT INTO categorias (nombre) VALUES ('Conference'), ('Workshop')");
        $this->appDb->exec("INSERT INTO dias (nombre) VALUES ('Friday'), ('Saturday')");
        $this->appDb->exec("INSERT INTO horas (hora) VALUES ('09:00')");
        $this->appDb->exec("INSERT INTO ponentes (nombre, apellido, ciudad, pais, imagen, tags, redes)"
            . " VALUES ('Synthetic', 'Speaker', 'Test City', 'Test Country', 'fixture-image', 'PHP', '{}')");
        $this->appDb->exec('DELETE FROM eventos');
        $insert = $this->appDb->prepare('INSERT INTO eventos'
            . ' (id, categoria_id, dia_id, hora_id, nombre, descripcion, disponibles, ponente_id)'
            . ' VALUES (?, ?, ?, 1, ?, ?, 10, 1)');
        foreach ([[1, 1], [1, 2], [2, 1], [2, 2]] as $index => [$category, $day]) {
            $insert->execute([$index + 1, $category, $day, 'Synthetic Session ' . ($index + 1), 'Synthetic description']);
        }
        $this->initialRows = $this->snapshot();
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (['usuarios_devwebcamp', 'categorias', 'dias', 'horas', 'ponentes', 'eventos'] as $table) {
            $rows[$table] = $this->appDb->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }

    /** Replace only synthetic agenda events, preserving catalog/speaker/user rows. */
    public function agendaFixture(array $ids): void
    {
        $this->appDb->exec('DELETE FROM eventos');
        $insert = $this->appDb->prepare('INSERT INTO eventos'
            . ' (id, categoria_id, dia_id, hora_id, nombre, descripcion, disponibles, ponente_id)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($this->initialRows['eventos'] as $row) {
            if (in_array((int) $row['id'], $ids, true)) {
                $insert->execute(array_map(static fn(string $key) => $row[$key],
                    ['id', 'categoria_id', 'dia_id', 'hora_id', 'nombre', 'descripcion', 'disponibles', 'ponente_id']));
            }
        }
    }

    private function allocatePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        if ($socket === false) {
            throw new RuntimeException('Cannot allocate a loopback port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($address === false) {
            throw new RuntimeException('Cannot identify loopback port.');
        }
        $port = (int) substr(strrchr($address, ':'), 1);
        $this->ports[] = $port;
        return $port;
    }

    private function launch(array $command, array $environment, string $name): void
    {
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->temporary . '/' . $name . '.stdout', 'w'],
            2 => ['file', $this->temporary . '/' . $name . '.stderr', 'w'],
        ], $pipes, dirname(__DIR__, 2), $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot launch public HTTP ' . $name . '.');
        }
        $this->processes[] = $process;
    }

    private function startServer(): void
    {
        $this->temporary = sys_get_temp_dir() . '/port18-public-' . bin2hex(random_bytes(8));
        if (!mkdir($this->temporary, 0700) || !mkdir($this->temporary . '/sessions', 0700)) {
            throw new RuntimeException('Cannot create isolated public HTTP directory.');
        }
        $root = dirname(__DIR__, 2);
        $port = $this->allocatePort();
        $this->baseUrl = 'http://127.0.0.1:' . $port;
        // This probe observes SAPI values; it never manufactures PATH_INFO.
        $probe = <<<'PHP'
<?php
header('X-Public-Path-Info: ' . rawurlencode($_SERVER['PATH_INFO'] ?? '(unset)'));
header('X-Public-Method: ' . $_SERVER['REQUEST_METHOD']);
header('X-Public-Query: ' . rawurlencode($_SERVER['QUERY_STRING'] ?? ''));
header('X-Public-Body-Sha256: ' . hash('sha256', file_get_contents('php://input')));
PHP;
        if (file_put_contents($this->temporary . '/probe.php', $probe) === false) {
            throw new RuntimeException('Cannot create test-only SAPI probe.');
        }
        $environment = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'APP_ENV_MODE' => 'isolated-test', 'HOST' => $this->baseUrl,
            'DB_HOST' => $this->config->host, 'DB_PORT' => (string) $this->config->port,
            'DB_NAME' => $this->config->database, 'DB_USER' => $this->config->user,
            'DB_PASS' => $this->config->password,
        ];
        $phpOptions = [
            '-d', 'variables_order=EGPCS', '-d', 'error_reporting=E_ALL',
            '-d', 'display_errors=1', '-d', 'log_errors=1',
            '-d', 'error_log=' . $this->temporary . '/php-errors.log',
            '-d', 'session.save_path=' . $this->temporary . '/sessions',
            '-d', 'auto_prepend_file=' . $this->temporary . '/probe.php',
            '-d', 'allow_url_fopen=0', '-d',
            'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client',
        ];
        if ($this->backend === 'php') {
            $this->launch([PHP_BINARY, ...$phpOptions, '-S', '127.0.0.1:' . $port, '-t', $root . '/public'],
                $environment, 'php');
        } else {
            $cgiPort = $this->allocatePort();
            $cgi = getenv('PHP_CGI_BINARY') ?: dirname(PHP_BINARY) . '/php-cgi';
            $apache = getenv('APACHE_BINARY') ?: '/usr/sbin/apache2';
            $modules = getenv('APACHE_MODULE_DIRECTORY') ?: '/usr/lib/apache2/modules';
            if (!is_executable($cgi) || !is_executable($apache) || !is_dir($modules)) {
                throw new RuntimeException('Apache contracts require Apache modules and PHP CGI matching the CLI runtime.');
            }
            $this->launch([$cgi, ...$phpOptions, '-d', 'cgi.fix_pathinfo=1', '-b', '127.0.0.1:' . $cgiPort],
                $environment, 'cgi');
            $quote = static fn(string $value): string => '"' . addcslashes($value, '\\"') . '"';
            $config = 'ServerRoot ' . $quote($this->temporary) . "\n"
                . 'DefaultRuntimeDir ' . $quote($this->temporary) . "\n"
                . 'PidFile ' . $quote($this->temporary . '/httpd.pid') . "\n"
                . "Listen 127.0.0.1:{$port}\nServerName 127.0.0.1\n";
            foreach (['mpm_event', 'authz_core', 'authz_host', 'dir', 'mime', 'rewrite', 'proxy', 'proxy_fcgi'] as $module) {
                $config .= "LoadModule {$module}_module " . $quote($modules . '/mod_' . $module . '.so') . "\n";
            }
            $config .= 'ErrorLog ' . $quote($this->temporary . '/apache-errors.log') . "\n"
                . 'User "#' . posix_geteuid() . '"' . "\n"
                . 'Group "#' . posix_getegid() . '"' . "\n"
                . "LogLevel warn\nTypesConfig /etc/mime.types\nProxyFCGIBackendType GENERIC\n"
                // PHP-CGI mistakes internal rewrites for legacy Apache Action dispatch when this is present.
                // Leave Apache's real SCRIPT_FILENAME and PATH_INFO untouched.
                . "ProxyFCGISetEnvIf true !REDIRECT_URL\n"
                . 'DocumentRoot ' . $quote($root . '/public') . "\n"
                . "<Directory />\nRequire all denied\nAllowOverride None\n</Directory>\n"
                . '<Directory ' . $quote($root . '/public') . ">\n"
                . "Require all granted\nAllowOverride All\nOptions -Indexes +FollowSymLinks\n</Directory>\n"
                . '<FilesMatch "\.php$">' . "\nSetHandler \"proxy:fcgi://127.0.0.1:{$cgiPort}\"\n</FilesMatch>\n";
            if (file_put_contents($this->temporary . '/httpd.conf', $config) === false) {
                throw new RuntimeException('Cannot create isolated Apache configuration.');
            }
            $this->launch([$apache, '-X', '-f', $this->temporary . '/httpd.conf'], $environment, 'apache');
        }
        $readiness = 'no HTTP response';
        for ($attempt = 0; $attempt < 50; $attempt++) {
            foreach ($this->processes as $process) {
                if (!proc_get_status($process)['running']) {
                    throw new RuntimeException('Public HTTP server exited before readiness: '
                        . $readiness . $this->apacheStartupDiagnostics());
                }
            }
            try {
                $response = $this->request('GET', '/devwebcamp');
                $readiness = 'HTTP ' . $response['status'];
                if ($response['status'] === 200) {
                    return;
                }
            } catch (RuntimeException $error) {
                // Startup can race the first loopback connection.
                $readiness = $error->getMessage();
            }
            usleep(100_000);
        }
        throw new RuntimeException('Public HTTP server did not become ready: '
            . $readiness . $this->apacheStartupDiagnostics());
    }

    private function apacheStartupDiagnostics(): string
    {
        $diagnostics = '';
        // No application/CGI logs or process environment: only Apache startup/configuration faults.
        foreach (['apache.stderr', 'apache-errors.log'] as $name) {
            $file = $this->temporary . '/' . $name;
            if (is_file($file)) {
                $diagnostics .= file_get_contents($file);
            }
        }
        if ($this->config->password !== '') {
            $diagnostics = str_replace($this->config->password, '[redacted]', $diagnostics);
        }
        return $diagnostics === '' ? '' : '; Apache: ' . substr(trim($diagnostics), 0, 2000);
    }

    public function request(string $method, string $path, ?array $form = null): array
    {
        $route = parse_url($path, PHP_URL_PATH);
        $normalized = preg_replace('~^/index\.php(?=/|$)~', '', $route) ?: '/';
        if (!in_array($method . ' ' . $normalized, [
            'GET /', 'GET /workshops-conferencias', 'GET /devwebcamp', 'GET /admin/dashboard',
            'GET /api/eventos-horario', 'POST /login', 'GET /port18-unknown-route', 'GET /404',
            'GET /build/css/app.css', 'GET /build/port18-missing.css', 'GET /img/port18-missing.png',
            'GET /composer.json', 'GET /.env',
        ], true) || ($form !== null && $method !== 'POST')) {
            throw new RuntimeException('Route outside public-page contract allowlist.');
        }
        $content = $form === null ? '' : http_build_query($form, '', '&', PHP_QUERY_RFC1738);
        $context = stream_context_create(['http' => [
            'method' => $method, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 3,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $content,
        ]]);
        $body = @file_get_contents($this->baseUrl . $path, false, $context);
        $headers = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? []) : (get_defined_vars()['http_response_header'] ?? []);
        if ($body === false || !preg_match('~^HTTP/\S+\s+(\d+)~', $headers[0] ?? '', $match)) {
            throw new RuntimeException('Loopback HTTP request failed.');
        }
        $mapped = [];
        foreach (array_slice($headers, 1) as $header) {
            if (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $mapped[strtolower($name)] = trim($value);
            }
        }
        $this->assertNoDiagnostics();
        return ['status' => (int) $match[1], 'body' => $body, 'headers' => $mapped];
    }

    private function assertNoDiagnostics(): void
    {
        $log = $this->temporary . '/php-errors.log';
        if (is_file($log) && file_get_contents($log) !== '') {
            throw new RuntimeException('PHP emitted diagnostics during public-page contracts.');
        }
    }

    private function cleanup(): void
    {
        $errors = [];
        foreach (array_reverse($this->processes) as $process) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
                for ($attempt = 0; $attempt < 30 && proc_get_status($process)['running']; $attempt++) {
                    usleep(50_000);
                }
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
            }
            proc_close($process);
        }
        foreach ($this->ports as $port) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $message, 0.2);
            if (is_resource($socket)) {
                fclose($socket);
                $errors[] = 'A public HTTP process is still listening.';
            }
        }
        try {
            if ($this->created && $this->serverDb !== null) {
                if ($this->serverDb->query('SELECT DATABASE()')->fetchColumn() !== 'information_schema') {
                    throw new RuntimeException('Cleanup database identity mismatch.');
                }
                $this->appDb = null;
                $this->serverDb->exec("DROP DATABASE `{$this->config->database}`");
                echo "Disposable public-page database removed.\n";
            }
        } catch (\Throwable $error) {
            $errors[] = $error->getMessage();
        }
        if ($this->temporary !== '' && is_dir($this->temporary)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $this->temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir() ? !rmdir($file->getPathname()) : !unlink($file->getPathname())) {
                    $errors[] = 'Cannot remove public HTTP temporary file.';
                }
            }
            if (!rmdir($this->temporary)) {
                $errors[] = 'Cannot remove public HTTP temporary directory.';
            }
        }
        if ($errors !== []) {
            throw new RuntimeException('Public HTTP cleanup incomplete: ' . implode('; ', $errors));
        }
        echo "Public HTTP processes, listeners and temporary files removed.\n";
    }
}

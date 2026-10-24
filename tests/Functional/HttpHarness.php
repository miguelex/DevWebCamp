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
    private array $fixtures = [];
    private array $initialRows = [];
    private int $requests = 0;

    public function __construct(
        private readonly IntegrationConfig $config,
        private readonly bool $blockMail = false,
        private readonly bool $crud = false,
        private readonly bool $ticketing = false,
    ) {}

    /** @param callable(self): void $checks */
    public function run(callable $checks, ?string $expectedNewUserEmail = null): void
    {
        if ($expectedNewUserEmail !== null && !$this->blockMail) {
            throw new RuntimeException('User lifecycle requires the mail guard.');
        }
        $failure = null;
        try {
            $this->prepareDatabase();
            $this->startServer();
            $checks($this);
            if ($expectedNewUserEmail === null) {
                $this->assertDatabaseUnchanged();
            } else {
                $this->assertOnlyNewUser($expectedNewUserEmail);
            }
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
        $this->seedUsers();
        if ($this->crud || $this->ticketing) {
            $this->seedCrudFixture();
        }
        if ($this->ticketing) {
            $this->seedTicketingFixture();
        }
        $this->initialRows = $this->rows();
        $this->assertSyntheticFixture();
    }

    private function seedUsers(): void
    {
        $password = 'Synthetic-' . bin2hex(random_bytes(12));
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $insert = $this->appDb->prepare(
            'INSERT INTO usuarios_devwebcamp (nombre, apellido, email, password, confirmado, admin)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        $roles = [
            'admin' => [1, 1],
            'attendee' => [1, 0],
            'unconfirmed' => [0, 0],
        ];
        if ($this->ticketing) {
            $roles['online'] = [1, 0];
            $roles['presencial'] = [1, 0];
        }
        foreach ($roles as $role => [$confirmed, $admin]) {
            $email = $role . '-' . bin2hex(random_bytes(6)) . '@example.invalid';
            $insert->execute(['Synthetic', ucfirst($role), $email, $hash, $confirmed, $admin]);
            $this->fixtures[$role] = [
                'id' => (int) $this->appDb->lastInsertId(),
                'email' => $email,
                'password' => $password,
                'admin' => $admin,
            ];
        }
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function rows(): array
    {
        $rows = [
            'eventos' => $this->appDb->query('SELECT * FROM eventos ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'usuarios_devwebcamp' => $this->appDb->query(
                'SELECT * FROM usuarios_devwebcamp ORDER BY id'
            )->fetchAll(PDO::FETCH_ASSOC),
        ];
        if ($this->crud || $this->ticketing) {
            foreach (['categorias', 'dias', 'horas', 'ponentes'] as $table) {
                $rows[$table] = $this->appDb->query("SELECT * FROM {$table} ORDER BY id")
                    ->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        if ($this->ticketing) {
            foreach (['paquetes', 'registros'] as $table) {
                $rows[$table] = $this->appDb->query("SELECT * FROM {$table} ORDER BY id")
                    ->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        return $rows;
    }

    private function seedTicketingFixture(): void
    {
        $schema = file_get_contents(__DIR__ . '/ticketing-schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Ticketing-only schema is missing.');
        }
        foreach (explode(';', $schema) as $statement) {
            if (trim($statement) !== '') {
                $this->appDb->exec($statement);
            }
        }
        foreach (['Presencial', 'Virtual', 'Gratis'] as $name) {
            $insert = $this->appDb->prepare('INSERT INTO paquetes (nombre) VALUES (?)');
            $insert->execute([$name]);
            $this->fixtures['package_' . strtolower($name)] = (int) $this->appDb->lastInsertId();
        }
        if ($this->fixtures['package_presencial'] !== 1 || $this->fixtures['package_virtual'] !== 2
            || $this->fixtures['package_gratis'] !== 3
            || $this->fixtures['categoria_id'] !== 1 || $this->fixtures['dia_id'] !== 1) {
            throw new RuntimeException('Legacy package/category/day semantics require initial IDs 1/2/3.');
        }
        $this->appDb->exec("INSERT INTO categorias (nombre) VALUES ('Synthetic Workshop')");
        $category = (int) $this->appDb->lastInsertId();
        $this->appDb->exec("INSERT INTO dias (nombre) VALUES ('Synthetic Saturday')");
        $day = (int) $this->appDb->lastInsertId();
        if ($category !== 2 || $day !== 2) {
            throw new RuntimeException('Legacy conference grouping requires category/day ID 2.');
        }
        $insert = $this->appDb->prepare('INSERT INTO eventos (nombre, descripcion, disponibles, '
            . 'categoria_id, dia_id, hora_id, ponente_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ([[$this->fixtures['categoria_id'], $day], [$category, $this->fixtures['dia_id']], [$category, $day]] as $index => [$categoryId, $dayId]) {
            $insert->execute(['Fixture Session ' . ($index + 2), 'Synthetic fixture', $index === 2 ? 0 : 10,
                $categoryId, $dayId, $this->fixtures['hora_id'], $this->fixtures['ponente_id']]);
        }
        $insert = $this->appDb->prepare('INSERT INTO registros (paquete_id, pago_id, token, usuario_id)'
            . ' VALUES (?, ?, ?, ?)');
        foreach (['presencial' => 'presencial', 'online' => 'virtual'] as $role => $package) {
            $insert->execute([$this->fixtures['package_' . $package], '', bin2hex(random_bytes(4)),
                $this->fixtures[$role]['id']]);
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function ticketingSnapshot(): array
    {
        if (!$this->ticketing) {
            throw new RuntimeException('Ticketing database is unavailable.');
        }
        return $this->rows();
    }

    public function removeTestRegistration(int $id): void
    {
        if (!$this->ticketing || $this->appDb === null
            || in_array($id, array_map('intval', array_column($this->initialRows['registros'], 'id')), true)) {
            throw new RuntimeException('Refusing to delete a fixture registration.');
        }
        $delete = $this->appDb->prepare('DELETE FROM registros WHERE id = ?');
        $delete->execute([$id]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('Expected synthetic registration was not removed.');
        }
    }

    private function seedCrudFixture(): void
    {
        $schema = file_get_contents(__DIR__ . '/crud-schema.sql');
        if ($schema === false) {
            throw new RuntimeException('CRUD-only schema is missing.');
        }
        foreach (explode(';', $schema) as $statement) {
            if (trim($statement) !== '') {
                $this->appDb->exec($statement);
            }
        }
        $this->appDb->exec("INSERT INTO categorias (nombre) VALUES ('Synthetic Category')");
        $this->fixtures['categoria_id'] = (int) $this->appDb->lastInsertId();
        $this->appDb->exec("INSERT INTO dias (nombre) VALUES ('Synthetic Day')");
        $this->fixtures['dia_id'] = (int) $this->appDb->lastInsertId();
        $this->appDb->exec("INSERT INTO horas (hora) VALUES ('09:00')");
        $this->fixtures['hora_id'] = (int) $this->appDb->lastInsertId();
        $this->appDb->exec("INSERT INTO ponentes (nombre, apellido, ciudad, pais, imagen, tags, redes)"
            . " VALUES ('Fixture', 'Speaker', 'Test City', 'Test Country', 'fixture-image', 'PHP', '{}')");
        $this->fixtures['ponente_id'] = (int) $this->appDb->lastInsertId();
        $baseEventId = $this->appDb->query('SELECT id FROM eventos')->fetchColumn();
        if ($baseEventId === false) {
            throw new RuntimeException('Base synthetic event is missing.');
        }
        $this->fixtures['base_event_id'] = (int) $baseEventId;
        $update = $this->appDb->prepare('UPDATE eventos SET nombre = ?, descripcion = ?, disponibles = ?, '
            . 'categoria_id = ?, dia_id = ?, hora_id = ?, ponente_id = ? WHERE id = ?');
        $update->execute(['Fixture Event', 'Synthetic fixture', 10, $this->fixtures['categoria_id'],
            $this->fixtures['dia_id'], $this->fixtures['hora_id'], $this->fixtures['ponente_id'],
            $this->fixtures['base_event_id']]);
    }

    public function crudId(string $name): int
    {
        if (!$this->crud || !isset($this->fixtures[$name]) || !is_int($this->fixtures[$name])) {
            throw new RuntimeException('Unknown CRUD fixture ID.');
        }
        return $this->fixtures[$name];
    }

    /** @return list<array<string, mixed>> */
    public function tableRows(string $table): array
    {
        if (!$this->crud || !in_array($table, ['eventos', 'ponentes', 'categorias', 'dias', 'horas'], true)
            || $this->appDb === null) {
            throw new RuntimeException('CRUD table is unavailable.');
        }
        return $this->appDb->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function requestCount(): int { return $this->requests; }

    public function speakerFiles(): array
    {
        if (!$this->crud) {
            throw new RuntimeException('CRUD filesystem is unavailable.');
        }
        return glob($this->temporaryDirectory . '/public/img/speakers/*') ?: [];
    }

    /** @return array{id: int, email: string, password: string, admin: int} */
    public function fixture(string $role): array
    {
        if (!isset($this->fixtures[$role])) {
            throw new RuntimeException('Unknown synthetic role.');
        }
        return $this->fixtures[$role];
    }

    /** @return ?array<string, mixed> */
    public function userByEmail(string $email): ?array
    {
        if ($this->appDb === null || $email === '') {
            throw new RuntimeException('Disposable database is unavailable.');
        }
        $query = $this->appDb->prepare('SELECT * FROM usuarios_devwebcamp WHERE email = ?');
        $query->execute([$email]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new RuntimeException('Duplicate synthetic email rows.');
        }
        return $rows[0] ?? null;
    }

    public function userCount(): int
    {
        if ($this->appDb === null) {
            throw new RuntimeException('Disposable database is unavailable.');
        }
        return (int) $this->appDb->query('SELECT COUNT(*) FROM usuarios_devwebcamp')->fetchColumn();
    }

    public function assertFixturesUnchanged(): void
    {
        $rows = $this->rows();
        foreach ($this->initialRows['usuarios_devwebcamp'] as $fixture) {
            $found = array_values(array_filter($rows['usuarios_devwebcamp'],
                static fn(array $row): bool => $row['id'] === $fixture['id']));
            if ($found !== [$fixture]) {
                throw new RuntimeException('Original synthetic user was modified.');
            }
        }
        if ($rows['eventos'] !== $this->initialRows['eventos']) {
            throw new RuntimeException('Synthetic event was modified.');
        }
    }

    private function assertOnlyNewUser(string $email): void
    {
        $this->assertFixturesUnchanged();
        $rows = $this->rows()['usuarios_devwebcamp'];
        $new = array_values(array_filter($rows,
            static fn(array $row): bool => $row['email'] === $email));
        if (count($rows) !== count($this->initialRows['usuarios_devwebcamp']) + 1
            || count($new) !== 1) {
            throw new RuntimeException('Unexpected user lifecycle database writes.');
        }
        echo "Only the expected synthetic user changed the disposable database.\n";
    }

    private function assertSyntheticFixture(): void
    {
        if ($this->crud || $this->ticketing) {
            if ($this->appDb === null || $this->appDb->query('SELECT DATABASE()')->fetchColumn()
                !== $this->config->database || $this->rows() !== $this->initialRows
                || count($this->initialRows['usuarios_devwebcamp'] ?? []) !== ($this->ticketing ? 5 : 3)) {
                throw new RuntimeException('CRUD synthetic fixture identity mismatch.');
            }
            return;
        }
        if ($this->appDb === null
            || $this->appDb->query('SELECT DATABASE()')->fetchColumn() !== $this->config->database
            || $this->appDb->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
                !== ['eventos', 'usuarios_devwebcamp']
            || $this->appDb->query('SELECT id, categoria_id, dia_id, hora_id FROM eventos')
                ->fetchAll(PDO::FETCH_NUM) !== [['1', '1', '1', '1']]
            || count($this->initialRows['usuarios_devwebcamp'] ?? []) !== 3
            || $this->rows() !== $this->initialRows) {
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
            if (str_starts_with($key, 'DB_') || str_starts_with($key, 'EMAIL_')
                || $key === 'HOST') {
                unset($environment[$key]);
            }
        }
        $environment = array_replace($environment, [
            'APP_ENV_MODE' => 'isolated-test',
            'HOST' => $this->baseUrl,
            'DB_HOST' => $this->config->host,
            'DB_PORT' => (string) $this->config->port,
            'DB_NAME' => $this->config->database,
            'DB_USER' => $this->config->user,
            'DB_PASS' => $this->config->password,
        ]);
        if ($this->crud) {
            $environment['PORT9_SPEAKER_DIRECTORY'] = $this->temporaryDirectory . '/public/img/speakers';
        }
        $root = dirname(__DIR__, 2);
        if ($this->crud || $this->ticketing) {
            if (!mkdir($this->temporaryDirectory . '/runtime', 0700)
                || !mkdir($this->temporaryDirectory . '/public/img/speakers', 0700, true)) {
                throw new RuntimeException('Cannot create isolated CRUD filesystem.');
            }
            $router = $this->temporaryDirectory . '/router.php';
            $code = '<?php ' . ($this->crud ? 'require_once '
                . var_export(__DIR__ . '/ImageGuard.php', true) . '; ' : '')
                . '$_SERVER[\'PATH_INFO\'] = parse_url($_SERVER[\'REQUEST_URI\'], PHP_URL_PATH); '
                . 'chdir(' . var_export($this->temporaryDirectory . '/runtime', true)
                . '); require ' . var_export($root . '/public/index.php', true) . ';';
            if (file_put_contents($router, $code) === false) {
                throw new RuntimeException('Cannot create test-only router.');
            }
        }
        $command = [PHP_BINARY, '-d', 'variables_order=EGPCS',
            '-d', 'session.save_path=' . $this->temporaryDirectory . '/sessions',
            '-d', 'session.name=PORT9SESSID', '-d', 'session.serialize_handler=php_serialize',
            '-d', 'session.use_strict_mode=1', '-d', 'session.cookie_httponly=1'];
        if ($this->ticketing) {
            array_push($command, '-d', 'allow_url_fopen=0', '-d',
                'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client');
        }
        if ($this->crud) {
            $command[] = '-d';
            $command[] = 'upload_tmp_dir=' . $this->temporaryDirectory . '/runtime';
        }
        if ($this->blockMail) {
            $guard = __DIR__ . '/MailGuard.php';
            if (!is_file($guard)) {
                throw new RuntimeException('Test-only mail guard is missing.');
            }
            $command[] = '-d';
            $command[] = 'auto_prepend_file=' . $guard;
        }
        array_push($command, '-S', '127.0.0.1:' . $port, '-t', $root . '/public');
        if ($this->crud || $this->ticketing) {
            $command[] = $router;
        }
        $this->server = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->temporaryDirectory . '/stdout.log', 'w'],
            2 => ['file', $this->blockMail || $this->ticketing ? '/dev/null'
                : $this->temporaryDirectory . '/stderr.log', 'w'],
        ], $pipes, $this->crud || $this->ticketing ? $this->temporaryDirectory . '/runtime' : $root, $environment);
        if (!is_resource($this->server)) {
            throw new RuntimeException('Cannot launch isolated HTTP server.');
        }
        $readiness = 'no HTTP response';
        for ($attempt = 0; $attempt < 30; $attempt++) {
            if (!proc_get_status($this->server)['running']) {
                throw new RuntimeException('HTTP server exited before readiness.');
            }
            try {
                $response = $this->request('GET', '/devwebcamp');
                $readiness = 'HTTP ' . $response['status'] . ' Location='
                    . ($response['location'] ?? '(none)');
                if ($response['status'] === 200) {
                    return;
                }
            } catch (RuntimeException $error) {
                $readiness = $error->getMessage();
            }
            usleep(100_000);
        }
        throw new RuntimeException('HTTP server did not become ready: ' . $readiness);
    }

    public function client(): HttpClient
    {
        return new HttpClient($this);
    }

    /** @param ?array<string, string> $form
     *  @return array{method: string, path: string, status: int, location: ?string, body: string, setCookies: list<string>}
     */
    public function request(string $method, string $path, ?array $form = null, ?string $cookie = null,
        ?string $png = null): array
    {
        if ($this->ticketing && !in_array($method . ' ' . strtok($path, '?'), [
            'GET /devwebcamp', 'POST /login', 'GET /boleto',
            'POST /finalizar-registro/gratis', 'GET /finalizar-registro/conferencias',
        ], true)) {
            throw new RuntimeException('Route outside the ticketing-only allowlist.');
        }
        if (!in_array($method, ['GET', 'POST'], true) || !str_starts_with($path, '/')
            || ($form !== null && $method !== 'POST') || ($png !== null && (!$this->crud || $method !== 'POST'))
            || ($cookie !== null && preg_match('/[\r\n]/', $cookie))) {
            throw new RuntimeException('Invalid HTTP request.');
        }
        $headers = "User-Agent: PORT-9-functional-smoke\r\n";
        $content = $form === null ? '' : http_build_query($form, '', '&', PHP_QUERY_RFC1738);
        if ($method === 'POST') {
            if ($png !== null) {
                $boundary = 'port9' . bin2hex(random_bytes(12));
                $content = '';
                foreach ($form ?? [] as $name => $value) {
                    if (!is_string($value)) {
                        throw new RuntimeException('Multipart fields must be flattened.');
                    }
                    $content .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
                }
                $content .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"imagen\"; filename=\"synthetic.png\"\r\n"
                    . "Content-Type: image/png\r\n\r\n{$png}\r\n--{$boundary}--\r\n";
                $headers .= "Content-Type: multipart/form-data; boundary={$boundary}\r\n";
            } else {
                $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
            }
            $headers .= 'Content-Length: ' . strlen($content) . "\r\n";
        }
        if ($cookie !== null) {
            $headers .= "Cookie: {$cookie}\r\n";
        }
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => $headers, 'follow_location' => 0,
            'ignore_errors' => true, 'timeout' => 2, 'content' => $content,
        ]]);
        $body = @file_get_contents($this->baseUrl . $path, false, $context);
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : (get_defined_vars()['http_response_header'] ?? []);
        if ($body === false || empty($responseHeaders)
            || !preg_match('~^HTTP/\S+\s+(\d+)~', $responseHeaders[0], $match)) {
            throw new RuntimeException('HTTP request failed: ' . $method . ' ' . strtok($path, '?'));
        }
        $this->requests++;
        $location = null;
        $setCookies = [];
        $mailGuard = false;
        $imageGuard = false;
        foreach ($responseHeaders as $header) {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            } elseif (stripos($header, 'Set-Cookie:') === 0) {
                $setCookies[] = trim(substr($header, 11));
            } elseif ($header === 'X-Port9-Mail-Guard: active') {
                $mailGuard = true;
            } elseif ($header === 'X-Port9-Image-Guard: active') {
                $imageGuard = true;
            }
        }
        if ($this->blockMail && !$mailGuard) {
            throw new RuntimeException('Test-only mail guard was not active; aborting HTTP checks.');
        }
        if ($this->crud && !$imageGuard) {
            throw new RuntimeException('Test-only image guard was not active; aborting HTTP checks.');
        }
        return ['method' => $method, 'path' => $path, 'status' => (int) $match[1],
            'location' => $location, 'body' => $body, 'setCookies' => $setCookies];
    }

    public function assertSessionIdentity(string $sessionId, ?array $expected): void
    {
        if (!preg_match('/\A[a-zA-Z0-9,-]{1,128}\z/D', $sessionId)) {
            throw new RuntimeException('Invalid isolated session ID.');
        }
        $file = $this->temporaryDirectory . '/sessions/sess_' . $sessionId;
        $contents = @file_get_contents($file);
        $identity = $contents === false || $contents === ''
            ? [] : unserialize($contents, ['allowed_classes' => false]);
        if (!is_array($identity)) {
            throw new RuntimeException('Invalid isolated session contents.');
        }
        if ($expected === null) {
            if (isset($identity['id']) || isset($identity['admin'])) {
                throw new RuntimeException('Unexpected authenticated session.');
            }
        } elseif ((int) ($identity['id'] ?? 0) !== $expected['id']
            || (int) ($identity['admin'] ?? -1) !== $expected['admin']
            || ($identity['email'] ?? null) !== $expected['email']) {
            throw new RuntimeException('Authenticated session identity mismatch.');
        }
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
            if ($this->crud || $this->ticketing) {
                foreach (glob($this->temporaryDirectory . '/public/img/speakers/*') ?: [] as $file) {
                    if (!is_file($file) || !unlink($file)) {
                        throw new RuntimeException('Cannot remove isolated speaker image.');
                    }
                }
                foreach (['public/img/speakers', 'public/img', 'public', 'runtime'] as $dir) {
                    if (is_dir($this->temporaryDirectory . '/' . $dir)
                        && !rmdir($this->temporaryDirectory . '/' . $dir)) {
                        throw new RuntimeException('Cannot remove isolated CRUD directory.');
                    }
                }
                if (is_file($this->temporaryDirectory . '/router.php')
                    && !unlink($this->temporaryDirectory . '/router.php')) {
                    throw new RuntimeException('Cannot remove test-only router.');
                }
            }
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

final class HttpClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(private readonly HttpHarness $harness) {}

    /** @param ?array<string, string> $form
     *  @return array{method: string, path: string, status: int, location: ?string, body: string, setCookies: list<string>}
     */
    public function request(string $method, string $path, ?array $form = null, ?string $png = null): array
    {
        $cookie = [];
        foreach ($this->cookies as $name => $value) {
            $cookie[] = $name . '=' . $value;
        }
        $response = $this->harness->request($method, $path, $form,
            $cookie === [] ? null : implode('; ', $cookie), $png);
        foreach ($response['setCookies'] as $header) {
            $parts = explode(';', $header);
            [$name, $value] = array_pad(explode('=', trim($parts[0]), 2), 2, '');
            if (!preg_match('/\A[A-Za-z0-9_-]+\z/D', $name)
                || preg_match('/[\r\n;]/', $value)) {
                throw new RuntimeException('Invalid server cookie.');
            }
            $expired = $value === '';
            foreach (array_slice($parts, 1) as $attribute) {
                if (preg_match('/^\s*Max-Age\s*=\s*0\s*$/i', $attribute)
                    || (preg_match('/^\s*Expires\s*=\s*(.+)$/i', $attribute, $match)
                        && strtotime($match[1]) < time())) {
                    $expired = true;
                }
            }
            if ($expired) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
        return $response;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }
}

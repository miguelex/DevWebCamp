<?php

declare(strict_types=1);

namespace Intervention\Image {
    final class ImageManagerStatic
    {
        public static int $makeCalls = 0;
        public static array $saved = [];

        public static function make(string $path): ImageDouble
        {
            if (!is_file($path)) {
                throw new \RuntimeException('The synthetic upload file is missing.');
            }
            self::$makeCalls++;
            return new ImageDouble();
        }
    }

    final class ImageDouble
    {
        private string $format = '';

        public function fit(int $width, int $height): self { return $this; }
        public function encode(string $format, int $quality): self { $this->format = $format; return $this; }
        public function save(string $path): void { ImageManagerStatic::$saved[] = [$path, $this->format]; }
    }
}

namespace {
    use App\Controllers\PonentesController;
    use App\Core\ActiveRecord;
    use App\Core\Router;
    use Intervention\Image\ImageManagerStatic;

    require dirname(__DIR__, 2) . '/vendor/autoload.php';

    final class CaptureRouter extends Router
    {
        public array $renders = [];

        public function render($view, $datos = [])
        {
            $this->renders[] = [$view, $datos];
        }
    }

    final class FakeDatabase
    {
        public int $selects = 0;
        public array $updates = [];

        public function prepare(string $sql): FakeStatement
        {
            return new FakeStatement($this, $sql);
        }
    }

    final class FakeStatement
    {
        private array $rows = [];

        public function __construct(private FakeDatabase $database, private string $sql) {}

        public function execute(array $values): bool
        {
            if (str_starts_with($this->sql, 'SELECT')) {
                $this->database->selects++;
                if ((int) $values[0] === 31) {
                    $this->rows = [[
                        'id' => '31', 'nombre' => 'Legacy', 'apellido' => 'Speaker',
                        'ciudad' => 'Madrid', 'pais' => 'ES', 'imagen' => 'existing-image',
                        'tags' => 'PHP', 'redes' => '{}',
                    ]];
                }
                return true;
            }

            if (!str_starts_with($this->sql, 'UPDATE')) {
                throw new \RuntimeException('Unexpected SQL in the isolated edit test.');
            }
            $this->database->updates[] = $values;
            return true;
        }

        public function fetchAll(int $mode): array
        {
            return $this->rows;
        }
    }

    final class MemorySessionHandler implements \SessionHandlerInterface
    {
        private array $sessions = [];

        public function open(string $path, string $name): bool { return true; }
        public function close(): bool { return true; }
        public function read(string $id): string|false { return $this->sessions[$id] ?? ''; }
        public function write(string $id, string $data): bool { $this->sessions[$id] = $data; return true; }
        public function destroy(string $id): bool { unset($this->sessions[$id]); return true; }
        public function gc(int $max_lifetime): int|false { return 0; }
    }

    function checkEdit(bool $condition, string $description): void
    {
        if (!$condition) {
            throw new \RuntimeException($description);
        }
        echo "PASS: {$description}\n";
    }

    function submitEdit(array $get, array $post, array $files, FakeDatabase $database): CaptureRouter
    {
        (new \ReflectionProperty(ActiveRecord::class, 'alertas'))->setValue(null, []);
        ActiveRecord::setDB($database);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = $get;
        $_POST = $post;
        $_FILES = $files;
        header_remove();
        http_response_code(200);
        $router = new CaptureRouter();
        PonentesController::editar($router);
        return $router;
    }

    $root = dirname(__DIR__, 2);
    $originalDirectory = getcwd();
    $temporaryUpload = null;
    $failure = null;
    ob_start();

    try {
        checkEdit(session_set_save_handler(new MemorySessionHandler(), true), 'session uses memory only');
        checkEdit(session_start(['use_cookies' => false]), 'synthetic administrator session started');
        $_SESSION = ['id' => 1, 'nombre' => 'Synthetic', 'admin' => 1];

        checkEdit(chdir($root . '/public'), 'public working directory selected');
        $uploadDirectory = '../public/img/speakers/';
        checkEdit(is_dir($uploadDirectory), 'existing upload directory prevents mkdir');
        $existingFiles = scandir($uploadDirectory);
        $validFields = [
            'nombre' => 'Updated', 'apellido' => 'Speaker', 'ciudad' => 'Madrid',
            'pais' => 'ES', 'tags' => 'PHP', 'redes' => [],
        ];

        foreach ([[] , ['id' => 'invalid'], ['id' => '0']] as $invalidId) {
            $database = new FakeDatabase();
            $router = submitEdit($invalidId, $validFields, [], $database);
            checkEdit(http_response_code() === 302 && $router->renders === [],
                'missing or invalid id redirects without render');
            checkEdit($database->selects === 0 && $database->updates === []
                && ImageManagerStatic::$makeCalls === 0, 'invalid id stops before queries and image processing');
        }

        $database = new FakeDatabase();
        $router = submitEdit(['id' => '999'], $validFields,
            ['imagen' => ['tmp_name' => 'missing-synthetic-upload']], $database);
        checkEdit(http_response_code() === 302 && $router->renders === [],
            'missing speaker redirects without property access or render');
        checkEdit($database->selects === 1 && $database->updates === []
            && ImageManagerStatic::$makeCalls === 0, 'missing speaker stops after SELECT without DML or upload');

        $database = new FakeDatabase();
        $router = submitEdit(['id' => '31'], $validFields, [], $database);
        $speaker = $router->renders[0][1]['ponente'];
        checkEdit($router->renders[0][0] === 'admin/ponentes/editar'
            && $router->renders[0][1]['alertas'] === [], 'edit without upload follows valid rendering path');
        checkEdit($speaker->imagen === 'existing-image' && $speaker->imagen_actual === 'existing-image'
            && $database->updates[0][4] === 'existing-image', 'edit without upload preserves stored image');
        checkEdit(ImageManagerStatic::$makeCalls === 0 && ImageManagerStatic::$saved === [],
            'edit without upload neither processes nor saves images');

        $database = new FakeDatabase();
        $router = submitEdit(['id' => '31'],
            $validFields + ['imagen' => 'forged-image', 'imagen_actual' => 'forged-preview'], [], $database);
        $speaker = $router->renders[0][1]['ponente'];
        checkEdit($speaker->imagen === 'existing-image' && $speaker->imagen_actual === 'existing-image'
            && $database->updates[0][4] === 'existing-image', 'forged POST cannot replace stored or preview image');
        checkEdit(!isset($_POST['imagen_actual']) && $_POST['imagen'] === 'existing-image'
            && ImageManagerStatic::$saved === [], 'transient image input is discarded without an upload');

        $temporaryUpload = tmpfile();
        checkEdit(is_resource($temporaryUpload), 'temporary upload file created');
        fwrite($temporaryUpload, 'synthetic image bytes');
        $uploadPath = stream_get_meta_data($temporaryUpload)['uri'];
        $database = new FakeDatabase();
        $router = submitEdit(['id' => '31'],
            $validFields + ['imagen' => 'forged-image', 'imagen_actual' => 'forged-preview'],
            ['imagen' => ['tmp_name' => $uploadPath]], $database);
        $speaker = $router->renders[0][1]['ponente'];
        checkEdit(ImageManagerStatic::$makeCalls === 2 && count(ImageManagerStatic::$saved) === 2,
            'processed replacement reaches both image-save calls');
        checkEdit(count($database->updates) === 1 && $speaker->imagen === $database->updates[0][4]
            && $speaker->imagen !== 'forged-image' && $speaker->imagen !== 'existing-image',
            'processed replacement updates only the generated image name');
        checkEdit($speaker->imagen_actual === 'existing-image' && !isset($_POST['imagen_actual']),
            'processed replacement preserves the original preview identity');
        checkEdit(scandir($uploadDirectory) === $existingFiles,
            'image double left no persistent upload files');
    } catch (\Throwable $error) {
        $failure = $error;
    } finally {
        if (is_resource($temporaryUpload)) {
            fclose($temporaryUpload);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if ($originalDirectory !== false) {
            chdir($originalDirectory);
        }
        $output = ob_get_clean();
    }

    if ($failure !== null) {
        fwrite(STDERR, $output . 'FAIL: ' . $failure->getMessage() . "\n");
        exit(1);
    }

    echo $output;
}

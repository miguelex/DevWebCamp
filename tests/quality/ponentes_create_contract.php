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

        public function fit(int $width, int $height): self
        {
            return $this;
        }

        public function encode(string $format, int $quality): self
        {
            $this->format = $format;
            return $this;
        }

        public function save(string $path): void
        {
            ImageManagerStatic::$saved[] = [$path, $this->format];
        }
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
        public array $executions = [];

        public function prepare(string $sql): FakeStatement
        {
            return new FakeStatement($this, $sql);
        }

        public function lastInsertId(): string
        {
            return '1';
        }
    }

    final class FakeStatement
    {
        public function __construct(private FakeDatabase $database, private string $sql) {}

        public function execute(array $values): bool
        {
            $this->database->executions[] = [$this->sql, $values];
            return true;
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

    function checkSpeaker(bool $condition, string $description): void
    {
        if (!$condition) {
            throw new \RuntimeException($description);
        }
        echo "PASS: {$description}\n";
    }

    function submitSpeaker(array $post, array $files): CaptureRouter
    {
        (new \ReflectionProperty(ActiveRecord::class, 'alertas'))->setValue(null, []);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $post;
        $_FILES = $files;
        $router = new CaptureRouter();
        PonentesController::crear($router);
        return $router;
    }

    $root = dirname(__DIR__, 2);
    $originalDirectory = getcwd();
    $temporaryUpload = null;
    $failure = null;
    ob_start();

    try {
        checkSpeaker(session_set_save_handler(new MemorySessionHandler(), true), 'session uses memory only');
        checkSpeaker(session_start(['use_cookies' => false]), 'synthetic session started');
        $_SESSION = ['id' => 1, 'nombre' => 'Synthetic', 'admin' => 1];

        checkSpeaker(chdir($root . '/public'), 'public working directory selected');
        $uploadDirectory = '../public/img/speakers/';
        checkSpeaker(is_dir($uploadDirectory), 'existing upload directory prevents mkdir');
        $existingFiles = scandir($uploadDirectory);

        $database = new FakeDatabase();
        ActiveRecord::setDB($database);
        $validFields = [
            'nombre' => 'Synthetic', 'apellido' => 'Speaker', 'ciudad' => 'Madrid',
            'pais' => 'ES', 'tags' => 'PHP', 'redes' => [],
        ];

        $forged = submitSpeaker($validFields + ['imagen' => 'forged-name'], []);
        checkSpeaker($forged->renders[0][0] === 'admin/ponentes/crear', 'forged image renders creation form');
        checkSpeaker(in_array('La imagen es obligatoria', $forged->renders[0][1]['alertas']['error'] ?? [], true),
            'forged POST image cannot satisfy required-image validation');
        checkSpeaker($forged->renders[0][1]['ponente']->imagen === '' && !isset($_POST['imagen']),
            'forged image is discarded before synchronization');
        checkSpeaker($database->executions === [] && ImageManagerStatic::$saved === [],
            'forged image causes no database or file writes');

        $missing = submitSpeaker($validFields, []);
        checkSpeaker(in_array('La imagen es obligatoria', $missing->renders[0][1]['alertas']['error'] ?? [], true),
            'missing image keeps the existing required-image error');
        checkSpeaker($database->executions === [] && ImageManagerStatic::$makeCalls === 0,
            'missing image causes no database or image processing');

        $temporaryUpload = tmpfile();
        checkSpeaker(is_resource($temporaryUpload), 'temporary upload file created');
        fwrite($temporaryUpload, 'synthetic image bytes');
        $uploadPath = stream_get_meta_data($temporaryUpload)['uri'];
        $accepted = submitSpeaker($validFields + ['imagen' => 'forged-name'],
            ['imagen' => ['tmp_name' => $uploadPath]]);
        checkSpeaker(ImageManagerStatic::$makeCalls === 2 && count(ImageManagerStatic::$saved) === 2,
            'processed upload reaches both image-save calls');
        checkSpeaker(count($database->executions) === 1,
            'processed upload reaches the existing insert flow');
        checkSpeaker($accepted->renders[0][1]['alertas'] === [],
            'processed upload passes existing validation');
        checkSpeaker($_POST['imagen'] !== 'forged-name'
            && preg_match('/\A[a-f0-9]{32}\z/D', $_POST['imagen']) === 1,
            'processed upload replaces the forged POST image name');
        checkSpeaker(scandir($uploadDirectory) === $existingFiles,
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

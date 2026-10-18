<?php

declare(strict_types=1);

namespace Tests\Unit\SpeakerCreate;

use App\Controllers\PonentesController;
use App\Core\ActiveRecord;
use App\Core\Router;
use Intervention\Image\ImageManagerStatic;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

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

final class PonentesCreateTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSpeakerCreationRejectsForgedAndMissingImagesAndUsesAnImageDouble(): void
    {
        require_once dirname(__DIR__) . '/Support/RecordingImage.php';
        $root = dirname(__DIR__, 2);
        $originalDirectory = getcwd();
        $temporaryUpload = null;

        try {
            self::assertTrue(session_set_save_handler(new MemorySessionHandler(), true), 'session uses memory only');
            self::assertTrue(session_start(['use_cookies' => false]), 'synthetic session started');
            $_SESSION = ['id' => 1, 'nombre' => 'Synthetic', 'admin' => 1];

            self::assertTrue(chdir($root . '/public'), 'public working directory selected');
            $uploadDirectory = '../public/img/speakers/';
            self::assertTrue(is_dir($uploadDirectory), 'existing upload directory prevents mkdir');
            $existingFiles = scandir($uploadDirectory);

            $database = new FakeDatabase();
            ActiveRecord::setDB($database);
            $validFields = [
                'nombre' => 'Synthetic', 'apellido' => 'Speaker', 'ciudad' => 'Madrid',
                'pais' => 'ES', 'tags' => 'PHP', 'redes' => [],
            ];

            $forged = submitSpeaker($validFields + ['imagen' => 'forged-name'], []);
            self::assertTrue($forged->renders[0][0] === 'admin/ponentes/crear', 'forged image renders creation form');
            self::assertTrue(in_array('La imagen es obligatoria', $forged->renders[0][1]['alertas']['error'] ?? [], true),
                'forged POST image cannot satisfy required-image validation');
            self::assertTrue($forged->renders[0][1]['ponente']->imagen === '' && !isset($_POST['imagen']),
                'forged image is discarded before synchronization');
            self::assertTrue($database->executions === [] && ImageManagerStatic::$saved === [],
                'forged image causes no database or file writes');

            $missing = submitSpeaker($validFields, []);
            self::assertTrue(in_array('La imagen es obligatoria', $missing->renders[0][1]['alertas']['error'] ?? [], true),
                'missing image keeps the existing required-image error');
            self::assertTrue($database->executions === [] && ImageManagerStatic::$makeCalls === 0,
                'missing image causes no database or image processing');

            $temporaryUpload = tmpfile();
            self::assertTrue(is_resource($temporaryUpload), 'temporary upload file created');
            fwrite($temporaryUpload, 'synthetic image bytes');
            $uploadPath = stream_get_meta_data($temporaryUpload)['uri'];
            $accepted = submitSpeaker($validFields + ['imagen' => 'forged-name'],
                ['imagen' => ['tmp_name' => $uploadPath]]);
            self::assertTrue(ImageManagerStatic::$makeCalls === 2 && count(ImageManagerStatic::$saved) === 2,
                'processed upload reaches both image-save calls');
            self::assertTrue(count($database->executions) === 1,
                'processed upload reaches the existing insert flow');
            self::assertTrue($accepted->renders[0][1]['alertas'] === [],
                'processed upload passes existing validation');
            self::assertTrue($_POST['imagen'] !== 'forged-name'
                && preg_match('/\A[a-f0-9]{32}\z/D', $_POST['imagen']) === 1,
                'processed upload replaces the forged POST image name');
            self::assertTrue(scandir($uploadDirectory) === $existingFiles,
                'image double left no persistent upload files');
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
            ActiveRecord::setDB(null);
        }
    }
}

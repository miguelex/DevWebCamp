<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BootstrapPathsTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/port18-bootstrap-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporary . '/cwd', 0700, true));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temporary, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            self::assertTrue($file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()));
        }
        self::assertTrue(rmdir($this->temporary));
        self::assertDirectoryDoesNotExist($this->temporary);
    }

    public function testLocalFallbackBootsWithoutRootVariablesFromAnUnrelatedWorkingDirectory(): void
    {
        $root = $this->temporary . '/repo';
        $this->prepareApplication($root);
        $entry = $this->prepareEntry($root . '/public');

        $this->assertBoot($this->runEntry($entry), $root, $root . '/public');
    }

    public function testSplitRootsLoadPrivateAutoloadRoutesHelpersAndDotenv(): void
    {
        $root = $this->temporary . '/private-app';
        $public = $this->temporary . '/document-root';
        $this->prepareApplication($root);
        $entry = $this->prepareEntry($public);

        $this->assertBoot($this->runEntry($entry, [
            'APP_ROOT' => $root,
            'APP_PUBLIC_ROOT' => $public,
        ]), $root, $public);
        self::assertFileDoesNotExist($public . '/.env');
        self::assertDirectoryDoesNotExist($public . '/vendor');
    }

    public function testAbsoluteAndRelativeOverridesAreIndependentOfTheWorkingDirectory(): void
    {
        $root = $this->temporary . '/private-app';
        $public = $this->temporary . '/document-root';
        $this->prepareApplication($root);
        $entry = $this->prepareEntry($public);
        self::assertTrue(mkdir($this->temporary . '/other-cwd/nested', 0700, true));

        foreach (['/cwd', '/other-cwd/nested'] as $directory) {
            $cwd = $this->temporary . $directory;
            foreach ([
                ['APP_ROOT' => $root, 'APP_PUBLIC_ROOT' => $public],
                ['APP_ROOT' => '../private-app', 'APP_PUBLIC_ROOT' => '../document-root'],
            ] as $overrides) {
                $this->assertBoot($this->runEntry($entry, $overrides, $cwd), $root, $public, $cwd);
            }
        }
    }

    public function testNativeAbsolutePathFormsAreClassifiedWithoutFilesystemOrCwdAssumptions(): void
    {
        $root = $this->temporary . '/repo';
        $this->prepareApplication($root);
        $entry = $this->prepareEntry($root . '/public');
        // Windows forms are checked lexically here, not through a simulated Windows filesystem.
        $cases = [
            ['/var/www/app', '/anchor', false, '/var/www/app'],
            ['/domains/example/app', '/anchor', false, '/domains/example/app'],
            ['../app', '/anchor', false, '/anchor/../app'],
            ['C:\\path\\to\\app', 'C:/anchor', true, 'C:\\path\\to\\app'],
            ['C:/path/to/app', 'C:/anchor', true, 'C:/path/to/app'],
            ['\\\\server\\share\\path', 'C:/anchor', true, '\\\\server\\share\\path'],
            ['//server/share/path', 'C:/anchor', true, '//server/share/path'],
            ['../app', 'C:/anchor', true, 'C:/anchor/../app'],
            ['C:app', 'C:/anchor', true, null],
            ['\\app', 'C:/anchor', true, null],
            ['/app', 'C:/anchor', true, null],
            ['\\\\server', 'C:/anchor', true, null],
            ['\\\\server\\', 'C:/anchor', true, null],
            ['C:\\path\\to\\app', '/anchor', false, null],
            ['C:/path/to/app', '/anchor', false, null],
            ['C:app', '/anchor', false, null],
            ['\\app', '/anchor', false, null],
            ['\\\\server\\share\\path', '/anchor', false, null],
        ];
        $result = $this->runEntry($entry, ['BOOTSTRAP_PATH_CASES' => json_encode($cases, JSON_THROW_ON_ERROR)]);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame('status=200', $result['stderr']);
        self::assertJson($result['stdout'], $result['stdout']);
        self::assertSame(array_column($cases, 3), json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testPosixRejectsForeignWindowsRootsEvenWhenCwdContainsMatchingDirectories(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('This regression exercises POSIX filenames and native POSIX realpath.');
        }
        $root = $this->temporary . '/private-app';
        $public = $this->temporary . '/document-root';
        $this->prepareApplication($root);
        $entry = $this->prepareEntry($public);
        self::assertTrue(mkdir($this->temporary . '/other-cwd', 0700));

        foreach (['/cwd', '/other-cwd'] as $directory) {
            $cwd = $this->temporary . $directory;
            foreach (['\\relative-app', 'C:\\path\\to\\app', 'C:/path/to/app',
                '\\\\server\\share\\path', 'C:relative-app'] as $path) {
                // Before the fix, these real POSIX directories could be selected via the CWD.
                $this->prepareApplication($cwd . '/' . $path);
                foreach (['APP_ROOT', 'APP_PUBLIC_ROOT'] as $variable) {
                    $overrides = ['APP_ROOT' => $root, 'APP_PUBLIC_ROOT' => $public];
                    $overrides[$variable] = $path;
                    $this->assertInvalidRoot($this->runEntry($entry, $overrides, $cwd), $variable);
                }
            }
        }
    }

    public static function invalidRoots(): array
    {
        return [
            'missing app directory' => ['APP_ROOT', 'missing'],
            'app path is a file' => ['APP_ROOT', 'file'],
            'empty explicit app root' => ['APP_ROOT', 'empty'],
            'missing public directory' => ['APP_PUBLIC_ROOT', 'missing'],
            'public path is a file' => ['APP_PUBLIC_ROOT', 'file'],
            'empty explicit public root' => ['APP_PUBLIC_ROOT', 'empty'],
        ];
    }

    #[DataProvider('invalidRoots')]
    public function testInvalidRootFailsBeforeBootWithoutLeakingPaths(string $variable, string $kind): void
    {
        $root = $this->temporary . '/repo';
        $this->prepareApplication($root);
        $entry = $this->prepareEntry($root . '/public');
        $file = $this->temporary . '/not-a-directory';
        self::assertNotFalse(file_put_contents($file, 'synthetic'));
        $path = match ($kind) {
            'file' => $file,
            'empty' => '',
            default => $this->temporary . '/does-not-exist',
        };

        $result = $this->runEntry($entry, [$variable => $path]);

        $this->assertInvalidRoot($result, $variable);
    }

    public function testExistingDirectoryWithoutAnApplicationFailsWithoutAnAutoloadWarning(): void
    {
        $entry = $this->prepareEntry($this->temporary . '/public');
        $result = $this->runEntry($entry, [
            'APP_ROOT' => $this->temporary . '/cwd',
            'APP_PUBLIC_ROOT' => $this->temporary . '/public',
        ]);

        self::assertSame(1, $result['exit']);
        self::assertSame('status=500', $result['stderr']);
        self::assertSame('Invalid APP_ROOT configuration.', $result['stdout']);
    }

    private function prepareApplication(string $root): void
    {
        foreach (['vendor', 'config', 'src/Core', 'src/Helpers'] as $directory) {
            self::assertTrue(mkdir($root . '/' . $directory, 0700, true));
        }
        self::assertTrue(copy(dirname(__DIR__, 2) . '/src/Core/App.php', $root . '/src/Core/App.php'));
        self::assertNotFalse(file_put_contents($root . '/.env', "BOOTSTRAP_TEST_ENV=private-dotenv-loaded\n"));
        self::assertNotFalse(file_put_contents($root . '/src/Helpers/funciones.php',
            '<?php $GLOBALS["bootstrap_helpers_loaded"] = true;'));
        self::assertNotFalse(file_put_contents($root . '/config/routes.php',
            '<?php $GLOBALS["bootstrap_routes_loaded"] = true;'));

        // Only Session/Database/Router are doubled. App::boot and Dotenv execute their real code.
        $autoload = <<<'PHP'
<?php
namespace App\Core;
require __COMPOSER_AUTOLOAD__;
require BASE_PATH . '/src/Core/App.php';
final class Session
{
    public static function start(): void {}
}
final class Database
{
    public static function connect(): object { return new \stdClass(); }
}
final class ActiveRecord
{
    public static function setDB(object $database): void {}
}
final class Router
{
    public function dispatch(): void
    {
        if (($cases = getenv('BOOTSTRAP_PATH_CASES')) !== false) {
            $anchorPath = $GLOBALS['anchorPath'];
            echo json_encode(array_map(static fn(array $case): ?string => $anchorPath(...array_slice($case, 0, 3)),
                json_decode($cases, true, 512, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
            return;
        }
        echo json_encode([
            'app_root' => BASE_PATH,
            'public_root' => PUBLIC_PATH,
            'environment' => $_ENV['BOOTSTRAP_TEST_ENV'] ?? null,
            'helpers' => $GLOBALS['bootstrap_helpers_loaded'] ?? false,
            'routes' => $GLOBALS['bootstrap_routes_loaded'] ?? false,
            'path_info' => $_SERVER['PATH_INFO'],
            'cwd' => getcwd(),
        ], JSON_THROW_ON_ERROR);
    }
}
PHP;
        $autoload = str_replace('__COMPOSER_AUTOLOAD__',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true), $autoload);
        self::assertNotFalse(file_put_contents($root . '/vendor/autoload.php', $autoload));
    }

    private function prepareEntry(string $public): string
    {
        self::assertTrue(mkdir($public, 0700, true));
        self::assertTrue(copy(dirname(__DIR__, 2) . '/public/index.php', $public . '/index.php'));
        return $public . '/index.php';
    }

    private function runEntry(string $entry, array $overrides = [], ?string $cwd = null): array
    {
        $environment = array_replace([
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ], $overrides);
        // proc_open can omit empty env values; create those explicitly inside the child.
        $emptyOverrides = '';
        foreach ($overrides as $name => $value) {
            if ($value === '') {
                $emptyOverrides .= 'putenv(' . var_export($name . '=', true) . '); ';
            }
        }
        $code = 'http_response_code(200); '
            . 'register_shutdown_function(static function (): void {'
            . 'fwrite(STDERR, "status=" . http_response_code()); }); '
            . $emptyOverrides
            . '$_SERVER["PATH_INFO"] = "/bootstrap-proof"; require ' . var_export($entry, true) . ';';
        $process = proc_open([
            PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1',
            '-d', 'allow_url_fopen=0', '-d',
            'disable_functions=mail,fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec',
            '-r', $code,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $cwd ?? $this->temporary . '/cwd', $environment);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function assertInvalidRoot(array $result, string $variable): void
    {
        self::assertSame(1, $result['exit']);
        self::assertSame('status=500', $result['stderr']);
        self::assertSame("Invalid {$variable} configuration.", $result['stdout']);
        self::assertStringNotContainsString($this->temporary, $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('private-dotenv-loaded', $result['stdout']);
    }

    private function assertBoot(array $result, string $appRoot, string $publicRoot, ?string $cwd = null): void
    {
        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame('status=200', $result['stderr']);
        self::assertJson($result['stdout'], 'Unexpected bootstrap output: ' . $result['stdout']);
        self::assertSame([
            'app_root' => $appRoot,
            'public_root' => $publicRoot,
            'environment' => 'private-dotenv-loaded',
            'helpers' => true,
            'routes' => true,
            'path_info' => '/bootstrap-proof',
            'cwd' => $cwd ?? $this->temporary . '/cwd',
        ], json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR));
    }
}

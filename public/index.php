<?php

declare(strict_types=1);

$anchorPath = static function (string $path, string $relativeTo, bool $windows): ?string {
    if ($path === '' || str_contains($path, "\0")) {
        return null;
    }

    if ($windows) {
        $normalized = str_replace('\\', '/', $path);
        if (preg_match('~^[A-Za-z]:/~', $normalized) === 1
            || preg_match('~^//[^/]+/[^/]+(?:/|$)~', $normalized) === 1) {
            return $path;
        }
        // Drive-relative and rooted-without-drive paths depend on Windows process state.
        if ($normalized[0] === '/' || preg_match('~^[A-Za-z]:~', $path) === 1) {
            return null;
        }
    } else {
        if ($path[0] === '/') {
            return $path;
        }
        // A foreign Windows root must not become a CWD-relative POSIX filename.
        if ($path[0] === '\\' || preg_match('~^[A-Za-z]:~', $path) === 1) {
            return null;
        }
    }

    return $relativeTo . '/' . $path;
};

$resolveRoot = static function (string $name, string $fallback, string $relativeTo) use ($anchorPath): string {
    $configured = getenv($name);
    $path = $anchorPath($configured === false ? $fallback : $configured, $relativeTo, DIRECTORY_SEPARATOR === '\\');
    if ($path === null) {
        throw new InvalidArgumentException("Invalid {$name} configuration.");
    }
    $resolved = @realpath($path);
    if ($resolved === false || !is_dir($resolved)) {
        throw new InvalidArgumentException("Invalid {$name} configuration.");
    }
    return $resolved;
};

try {
    $appRoot = $resolveRoot('APP_ROOT', dirname(__DIR__), __DIR__);
    $publicRoot = $resolveRoot('APP_PUBLIC_ROOT', $appRoot . '/public', $appRoot);
    foreach (['vendor/autoload.php', 'config/routes.php', 'src/Core/App.php'] as $file) {
        if (!is_file($appRoot . '/' . $file) || !is_readable($appRoot . '/' . $file)) {
            throw new InvalidArgumentException('Invalid APP_ROOT configuration.');
        }
    }
} catch (InvalidArgumentException $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $error->getMessage();
    exit(1);
}

define('BASE_PATH', $appRoot);
define('PUBLIC_PATH', $publicRoot);

require_once BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Router;

App::boot();

$router = new Router();

require BASE_PATH . '/config/routes.php';

$router->dispatch();

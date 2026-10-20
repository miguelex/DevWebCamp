<?php

declare(strict_types=1);

use Tests\Functional\HttpHarness;
use Tests\Support\IntegrationConfig;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tests/Functional/HttpHarness.php';

try {
    $config = IntegrationConfig::fromEnvironment();
    (new HttpHarness($config))->run(static function (HttpHarness $harness): void {
        $cases = [
            ['GET', '/devwebcamp', 200, null, 'Sobre DevWebCamp'],
            ['GET', '/paquetes', 200, null, 'Paquetes DevWebCamp'],
            ['GET', '/port9-unknown-route', 302, '/404', ''],
            ['GET', '/admin/dashboard', 302, '/login', ''],
            ['GET', '/api/eventos-horario?dia_id=1&categoria_id=1', 200, null, '"id":"1"'],
        ];
        foreach ($cases as [$method, $path, $status, $location, $fragment]) {
            $actual = $harness->request($method, $path);
            if ($actual['status'] !== $status || $actual['location'] !== $location
                || ($fragment !== '' && !str_contains($actual['body'], $fragment))) {
                throw new RuntimeException("Unexpected HTTP response: {$method} {$path}");
            }
            if ($path === '/admin/dashboard' && str_contains($actual['body'], '<body class="dashboard">')) {
                throw new RuntimeException('Anonymous response contains the admin layout.');
            }
            if (str_starts_with($path, '/api/')
                && json_decode($actual['body'], true, 512, JSON_THROW_ON_ERROR)
                    !== [['id' => '1', 'categoria_id' => '1', 'dia_id' => '1', 'hora_id' => '1']]) {
                throw new RuntimeException('Read-only API did not return the synthetic event.');
            }
            echo "PASS {$method} {$path}: {$actual['status']} Location="
                . ($actual['location'] ?? '(none)') . "\n";
        }
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'HTTP smoke failed: ' . $error->getMessage() . "\n");
    exit(1);
}

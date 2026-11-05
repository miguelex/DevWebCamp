<?php

declare(strict_types=1);

use Tests\Functional\PublicHttpHarness;
use Tests\Support\IntegrationConfig;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tests/Functional/PublicHttpHarness.php';

$backend = str_replace('--server=', '', $argv[1] ?? '--server=php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    (new PublicHttpHarness(IntegrationConfig::fromEnvironment(), $backend))->run(
        static function (PublicHttpHarness $harness) use ($assert): void {
            $assert(PHP_VERSION_ID >= 80500, 'Public contracts require PHP 8.5 or later.');
            $check = static function (string $method, string $path, int $status, ?string $location = null,
                string $fragment = '', ?array $form = null) use ($harness, $assert): array {
                $response = $harness->request($method, $path, $form);
                $headers = $response['headers'];
                $assert($response['status'] === $status, "Unexpected status for {$method} {$path}");
                $assert(($headers['location'] ?? null) === $location, "Unexpected redirect for {$path}");
                $assert($fragment === '' || str_contains($response['body'], $fragment), "Missing HTML for {$path}");
                $expectedPath = preg_replace('~^/index\.php(?=/|$)~', '', parse_url($path, PHP_URL_PATH)) ?: '/';
                $actualPath = rawurldecode($headers['x-public-path-info'] ?? '(missing probe)');
                $assert($actualPath === $expectedPath
                    || ($expectedPath === '/' && in_array($actualPath, ['', '(unset)'], true)),
                    "SAPI PATH_INFO mismatch for {$path}: {$actualPath}");
                $assert(($headers['x-public-method'] ?? '') === $method, "HTTP method was not preserved for {$path}");
                $assert(rawurldecode($headers['x-public-query'] ?? '') === (parse_url($path, PHP_URL_QUERY) ?? ''),
                    "Query string was not preserved for {$path}");
                $body = $form === null ? '' : http_build_query($form, '', '&', PHP_QUERY_RFC1738);
                $assert(($headers['x-public-body-sha256'] ?? '') === hash('sha256', $body),
                    "HTTP body was not preserved for {$path}");
                $assert(!preg_match('/(?:Warning|Deprecated|Notice|Fatal error):/i', $response['body']),
                    "PHP diagnostics in response for {$path}");
                echo "PASS {$harness->backend} {$method} {$path}: {$status} PATH_INFO={$actualPath}\n";
                return $response;
            };
            foreach (['', '/index.php'] as $prefix) {
                $check('GET', $prefix . '/devwebcamp', 200, null, 'Sobre DevWebCamp');
                $check('GET', $prefix . '/admin/dashboard', 302, '/login');
                $api = $check('GET', $prefix . '/api/eventos-horario?dia_id=1&categoria_id=1', 200);
                $assert(json_decode($api['body'], true, 512, JSON_THROW_ON_ERROR)
                    === [['id' => '1', 'categoria_id' => '1', 'dia_id' => '1', 'hora_id' => '1']],
                    'Read-only API query did not select the synthetic event.');
                $check('POST', $prefix . '/login?contract=port18', 200, null, 'El Email del Usuario es Obligatorio',
                    ['email' => '', 'password' => '', 'contract' => 'synthetic + & = value']);
                $check('GET', $prefix . '/port18-unknown-route', 302, '/404');
                $check('GET', $prefix . '/404', 200, null, 'Página No Encontrada');
            }
            $asset = $harness->request('GET', '/build/css/app.css');
            $assert($asset['status'] === 200
                && $asset['body'] === file_get_contents(dirname(__DIR__) . '/public/build/css/app.css')
                && !isset($asset['headers']['x-public-path-info']), 'Static CSS entered PHP or changed bytes.');
            echo "PASS {$harness->backend} static CSS served directly, identical bytes\n";
            if ($harness->backend === 'apache') {
                foreach (['/build/port18-missing.css', '/img/port18-missing.png'] as $path) {
                    $asset = $harness->request('GET', $path);
                    $assert($asset['status'] === 404 && !isset($asset['headers']['x-public-path-info'])
                        && !isset($asset['headers']['location']), 'Missing asset entered the front controller.');
                    echo "PASS apache missing asset {$path}: native 404 without PHP\n";
                }
            }
            foreach (['/composer.json', '/.env'] as $path) {
                $private = $harness->request('GET', $path);
                $assert($private['status'] !== 200
                    && !str_contains($private['body'], 'juandelatorre/devwebcamp')
                    && !str_contains($private['body'], 'DB_PASS='), 'Private application file was exposed.');
            }

            foreach (['complete' => [1, 2, 3, 4], 'one missing' => [1, 2, 3],
                'several missing' => [2], 'empty' => []] as $scenario => $ids) {
                $harness->agendaFixture($ids);
                foreach (['/', '/workshops-conferencias', '/index.php/', '/index.php/workshops-conferencias'] as $path) {
                    $response = $check('GET', $path, 200, null, 'Workshops y Conferencias');
                    $html = $response['body'];
                    $assert(substr_count($html, 'class="swiper-wrapper"') === 4,
                        "Agenda group wrappers changed ({$scenario}, {$path}).");
                    $assert(substr_count($html, 'class="evento swiper-slide"') === count($ids),
                        "Agenda card count mismatch ({$scenario}, {$path}).");
                    $last = -1;
                    foreach ([1, 2, 3, 4] as $id) {
                        $position = strpos($html, '<h4 class="evento__nombre">Synthetic Session ' . $id . '</h4>');
                        $assert(in_array($id, $ids, true) ? $position !== false && $position > $last : $position === false,
                            "Agenda group/card order changed ({$scenario}, {$path}).");
                        if ($position !== false) {
                            $last = $position;
                        }
                    }
                    if ($ids !== []) {
                        $assert(substr_count($html, 'class="evento__hora">09:00</p>') === count($ids)
                            && substr_count($html, 'Synthetic description') === count($ids)
                            && str_contains($html, 'Synthetic Speaker')
                            && str_contains($html, '/img/speakers/fixture-image.webp'),
                            "Agenda relationship markup changed ({$scenario}, {$path}).");
                    }
                    echo "PASS agenda {$scenario}: {$path}, " . count($ids) . " cards, four groups, no diagnostics\n";
                }
            }
        }
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'Public HTTP contracts failed: ' . $error->getMessage() . "\n");
    exit(1);
}

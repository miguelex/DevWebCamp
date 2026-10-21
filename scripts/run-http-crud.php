<?php

declare(strict_types=1);

use Tests\Functional\HttpHarness;
use Tests\Support\IntegrationConfig;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tests/Functional/HttpHarness.php';

try {
    $assertions = 0;
    $assert = static function (bool $condition, string $message) use (&$assertions): void {
        $assertions++;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };
    $expect = static function (string $label, array $response, int $status, ?string $location)
        use ($assert): void {
        $assert($response['status'] === $status, "{$label}: unexpected status");
        $assert($response['location'] === $location, "{$label}: unexpected Location");
        echo "{$label}: {$response['method']} {$response['path']} => {$status} Location="
            . ($location ?? '(none)') . "\n";
    };

    (new HttpHarness(IntegrationConfig::fromEnvironment(), false, true))->run(
        static function (HttpHarness $harness) use ($assert, $expect, &$assertions): void {
            $baseRequests = $harness->requestCount();
            $admin = $harness->client();
            $attendee = $harness->client();
            $anonymous = $harness->client();
            foreach (['admin' => $admin, 'attendee' => $attendee] as $role => $client) {
                $fixture = $harness->fixture($role);
                $expect("{$role} login", $client->request('POST', '/login', [
                    'email' => $fixture['email'], 'password' => $fixture['password'],
                ]), 302, $role === 'admin' ? '/admin/dashboard' : '/finalizar-registro');
                $session = $client->cookie('PORT9SESSID');
                $assert($session !== null, "{$role} cookie missing");
                $harness->assertSessionIdentity($session, $fixture);
            }

            $speakerFixture = $harness->crudId('ponente_id');
            $eventFixture = $harness->crudId('base_event_id');
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true);
            $assert($png !== false, 'Synthetic PNG is invalid');
            $speakerForm = ['nombre' => 'Created', 'apellido' => 'Speaker', 'ciudad' => 'Test City',
                'pais' => 'Test Country', 'tags' => 'PHP,Architecture',
                'redes[facebook]' => 'https://example.invalid/speaker'];
            $eventForm = ['nombre' => 'Created Event', 'descripcion' => 'Synthetic description',
                'disponibles' => '15', 'categoria_id' => (string) $harness->crudId('categoria_id'),
                'dia_id' => (string) $harness->crudId('dia_id'),
                'hora_id' => (string) $harness->crudId('hora_id'),
                'ponente_id' => (string) $speakerFixture];

            // Every administrative route is rejected before its controller can mutate data.
            $denied = [
                ['GET', '/admin/ponentes?page=1', null],
                ['GET', '/admin/ponentes/crear', null],
                ['POST', '/admin/ponentes/crear', $speakerForm],
                ['GET', '/admin/ponentes/editar?id=' . $speakerFixture, null],
                ['POST', '/admin/ponentes/editar?id=' . $speakerFixture, $speakerForm],
                ['POST', '/admin/ponentes/eliminar', ['id' => (string) $speakerFixture]],
                ['GET', '/admin/eventos?page=1', null],
                ['GET', '/admin/eventos/crear', null],
                ['POST', '/admin/eventos/crear', $eventForm],
                ['GET', '/admin/eventos/editar?id=' . $eventFixture, null],
                ['POST', '/admin/eventos/editar?id=' . $eventFixture, $eventForm],
                ['POST', '/admin/eventos/eliminar', ['id' => (string) $eventFixture]],
            ];
            foreach (['anonymous' => $anonymous, 'attendee' => $attendee] as $role => $client) {
                $before = [$harness->tableRows('eventos'), $harness->tableRows('ponentes'),
                    $harness->speakerFiles()];
                foreach ($denied as [$method, $path, $form]) {
                    $upload = $method === 'POST' && $path === '/admin/ponentes/crear' ? $png : null;
                    $expect("{$role} denied", $client->request($method, $path, $form, $upload),
                        302, '/login');
                }
                $assert($before === [$harness->tableRows('eventos'), $harness->tableRows('ponentes'),
                    $harness->speakerFiles()], "{$role} request wrote data or files");
            }

            $response = $admin->request('GET', '/admin/ponentes?page=1');
            $expect('speaker list', $response, 200, null);
            $assert(str_contains($response['body'], 'Fixture Speaker'), 'Speaker fixture not listed');
            $expect('speaker create form', $admin->request('GET', '/admin/ponentes/crear'), 200, null);
            $before = $harness->tableRows('ponentes');
            $response = $admin->request('POST', '/admin/ponentes/crear',
                array_replace($speakerForm, ['nombre' => '']), $png);
            $expect('invalid speaker create', $response, 200, null);
            $assert(str_contains($response['body'], 'El Nombre es Obligatorio'), 'Missing speaker validation');
            $assert($harness->tableRows('ponentes') === $before && $harness->speakerFiles() === [],
                'Invalid speaker create wrote a row or image');

            $expect('valid speaker create', $admin->request('POST', '/admin/ponentes/crear',
                $speakerForm, $png), 302, '/admin/ponentes');
            $speakers = $harness->tableRows('ponentes');
            $assert(count($speakers) === count($before) + 1, 'Speaker INSERT count mismatch');
            $speaker = end($speakers);
            $assert($speaker['nombre'] === ' Created' && $speaker['apellido'] === 'Speaker'
                && $speaker['tags'] === 'PHP,Architecture'
                && json_decode($speaker['redes'], true) === ['facebook' => 'https://example.invalid/speaker'],
                'Speaker INSERT data mismatch');
            $speakerId = (int) $speaker['id'];
            $files = $harness->speakerFiles();
            sort($files);
            $assert(count($files) === 2 && basename($files[0]) === $speaker['imagen'] . '.png'
                && basename($files[1]) === $speaker['imagen'] . '.webp', 'Synthetic image pair missing');
            $pngInfo = getimagesizefromstring(file_get_contents($files[0]));
            $webpInfo = getimagesizefromstring(file_get_contents($files[1]));
            $assert($pngInfo !== false && $pngInfo['mime'] === 'image/png'
                && $pngInfo[0] === 1 && $pngInfo[1] === 1
                && $webpInfo !== false && $webpInfo['mime'] === 'image/webp'
                && $webpInfo[0] === 1 && $webpInfo[1] === 1, 'Synthetic images malformed');
            $response = $admin->request('GET', '/admin/ponentes?page=1');
            $expect('created speaker read', $response, 200, null);
            $assert(str_contains($response['body'], 'Created Speaker'), 'Created speaker not listed');

            $expect('speaker edit form', $admin->request('GET',
                '/admin/ponentes/editar?id=' . $speakerId), 200, null);
            $expect('valid speaker edit', $admin->request('POST',
                '/admin/ponentes/editar?id=' . $speakerId,
                array_replace($speakerForm, ['nombre' => 'Edited'])), 302, '/admin/ponentes');
            $editedRows = $harness->tableRows('ponentes');
            $edited = end($editedRows);
            $assert($edited['nombre'] === 'Edited' && $edited['imagen'] === $speaker['imagen']
                && $harness->speakerFiles() === $files, 'Speaker edit changed image unexpectedly');

            $response = $admin->request('GET', '/admin/eventos?page=1');
            $expect('event list', $response, 200, null);
            $assert(str_contains($response['body'], 'Fixture Event')
                && str_contains($response['body'], 'Fixture Speaker'), 'Event relations not listed');
            $expect('event create form', $admin->request('GET', '/admin/eventos/crear'), 200, null);
            $eventsBefore = $harness->tableRows('eventos');
            $response = $admin->request('POST', '/admin/eventos/crear',
                array_replace($eventForm, ['nombre' => '']));
            $expect('invalid event create', $response, 200, null);
            $assert(str_contains($response['body'], 'El Nombre es Obligatorio'), 'Missing event validation');
            $assert($harness->tableRows('eventos') === $eventsBefore, 'Invalid event create wrote a row');
            $eventForm['ponente_id'] = (string) $speakerId;
            $expect('valid event create', $admin->request('POST', '/admin/eventos/crear', $eventForm),
                302, '/admin/eventos');
            $events = $harness->tableRows('eventos');
            $assert(count($events) === count($eventsBefore) + 1, 'Event INSERT count mismatch');
            $event = end($events);
            $eventId = (int) $event['id'];
            $assert($event['nombre'] === ' Created Event' && (int) $event['ponente_id'] === $speakerId
                && (int) $event['categoria_id'] === $harness->crudId('categoria_id'),
                'Event INSERT or relation mismatch');
            $expect('event edit form', $admin->request('GET',
                '/admin/eventos/editar?id=' . $eventId), 200, null);
            $expect('valid event edit', $admin->request('POST',
                '/admin/eventos/editar?id=' . $eventId,
                array_replace($eventForm, ['nombre' => 'Edited Event', 'disponibles' => '20'])),
                302, '/admin/eventos');
            $editedEvents = $harness->tableRows('eventos');
            $editedEvent = end($editedEvents);
            $assert($editedEvent['nombre'] === 'Edited Event' && $editedEvent['disponibles'] === '20'
                && (int) $editedEvent['ponente_id'] === $speakerId, 'Event UPDATE mismatch');
            $expect('event delete', $admin->request('POST', '/admin/eventos/eliminar',
                ['id' => (string) $eventId]), 302, '/admin/eventos');
            $assert($harness->tableRows('eventos') === $eventsBefore,
                'Event DELETE affected baseline or left created event');
            $expect('speaker delete', $admin->request('POST', '/admin/ponentes/eliminar',
                ['id' => (string) $speakerId]), 302, '/admin/ponentes');
            $assert($harness->tableRows('ponentes') === $before, 'Speaker DELETE affected baseline');
            $assert($harness->speakerFiles() === $files,
                'Legacy speaker DELETE unexpectedly removed physical images');
            echo 'CRUD requests (excluding server readiness): ' . ($harness->requestCount() - $baseRequests)
                . "; assertions: {$assertions}\n";
            echo "Expected writes: 2 INSERT, 2 UPDATE, 2 DELETE; actual rows returned to baseline.\n";
            echo "Image files: 2 created, 0 deleted by legacy controller; 2 removed by harness cleanup.\n";
        }
    );
    echo "CRUD HTTP checks passed: {$assertions} assertions.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'CRUD HTTP checks failed: ' . $error->getMessage() . "\n");
    exit(1);
}

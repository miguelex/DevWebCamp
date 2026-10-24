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
        $assert($response['status'] === $status, "{$label}: unexpected status {$response['status']}");
        $assert($response['location'] === $location, "{$label}: unexpected Location");
        // Never print token-bearing URLs or Location values.
        echo "{$label}: {$response['method']} " . strtok($response['path'], '?')
            . " => {$status} Location=" . ($location === null ? '(none)'
                : (str_starts_with($location, '/boleto?') ? '/boleto?id=[redacted]' : $location)) . "\n";
    };

    (new HttpHarness(IntegrationConfig::fromEnvironment(), false, false, true))->run(
        static function (HttpHarness $harness) use ($assert, $expect, &$assertions): void {
            $baseRequests = $harness->requestCount();
            $baseline = $harness->ticketingSnapshot();
            $current = $baseline;
            $unchanged = static function () use ($harness, $assert, &$current): void {
                $assert($harness->ticketingSnapshot() === $current,
                    'Request changed rows outside the expected snapshot');
            };
            $anonymous = $harness->client();
            $presencial = $harness->client();
            $attendee = $harness->client();
            $online = $harness->client();
            $ticket = static function ($client, array $registration, string $name, string $package)
                use ($assert, $expect, $unchanged): void {
                $response = $client->request('GET', '/boleto?id=' . rawurlencode($registration['token']));
                $expect('valid ticket', $response, 200, null);
                foreach (['<!DOCTYPE html>', 'Boleto de Registro', 'boleto-virtual',
                    'boleto__nombre', $name, 'boleto__plan', $package,
                    'boleto__codigo', '#' . $registration['token']] as $fragment) {
                    $assert(str_contains($response['body'], $fragment), 'Ticket HTML contract missing');
                }
                $assert(!str_contains(strtolower($response['body']), 'paypal.com/sdk'),
                    'Ticket unexpectedly includes PayPal SDK');
                $unchanged();
            };

            // Tickets are token-only bearer access: no login, including another user's ticket.
            $ticket($anonymous, $baseline['registros'][0], 'Synthetic Presencial', 'Presencial');
            $session = $anonymous->cookie('PORT9SESSID');
            $assert($session !== null, 'Anonymous session cookie missing');
            $harness->assertSessionIdentity($session, null);
            foreach (['ffffffff', 'bad'] as $invalid) {
                $assert(!in_array($invalid, array_column($baseline['registros'], 'token'), true),
                    'Invalid token collides with fixture');
                $expect('invalid ticket legacy failure', $anonymous->request('GET', '/boleto?id=' . $invalid),
                    302, '/');
                $unchanged();
            }
            $expect('anonymous free denied', $anonymous->request('POST', '/finalizar-registro/gratis', []),
                302, '/login');
            $unchanged();
            $expect('anonymous conferences denied', $anonymous->request('GET', '/finalizar-registro/conferencias'),
                302, '/login');
            $unchanged();

            foreach (['presencial' => $presencial, 'attendee' => $attendee, 'online' => $online] as $role => $client) {
                $fixture = $harness->fixture($role);
                $expect("{$role} login", $client->request('POST', '/login', [
                    'email' => $fixture['email'], 'password' => $fixture['password'],
                ]), 302, '/finalizar-registro');
                $session = $client->cookie('PORT9SESSID');
                $assert($session !== null, 'Authenticated session cookie missing');
                $harness->assertSessionIdentity($session, $fixture);
                $unchanged();
            }
            $ticket($attendee, $baseline['registros'][0], 'Synthetic Presencial', 'Presencial');
            foreach (['no registration' => $attendee, 'virtual package' => $online] as $label => $client) {
                $expect("{$label} conferences denied", $client->request('GET', '/finalizar-registro/conferencias'),
                    302, '/');
                $unchanged();
            }
            for ($repeat = 0; $repeat < 2; $repeat++) {
                $response = $presencial->request('GET', '/finalizar-registro/conferencias');
                $expect('presencial conferences allowed', $response, 200, null);
                foreach (['Elige las Conferencias o Workshops', 'Fixture Speaker', '09:00',
                    'id="registro-resumen"', 'Agotado'] as $fragment) {
                    $assert(str_contains($response['body'], $fragment), 'Conference HTML contract missing');
                }
                foreach ($baseline['eventos'] as $event) {
                    $assert(str_contains($response['body'], $event['nombre'])
                        && str_contains($response['body'], $event['descripcion'])
                        && str_contains($response['body'], 'data-id="' . $event['id'] . '"'),
                        'Conference fixture or database-generated ID missing');
                }
                $assert(substr_count($response['body'], 'class="evento__agregar"') === 4,
                    'Expected all four category/day groups');
                $assert(str_contains($response['body'], "class=\"evento__agregar\"\n            disabled"),
                    'Sold-out conference must be disabled');
                $unchanged();
            }

            // Preserve legacy behavior: first free, duplicate free, and free after paid all INSERT.
            $createdIds = [];
            foreach ([['eligible free', $attendee, 'attendee'], ['duplicate free', $attendee, 'attendee'],
                ['paid duplicate free', $presencial, 'presencial']] as [$label, $client, $role]) {
                $response = $client->request('POST', '/finalizar-registro/gratis', []);
                $after = $harness->ticketingSnapshot();
                $new = array_values(array_filter($after['registros'],
                    static fn(array $row): bool => !in_array($row['id'], array_column($current['registros'], 'id'), true)));
                $assert(count($new) === 1, 'Free registration must insert exactly one row');
                $registration = $new[0];
                $assert((int) $registration['usuario_id'] === $harness->fixture($role)['id']
                    && $registration['paquete_id'] === $baseline['paquetes'][2]['id']
                    && $registration['pago_id'] === ''
                    && preg_match('/\A[a-f0-9]{8}\z/D', $registration['token']) === 1,
                    'Unexpected free registration data');
                $expect($label, $response, 302, '/boleto?id=' . $registration['token']);
                $current['registros'][] = $registration;
                $createdIds[] = (int) $registration['id'];
                $unchanged();
                $session = $client->cookie('PORT9SESSID');
                $assert($session !== null, 'Free registration lost session cookie');
                $harness->assertSessionIdentity($session, $harness->fixture($role));
                $ticket($anonymous, $registration, $role === 'presencial' ? 'Synthetic Presencial' : 'Synthetic Attendee', 'Gratis');
            }
            $expect('free package conferences denied', $attendee->request('GET', '/finalizar-registro/conferencias'),
                302, '/');
            $unchanged();
            foreach ($createdIds as $id) {
                $harness->removeTestRegistration($id);
            }
            $assert($harness->ticketingSnapshot() === $baseline, 'Original eight-table snapshot was not restored');
            echo 'Ticketing requests (excluding server readiness): '
                . ($harness->requestCount() - $baseRequests) . "; assertions: {$assertions}\n";
            echo "Expected/actual application writes: 3 INSERT registros, 0 UPDATE, 0 DELETE.\n";
            echo "Fixture cleanup: 3 DELETE registros; eight-table snapshot restored.\n";
            echo "No payment endpoint, browser assets, SDK, or selection persistence requested.\n";
        }
    );
    echo "Ticketing HTTP checks passed: {$assertions} assertions.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Ticketing HTTP checks failed: ' . $error->getMessage() . "\n");
    exit(1);
}

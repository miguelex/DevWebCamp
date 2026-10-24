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
        echo "{$label}: status expected={$status} actual={$response['status']}; Location expected="
            . ($location ?? '(none)') . ' actual=' . ($response['location'] ?? '(none)') . "\n";
    };
    $identityChecks = 0;
    $identity = static function (HttpHarness $harness, string $sessionId, ?array $expected)
        use (&$identityChecks): void {
        $harness->assertSessionIdentity($sessionId, $expected);
        $identityChecks++;
    };

    (new HttpHarness(IntegrationConfig::fromEnvironment()))->run(
        static function (HttpHarness $harness) use ($assert, $expect, $identity): void {
            $admin = $harness->client();
            $attendee = $harness->client();
            $invalid = $harness->client();
            $unconfirmed = $harness->client();

            $adminUser = $harness->fixture('admin');
            $attendeeUser = $harness->fixture('attendee');
            $unconfirmedUser = $harness->fixture('unconfirmed');

            $expect('admin login form', $admin->request('GET', '/login'), 200, null);
            $before = $admin->cookie('PORT9SESSID');
            $assert($before !== null, 'Admin pre-login cookie missing.');
            $identity($harness, $before, null);
            $expect('admin valid login', $admin->request('POST', '/login', [
                'email' => $adminUser['email'], 'password' => $adminUser['password'],
            ]), 302, '/admin/dashboard');
            $adminSession = $admin->cookie('PORT9SESSID');
            $assert($adminSession !== null && $adminSession !== $before,
                'Admin session ID was not regenerated.');
            $identity($harness, $before, null);
            $identity($harness, $adminSession, $adminUser);
            $response = $admin->request('GET', '/admin/dashboard');
            $expect('admin dashboard', $response, 200, null);
            $assert(str_contains($response['body'], '<body class="dashboard">'),
                'Authenticated admin layout missing.');

            $expect('invalid login form', $invalid->request('GET', '/login'), 200, null);
            $invalidSession = $invalid->cookie('PORT9SESSID');
            $assert($invalidSession !== null && $invalidSession !== $adminSession,
                'Clients share a session cookie.');
            $response = $invalid->request('POST', '/login', [
                'email' => $adminUser['email'], 'password' => 'wrong-' . $adminUser['password'],
            ]);
            $expect('invalid credentials', $response, 200, null);
            $assert(str_contains($response['body'], 'Password Incorrecto'),
                'Legacy invalid-password alert missing.');
            $identity($harness, $invalid->cookie('PORT9SESSID'), null);
            $expect('invalid credentials dashboard', $invalid->request('GET', '/admin/dashboard'),
                302, '/login');

            $expect('unconfirmed login form', $unconfirmed->request('GET', '/login'), 200, null);
            $unconfirmedSession = $unconfirmed->cookie('PORT9SESSID');
            $assert($unconfirmedSession !== null
                && !in_array($unconfirmedSession, [$adminSession, $invalidSession], true),
                'Unconfirmed client shares a session cookie.');
            $response = $unconfirmed->request('POST', '/login', [
                'email' => $unconfirmedUser['email'], 'password' => $unconfirmedUser['password'],
            ]);
            $expect('unconfirmed login', $response, 200, null);
            $assert(str_contains($response['body'], 'El Usuario No Existe o no esta confirmado'),
                'Legacy unconfirmed-user alert missing.');
            $identity($harness, $unconfirmed->cookie('PORT9SESSID'), null);
            $expect('unconfirmed dashboard', $unconfirmed->request('GET', '/admin/dashboard'),
                302, '/login');

            $expect('attendee login form', $attendee->request('GET', '/login'), 200, null);
            $response = $attendee->request('POST', '/login', [
                'email' => $attendeeUser['email'], 'password' => $attendeeUser['password'],
            ]);
            $expect('attendee valid login', $response, 302, '/finalizar-registro');
            $attendeeSession = $attendee->cookie('PORT9SESSID');
            $assert($attendeeSession !== null
                && !in_array($attendeeSession, [$adminSession, $invalidSession, $unconfirmedSession], true),
                'Attendee client shares a session cookie.');
            $identity($harness, $attendeeSession, $attendeeUser);
            $expect('attendee dashboard', $attendee->request('GET', '/admin/dashboard'),
                302, '/login');
            $identity($harness, $attendeeSession, $attendeeUser);

            $expect('admin logout', $admin->request('POST', '/logout'), 302, '/');
            $assert($admin->cookie('PORT9SESSID') === null, 'Logout cookie was not expired.');
            $identity($harness, $adminSession, null);
            $expect('old admin cookie dashboard', $harness->request('GET', '/admin/dashboard',
                null, 'PORT9SESSID=' . $adminSession), 302, '/login');
            $expect('logged-out dashboard', $admin->request('GET', '/admin/dashboard'),
                302, '/login');
            echo "Authenticated HTTP assertions passed.\n";
        }
    );
    echo "Assertions: {$assertions} HTTP/cookie, {$identityChecks} session identities.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Authenticated HTTP smoke failed: ' . $error->getMessage() . "\n");
    exit(1);
}

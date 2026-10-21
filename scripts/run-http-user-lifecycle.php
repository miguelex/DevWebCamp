<?php

declare(strict_types=1);

use Tests\Functional\HttpHarness;
use Tests\Support\IntegrationConfig;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tests/Functional/HttpHarness.php';

try {
    $checks = 0;
    $assert = static function (bool $condition, string $message) use (&$checks): void {
        $checks++;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };
    $expect = static function (string $label, array $response, int $status, ?string $location,
        ?string $message = null) use ($assert): void {
        $assert($response['status'] === $status, "{$label}: unexpected status");
        $assert($response['location'] === $location, "{$label}: unexpected redirect");
        if ($message !== null) {
            $assert(str_contains($response['body'], $message), "{$label}: legacy message missing");
        }
        echo "PASS {$label}\n";
    };
    $email = 'lifecycle-' . bin2hex(random_bytes(8)) . '@example.invalid';
    $oldPassword = 'Synthetic-' . bin2hex(random_bytes(12));
    $newPassword = 'Replacement-' . bin2hex(random_bytes(12));

    (new HttpHarness(IntegrationConfig::fromEnvironment(), true))->run(
        static function (HttpHarness $harness) use ($assert, $expect, $email, $oldPassword,
            $newPassword): void {
            $client = $harness->client();
            $other = $harness->client();
            $initialCount = $harness->userCount();
            $existing = $harness->fixture('attendee');
            $unconfirmed = $harness->fixture('unconfirmed');

            $expect('registration form', $client->request('GET', '/registro'), 200, null);
            $sessionId = $client->cookie('PORT9SESSID');
            $assert($sessionId !== null, 'Registration session cookie missing.');
            $harness->assertSessionIdentity($sessionId, null);

            $form = ['nombre' => 'Synthetic', 'apellido' => 'Lifecycle', 'email' => $email,
                'password' => $oldPassword, 'password2' => $oldPassword];
            $expect('valid registration', $client->request('POST', '/registro', $form), 302, '/mensaje');
            $user = $harness->userByEmail($email);
            $assert($user !== null, 'Registration did not create a user.');
            $assert($harness->userCount() === $initialCount + 1, 'Registration created extra rows.');
            $assert($user['confirmado'] === '0' && $user['admin'] === '0',
                'New user is confirmed or privileged.');
            $assert(password_verify($oldPassword, $user['password']), 'Initial password hash mismatch.');
            $assert($user['password'] !== $oldPassword, 'Initial password was stored in plaintext.');
            $registrationToken = $user['token'];
            $assert(is_string($registrationToken) && $registrationToken !== '',
                'Registration token was not persisted.');
            $assert($client->cookie('PORT9SESSID') === $sessionId,
                'Registration unexpectedly rotated the session.');
            $harness->assertSessionIdentity($sessionId, null);
            $harness->assertFixturesUnchanged();
            $expect('registration message', $client->request('GET', '/mensaje'), 200, null);

            $expect('duplicate email', $client->request('POST', '/registro',
                array_replace($form, ['email' => $existing['email']])), 200, null,
                'El Usuario ya esta registrado');
            $assert($harness->userCount() === $initialCount + 1
                && $harness->userByEmail($email) === $user, 'Duplicate registration changed users.');
            $harness->assertFixturesUnchanged();
            $expect('unconfirmed login', $client->request('POST', '/login',
                ['email' => $email, 'password' => $oldPassword]), 200, null,
                'El Usuario No Existe o no esta confirmado');
            $harness->assertSessionIdentity($sessionId, null);

            $invalidToken = 'invalid-' . bin2hex(random_bytes(12));
            $expect('invalid confirmation', $client->request('GET',
                '/confirmar-cuenta?token=' . $invalidToken), 200, null,
                'Token No Válido, la cuenta no se confirmo');
            $assert($harness->userByEmail($email) === $user, 'Invalid confirmation changed the user.');
            $expect('valid confirmation', $client->request('GET',
                '/confirmar-cuenta?token=' . rawurlencode($registrationToken)), 200, null,
                'Cuenta Comprobada Correctamente');
            $confirmed = $harness->userByEmail($email);
            $assert($confirmed !== null && $confirmed['confirmado'] === '1'
                && $confirmed['token'] === '', 'Confirmation state or token mismatch.');
            $assert($confirmed['password'] === $user['password'], 'Confirmation changed password.');
            $harness->assertSessionIdentity($sessionId, null);
            $expect('consumed confirmation', $client->request('GET',
                '/confirmar-cuenta?token=' . rawurlencode($registrationToken)), 200, null,
                'Token No Válido, la cuenta no se confirmo');
            $assert($harness->userByEmail($email) === $confirmed,
                'Consumed confirmation token changed the user.');

            $expect('unknown recovery', $other->request('POST', '/olvide',
                ['email' => 'missing-' . bin2hex(random_bytes(8)) . '@example.invalid']),
                200, null, 'El Usuario no existe o no esta confirmado');
            $assert($harness->userByEmail($email) === $confirmed
                && $harness->userCount() === $initialCount + 1, 'Unknown recovery wrote data.');
            $expect('unconfirmed recovery', $other->request('POST', '/olvide',
                ['email' => $unconfirmed['email']]), 200, null,
                'El Usuario no existe o no esta confirmado');
            $harness->assertFixturesUnchanged();
            $expect('confirmed recovery', $client->request('POST', '/olvide',
                ['email' => $email]), 200, null,
                'Hemos enviado las instrucciones a tu email');
            $recovery = $harness->userByEmail($email);
            $assert($recovery !== null && is_string($recovery['token'])
                && $recovery['token'] !== '' && $recovery['token'] !== $registrationToken,
                'Recovery token was not freshly persisted.');
            $assert($recovery['password'] === $confirmed['password']
                && $recovery['confirmado'] === '1', 'Recovery changed credentials or confirmation.');
            $harness->assertSessionIdentity($sessionId, null);
            $resetToken = $recovery['token'];

            $expect('invalid reset', $client->request('GET',
                '/reestablecer?token=' . $invalidToken), 200, null,
                'Token No Válido, intenta de nuevo');
            $expect('invalid reset submission', $client->request('POST',
                '/reestablecer?token=' . $invalidToken, ['password' => $newPassword]),
                200, null, 'Token No Válido, intenta de nuevo');
            $assert($harness->userByEmail($email) === $recovery,
                'Invalid reset token changed the user.');
            $response = $client->request('GET', '/reestablecer?token=' . rawurlencode($resetToken));
            $expect('valid reset form', $response, 200, null);
            $assert(str_contains($response['body'], 'name="password"'),
                'Valid reset token did not show the password form.');
            $expect('valid reset', $client->request('POST',
                '/reestablecer?token=' . rawurlencode($resetToken),
                ['password' => $newPassword]), 302, '/');
            $reset = $harness->userByEmail($email);
            $assert($reset !== null && $reset['token'] === '' && $reset['confirmado'] === '1',
                'Reset did not clear the token as legacy behavior requires.');
            $assert($reset['password'] !== $recovery['password']
                && password_verify($newPassword, $reset['password'])
                && !password_verify($oldPassword, $reset['password']),
                'Reset password hash or old-password rejection mismatch.');
            $harness->assertSessionIdentity($sessionId, null);
            $response = $client->request('GET',
                '/reestablecer?token=' . rawurlencode($resetToken));
            $expect('consumed reset token', $response, 200, null,
                'Token No Válido, intenta de nuevo');
            $assert(!str_contains($response['body'], 'name="password"'),
                'Consumed reset token still shows the form.');
            $expect('consumed reset submission', $client->request('POST',
                '/reestablecer?token=' . rawurlencode($resetToken),
                ['password' => $oldPassword]), 200, null,
                'Token No Válido, intenta de nuevo');
            $assert($harness->userByEmail($email) === $reset,
                'Consumed reset token changed the user.');

            $expect('old password login', $other->request('POST', '/login',
                ['email' => $email, 'password' => $oldPassword]), 200, null,
                'Password Incorrecto');
            $otherSession = $other->cookie('PORT9SESSID');
            $assert($otherSession !== null, 'Login client session cookie missing.');
            $harness->assertSessionIdentity($otherSession, null);
            $expect('new password login', $other->request('POST', '/login',
                ['email' => $email, 'password' => $newPassword]), 302, '/finalizar-registro');
            $loggedInSession = $other->cookie('PORT9SESSID');
            $assert($loggedInSession !== null && $loggedInSession !== $otherSession,
                'Successful login did not regenerate the session.');
            $harness->assertSessionIdentity($loggedInSession,
                ['id' => (int) $reset['id'], 'email' => $email, 'admin' => 0]);
            $expect('lifecycle logout', $other->request('POST', '/logout'), 302, '/');
            $assert($other->cookie('PORT9SESSID') === null, 'Logout left a session cookie.');
            $harness->assertSessionIdentity($loggedInSession, null);
            $harness->assertFixturesUnchanged();
        },
        $email,
    );
    echo "User lifecycle HTTP checks passed: {$checks} assertions.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'User lifecycle HTTP checks failed: ' . $error->getMessage() . "\n");
    exit(1);
}

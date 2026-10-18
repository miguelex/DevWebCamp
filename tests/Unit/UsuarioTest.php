<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Usuario;
use PHPUnit\Framework\TestCase;

final class UsuarioTest extends TestCase
{
    protected function setUp(): void
    {
        (new Usuario())->validar();
    }

    protected function tearDown(): void
    {
        (new Usuario())->validar();
    }

    public function testAccountValidationAcceptsCompleteFields(): void
    {
        $user = new Usuario([
            'nombre' => 'Synthetic',
            'apellido' => 'Account',
            'email' => 'synthetic@example.invalid',
            'password' => 'synthetic-password',
            'password2' => 'synthetic-password',
        ]);

        self::assertSame([], $user->validar_cuenta());
        self::assertSame([], Usuario::getAlertas());
    }

    public function testAccountValidationRejectsMissingFieldsAndPasswordMismatch(): void
    {
        $user = new Usuario();
        $errors = $user->validar_cuenta()['error'];

        self::assertContains('El Nombre es Obligatorio', $errors);
        self::assertContains('El Apellido es Obligatorio', $errors);
        self::assertContains('El Email es Obligatorio', $errors);
        self::assertContains('El Password no puede ir vacio', $errors);

        $user->validar();
        $user->nombre = 'Synthetic';
        $user->apellido = 'Account';
        $user->email = 'synthetic@example.invalid';
        $user->password = 'short';
        $user->password2 = 'different';
        $errors = $user->validar_cuenta()['error'];

        self::assertContains('El password debe contener al menos 6 caracteres', $errors);
        self::assertContains('Los password son diferentes', $errors);
    }

    public function testLoginValidationChecksEmailAndPasswordWithoutDatabase(): void
    {
        $user = new Usuario();
        $errors = $user->validarLogin()['error'];
        self::assertContains('El Email del Usuario es Obligatorio', $errors);
        self::assertContains('Email no válido', $errors);
        self::assertContains('El Password no puede ir vacio', $errors);

        $user->validar();
        $user->email = 'not-an-email';
        $user->password = 'synthetic-password';
        self::assertContains('Email no válido', $user->validarLogin()['error']);

        $user->validar();
        $user->email = 'synthetic@example.invalid';
        self::assertSame([], $user->validarLogin());
    }

    public function testEmailValidationChecksPresenceAndFormat(): void
    {
        $user = new Usuario();
        self::assertContains('El Email es Obligatorio', $user->validarEmail()['error']);

        $user->validar();
        $user->email = 'not-an-email';
        self::assertContains('Email no válido', $user->validarEmail()['error']);

        $user->validar();
        $user->email = 'synthetic@example.invalid';
        self::assertSame([], $user->validarEmail());
    }

    public function testPasswordValidationChecksPresenceAndMinimumLength(): void
    {
        $user = new Usuario();
        self::assertContains('El Password no puede ir vacio', $user->validarPassword()['error']);

        $user->validar();
        $user->password = 'short';
        self::assertContains('El password debe contener al menos 6 caracteres', $user->validarPassword()['error']);

        $user->validar();
        $user->password = 'long-enough';
        self::assertSame([], $user->validarPassword());
    }

    public function testNewPasswordValidationChecksBothFields(): void
    {
        $user = new Usuario();
        $user->password_actual = '';
        $user->password_nuevo = '';
        $errors = $user->nuevo_password()['error'];
        self::assertContains('El Password Actual no puede ir vacio', $errors);
        self::assertContains('El Password Nuevo no puede ir vacio', $errors);

        $user->validar();
        $user->password_actual = 'current-password';
        $user->password_nuevo = 'short';
        self::assertContains('El Password debe contener al menos 6 caracteres', $user->nuevo_password()['error']);

        $user->validar();
        $user->password_nuevo = 'new-password';
        self::assertSame([], $user->nuevo_password());
    }

    public function testPasswordHashAndVerificationArePure(): void
    {
        $plain = 'synthetic-password';
        $user = new Usuario(['password' => $plain]);

        $user->hashPassword();

        self::assertNotSame($plain, $user->password);
        self::assertSame('bcrypt', password_get_info($user->password)['algoName']);
        self::assertTrue(password_verify($plain, $user->password));
        $user->password_actual = $plain;
        self::assertTrue($user->comprobar_password());
        $user->password_actual = 'wrong-password';
        self::assertFalse($user->comprobar_password());
    }

    public function testTokenGenerationReplacesPreviousToken(): void
    {
        $user = new Usuario(['token' => 'injected-token']);

        $user->crearToken();
        $first = $user->token;
        self::assertIsString($first);
        self::assertNotSame('', $first);
        self::assertNotSame('injected-token', $first);

        $user->crearToken();
        self::assertNotSame($first, $user->token);
    }
}

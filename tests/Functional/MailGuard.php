<?php

declare(strict_types=1);

namespace App\Services;

if (getenv('APP_ENV_MODE') !== 'isolated-test') {
    throw new \RuntimeException('Test-only mail guard cannot be activated.');
}

final class Email
{
    public function __construct(string $email, string $name, string $token) {}

    public function enviarConfirmacion(): void {}

    public function enviarInstrucciones(): void {}
}

header('X-Port9-Mail-Guard: active');

<?php

declare(strict_types=1);

namespace App\Services;

final class Email
{
    public static array $sent = [];

    public function __construct(
        private string $email,
        private string $name,
        private string $token,
    ) {}

    public function enviarConfirmacion(): void
    {
        self::$sent[] = ['confirmation', $this->email, $this->name, $this->token];
    }

    public function enviarInstrucciones(): void
    {
        self::$sent[] = ['recovery', $this->email, $this->name, $this->token];
    }
}

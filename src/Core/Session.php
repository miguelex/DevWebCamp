<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;

final class Session
{
    private const IDENTITY_KEYS = ['id', 'nombre', 'apellido', 'email', 'admin'];

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            throw new RuntimeException('Session cannot be started.');
        }

        if (!@session_start()) {
            throw new RuntimeException('Session could not be started.');
        }
    }

    /** @param array<string, mixed> $identity */
    public static function login(array $identity): void
    {
        self::start();

        foreach (self::IDENTITY_KEYS as $key) {
            if (!array_key_exists($key, $identity)) {
                throw new InvalidArgumentException('Incomplete session identity.');
            }
        }

        if (!@session_regenerate_id(true)) {
            throw new RuntimeException('Session ID could not be regenerated.');
        }

        $_SESSION = [];
        foreach (self::IDENTITY_KEYS as $key) {
            $_SESSION[$key] = $identity[$key];
        }
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];

        $cookieCleared = true;
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            $options = [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
            ];
            if ($params['samesite'] !== '') {
                $options['samesite'] = $params['samesite'];
            }
            $cookieCleared = setcookie(session_name(), '', $options);
        }

        $destroyed = session_destroy();
        if (!$cookieCleared || !$destroyed) {
            throw new RuntimeException('Session could not be destroyed.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

final class AccessGuard
{
    public static function authenticated(): bool
    {
        Session::start();

        if (filter_var($_SESSION['id'] ?? null, FILTER_VALIDATE_INT) !== false
            && (int) $_SESSION['id'] > 0
            && is_string($_SESSION['nombre'] ?? null)
            && $_SESSION['nombre'] !== '') {
            return true;
        }

        header('Location: /login');
        return false;
    }

    public static function administrator(): bool
    {
        if (!self::authenticated()) {
            return false;
        }

        if ((string) ($_SESSION['admin'] ?? '') === '1') {
            return true;
        }

        header('Location: /login');
        return false;
    }
}

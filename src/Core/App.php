<?php

declare(strict_types=1);

namespace App\Core;

use Dotenv\Dotenv;

final class App
{
    public static function boot(): void
    {
        $envPath = is_file(BASE_PATH . '/.env') ? BASE_PATH : BASE_PATH . '/includes';
        Dotenv::createImmutable($envPath)->safeLoad();

        require_once BASE_PATH . '/src/Helpers/funciones.php';
        require BASE_PATH . '/config/database.php';

        ActiveRecord::setDB($db);
    }
}

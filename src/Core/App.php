<?php

declare(strict_types=1);

namespace App\Core;

use Dotenv\Dotenv;

final class App
{
    public static function boot(): void
    {
        Dotenv::createImmutable(BASE_PATH . '/includes')->safeLoad();

        require_once BASE_PATH . '/includes/funciones.php';
        require BASE_PATH . '/includes/database.php';

        ActiveRecord::setDB($db);
    }
}

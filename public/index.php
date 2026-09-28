<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Router;

App::boot();

$router = new Router();

require BASE_PATH . '/config/routes.php';

$router->dispatch();

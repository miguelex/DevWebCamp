<?php

namespace App\Controllers;

use App\Core\Router;
use App\Core\AccessGuard;

class RegistradosController {
    public static function index(Router $router) {
        if (!AccessGuard::administrator()) {
            return;
        }

        // Render a la vista 
        $router->render('admin/registrados/index', [
            'titulo' => 'Registrados'
        ]);
    }
}

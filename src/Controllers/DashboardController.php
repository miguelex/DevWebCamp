<?php

namespace App\Controllers;

use App\Core\Router;

class DashboardController {
    public static function index(Router $router) {

        // Render a la vista 
        $router->render('admin/dashboard/index', [
            'titulo' => 'Panel de Administración'
        ]);
    }
}
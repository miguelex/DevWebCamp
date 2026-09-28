<?php

namespace App\Controllers;

use App\Core\Router;

class RegistradosController {
    public static function index(Router $router) {

        // Render a la vista 
        $router->render('admin/registrados/index', [
            'titulo' => 'Registrados'
        ]);
    }
}
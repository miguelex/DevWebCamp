<?php

namespace App\Core;

class Router
{
    public array $getRoutes = [];
    public array $postRoutes = [];

    public function get($url, $fn)
    {
        $this->getRoutes[$url] = $fn;
    }

    public function post($url, $fn)
    {
        $this->postRoutes[$url] = $fn;
    }

    public function dispatch()
    {

        $url_actual = $_SERVER['PATH_INFO'] ?? '/';
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method === 'GET') {
            $fn = $this->getRoutes[$url_actual] ?? null;
        } else {
            $fn = $this->postRoutes[$url_actual] ?? null;
        }

        if ($fn) {
            call_user_func($fn, $this);
        } else {
            header('Location: /404');
        }
    }

    public function render($view, $datos = [])
    {
        foreach ($datos as $key => $value) {
            $$key = $value;
        }

        ob_start();

        include_once BASE_PATH . "/views/$view.php";

        $contenido = ob_get_clean(); // Limpia el Buffer

        // Utilziar layout segun url

        $url_actual = $_SERVER['PATH_INFO'] ?? '/';

        if (str_contains($url_actual, '/admin')) {
            include_once BASE_PATH . '/views/layouts/admin.php';
            return;
        }

        include_once BASE_PATH . '/views/layouts/default.php';
    }
}
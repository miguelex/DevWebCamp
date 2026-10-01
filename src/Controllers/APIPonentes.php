<?php 

namespace App\Controllers;

use App\Models\Ponente;

class APIPonentes {
    public static function index() {
        $ponentes = Ponente::all();
        echo json_encode($ponentes);
    }
}
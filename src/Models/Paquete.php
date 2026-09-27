<?php

namespace App\Models;

use App\Core\ActiveRecord;

class Paquete extends ActiveRecord
{
    protected static $tabla = 'paquetes';
    protected static $columnasDB = ['id', 'nombre'];

    public $id;
    public $nombre;
}
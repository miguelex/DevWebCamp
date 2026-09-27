<?php 

namespace App\Models;

use App\Core\ActiveRecord;

class Hora extends ActiveRecord {
    protected static $tabla = 'horas';
    protected static $columnasDB = ['id', 'hora'];

    public $id;
    public $hora;
}
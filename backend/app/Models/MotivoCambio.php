<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Por que una linea se cotiza distinta de lo que pidio el cliente.
 *
 * Sale impreso al lado de la diferencia, asi que la lista la maneja la
 * empresa: se elige uno o se escribe, y lo escrito queda para la proxima.
 */
class MotivoCambio extends Model
{
    protected $table = 'motivos_cambio';

    protected $guarded = [];

    protected $casts = ['activo' => 'boolean'];
}

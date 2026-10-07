<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo que completa al material, la forma y la medida: "SIN COSTURA",
 * "LAMINADA", "ASTM B348". Una lista de sugerencias que crece con lo que se
 * escribe, igual que los motivos de cambio.
 */
class Caracteristica extends Model
{
    protected $table = 'caracteristicas';

    protected $fillable = ['nombre', 'orden', 'activo'];

    protected $casts = ['activo' => 'boolean'];
}

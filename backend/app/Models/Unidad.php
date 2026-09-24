<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unidad extends Model
{
    protected $table = 'unidades';

    protected $guarded = [];

    protected $casts = [
        'activo' => 'boolean',
        'sirve_para_vender' => 'boolean',
        'sirve_para_facturar' => 'boolean',
    ];
}

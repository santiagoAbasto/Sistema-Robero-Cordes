<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoMedio extends Model
{
    protected $table = 'tipos_medio';

    protected $guarded = [];

    protected $casts = ['activo' => 'boolean'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Por que no se cotiza un item. Sale impreso debajo del material. */
class MotivoNoCotiza extends Model
{
    protected $table = 'motivos_no_cotiza';

    protected $guarded = [];

    protected $casts = ['activo' => 'boolean'];
}

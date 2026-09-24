<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CondicionPago extends Model
{
    protected $table = 'condiciones_pago';

    protected $guarded = [];

    protected $casts = ['activo' => 'boolean'];
}

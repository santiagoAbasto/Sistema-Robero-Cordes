<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rubro extends Model
{
    protected $table = 'rubros';

    protected $guarded = [];

    protected $casts = ['activo' => 'boolean'];
}

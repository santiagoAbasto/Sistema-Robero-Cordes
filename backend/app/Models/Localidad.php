<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Localidad extends Model
{
    protected $table = 'localidades';

    protected $guarded = [];

    protected $casts = ['activo' => 'boolean'];

    public function provincia(): BelongsTo
    {
        return $this->belongsTo(Provincia::class);
    }
}

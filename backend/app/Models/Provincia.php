<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provincia extends Model
{
    protected $table = 'provincias';

    protected $guarded = [];

    protected $casts = ['activo' => 'boolean'];

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }

    public function localidades(): HasMany
    {
        return $this->hasMany(Localidad::class);
    }
}

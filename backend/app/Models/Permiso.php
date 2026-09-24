<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Qué fichas ve y qué puede hacer cada usuario. */
class Permiso extends Model
{
    protected $table = 'permisos';

    protected $guarded = [];

    protected $casts = [
        've_importes' => 'boolean',
        've_notas_de_otros' => 'boolean',
        'puede_modificar' => 'boolean',
        'puede_imprimir' => 'boolean',
        'puede_archivar' => 'boolean',
        've_control_cambios' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

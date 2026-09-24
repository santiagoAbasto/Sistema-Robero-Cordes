<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cada vez que se imprime o se manda una cotización.
 * Los datos elegidos acá valen solo para esa hoja: la ficha no se toca.
 */
class Impresion extends Model
{
    protected $table = 'impresiones';

    protected $guarded = [];

    protected $casts = [
        'fecha' => 'datetime',
        'vencida_al_mandar' => 'boolean',
        'incluye_importes' => 'boolean',
        'incluye_nota' => 'boolean',
    ];

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    public function contacto(): BelongsTo
    {
        return $this->belongsTo(Contacto::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}

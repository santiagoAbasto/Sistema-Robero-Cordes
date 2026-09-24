<?php

namespace App\Models;

use App\Models\Concerns\RegistraCambios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactoMedio extends Model
{
    use RegistraCambios;

    protected $table = 'contacto_medios';

    protected $guarded = [];

    protected $casts = [
        'principal' => 'boolean',
        'activo' => 'boolean',
    ];

    public function contacto(): BelongsTo
    {
        return $this->belongsTo(Contacto::class);
    }

    public function tipoMedio(): BelongsTo
    {
        return $this->belongsTo(TipoMedio::class);
    }

    public function empresaDelHistorial(): ?int
    {
        return $this->contacto?->empresa_id;
    }
}

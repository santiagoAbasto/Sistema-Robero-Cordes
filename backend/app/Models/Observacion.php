<?php

namespace App\Models;

use App\Models\Concerns\RegistraCambios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Observación 1, 2, 3… el hilo interno de una cotización. Nunca se imprime. */
class Observacion extends Model
{
    use RegistraCambios;

    protected $table = 'observaciones';

    protected $guarded = [];

    protected $casts = ['fecha' => 'datetime'];

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function empresaDelHistorial(): ?int
    {
        return $this->consulta?->empresa_id;
    }
}

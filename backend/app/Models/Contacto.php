<?php

namespace App\Models;

use App\Models\Concerns\RegistraCambios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contacto extends Model
{
    use RegistraCambios;

    protected $table = 'contactos';

    protected $guarded = [];

    protected $casts = [
        'principal' => 'boolean',
        'activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function medios(): HasMany
    {
        return $this->hasMany(ContactoMedio::class);
    }

    /** El teléfono, WhatsApp o mail principal de esta persona. */
    public function medio(string $tipo): ?ContactoMedio
    {
        return $this->medios
            ->filter(fn ($m) => $m->tipoMedio?->nombre === $tipo && $m->activo)
            ->sortByDesc('principal')
            ->first();
    }
}

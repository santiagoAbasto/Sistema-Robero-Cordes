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

    /**
     * El teléfono que sale en la hoja.
     *
     * El que se marcó como principal gana, sea fijo, celular o WhatsApp: un
     * celular marcado como principal salía igual con el fijo. Si ninguno está
     * marcado, el orden de siempre: teléfono, celular, WhatsApp.
     */
    public function telefonoParaLaHoja(): ?ContactoMedio
    {
        $telefonos = collect(['Telefono', 'Celular', 'WhatsApp'])
            ->map(fn ($tipo) => $this->medio($tipo))
            ->filter();

        return $telefonos->firstWhere('principal', true) ?? $telefonos->first();
    }
}

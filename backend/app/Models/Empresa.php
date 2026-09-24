<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\RegistraCambios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empresa extends Model
{
    use RegistraCambios;

    protected $table = 'empresas';

    protected $guarded = [];

    protected $casts = [
        'activa' => 'boolean',
    ];

    protected $appends = ['relaciones_texto'];

    // ------------------------------------------------------------------ datos

    public function localidad(): BelongsTo
    {
        return $this->belongsTo(Localidad::class);
    }

    public function provincia(): BelongsTo
    {
        return $this->belongsTo(Provincia::class);
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }

    public function rubro(): BelongsTo
    {
        return $this->belongsTo(Rubro::class);
    }

    // ------------------------------------------------------------- lo que cuelga

    public function relaciones(): HasMany
    {
        return $this->hasMany(EmpresaRelacion::class);
    }

    public function contactos(): HasMany
    {
        // El principal primero: es el que se propone al imprimir.
        return $this->hasMany(Contacto::class)
            ->orderByDesc('principal')
            ->orderBy('nombre');
    }

    public function razonesSociales(): HasMany
    {
        return $this->hasMany(RazonSocial::class)->orderByDesc('habitual');
    }

    public function enlaces(): HasMany
    {
        return $this->hasMany(EmpresaEnlace::class)->orderBy('orden');
    }

    public function campos(): HasMany
    {
        return $this->hasMany(EmpresaCampo::class)->orderBy('orden');
    }

    public function consultas(): HasMany
    {
        // Del mas nuevo al mas viejo, como en el indice.
        return $this->hasMany(Consulta::class)->orderByDesc('fecha')->orderByDesc('id');
    }

    // ------------------------------------------------------------------ ayudas

    /** El contacto que se propone al imprimir. */
    public function contactoPrincipal(): ?Contacto
    {
        return $this->contactos->firstWhere('principal', true)
            ?? $this->contactos->firstWhere('activo', true);
    }

    /** La razón social con la que se factura normalmente. */
    public function razonSocialHabitual(): ?RazonSocial
    {
        return $this->razonesSociales->firstWhere('habitual', true)
            ?? $this->razonesSociales->first();
    }

    /** "Cliente · Proveedor", para mostrar de un vistazo en las listas. */
    public function getRelacionesTextoAttribute(): string
    {
        if (! $this->relationLoaded('relaciones')) {
            return '';
        }

        return $this->relaciones->where('activa', true)->pluck('relacion')->join(' · ');
    }

    // ------------------------------------------------------------------ scopes

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    /**
     * El buscador de arriba: encuentra por nombre, CUIT, código ISIS,
     * por el teléfono o el mail de cualquiera de sus contactos, y por
     * el material de cualquiera de sus cotizaciones.
     */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($texto) {
            $q->where('nombre', 'like', "%{$texto}%")
                ->orWhere('cuit', 'like', "%{$texto}%")
                ->orWhere('codigo_isis', 'like', "%{$texto}%")
                ->orWhere('codigo_indice', 'like', "%{$texto}%")
                ->orWhereHas('contactos.medios', fn ($m) => $m->where('valor', 'like', "%{$texto}%"))
                ->orWhereHas('contactos', fn ($c) => $c->where('nombre', 'like', "%{$texto}%"))
                ->orWhereHas('razonesSociales', function ($r) use ($texto) {
                    $r->where('razon_social', 'like', "%{$texto}%")
                        ->orWhere('cuit', 'like', "%{$texto}%");
                })
                ->orWhereHas(
                    'consultas.lineas',
                    fn ($l) => $l->where('descripcion', 'like', "%{$texto}%")
                );
        });
    }

    /** Empresas que tienen esa relación (una empresa puede tener varias). */
    public function scopeConRelacion(Builder $query, ?string $relacion): Builder
    {
        if (! $relacion) {
            return $query;
        }

        return $query->whereHas(
            'relaciones',
            fn ($r) => $r->where('relacion', $relacion)->where('activa', true)
        );
    }

    /** La empresa es la ficha: su propio historial se apunta a si misma. */
    public function empresaDelHistorial(): ?int
    {
        return $this->id;
    }
}

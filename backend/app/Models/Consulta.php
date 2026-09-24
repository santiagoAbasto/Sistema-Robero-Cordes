<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\RegistraCambios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una consulta es una cotización, un pedido o una observación.
 * Es la palabra del sistema que ya usan: "Consultas por fecha".
 */
class Consulta extends Model
{
    use RegistraCambios;

    protected $table = 'consultas';

    protected $guarded = [];

    protected $casts = [
        'fecha' => 'date',
        'vence_el' => 'date',
        'solicitud_fecha' => 'date',
        'tipo_cambio' => 'decimal:4',
        'ajuste_dif_cambio' => 'boolean',
    ];

    protected $appends = ['total', 'esta_vencida', 'dias_para_vencer', 'lineas_iguales_a_lo_pedido', 'total_kilos'];

    /** Días de validez por defecto. Se define una sola vez y se puede pisar por cotización. */
    public const VALIDEZ_POR_DEFECTO = 7;

    /** Se le paso el tiempo de validez y el cliente nunca contesto. */
    public const VENCIDA = 'Vencida por tiempo';

    /** El cliente contesto que no. Es un final distinto: ya hay respuesta. */
    public const CERRADA = 'Cerrada por declinacion';

    /**
     * Los estados que puede tener una cotizacion, en el orden en que suceden.
     *
     * Esta lista es la unica: la validacion de escritura y la lista que se le
     * ofrece a la pantalla salen de aca. Antes estaba escrita cuatro veces
     * —en el enum de la base, en el catalogo, en la validacion y en el tipo
     * del front— y agregar un estado significaba acordarse de las cuatro.
     */
    public const ESTADOS = [
        'Borrador',
        'Sin cotizar',
        'Confirmada',
        'Vendida',
        self::CERRADA,
        self::VENCIDA,
    ];

    /** Los dos finales de los que no se vuelve solo: los cierra una persona. */
    public const ESTADOS_DE_CIERRE = [self::CERRADA, self::VENCIDA];

    // ------------------------------------------------------------- relaciones

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function contacto(): BelongsTo
    {
        return $this->belongsTo(Contacto::class);
    }

    public function razonSocial(): BelongsTo
    {
        return $this->belongsTo(RazonSocial::class, 'razon_social_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(ConsultaLinea::class)->orderBy('orden');
    }

    public function condiciones(): HasMany
    {
        return $this->hasMany(ConsultaCondicion::class)->orderBy('orden');
    }

    public function observaciones(): HasMany
    {
        return $this->hasMany(Observacion::class)->orderBy('numero');
    }

    public function impresiones(): HasMany
    {
        return $this->hasMany(Impresion::class)->latest('fecha');
    }

    /** De qué cotización salió, cuando se copió a otra empresa. */
    public function copiadaDe(): BelongsTo
    {
        return $this->belongsTo(Consulta::class, 'copiada_de_id');
    }

    public function copias(): HasMany
    {
        return $this->hasMany(Consulta::class, 'copiada_de_id');
    }

    /**
     * Las cotizaciones relacionadas por cómo se generó ésta, no por parecido.
     *
     * Si de A salieron B y C: en A figuran B y C; en B figuran A (de dónde
     * salió) y C (la otra que salió de la misma).
     *
     * @return array{madre: ?Consulta, hijas: \Illuminate\Support\Collection, hermanas: \Illuminate\Support\Collection}
     */
    public function familia(): array
    {
        $conDatos = fn ($q) => $q->with('empresa', 'usuario', 'lineas')->orderBy('fecha');

        $madre = $this->copiada_de_id
            ? $conDatos(static::query())->find($this->copiada_de_id)
            : null;

        $hijas = $conDatos($this->copias())->get();

        // Las que salieron de la misma original, sin contarse a sí misma.
        $hermanas = $this->copiada_de_id
            ? $conDatos(static::where('copiada_de_id', $this->copiada_de_id)
                ->where('id', '!=', $this->id))->get()
            : collect();

        return ['madre' => $madre, 'hijas' => $hijas, 'hermanas' => $hermanas];
    }

    /** El total de kilos de la cotización, para las que se facturan por peso. */
    public function getTotalKilosAttribute(): ?float
    {
        if (! $this->relationLoaded('lineas')) {
            return null;
        }

        $kilos = $this->lineas
            ->where('quitada', false)
            ->filter(fn ($l) => $l->unidadFactura?->codigo === 'KG')
            ->sum('cantidad_facturar');

        return $kilos > 0 ? round((float) $kilos, 2) : null;
    }

    // ---------------------------------------------------------------- cálculos

    /** El total de la cotización, sin contar las líneas quitadas. */
    public function getTotalAttribute(): float
    {
        if (! $this->relationLoaded('lineas')) {
            return 0.0;
        }

        return (float) $this->lineas->where('quitada', false)->sum('importe');
    }

    /**
     * Cuantos dias faltan para que venza. Negativo si ya paso.
     *
     * Null cuando no tiene vencimiento, que es el caso de las 7.148 traidas
     * del sistema anterior: nunca tuvieron validez cargada.
     */
    public function getDiasParaVencerAttribute(): ?int
    {
        return $this->vence_el === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->vence_el->startOfDay(), false);
    }

    public function getEstaVencidaAttribute(): bool
    {
        return $this->vence_el !== null && $this->vence_el->isPast();
    }

    /** "2 de 4 iguales a lo pedido" — lo que se muestra arriba de las líneas. */
    public function getLineasIgualesALoPedidoAttribute(): ?string
    {
        if (! $this->relationLoaded('lineas')) {
            return null;
        }

        $vigentes = $this->lineas->where('quitada', false);

        if ($vigentes->isEmpty()) {
            return null;
        }

        return $vigentes->where('igual_a_lo_pedido', true)->count().' de '.$vigentes->count();
    }

    /** La fecha de vencimiento sale de la fecha más los días de validez. */
    public function recalcularVencimiento(): void
    {
        if ($this->fecha && $this->validez_dias) {
            $this->vence_el = $this->fecha->copy()->addDays((int) $this->validez_dias);
        }
    }

    // ------------------------------------------------------------------ scopes

    public function scopeCotizaciones(Builder $q): Builder
    {
        return $q->where('tipo', 'Cotizacion');
    }

    public function scopePedidos(Builder $q): Builder
    {
        return $q->where('tipo', 'Pedido');
    }

    /** Los borradores no figuran en el historial ni en las búsquedas. */
    public function scopeFirmes(Builder $q): Builder
    {
        return $q->where('estado', '!=', 'Borrador');
    }

    /**
     * Todavia no vencio, pero le quedan pocos dias.
     *
     * Es la lista con la que se trabaja: llamar antes de que se caiga sola.
     * Una cotizacion ya cerrada o ya vendida no entra aunque tenga fecha: no
     * hay nada que seguir.
     */
    public function scopePorVencer(Builder $q, int $dias = 7): Builder
    {
        return $q->whereNotNull('vence_el')
            ->whereDate('vence_el', '>=', now())
            ->whereDate('vence_el', '<=', now()->addDays($dias))
            ->whereNotIn('estado', [...self::ESTADOS_DE_CIERRE, 'Vendida']);
    }

    /** Las que terminaron, por el motivo que sea. */
    public function scopeCerradas(Builder $q): Builder
    {
        return $q->whereIn('estado', self::ESTADOS_DE_CIERRE);
    }

    /** Pasaron los días de validez y nadie contestó. */
    public function scopeSinRespuesta(Builder $q): Builder
    {
        return $q->whereIn('estado', ['Confirmada', self::VENCIDA])
            ->whereNotNull('vence_el')
            ->whereDate('vence_el', '<', now());
    }
}

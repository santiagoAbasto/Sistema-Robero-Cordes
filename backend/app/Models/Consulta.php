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
        'revision' => 'integer',
        'emitida_el' => 'datetime',
    ];

    protected $appends = ['total', 'esta_vencida', 'dias_para_vencer', 'lineas_iguales_a_lo_pedido', 'total_kilos'];

    /**
     * Le pone el numero, si todavia no lo tiene.
     *
     * Correlativo por anio: 2026-0001, 2026-0002. El anio sale de la fecha de
     * la cotizacion y no del dia en que se numera, asi una de diciembre que se
     * imprime en enero sigue siendo del anio en que se hizo.
     *
     * NO se numera al crear: copiar una cotizacion a veinte empresas deja
     * veinte borradores, y la mitad se descarta. Se numera cuando la
     * cotizacion sale —al imprimirla— o cuando deja de ser un borrador. Un
     * numero quemado es un agujero en la correlatividad que despues alguien
     * tiene que explicar.
     *
     * Las que vinieron del Access no se numeran: su numero es el id_sistema
     * que ya esta impreso en los papeles que tiene el cliente.
     */
    public function numerar(): ?string
    {
        // Una observacion de la empresa no se le manda al cliente: no lleva
        // numero de la serie de las cotizaciones, llegue por donde llegue
        // (emitir, cambiar el estado).
        if ($this->tipo === 'Observacion') {
            return null;
        }

        if (filled($this->numero)) {
            return $this->numero;
        }

        // Una revision no se gana un numero nuevo: lleva el de su familia.
        // Numerarla aparte partiria la misma cotizacion en dos.
        if ($this->revision_de_id) {
            return $this->numerarComoRevision();
        }

        $anio = ($this->fecha ?? now())->format('Y');

        /*
          Dos personas guardando al mismo tiempo pueden pedir el mismo numero.
          La columna es unica, asi que el segundo choca: se reintenta con el
          siguiente en vez de fallar la impresion.
        */
        for ($intento = 0; $intento < 5; $intento++) {
            $ultimo = static::query()
                ->where('numero', 'like', $anio.'-%')
                ->orderByDesc('numero')
                ->value('numero');

            $siguiente = $ultimo ? ((int) substr($ultimo, 5)) + 1 : 1;
            $numero = $anio.'-'.str_pad((string) ($siguiente + $intento), 4, '0', STR_PAD_LEFT);

            try {
                static::whereKey($this->id)->update(['numero' => $numero]);
                $this->numero = $numero;

                return $numero;
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new \RuntimeException('No se pudo asignar un numero de cotizacion.');
    }

    /**
     * El numero de la familia y la revision siguiente: 2026-0001 R1, R2.
     *
     * Dos personas emitiendo revisiones de la misma cotizacion a la vez piden
     * la misma revision. El par numero+revision es unico, asi que la segunda
     * choca y se reintenta con la siguiente.
     */
    private function numerarComoRevision(): string
    {
        $numero = static::find($this->revision_de_id)?->numerar()
            ?? throw new \RuntimeException('La revision no tiene de que cotizacion salio.');

        for ($intento = 0; $intento < 5; $intento++) {
            $revision = (int) static::where('numero', $numero)->max('revision') + 1 + $intento;

            try {
                static::whereKey($this->id)->update(['numero' => $numero, 'revision' => $revision]);
                $this->numero = $numero;
                $this->revision = $revision;

                return $numero;
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new \RuntimeException('No se pudo asignar la revision de la cotizacion.');
    }

    // ------------------------------------------------------------- revisiones

    /**
     * Si ya salio. Emitida no se toca: para cambiarla se hace una revision.
     *
     * "Si ya la emitiste no la podes modificar, pero si te deja como base y
     * hace un borrador". Lo que se le mando al cliente tiene que quedar como
     * se mando: si se pudiera editar, la hoja que tiene el cliente y la que
     * esta en el sistema dejarian de coincidir sin que nadie lo note.
     */
    public function estaEmitida(): bool
    {
        return $this->emitida_el !== null;
    }

    /** "2026-0001 R0". Null mientras no tenga numero. */
    public function numeroConRevision(): ?string
    {
        return filled($this->numero) ? $this->numero.' R'.(int) $this->revision : null;
    }

    /** El id de la R0: la primera de la familia. */
    public function raizId(): int
    {
        return $this->revision_de_id ?? $this->id;
    }

    /** Todas las revisiones de esta cotizacion, de la R0 a la ultima. */
    public function versiones(): Builder
    {
        $raiz = $this->raizId();

        return static::query()
            ->where(fn ($q) => $q->whereKey($raiz)->orWhere('revision_de_id', $raiz))
            ->orderBy('revision')
            ->orderBy('id');
    }

    /**
     * La emite: le pone el numero y la revision, y la congela.
     *
     * Emitir es mandarla. Por eso va junto con imprimir: "Emitir e imprimir".
     * Se guarda quien y cuando, que es lo que despues se pregunta.
     */
    public function emitir(?int $usuarioId): void
    {
        if ($this->estaEmitida()) {
            return;
        }

        $this->numerar();

        /*
          Lo emitido ya es firme. Una revision o una copia nacen en Borrador, y
          si quedaran asi despues de emitirse, la ficha, Consultas por fecha y
          Seguimiento —que esconden los borradores— no las mostrarian: una R1
          mandada al cliente no aparecia en ningun lado.
        */
        $this->forceFill([
            'emitida_el' => now(),
            'emitida_por' => $usuarioId,
            'estado' => $this->estado === 'Borrador' ? 'Confirmada' : $this->estado,
        ])->save();
    }

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

    /**
     * Por donde llego la consulta.
     *
     * Una sola lista: la que se ofrece en la pantalla y la que acepta el
     * guardado. Estaban escritas por separado, y al sumar "Web" a la de la
     * pantalla la otra la rechazaba: elegirla y guardar daba error.
     */
    public const VIAS = ['Mail', 'WhatsApp', 'Web', 'Telefono', 'En persona'];

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

    /** Quien la emitio. Puede no ser quien la cargo. */
    public function emisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitida_por');
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
        // Una observacion no es una oferta: no vence. Con los 7 dias de una
        // cotizacion aparecia en Seguimiento como por vencer.
        if ($this->tipo === 'Observacion') {
            $this->vence_el = null;

            return;
        }

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

<?php

namespace App\Models;

use App\Models\Concerns\RegistraCambios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una línea de la cotización.
 *
 * Guarda tres cosas que antes iban mezcladas en un solo texto:
 *  · lo que pidió el cliente (forma, material, medida)
 *  · si se cotiza tal cual o distinto, y por qué
 *  · en qué unidad se vende y en cuál se factura, con el factor que las relaciona
 */
class ConsultaLinea extends Model
{
    use RegistraCambios;

    protected $table = 'consulta_lineas';

    /** Por donde viaja lo importado, cuando importa: cambia el plazo y el precio. */
    public const TRANSPORTES = ['Marítimo', 'Aéreo'];

    /**
     * Lo que decide el servidor no entra por fill().
     *
     * El navegador ya no puede mandar estos campos porque la validación no los
     * acepta, pero esa lista es una sola cerradura: alcanza con que alguien
     * agregue una regla para que se abran todos. Estos son la foto del cálculo
     * y la auditoría del factor — quién lo cargó y cuándo. El servidor los
     * escribe uno por uno ($linea->peso_kg = ...), y copiar una cotización usa
     * replicate(), así que ninguno de los dos pasa por acá.
     *
     * consulta_id no está en la lista y no hace falta: al guardar se le asigna
     * la cotización de la ruta después del fill, así que lo que venga del
     * navegador se pisa igual. Ponerlo acá rompería los ConsultaLinea::create()
     * de seeders y pruebas sin agregar nada.
     */
    protected $guarded = [
        'calculo',
        'peso_kg',
        'factor_calculado',
        'origen_factor',
        'factor_cargado_por',
        'factor_cargado_el',
    ];

    protected $casts = [
        'igual_a_lo_pedido' => 'boolean',
        'aprox' => 'boolean',
        'idem' => 'boolean',
        'desde_stock' => 'boolean',
        'quitada' => 'boolean',
        // Si el factor lo saco la cuenta o lo puso una persona.
        'factor_calculado' => 'boolean',
        'cantidad' => 'decimal:2',
        'cantidad_pedida' => 'decimal:2',
        'cantidad_facturar' => 'decimal:2',
        'factor_conversion' => 'decimal:4',
        'precio_unitario' => 'decimal:2',
        'precio_por_kilo' => 'decimal:2',
        'importe' => 'decimal:2',
        'diametro_mm' => 'decimal:2',
        'largo_mm' => 'decimal:2',
        // Los extremos del rango, cuando el largo es variable. El que usan el
        // peso y el factor sigue siendo largo_mm, con el promedio adentro.
        'largo_min_mm' => 'decimal:2',
        'largo_max_mm' => 'decimal:2',
        'espesor_mm' => 'decimal:2',
        // La foto del calculo de peso: medidas, unidades, densidad y formula
        // usadas el dia que se cotizo.
        'calculo' => 'array',
        // Las medidas de lo que pidio el cliente, con la misma estructura que
        // las de la calculadora: clave, valor y unidad.
        'pedido_medidas' => 'array',
        'peso_kg' => 'decimal:3',
        'factor_cargado_el' => 'datetime',
    ];

    /**
     * De dónde salió el factor.
     *
     * Depende de la operación que se usó, no del valor: que un número coincida
     * con el que da la fórmula no prueba de dónde vino.
     */
    public const FACTOR_CALCULADORA = 'calculadora';

    public const FACTOR_MANUAL = 'manual';

    /** Los motivos salen de una lista para poder contarlos después. Siempre se puede escribir otro. */
    public const MOTIVOS = [
        'No hay esa medida',
        'Largo comercial',
        'Se aprovecha mejor el material',
        'Minimo de compra',
        'Se sugiere otra calidad',
        'A pedido del cliente',
        'Otro',
    ];

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /** Quien dejo el factor cargado a mano. Vacio si es el calculado. */
    public function factorCargadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'factor_cargado_por');
    }

    public function forma(): BelongsTo
    {
        return $this->belongsTo(Forma::class);
    }

    /**
     * Las alternativas: el mismo item ofrecido de otra manera.
     *
     * Por avion en vez de barco, otra cantidad, otro material, una medida
     * cercana ("piden 50,8, se ofrece Ø50"). Cada una es una linea completa
     * que cuelga de esta: en la hoja va como 1.1, 1.2, y no suma al total.
     */
    public function alternativas(): HasMany
    {
        return $this->hasMany(self::class, 'alternativa_de_id')->orderBy('orden')->orderBy('id');
    }

    public function madre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'alternativa_de_id');
    }

    public function esAlternativa(): bool
    {
        return $this->alternativa_de_id !== null;
    }

    /** Lo que suma: lo que no se quito, sin las alternativas, que se ofrecen para elegir. */
    public function cuentaParaElTotal(): bool
    {
        return ! $this->quitada && ! $this->esAlternativa();
    }

    public function unidadPedida(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_pedida_id');
    }

    public function unidadVenta(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_venta_id');
    }

    public function unidadFactura(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_factura_id');
    }

    /** ¿Se cotiza en una unidad y se factura en otra? */
    /**
     * El largo es variable cuando estan cargados los dos extremos del rango.
     *
     * Las barras y los canos no vienen todos del mismo largo: se ofrecen "de
     * 2,80 a 3,20 m". No hay un tilde aparte para esto a proposito — podria
     * quedar marcado con el rango vacio, y habria que decidir cual gana.
     */
    public function largoEsVariable(): bool
    {
        return $this->largo_min_mm !== null && $this->largo_max_mm !== null;
    }

    /** El largo con el que se calcula cuando hay rango: el promedio. */
    public function largoPromedioMm(): ?float
    {
        return $this->largoEsVariable()
            ? ((float) $this->largo_min_mm + (float) $this->largo_max_mm) / 2
            : null;
    }

    public function cambiaDeUnidad(): bool
    {
        return $this->unidad_factura_id !== null
            && $this->unidad_venta_id !== null
            && $this->unidad_factura_id !== $this->unidad_venta_id;
    }

    /**
     * Rehace las cantidades y el importe.
     *
     * Cuando se cotiza por metro y se factura por kilo, el importe sale de los
     * kilos: cantidad × factor = kilos, y kilos × precio por kilo = importe.
     * Si no hay cambio de unidad, es cantidad × precio unitario, como siempre.
     */
    public function recalcular(): void
    {
        if ($this->cambiaDeUnidad() && $this->factor_conversion) {
            $this->cantidad_facturar = round((float) $this->cantidad * (float) $this->factor_conversion, 2);

            if ($this->precio_por_kilo) {
                $this->importe = round((float) $this->cantidad_facturar * (float) $this->precio_por_kilo, 2);
                // El precio por unidad de venta queda calculado, no se carga.
                $this->precio_unitario = round((float) $this->factor_conversion * (float) $this->precio_por_kilo, 2);

                return;
            }

            // Se puede entrar por cualquiera de los dos: si cargaron el precio
            // por metro, el precio por kilo sale de ahí.
            if ($this->precio_unitario) {
                $this->precio_por_kilo = round((float) $this->precio_unitario / (float) $this->factor_conversion, 2);
                $this->importe = round((float) $this->cantidad * (float) $this->precio_unitario, 2);
            }

            return;
        }

        $this->cantidad_facturar = $this->cantidad;

        // Sin cantidad no hay importe que calcular, y el que ya estaba no se
        // pisa con un cero: las lineas historicas del sistema anterior no
        // traen cantidad, y guardar la cotizacion les borraba el importe.
        if ($this->cantidad === null) {
            return;
        }

        $this->importe = round((float) $this->cantidad * (float) $this->precio_unitario, 2);
    }

    /** Lo que el cliente había pedido, en una línea. Se muestra cuando no coincide. */
    public function getPedidoTextoAttribute(): ?string
    {
        $partes = array_filter([
            $this->pedido_forma, $this->pedido_material, $this->pedido_dimensiones, $this->pedido_caracteristicas,
        ]);

        return $partes ? implode('  ·  ', $partes) : null;
    }

    public function empresaDelHistorial(): ?int
    {
        return $this->consulta?->empresa_id;
    }
}

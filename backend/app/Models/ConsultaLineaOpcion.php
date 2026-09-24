<?php

namespace App\Models;

use App\Models\Concerns\RegistraCambios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una alternativa de la línea: el mismo ítem cotizado de otra manera.
 *
 * Pisa solo lo que cambia. Todo lo que deja vacío lo hereda de la línea, así
 * que una alternativa de precio no repite la medida ni la cantidad.
 */
class ConsultaLineaOpcion extends Model
{
    use RegistraCambios;

    protected $table = 'consulta_linea_opciones';

    protected $guarded = [];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'precio_por_kilo' => 'decimal:2',
        'es_base' => 'boolean',
    ];

    /** Por qué es distinta. Se elige de la lista para poder agruparlas después. */
    public const TIPOS = ['Transporte', 'Cantidad', 'Material', 'Plazo', 'Otra'];

    public function linea(): BelongsTo
    {
        return $this->belongsTo(ConsultaLinea::class, 'consulta_linea_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    // ------------------------------------------------- lo que hereda o pisa

    public function laCantidad(): ?float
    {
        return $this->cantidad !== null
            ? (float) $this->cantidad
            : ($this->linea?->cantidad !== null ? (float) $this->linea->cantidad : null);
    }

    public function elPrecio(): ?float
    {
        return $this->precio_unitario !== null
            ? (float) $this->precio_unitario
            : ($this->linea?->precio_unitario !== null ? (float) $this->linea->precio_unitario : null);
    }

    public function elPrecioPorKilo(): ?float
    {
        return $this->precio_por_kilo !== null
            ? (float) $this->precio_por_kilo
            : ($this->linea?->precio_por_kilo !== null ? (float) $this->linea->precio_por_kilo : null);
    }

    public function laDescripcion(): ?string
    {
        return $this->descripcion ?: $this->linea?->descripcion;
    }

    /**
     * Lo que sale esta alternativa.
     *
     * Sigue la misma cuenta que la línea: si se vende por metro y se factura
     * por kilo, el importe sale de los kilos.
     */
    public function getImporteAttribute(): float
    {
        $cantidad = $this->laCantidad() ?? 0;
        $linea = $this->linea;

        if ($linea?->cambiaDeUnidad() && $linea->factor_conversion && $this->elPrecioPorKilo()) {
            return round($cantidad * (float) $linea->factor_conversion * $this->elPrecioPorKilo(), 2);
        }

        return round($cantidad * ($this->elPrecio() ?? 0), 2);
    }

    public function empresaDelHistorial(): ?int
    {
        return $this->linea?->consulta?->empresa_id;
    }
}

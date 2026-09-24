<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un caño de medida comercial.
 *
 * El caño no se mide: se pide por pulgada y schedule. El diametro exterior y
 * la pared salen de la norma, no de lo que alguien recuerde.
 */
class CanoEstandar extends Model
{
    protected $table = 'canos_estandar';

    protected $guarded = [];

    protected $casts = [
        'diametro_mm' => 'decimal:2',
        'pared_mm' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function getEtiquetaAttribute(): string
    {
        return $this->nombre.' · SCH '.$this->schedule;
    }
}

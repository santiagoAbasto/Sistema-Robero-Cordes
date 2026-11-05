<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un comprobante de la compra: factura, remito, nota de crédito, vale de retiro... */
class ConsultaComprobante extends Model
{
    protected $table = 'consulta_comprobantes';

    protected $guarded = [];

    protected $casts = ['fecha' => 'date', 'importe' => 'decimal:2'];

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }
}

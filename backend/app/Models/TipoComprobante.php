<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Los tipos de comprobante de una compra (factura, remito, nota de crédito...). Se edita en Configuración. */
class TipoComprobante extends Model
{
    protected $table = 'tipos_comprobante';

    protected $guarded = [];

    protected $casts = ['activo' => 'boolean'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Moneda extends Model
{
    protected $table = 'monedas';

    protected $guarded = [];

    protected $casts = [
        'activo' => 'boolean',
        'lleva_conversion' => 'boolean',
        'por_defecto' => 'boolean',
    ];

    /**
     * El simbolo que va delante del importe.
     *
     * Sale de la moneda base y no del nombre: "DOLAR BILLETE BNA VENDEDOR" y
     * "DOLAR LIBRE" son dos referencias de tipo de cambio distintas, pero las
     * dos se escriben US$. La referencia sale aparte, en el encabezado.
     *
     * US$ y no $ a secas: es como lo escribe la empresa en sus cotizaciones, y
     * en un mercado donde tambien se factura en pesos el $ solo es ambiguo.
     */
    public function simbolo(): string
    {
        return match ($this->moneda_base) {
            'Dolar' => 'US$',
            'Euro' => '€',
            'Peso argentino' => '$',
            default => '',
        };
    }
}

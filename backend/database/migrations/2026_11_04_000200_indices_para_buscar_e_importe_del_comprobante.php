<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cimientos del buscador único (F1) y el importe de cada comprobante.
 *
 * ÍNDICES. El buscador va a poder preguntar por el número de la orden de compra
 * del cliente, por factura, remito o pedido, por la fecha de cada uno y por un
 * rango de diámetros. Sin índice cada una de esas preguntas recorre las 145.000
 * consultas o las 232.000 líneas enteras: andan, pero no a la velocidad de una
 * pantalla de búsqueda. Sólo se indexa lo que se va a preguntar de verdad, y no
 * el texto libre de las descripciones: ése necesita otra herramienta (un LIKE
 * con comodín al principio no usa un índice común).
 *
 * IMPORTE. Cada comprobante puede decir cuánto es. Sin eso los reportes de
 * "lo facturado" no tienen de dónde sumar: el único importe de una venta era el
 * total de las líneas compradas, que no es lo mismo que lo que dice la factura
 * de anticipo, la de saldo o la nota de crédito. Opcional: los que ya están
 * cargados quedan sin importe y nadie está obligado a completarlo.
 *
 * Se puede correr dos veces: cada paso mira primero si ya está hecho.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> tabla => [nombre del índice => columnas] */
    private const INDICES = [
        'consultas' => [
            'consultas_solicitud_nro_idx' => ['solicitud_nro'],
            'consultas_nro_factura_idx' => ['nro_factura'],
            'consultas_nro_remito_idx' => ['nro_remito'],
            'consultas_nro_pedido_interno_idx' => ['nro_pedido_interno'],
            'consultas_fecha_factura_idx' => ['fecha_factura'],
            'consultas_fecha_remito_idx' => ['fecha_remito'],
            'consultas_fecha_pedido_idx' => ['fecha_pedido'],
        ],
        'consulta_comprobantes' => [
            'consulta_comprobantes_numero_idx' => ['numero'],
            'consulta_comprobantes_fecha_idx' => ['fecha'],
        ],
        'consulta_lineas' => [
            'consulta_lineas_diametro_idx' => ['diametro_mm'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('consulta_comprobantes', 'importe')) {
            Schema::table('consulta_comprobantes', function (Blueprint $tabla) {
                $tabla->decimal('importe', 14, 2)->nullable()->after('fecha');
            });
        }

        foreach (self::INDICES as $tabla => $indices) {
            foreach ($indices as $nombre => $columnas) {
                if (Schema::hasIndex($tabla, $nombre)) {
                    continue;
                }

                Schema::table($tabla, fn (Blueprint $t) => $t->index($columnas, $nombre));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as $tabla => $indices) {
            foreach (array_keys($indices) as $nombre) {
                if (Schema::hasIndex($tabla, $nombre)) {
                    Schema::table($tabla, fn (Blueprint $t) => $t->dropIndex($nombre));
                }
            }
        }

        if (Schema::hasColumn('consulta_comprobantes', 'importe')) {
            Schema::table('consulta_comprobantes', fn (Blueprint $t) => $t->dropColumn('importe'));
        }
    }
};

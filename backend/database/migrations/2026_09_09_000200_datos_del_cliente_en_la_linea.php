<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el cliente usa para reconocer cada item.
 *
 * Pedido por CORDES el 09-09-2026, a partir del caso Profertil: el cliente
 * mando "Plano SUO1413884/1 posición 21 y 22" y eso terminaba mezclado dentro
 * de la descripcion.
 *
 *  · codigo_cliente — el codigo de articulo con el que ese cliente lo llama.
 *    Es lo que despues aparece en su orden de compra y en su remito.
 *  · item_cliente   — el numero de item dentro de SU requerimiento, para que
 *    la oferta se pueda comparar renglon contra renglon con lo que pidieron.
 *  · nota           — datos extra del producto: plano, posicion, tratamiento,
 *    lo que haga falta aclarar de ESE articulo. "Cada artículo debiera tener
 *    su nota."
 *
 * La nota es del articulo y sale impresa, a diferencia de la NOTA de la
 * cotizacion, que es interna. Son cosas distintas y por eso no comparten campo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $table) {
            $table->string('codigo_cliente', 60)->nullable()->after('descripcion');
            $table->string('item_cliente', 20)->nullable()->after('codigo_cliente');
            $table->text('nota')->nullable()->after('item_cliente');
        });
    }

    public function down(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $table) {
            $table->dropColumn(['codigo_cliente', 'item_cliente', 'nota']);
        });
    }
};

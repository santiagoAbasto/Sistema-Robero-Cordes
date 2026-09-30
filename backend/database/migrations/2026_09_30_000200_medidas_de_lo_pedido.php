<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las medidas de lo que pidio el cliente, campo por campo.
 *
 * Estaban en pedido_dimensiones, una caja de texto libre donde cada uno
 * escribia lo que le parecia: "DIA 65 X 145", "Ø65x145mm", "65 x 145". Al lado,
 * lo que se cotiza tiene un campo por medida, con el nombre que le pone la
 * forma —Diametro, Largo, Piezas para una barra; Ancho y Largo para una chapa—
 * asi que las dos mitades de la misma linea no se podian comparar de un
 * vistazo, que es justamente para lo que esta el bloque.
 *
 * Se guardan con la misma estructura que las de la calculadora —clave, valor y
 * unidad— para poder armar el texto impreso con la misma funcion y no tener
 * dos formas de escribir la misma medida.
 *
 * pedido_dimensiones se conserva: sigue siendo lo que sale impreso, y las
 * lineas viejas que no tienen las medidas sueltas lo siguen usando tal cual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $tabla) {
            $tabla->json('pedido_medidas')->nullable()->after('pedido_dimensiones');
        });
    }

    public function down(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $tabla) {
            $tabla->dropColumn('pedido_medidas');
        });
    }
};

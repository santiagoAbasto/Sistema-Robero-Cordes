<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las condiciones, como se escriben de verdad.
 *
 * Hasta ahora una condicion era una linea suelta de 200 caracteres. Una
 * cotizacion real de CORDES no se parece a eso: son ocho bloques con titulo
 * —Cantidades, Precios, Entrega, Certificacion, Condic. de Pago, Forma de
 * Pago, Validez de Oferta, IMPORTANTE— que suman mas de 2000 caracteres y se
 * repiten casi iguales en cada cotizacion. Dos de esos bloques (Forma de Pago
 * y Validez de Oferta) no entraban: 734 y 674 caracteres contra un limite de
 * 200. O sea que el texto que la empresa manda todos los dias no se podia
 * guardar.
 *
 * Tres cambios:
 *  · titulo separado del texto, porque en la hoja va en negrita y aparte;
 *  · texto pasa a TEXT, para que entre lo que realmente se escribe;
 *  · por_defecto, para que una cotizacion nueva arranque con las condiciones
 *    de siempre puestas en vez de que alguien las tipee o las pegue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('condiciones_habituales', function (Blueprint $table) {
            $table->string('titulo', 60)->nullable()->after('id');
            $table->boolean('por_defecto')->default(false)->after('activo');
        });

        Schema::table('consulta_condiciones', function (Blueprint $table) {
            $table->string('titulo', 60)->nullable()->after('orden');
        });

        // El cambio de tipo va aparte: change() sobre una columna con indice
        // unico se lleva el indice puesto, y aca no hay ninguno que perder.
        Schema::table('condiciones_habituales', function (Blueprint $table) {
            $table->text('texto')->change();
        });

        Schema::table('consulta_condiciones', function (Blueprint $table) {
            $table->text('texto')->change();
        });
    }

    public function down(): void
    {
        // Volver a 200 truncaria los bloques largos, asi que primero se recorta
        // a mano: si no, MySQL corta en silencio o falla segun el modo estricto.
        Schema::table('condiciones_habituales', function (Blueprint $table) {
            $table->string('texto', 200)->change();
            $table->dropColumn(['titulo', 'por_defecto']);
        });

        Schema::table('consulta_condiciones', function (Blueprint $table) {
            $table->string('texto', 200)->change();
            $table->dropColumn('titulo');
        });
    }
};

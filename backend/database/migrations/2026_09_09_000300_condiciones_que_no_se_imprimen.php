<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hay condiciones que son para adentro.
 *
 * En las cotizaciones viejas aparecen renglones como "1RA FILA X 1.10": no son
 * condiciones comerciales, son referencias que la empresa usa para cruzar con
 * su otro sistema. Estaban saliendo impresas en la hoja del cliente, que es
 * justo donde no tienen que estar.
 *
 * Ahora cada condicion dice si sale en el papel. Por defecto sale, que es lo
 * que se espera de una condicion; las internas se marcan y quedan a la vista
 * en la pantalla pero fuera del PDF.
 */
return new class extends Migration
{
    /** Los renglones que la empresa marcó como referencias de su otro sistema. */
    private const INTERNAS = ['1RA FILA', '2DA FILA', '3RA FILA'];

    public function up(): void
    {
        foreach (['condiciones_habituales', 'consulta_condiciones'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->boolean('imprime')->default(true)->after('texto');
            });
        }

        // Las que ya estaban cargadas y son referencias internas se marcan
        // solas: si no, la proxima impresion las vuelve a sacar en la hoja.
        foreach (self::INTERNAS as $patron) {
            DB::table('consulta_condiciones')
                ->where('texto', 'like', $patron.'%')
                ->update(['imprime' => false]);
        }
    }

    public function down(): void
    {
        foreach (['condiciones_habituales', 'consulta_condiciones'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('imprime');
            });
        }
    }
};

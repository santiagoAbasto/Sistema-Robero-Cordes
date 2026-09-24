<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De donde salio el factor de una linea.
 *
 *  · calculadora — lo genero y lo aplico el servidor
 *  · manual      — lo escribio una persona
 *
 * Antes se deducia comparando el valor contra el que da la formula, y eso
 * estaba mal: que un numero coincida no prueba de donde vino. Si alguien
 * escribe a mano exactamente 8,8554, es un factor cargado a mano y tiene que
 * quedar auditado como tal — es justamente el caso que hay que poder revisar.
 *
 * Ahora el origen depende de la operacion que se uso, no del valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $table) {
            $table->string('origen_factor', 12)->nullable()->after('factor_calculado');
        });

        // Lo que ya estaba: se respeta la marca vieja, que es lo unico que hay.
        DB::table('consulta_lineas')->whereNotNull('factor_conversion')
            ->update(['origen_factor' => DB::raw("case when factor_calculado = 1 then 'calculadora' else 'manual' end")]);
    }

    public function down(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $table) {
            $table->dropColumn('origen_factor');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quien dejo cargado a mano el factor de una linea, y cuando.
 *
 * Ya se sabia SI un factor era calculado o cargado a mano (factor_calculado),
 * pero no quien lo puso. Cuando un peso a mano termina en una factura y el
 * numero no cierra, "lo cargo alguien" no alcanza: hay que poder preguntarle
 * a esa persona de donde lo saco.
 *
 * Se llena solo cuando el factor NO coincide con el que da la cuenta. Si
 * coincide, es el calculado y estas dos columnas quedan vacias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $table) {
            $table->foreignId('factor_cargado_por')
                ->nullable()
                ->after('factor_calculado')
                ->constrained('users');

            $table->timestamp('factor_cargado_el')->nullable()->after('factor_cargado_por');
        });
    }

    public function down(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('factor_cargado_por');
            $table->dropColumn('factor_cargado_el');
        });
    }
};

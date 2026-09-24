<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las condiciones vienen en juegos: importacion o stock.
 *
 * No hay un juego por defecto. CORDES lo dijo claro el 09-09-2026: "La entrega
 * varía según si es stock o importación". Son 120 dias corridos contra 2 a 4
 * dias habiles, y anticipo del 50% contra contado contra entrega. Poner uno de
 * los dos como default seria mandar la mitad de las ofertas con el plazo y la
 * forma de pago del otro caso.
 *
 * Asi que la cotizacion guarda cual eligieron, y hasta que elijan no se carga
 * ninguno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('condiciones_habituales', function (Blueprint $table) {
            $table->string('juego', 20)->nullable()->after('titulo');
        });

        Schema::table('consultas', function (Blueprint $table) {
            $table->string('juego_condiciones', 20)->nullable()->after('validez_dias');
        });

        // Lo que ya estaba cargado era el texto de una cotizacion de
        // importacion (Profertil): queda marcado como tal en vez de perderse.
        DB::table('condiciones_habituales')
            ->whereNotNull('titulo')
            ->update(['juego' => 'Importacion']);
    }

    public function down(): void
    {
        Schema::table('condiciones_habituales', function (Blueprint $table) {
            $table->dropColumn('juego');
        });

        Schema::table('consultas', function (Blueprint $table) {
            $table->dropColumn('juego_condiciones');
        });
    }
};

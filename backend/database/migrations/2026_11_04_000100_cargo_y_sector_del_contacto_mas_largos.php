<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El cargo y el sector de un contacto entran hasta 150 caracteres (antes 60).
 *
 * Roberto (04-11-2026) agregó una persona cuya firma dice "Gestión de
 * Solicitudes de Compras - Depto. Asistencia de Producción": 68 caracteres, y el
 * sistema no la dejaba guardar. Los cargos de las firmas reales son más largos
 * que 60. Ensanchar una columna no toca ningún dato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contactos', function (Blueprint $table) {
            $table->string('sector', 150)->nullable()->change();
            $table->string('cargo', 150)->nullable()->change();
        });
    }

    /**
     * No se achica: lo que ya se guardó con más de 60 caracteres se cortaría (o
     * la base se negaría a hacerlo). Dejar las columnas anchas no rompe nada.
     */
    public function down(): void
    {
        //
    }
};

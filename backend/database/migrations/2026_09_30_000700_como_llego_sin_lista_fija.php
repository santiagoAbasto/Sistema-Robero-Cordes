<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Como llego" deja de ser una lista fija en la base.
 *
 * Era un ENUM de MySQL con Mail, WhatsApp, Telefono y En persona. El
 * 30-09-2026 se sumo "Web" —lo pidieron dos veces— a la lista de la pantalla
 * y a la del guardado, pero no a esta: la base rechazaba el valor y guardar
 * una consulta que llego por la web daba error. Las pruebas no lo vieron
 * porque corren en SQLite, que no hace cumplir los ENUM.
 *
 * Es lo mismo que ya se hizo con los estados de la cotizacion: la lista vive
 * en un solo lugar, Consulta::VIAS, que es la que valida el guardado. La base
 * guarda el texto. Agregar una via es tocar una linea, no tres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->string('solicitud_via', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->enum('solicitud_via', ['Mail', 'WhatsApp', 'Telefono', 'En persona'])->nullable()->change();
        });
    }
};

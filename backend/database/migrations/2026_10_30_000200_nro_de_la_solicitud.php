<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
  El numero de la solicitud de cotizacion del cliente (pedido de Roberto,
  30-10-2026).

  Cuando la solicitud viene de un portal o de una licitacion trae su propio
  numero de pedido ("nro de pedido de cotizacion"). Va con el resto de "lo que
  pidio el cliente": no se imprime en la hoja, sirve para cruzar la cotizacion
  con el requerimiento del cliente. Columna propia, nullable.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->string('solicitud_nro', 60)->nullable()->after('solicitud_via');
        });
    }

    public function down(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->dropColumn('solicitud_nro');
        });
    }
};

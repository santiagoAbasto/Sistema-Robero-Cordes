<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
  Una linea que no se cotiza, con su motivo.

  Hay items que por una razon u otra no se trabajan —no es el material, el
  plazo, la medida—. Antes esa linea se quitaba y la razon se perdia. Ahora
  queda en la cotizacion, marcada "no se cotiza", con el motivo: sale impreso,
  no suma al total, y despues se junta todo para ver que no cotizamos y por que.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $table) {
            $table->boolean('no_cotizada')->default(false)->after('quitada');
            $table->string('motivo_no_cotiza', 120)->nullable()->after('no_cotizada');
        });
    }

    public function down(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $table) {
            $table->dropColumn(['no_cotizada', 'motivo_no_cotiza']);
        });
    }
};

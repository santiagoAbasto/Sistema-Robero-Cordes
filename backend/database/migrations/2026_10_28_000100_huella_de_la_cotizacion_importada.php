<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
  La huella de cada cotizacion que vino del Access.

  El Access no le da numero a una cotizacion: se la reconoce por empresa,
  fecha y los cinco renglones. Pero esos tres se pueden corregir en el
  sistema nuevo —la propia importacion pide arreglar a mano las fechas
  imposibles—, y una cotizacion corregida ya no se reconocia: al sumar el
  indice otra vez, entraba de nuevo.

  La huella se calcula con la fila del Access al importarla y no cambia
  aunque se edite la cotizacion.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->string('origen_clave', 32)->nullable()->index()->after('id_sistema');
        });
    }

    public function down(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->dropIndex(['origen_clave']);
            $tabla->dropColumn('origen_clave');
        });
    }
};

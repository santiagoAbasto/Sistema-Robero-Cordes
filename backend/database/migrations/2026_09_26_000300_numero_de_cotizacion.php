<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
  El numero de la cotizacion.

  "Imprimi cotizacion y no salio numerada": no salia porque no existia. Lo
  unico parecido era id_sistema, que es el codigo que traia cada cotizacion
  del Access, tipeado a mano (16677, 3805) y con repetidos.

  Correlativo por anio, como pidio CORDES: 2026-0001, 2026-0002. El anio sale
  de la fecha de la cotizacion, no del dia en que se numera: una cotizacion de
  diciembre que se imprime en enero sigue siendo del anio en que se hizo.

  Las 8.048 que vinieron del Access quedan sin numero y siguen mostrando su
  id_sistema. Numerarlas ahora seria inventar una correlatividad que nunca
  existio, y esos numeros ya estan impresos en papeles que el cliente tiene.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            // "2026-0001" son nueve caracteres. Con cinco digitos entran
            // 99.999 por anio; CORDES hace unas 300.
            $tabla->string('numero', 12)->nullable()->unique()->after('id_sistema');
        });
    }

    public function down(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->dropUnique(['numero']);
            $tabla->dropColumn('numero');
        });
    }
};

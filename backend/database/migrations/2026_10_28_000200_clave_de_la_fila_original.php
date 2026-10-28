<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
  La fila del Access que dio cada cotizacion, sin depender del orden.

  "Antes y ahora" emparejaba cada cotizacion con su fila por posicion: la
  fila 10 del archivo era la decima cotizacion de la base. Valia para las
  8.048 de septiembre, que entraron primero y juntas. Las que se suman del
  indice completo entran despues de todo lo cargado en el sistema nuevo, y
  por posicion quedarian al lado de la fila de otra.

  La fila guarda la misma huella que la cotizacion (consultas.origen_clave):
  se encuentran una a la otra aunque haya cualquier cosa en el medio.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migracion_originales', function (Blueprint $tabla) {
            $tabla->string('clave', 32)->nullable()->index()->after('fila');
        });
    }

    public function down(): void
    {
        Schema::table('migracion_originales', function (Blueprint $tabla) {
            $tabla->dropIndex(['clave']);
            $tabla->dropColumn('clave');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
  La accion del control de cambios pasa a texto.

  Era una lista cerrada (Alta, Modificacion, Archivado, Impresion,
  Vencimiento) y descartar un borrador anota "Eliminado", y restaurarlo,
  "Restaurado": en MySQL no entraban y descartar daba "Server Error"
  (29-10-2026). En SQLite, donde corren las pruebas, la columna ya habia
  quedado como texto y no se veia.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historial_cambios', function (Blueprint $tabla) {
            $tabla->string('accion', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('historial_cambios', function (Blueprint $tabla) {
            $tabla->enum('accion', ['Alta', 'Modificacion', 'Archivado', 'Impresion', 'Vencimiento', 'Eliminado', 'Restaurado'])->change();
        });
    }
};

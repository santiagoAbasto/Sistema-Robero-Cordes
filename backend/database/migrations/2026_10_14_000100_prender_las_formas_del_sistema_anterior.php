<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
  Las formas del sistema anterior, prendidas.

  Al importar el Access, las 238 formas nuevas nacieron apagadas para no
  llenar el desplegable con formas que nadie sabia si seguian vivas. En las
  pruebas faltaban las de todos los dias —el CODO 90° de una orden de compra
  tiene 51 cotizaciones en el sistema anterior— y se decidio prenderlas
  todas (14-10-2026).

  Menos tres, porque su propio nombre en el Access dice que no se usan o
  remite a otra: "CINTA NO USAR", "MANGUITO - USAR COLLAR" y "TAPA (VER
  CASQUETE)". Se prende solo lo que vino del Access (origen_id); las que
  se crearon despues en esta pantalla no se tocan.

  Prendidas sin medidas se cotizan por unidad con la medida escrita como
  texto, como la BRIDA; el peso llega cuando CORDES cargue sus tablas.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('formas')
            ->whereNotNull('origen_id')
            ->where('activo', false)
            ->where('nombre', 'not like', '%NO USAR%')
            ->where('nombre', 'not like', '%- USAR %')
            ->where('nombre', 'not like', '%(VER %')
            ->update(['activo' => true]);
    }

    public function down(): void
    {
        // Se apagan de a una desde Configuracion > Formas y formulas: no hay
        // forma de saber cuales estaban apagadas a proposito antes de esto.
    }
};

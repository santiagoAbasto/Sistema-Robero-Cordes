<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
  "Ver campo RUBRO", pidio CORDES.

  Lo miramos, y lo que hay ahi es otra cosa. De los 229 rubros, los que mas
  empresas tienen son TITANIO (104), TUNGSTENO (19), NIQUEL (18), INCONEL
  (12), MONEL (12): materiales, no rubros. Mezclados con los que si lo son —
  QUIRURGICO (18), GALVANOPLASTIA (11), FLETES (9), FERRETERIA (8)— y con 160
  que usa una sola empresa.

  El campo se venia usando para anotar QUE LE VENDEMOS a cada empresa, no a
  que se dedica. Cual de las dos cosas tiene que ser es una decision de ellos,
  y hasta que la tomen el campo no se toca: reclasificar 968 empresas por
  criterio propio no lo puede decidir el sistema.

  Esta migracion hace solo lo mecanico: dos rubros escritos de dos formas y
  uno que se llama "??".
*/
return new class extends Migration
{
    public function up(): void
    {
        /*
          Gana el que mas empresas tiene: si 4 dicen "TITANIO GR5" y 1 dice
          "TITANIO GR.5", la que se corrige es la de una sola.
        */
        $rubros = DB::table('rubros')
            ->select('rubros.id', 'rubros.nombre')
            ->selectSub(
                DB::table('empresas')->selectRaw('COUNT(*)')->whereColumn('empresas.rubro_id', 'rubros.id'),
                'usos',
            )
            ->orderByDesc('usos')
            ->orderByRaw('LENGTH(rubros.nombre) DESC')
            ->get();

        $buenos = [];

        foreach ($rubros as $r) {
            $plano = preg_replace('/[^a-z0-9]/', '', mb_strtolower($r->nombre)) ?? '';

            if ($plano === '') {
                // "??" no es un rubro.
                DB::table('empresas')->where('rubro_id', $r->id)->update(['rubro_id' => null]);
                DB::table('rubros')->where('id', $r->id)->delete();

                continue;
            }

            if (! isset($buenos[$plano])) {
                $buenos[$plano] = $r->id;

                continue;
            }

            DB::table('empresas')->where('rubro_id', $r->id)->update(['rubro_id' => $buenos[$plano]]);
            DB::table('rubros')->where('id', $r->id)->delete();
        }
    }

    public function down(): void
    {
        // A proposito: volver a partir un rubro en dos no le sirve a nadie.
    }
};

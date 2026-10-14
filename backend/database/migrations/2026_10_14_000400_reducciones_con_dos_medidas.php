<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
  Las reducciones, con la medida de entrada y la de salida.

  Lista de ajustes del cliente, 14-10-2026: "cuando son reduccion tenemos que
  tener dos veces, porque una es de entrada y otra de salida: diametro
  exterior dos veces y espesor de pared dos veces, y las tablas, todo
  compacto y lado a lado". Una reduccion de 6" SCH 40S a 4" SCH 40S une dos
  caños: cada punta se elige de la tabla del caño.

  Sin formula de peso, como los codos: la tabla de pesos la da CORDES.

  No se pisa una forma ya configurada desde Formas y formulas; lo vacio se
  mira en PHP, que compara igual en cualquier base.
*/
return new class extends Migration
{
    private const FORMAS = ['REDUCCIÓN CONCENTRICA', 'REDUCCIÓN EXCÉNTRICA'];

    public function up(): void
    {
        $ids = DB::table('formas')
            ->whereIn('nombre', self::FORMAS)
            ->get(['id', 'campos', 'expresion'])
            ->filter(fn ($f) => blank($f->expresion) && empty(json_decode((string) $f->campos, true)))
            ->pluck('id');

        DB::table('formas')->whereIn('id', $ids)->update([
            'usa_cano' => true,
            'campos' => json_encode([
                ['clave' => 'outer', 'label' => 'Diametro exterior de entrada'],
                ['clave' => 'wall', 'label' => 'Espesor de pared de entrada'],
                ['clave' => 'outer2', 'label' => 'Diametro exterior de salida'],
                ['clave' => 'wall2', 'label' => 'Espesor de pared de salida'],
            ]),
        ]);
    }

    public function down(): void
    {
        // Se cambian de a una desde Configuracion > Formas y formulas.
    }
};

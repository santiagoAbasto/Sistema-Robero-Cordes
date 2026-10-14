<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
  Los codos, la curva y el casquete se miden con la tabla del caño.

  Llegaron del Access sin medidas, y en la linea caian a DIMENSIONES como
  texto. Roberto, 14-10-2026: "los codos debieran ingresar las medidas por la
  misma tabla que los caños". Un accesorio para soldar a tope (ASME B16.9)
  tiene el diametro exterior y la pared del caño de su misma medida y
  schedule: elegir 6" SCH 40S en la tabla completa las dos.

  Solo los de UNA medida. La reduccion y la te de reduccion tienen dos
  (6" x 4"); las bridas van por DN y clase; los roscados —cuplas, niples,
  casquete roscado— por rosca. Ninguno cabe en un renglon de la tabla.

  Sin formula de peso: un codo no pesa lo que un tramo de caño, y esa tabla
  la tiene que dar CORDES. Mientras tanto se cotiza por unidad, como la brida.

  No se pisa una forma que alguien ya configuro desde Formas y formulas. Lo
  vacio se mira en PHP y no en SQL: un JSON vacio se compara distinto en cada
  base, y el sistema puede terminar en SQL Server.
*/
return new class extends Migration
{
    private const FORMAS = ['CODO 45°', 'CODO 90°', 'CODO 180°', 'CURVA', 'CASQUETE'];

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
                ['clave' => 'outer', 'label' => 'Diametro exterior'],
                ['clave' => 'wall', 'label' => 'Espesor de pared'],
            ]),
        ]);
    }

    public function down(): void
    {
        // Se cambian de a una desde Configuracion > Formas y formulas.
    }
};

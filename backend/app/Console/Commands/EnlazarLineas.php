<?php

namespace App\Console\Commands;

use App\Models\ConsultaLinea;
use App\Models\Forma;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Services\Migracion\EnlazadorDeLineas;
use App\Services\Migracion\MedidasDelTexto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Engancha cada linea historica con su material y su forma del catalogo.
 *
 * Las lineas del sistema anterior llegaron con la descripcion y el precio pero
 * sin enlace: material_id y forma_id en null en las 13.071. El nombre del
 * material esta escrito adentro del texto —"TIT GR1 BARRA 1.60 X 915 MM"— y de
 * ahi se lo saca. Ver EnlazadorDeLineas para el como y el por que.
 *
 * Solo completa lo que esta vacio. Una linea que ya tiene material enlazado no
 * se toca, venga de donde venga: lo que cargo o corrigio una persona manda
 * sobre lo que deduce esto.
 *
 * En seco por defecto, igual que el importador: sin --aplicar cuenta y no
 * escribe.
 */
class EnlazarLineas extends Command
{
    protected $signature = 'lineas:enlazar
        {--aplicar : escribe en la base; sin esto solo informa}
        {--muestra=0 : imprime N enlaces al azar para revisarlos a mano}';

    protected $description = 'Reconoce el material y la forma dentro del texto de las lineas historicas';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        // La forma tolera mas ruido: su nombre es una palabra comun y no hay
        // designaciones que la distingan.
        $formas = new EnlazadorDeLineas(
            Forma::query()->get(['id', 'nombre'])->map(fn ($f) => [$f->id, $f->nombre])->all(),
            material: false,
            // La forma exige menos: su nombre es una palabra comun.
            exigencia: 0.42,
        );

        /*
          Al buscar material, las palabras de las formas son ruido. No alcanza
          con una lista fija: el catalogo de materiales tiene entradas que son
          formas anotadas mal —"Agujas", "Malla Tejida"— y enganchaban el
          "BISMUTO AGUJAS" de cualquier descripcion devolviendo la aguja como
          material. El vocabulario de formas sale de la tabla de formas, asi
          que se corrige solo cuando alguien agrega una forma nueva.
        */
        $materiales = new EnlazadorDeLineas(
            [
                ...Material::query()->get(['id', 'nombre'])->map(fn ($m) => [$m->id, $m->nombre])->all(),
                ...MaterialAlias::query()->get(['material_id', 'alias'])->map(fn ($a) => [$a->material_id, $a->alias])->all(),
            ],
            material: true,
            ruidoExtra: $formas->vocabulario(),
        );

        if (! $aplicar) {
            $this->warn('Modo informe: no se escribe nada. Agregá --aplicar para hacerlo.');
        }

        $medidor = new MedidasDelTexto();
        // El nombre de cada forma, para saber en que orden escribia sus medidas.
        $nombreForma = Forma::query()->pluck('nombre', 'id');

        $conMaterial = $conForma = $conMedidas = $total = 0;
        $muestra = [];
        $cuantas = (int) $this->option('muestra');

        /*
          Se recorren todas. Filtrar por "las que no tienen material" dejaba
          afuera a las que ya lo tenian pero no las medidas, y cada campo se
          completa solo si esta vacio: lo puesto a mano nunca se pisa.
        */
        ConsultaLinea::query()
            ->select(['id', 'descripcion', 'material_id', 'forma_id',
                'diametro_mm', 'espesor_mm', 'ancho_mm', 'largo_mm'])
            ->chunkById(500, function ($lineas) use (
                $materiales, $formas, $medidor, $nombreForma, $aplicar,
                &$conMaterial, &$conForma, &$conMedidas, &$total, &$muestra, $cuantas
            ) {
                foreach ($lineas as $linea) {
                    $total++;

                    $m = $linea->material_id ?? $materiales->elegir($linea->descripcion);
                    $f = $linea->forma_id ?? $formas->elegir($linea->descripcion);

                    $cambios = [];

                    if ($linea->material_id === null && $m !== null) {
                        $cambios['material_id'] = $m;
                        $conMaterial++;
                    }

                    if ($linea->forma_id === null && $f !== null) {
                        $cambios['forma_id'] = $f;
                        $conForma++;
                    }

                    /*
                      Las medidas, que tambien estaban escritas en el texto y
                      en ningun campo: al abrir una cotizacion vieja para
                      editarla habia que volver a tipearlas mirando la
                      descripcion. Necesitan la forma, porque es la que dice en
                      que orden las escribia el sistema anterior.
                    */
                    $columna = [
                        'diameter' => 'diametro_mm', 'outer' => 'diametro_mm',
                        'side' => 'diametro_mm', 'across' => 'diametro_mm',
                        'wall' => 'espesor_mm', 'height' => 'espesor_mm',
                        'width' => 'ancho_mm', 'length' => 'largo_mm',
                    ];

                    $medidas = $f === null ? [] : $medidor->leer(
                        $linea->descripcion,
                        (string) ($nombreForma[$f] ?? ''),
                    );

                    $puso = false;

                    foreach ($medidas as $clave => $mm) {
                        $col = $columna[$clave] ?? null;

                        // Solo se completa lo que esta vacio.
                        if ($col === null || $linea->{$col} !== null || isset($cambios[$col])) {
                            continue;
                        }

                        $cambios[$col] = $mm;
                        $puso = true;
                    }

                    if ($puso) {
                        $conMedidas++;
                    }

                    if ($cambios === []) {
                        continue;
                    }

                    if (count($muestra) < $cuantas) {
                        $muestra[] = [
                            mb_substr($linea->descripcion, 0, 58),
                            isset($cambios['material_id']) ? Material::find($cambios['material_id'])?->nombre : '—',
                            isset($cambios['forma_id']) ? Forma::find($cambios['forma_id'])?->nombre : '—',
                        ];
                    }

                    if ($aplicar) {
                        /*
                          Update directo y no save(): RegistraCambios escribiria
                          una fila de historial por cada una de las 13.071, con
                          fecha de hoy y a nombre de quien corre el comando.
                          Esto no es alguien corrigiendo una cotizacion: es
                          completar un dato que ya estaba escrito en el texto.
                        */
                        DB::table('consulta_lineas')->where('id', $linea->id)->update($cambios);
                    }
                }
            });

        if ($muestra !== []) {
            $this->newLine();
            $this->table(['descripcion', 'material', 'forma'], $muestra);
        }

        $this->newLine();
        $this->table(['', 'lineas'], [
            ['lineas revisadas', number_format($total, 0, ',', '.')],
            ['se les reconocio el material', number_format($conMaterial, 0, ',', '.')],
            ['se les reconocio la forma', number_format($conForma, 0, ',', '.')],
            ['se les leyeron las medidas', number_format($conMedidas, 0, ',', '.')],
            ['quedan sin material', number_format(
                ConsultaLinea::whereNull('material_id')->count() - ($aplicar ? 0 : $conMaterial),
                0, ',', '.',
            )],
        ]);

        if (! $aplicar) {
            $this->warn('No se escribio nada.');
        }

        return self::SUCCESS;
    }
}

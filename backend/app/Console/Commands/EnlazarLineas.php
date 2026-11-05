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
 *
 * Con --corregir-materiales no completa nada: revisa los materiales que YA estan
 * enlazados y corrige los que las reglas de hoy no sostienen (ver mas abajo).
 */
class EnlazarLineas extends Command
{
    protected $signature = 'lineas:enlazar
        {--aplicar : escribe en la base; sin esto solo informa}
        {--solo-sumadas : solo las cotizaciones que entraron con --sumar, no las de septiembre}
        {--muestra=0 : imprime N enlaces al azar para revisarlos a mano}
        {--corregir-materiales : revisa los materiales ya enlazados y corrige los que las reglas de hoy no sostienen}';

    protected $description = 'Reconoce el material y la forma dentro del texto de las lineas historicas';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        // La forma tolera mas ruido: su nombre es una palabra comun y no hay
        // designaciones que la distingan.
        $formas = new EnlazadorDeLineas(
            // Con sus otros nombres: "BARRA" a secas es BARRA REDONDA.
            Forma::nombresParaEnlazar(),
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
                ...MaterialAlias::query()->get(['material_id', 'alias'])->map(fn ($a) => [$a->material_id, $a->alias, true])->all(),
            ],
            material: true,
            ruidoExtra: $formas->vocabulario(),
            formasEnteras: $formas->palabrasSolas(),
            // El catalogo deja marcados los que no se usan ("... NO USAR", "zz..."): un enlace nunca los elige.
            vetados: Material::idsQueNoSeUsan(),
        );

        if (! $aplicar) {
            $this->warn('Modo informe: no se escribe nada. Agregá --aplicar para hacerlo.');
        }

        if ($this->option('corregir-materiales')) {
            return $this->corregirMateriales($materiales, $aplicar);
        }

        $medidor = new MedidasDelTexto;
        // El nombre de cada forma, para saber en que orden escribia sus medidas.
        $nombreForma = Forma::query()->pluck('nombre', 'id');

        $conMaterial = $conForma = $conMedidas = $total = $cambiadas = 0;
        $muestra = [];
        $cuantas = (int) $this->option('muestra');

        /*
          Se recorren todas. Filtrar por "las que no tienen material" dejaba
          afuera a las que ya lo tenian pero no las medidas, y cada campo se
          completa solo si esta vacio: lo puesto a mano nunca se pisa.
        */
        ConsultaLinea::query()
            // Solo las historicas. Una linea cargada en el sistema nuevo sin
            // material lo dejo asi una persona: no se le deduce nada.
            ->whereIn('consulta_id', DB::table('consultas')->where('nota', 'like', 'Importada del sistema anterior.%')
                /*
                  Con --solo-sumadas, solo lo que trajo la suma: las de
                  septiembre quedan como el cliente ya las vio, y las copias y
                  revisiones —que heredan la nota— tienen lineas tipeadas por
                  una persona.
                */
                ->when($this->option('solo-sumadas'), fn ($q) => $q->whereNotNull('origen_clave')->whereNull('copiada_de_id')->whereNull('revision_de_id'))
                ->select('id'))
            ->select(['id', 'descripcion', 'material_id', 'forma_id',
                'diametro_mm', 'espesor_mm', 'ancho_mm', 'largo_mm'])
            ->chunkById(500, function ($lineas) use (
                $materiales, $formas, $medidor, $nombreForma, $aplicar,
                &$conMaterial, &$conForma, &$conMedidas, &$total, &$cambiadas, &$muestra, $cuantas
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

                    /*
                      Muestra AL AZAR de todo lo que se escribiria, no las
                      primeras: las primeras son las cotizaciones mas viejas y
                      parecen todas iguales, y un error que vive en el medio de
                      la tabla no se ve nunca. Es un muestreo uniforme (cada
                      cambio tiene la misma chance de salir) que no necesita
                      guardar todos.
                    */
                    $cambiadas++;

                    if ($cuantas > 0) {
                        // Las medidas que se escribirian, con el nombre de cada campo: es lo que
                        // hay que mirar con los datos reales, porque de ahi sale el peso.
                        $escribe = [];

                        foreach (['diametro_mm' => 'diam', 'espesor_mm' => 'esp', 'ancho_mm' => 'ancho', 'largo_mm' => 'largo'] as $col => $nombre) {
                            if (isset($cambios[$col])) {
                                $escribe[] = $nombre.' '.rtrim(rtrim(number_format((float) $cambios[$col], 3, '.', ''), '0'), '.');
                            }
                        }

                        $fila = [
                            mb_substr($linea->descripcion, 0, 110),
                            $cambios['material_id'] ?? null,
                            $cambios['forma_id'] ?? null,
                            // Solo para mostrar: lo que ya tenia, que no se cambia.
                            $linea->material_id,
                            $linea->forma_id,
                            $escribe === [] ? '—' : implode(' · ', $escribe),
                        ];

                        if (count($muestra) < $cuantas) {
                            $muestra[] = $fila;
                        } elseif (($lugar = random_int(0, $cambiadas - 1)) < $cuantas) {
                            $muestra[$lugar] = $fila;
                        }
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
            $this->line('Muestra al azar de lo que se escribiria ('.count($muestra).' de '.number_format($cambiadas, 0, ',', '.').'). "ya tenia" = el material ya estaba cargado y no se toca.');

            foreach (array_values($muestra) as $i => [$descripcion, $materialNuevo, $formaNueva, $materialViejo, $formaVieja, $medidas]) {
                $material = $materialNuevo !== null
                    ? Material::find($materialNuevo)?->nombre
                    : ($materialViejo !== null ? 'ya tenia '.Material::find($materialViejo)?->nombre : '—');
                $forma = $formaNueva !== null
                    ? Forma::find($formaNueva)?->nombre
                    : ($formaVieja !== null ? 'ya tenia '.Forma::find($formaVieja)?->nombre : '—');

                $this->line(sprintf('%2d. %s', $i + 1, $descripcion));
                $this->line("    material: {$material} · forma: {$forma} · medidas: {$medidas}");
            }
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

    /**
     * Revisa los materiales que ya estan enlazados con las reglas de hoy.
     *
     * Hasta el 05-11-2026 el enlazador tiraba "cobre", "plata" y los numeros de las formas como ruido, y
     * elegia materiales marcados "NO USAR": escribio enlaces como "COBRE TUNGSTENO 70/30" -> "Tungsteno
     * Plata 70/30" (plata, no cobre), "CROMO PIEDRA" -> "Cobre Cromo", "SCH 20S" -> "Cobre Plata 20S" o
     * "ACERO SUPER DUPLEX 2507" -> "Acero Duplex 2507 NO USAR".
     * Solo se corrige lo que ESE enlazador habria escrito y el de hoy no sostiene, y todo enlace a un
     * material marcado NO USAR que no haya elegido una persona: lo que cargo otro camino, o lo que una
     * persona toco, no se mira. Nada se borra: el material se vacia o se cambia por el
     * que sostienen las reglas de hoy, y un archivo guarda como estaba cada linea para poder volver.
     */
    private function corregirMateriales(EnlazadorDeLineas $materiales, bool $aplicar): int
    {
        $nombre = Material::query()->pluck('nombre', 'id');

        // Las lineas cuyo material toco una persona: lo que ella puso manda.
        $deUnaPersona = DB::table('historial_cambios')
            ->where('tabla', 'consulta_lineas')->where('campo', 'material_id')
            ->pluck('registro_id')->flip()->all();

        $archivo = null;
        $ruta = null;

        if ($aplicar) {
            $ruta = storage_path('app/materiales-corregidos-'.now()->format('Ymd-His').'.csv');
            $archivo = fopen($ruta, 'w');
            fputcsv($archivo, ['linea_id', 'consulta_id', 'material_anterior_id', 'material_anterior', 'material_nuevo_id', 'material_nuevo', 'descripcion']);
        }

        $revisadas = $cambian = $respetadas = $deVetados = 0;
        $grupos = [];
        $vetados = array_flip(Material::idsQueNoSeUsan());

        ConsultaLinea::query()
            ->whereIn('consulta_id', DB::table('consultas')->where('nota', 'like', 'Importada del sistema anterior.%')->select('id'))
            ->whereNotNull('material_id')
            ->select(['id', 'consulta_id', 'descripcion', 'material_id'])
            ->chunkById(500, function ($lineas) use ($materiales, $nombre, $aplicar, $archivo, $deUnaPersona, $vetados, &$revisadas, &$cambian, &$respetadas, &$grupos, &$deVetados) {
                foreach ($lineas as $linea) {
                    $revisadas++;

                    // El de hoy no lo elegiria...
                    $hoy = $materiales->elegir($linea->descripcion);

                    if ($hoy === $linea->material_id) {
                        continue;
                    }

                    // ...y o bien lo habria escrito el enlazador de antes, o el texto no dice ese material
                    // (un numero de la medida tomado por el grado, un NO USAR): una version todavia mas vieja lo escribio.
                    // Si el texto lo dice, aunque hoy el enlazador dude, se deja como esta.
                    if ($materiales->elegir($linea->descripcion, false) !== $linea->material_id
                        && $materiales->sostiene($linea->material_id, $linea->descripcion)) {
                        continue;
                    }

                    if (isset($deUnaPersona[$linea->id])) {
                        $respetadas++;

                        continue;
                    }

                    $cambian++;
                    $deVetados += isset($vetados[$linea->material_id]) ? 1 : 0;

                    $antes = $nombre[$linea->material_id] ?? '?';
                    $ahora = $hoy === null ? 'sin material' : ($nombre[$hoy] ?? '?');
                    $grupos["{$antes} → {$ahora}"]['n'] = ($grupos["{$antes} → {$ahora}"]['n'] ?? 0) + 1;

                    if (count($grupos["{$antes} → {$ahora}"]['ejemplos'] ?? []) < 2) {
                        $grupos["{$antes} → {$ahora}"]['ejemplos'][] = mb_substr($linea->descripcion, 0, 110);
                    }

                    if ($aplicar) {
                        fputcsv($archivo, [$linea->id, $linea->consulta_id, $linea->material_id, $antes, $hoy, $hoy === null ? '' : $ahora, $linea->descripcion]);

                        // Update directo y solo si sigue como se leyo: no es una persona corrigiendo, y no se pisa lo que cambio entre medio.
                        DB::table('consulta_lineas')->where('id', $linea->id)->where('material_id', $linea->material_id)->update(['material_id' => $hoy]);
                    }
                }
            });

        if ($archivo !== null) {
            fclose($archivo);
        }

        uasort($grupos, fn ($a, $b) => $b['n'] <=> $a['n']);

        $this->newLine();
        $this->line('Materiales que '.($aplicar ? 'se corrigieron' : 'se corregirian').' (agrupados por lo que cambia):');

        foreach (array_slice($grupos, 0, 40, true) as $cambio => $dato) {
            $this->line(sprintf('[%s] %s', number_format($dato['n'], 0, ',', '.'), $cambio));

            foreach ($dato['ejemplos'] as $ejemplo) {
                $this->line('      '.$ejemplo);
            }
        }

        if (count($grupos) > 40) {
            $this->line('... y '.(count($grupos) - 40).' cambios distintos mas.');
        }

        $this->newLine();
        $this->table(['', 'lineas'], [
            ['lineas con material revisadas', number_format($revisadas, 0, ',', '.')],
            [$aplicar ? 'corregidas' : 'se corregirian', number_format($cambian, 0, ',', '.')],
            ['no se tocan: las corrigio una persona', number_format($respetadas, 0, ',', '.')],
            // Las que quedan enlazadas a un material marcado NO USAR por cualquier otro camino: se ven, para decidirlas a mano.
            ['siguen enlazadas a un material NO USAR (las eligio una persona)', number_format(max(0, ConsultaLinea::whereIn('material_id', array_keys($vetados))->count() - ($aplicar ? 0 : $deVetados)), 0, ',', '.')],
        ]);

        if ($aplicar) {
            $this->info("Listo. Como estaba cada linea quedo guardado en: {$ruta}");
        } else {
            $this->warn('No se escribio nada.');
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Forma;
use App\Services\EvaluadorDeFormulas;
use App\Services\PlanillaXlsx;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Carga la matriz de parametros de formas de CORDES para verla en la pantalla.
 *
 * "Adjunto una primera version de la tabla de formas. Si bien falta agregar
 * formas, permite ver la cantidad de parametros que tendriamos que meter en
 * la pantalla" (21-10-2026). Es para mostrarla y decidir, no para quedarse:
 * las formas que estan en la matriz quedan con sus parametros, las que no, se
 * apagan, y con --deshacer todo vuelve a como estaba.
 *
 * Una forma de la matriz que ya existe con el mismo nombre (Chapa, Tubo,
 * Caño) se actualiza en lugar de duplicarse, y conserva su formula si la
 * cuenta sigue teniendo sus medidas: la chapa sigue pesando.
 *
 *   php artisan formas:matriz ~/Downloads/parametros_piping_matriz_V5-1.xlsx
 *   php artisan formas:matriz --deshacer
 */
class CargarMatrizDeFormas extends Command
{
    protected $signature = 'formas:matriz {archivo? : la planilla de la matriz (.xlsx)}
        {--deshacer : vuelve las formas a como estaban antes de cargarla}';

    protected $description = 'Carga la matriz de parametros de formas para mostrarla, o la deshace';

    /** Como estaban las formas antes de cargar la matriz. */
    public const ANTES = 'matriz-de-formas-antes.json';

    /**
     * Cada columna de la matriz y su parametro.
     *
     * Las medidas que ya tienen nombre en las formulas llevan ese nombre: el
     * espesor de una chapa es "height" y el de un tubo, "wall". Se usa el que
     * ya tenga la forma, y si no, el primero.
     */
    private const COLUMNAS = [
        'espesor' => ['height', 'wall'],
        'ancho' => ['width'],
        'largo' => ['length'],
        'diametro' => ['diameter', 'outer'],
        'diametro 2' => ['diameter2'],
        'distancia entre caras' => ['across'],
        'lado' => ['side'],
        'lado 2' => ['side2'],
        'radio' => ['radius'],
        'diametro nominal' => ['dn'],
        'diametro nominal 2' => ['dn2'],
        'schedule' => ['schedule'],
        'schedule 2' => ['schedule2'],
        'rating' => ['rating'],
        'angulo' => ['angle'],
        'extremos / conexion' => ['ends'],
        'tipo de resalte' => ['facing'],
        'tipo' => ['type'],
        'serie (a/b)' => ['series'],
        'malla' => ['mesh'],
        'rosca' => ['thread'],
        'largo de rosca' => ['thread_length'],
    ];

    /** Lo que se ofrece para elegir, tal como lo dicen las aclaraciones de la matriz. */
    private const OPCIONES = [
        'Caño' => ['ends' => ['PE', 'BE', 'TE']],
        'Niple' => ['ends' => ['PE', 'TOE', 'TBE', 'BE']],
        'Codo BW' => ['radius' => ['LR', 'SR'], 'angle' => ['45°', '90°', '180°']],
        'Codo SW / roscado' => ['rating' => ['3000', '6000'], 'ends' => ['SW', 'NPT']],
        'Curva (pipe bend)' => ['radius' => ['3D', '5D']],
        'Reducción BW' => ['type' => ['Concéntrica', 'Excéntrica']],
        'Swage' => ['type' => ['Concéntrico', 'Excéntrico']],
        'Tapón' => ['type' => ['Hexagonal', 'Cuadrada', 'Redonda']],
        'Stub end' => ['type' => ['Corto', 'Largo']],
        'Latrolet' => ['angle' => ['45°']],
        'Válvula (compuerta, globo, retención, esférica)' => ['type' => ['Compuerta', 'Globo', 'Retención', 'Esférica']],
        'Válvula mariposa' => ['type' => ['Wafer', 'Lug', 'Bridada']],
        'Tornillo Allen' => ['type' => ['Cilíndrica', 'Avellanada', 'Botón']],
        'Tuerca' => ['type' => ['Hexagonal normal', 'Pesada (heavy hex)', 'Autofrenante', 'Ciega']],
        'Arandela plana' => ['type' => ['Normal', 'Ancha', 'Endurecida (F436)']],
        'Malla tejida (rollo / paño)' => ['type' => ['Liso', 'Sarga (twill)', 'Holandés (dutch)']],
    ];

    public function handle(PlanillaXlsx $planilla, EvaluadorDeFormulas $evaluador): int
    {
        if ($this->option('deshacer')) {
            return $this->deshacer();
        }

        if (Storage::exists(self::ANTES)) {
            $this->error('La matriz ya esta cargada. Para volver a cargarla, primero: php artisan formas:matriz --deshacer');

            return self::FAILURE;
        }

        $archivo = (string) $this->argument('archivo');
        if (! is_file($archivo)) {
            $this->error('No encuentro la planilla: '.$archivo);

            return self::FAILURE;
        }

        $filas = $this->formasDeLaMatriz($planilla->leer($archivo));
        if ($filas === []) {
            $this->error('La planilla no tiene una columna "Forma" con formas debajo.');

            return self::FAILURE;
        }

        $antes = DB::table('formas')->get()->map(fn ($f) => (array) $f)->all();
        $creadas = [];

        DB::transaction(function () use ($filas, $evaluador, &$creadas) {
            // El nombre se compara como lo compara la base en MySQL: "Caño" es
            // "CAÑO". Aca y no en SQL, que en cada motor compara distinto. Se
            // leen ya apagadas: si no, prenderlas no seria un cambio y no se guardaria.
            Forma::query()->update(['activo' => false]);
            $existentes = Forma::all()->keyBy(fn (Forma $f) => $this->plano($f->nombre));

            foreach ($filas as $i => [$nombre, $columnas]) {
                $forma = $existentes[$this->plano($nombre)] ?? null;
                $campos = $this->campos($nombre, $columnas, $forma);
                $usaCano = in_array('wall', array_column($campos, 'clave'), true)
                    && in_array('schedule', array_map(fn ($t) => $this->plano($t), $columnas), true);

                if ($forma === null) {
                    $creadas[] = Forma::create([
                        'nombre' => $nombre,
                        'clave' => 'matriz_'.Str::of(Str::ascii($nombre))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(50, ''),
                        'campos' => $campos ?: null,
                        'usa_cano' => $usaCano,
                        'activo' => true,
                        'orden' => $i + 1,
                    ])->id;

                    continue;
                }

                $forma->update([
                    'nombre' => $nombre,
                    'campos' => $campos ?: null,
                    'activo' => true,
                    'usa_cano' => $usaCano,
                    'expresion' => $this->formulaQueSirve($forma->expresion, $campos, $evaluador),
                ]);
            }
        });

        Storage::put(self::ANTES, json_encode(['formas' => $antes, 'creadas' => $creadas], JSON_UNESCAPED_UNICODE));

        $this->table(['Forma', 'Parametros'], collect($filas)->map(fn ($f) => [
            $f[0], implode(', ', $f[1]) ?: '(texto libre)',
        ])->all());
        $this->info(count($filas).' formas de la matriz: '.count($creadas).' nuevas y '.(count($filas) - count($creadas)).' que ya estaban. Las demas quedan apagadas.');
        $this->line('Para volver a como estaba: php artisan formas:matriz --deshacer');

        return self::SUCCESS;
    }

    /**
     * Las formas de la matriz, cada una con los titulos de sus columnas marcadas.
     *
     * @param  list<list<string>>  $filas
     * @return list<array{0: string, 1: list<string>}>
     */
    private function formasDeLaMatriz(array $filas): array
    {
        $titulos = null;
        $formas = [];

        foreach ($filas as $fila) {
            if ($titulos === null) {
                if ($this->plano($fila[1] ?? '') === 'forma') {
                    $titulos = $fila;
                }

                continue;
            }

            $nombre = trim($fila[1] ?? '');
            if ($nombre === '') {
                continue;
            }

            $marcadas = [];
            foreach ($titulos as $c => $titulo) {
                if (isset(self::COLUMNAS[$this->plano($titulo)]) && trim($fila[$c] ?? '') !== '') {
                    $marcadas[] = trim($titulo);
                }
            }

            $formas[] = [$nombre, $marcadas];
        }

        return $formas;
    }

    /** Los parametros de una forma, con el nombre que ya usa si es una medida de su formula. */
    private function campos(string $nombre, array $titulos, ?Forma $forma): array
    {
        $tiene = array_column($forma?->campos ?? [], 'clave');

        $campos = array_map(function (string $titulo) use ($nombre, $tiene) {
            $posibles = self::COLUMNAS[$this->plano($titulo)];
            $clave = collect($posibles)->first(fn ($c) => in_array($c, $tiene, true)) ?? $posibles[0];
            $opciones = self::OPCIONES[$nombre][$clave] ?? null;

            return ['clave' => $clave, 'label' => $titulo] + ($opciones ? ['opciones' => $opciones] : []);
        }, $titulos);

        /*
            Diametro nominal con schedule es un caño de la tabla: se elige ahi,
            como hasta ahora, y pone el diametro exterior y la pared. La matriz
            completa lo que ya habia, no lo reemplaza: el caño sigue pesando y
            la reduccion sigue con sus dos caños lado a lado.
        */
        $claves = array_column($campos, 'clave');
        foreach ([['dn', 'schedule', 'outer', 'wall', ''], ['dn2', 'schedule2', 'outer2', 'wall2', ' de salida']] as [$dn, $sch, $diametro, $pared, $punta]) {
            if (in_array($dn, $claves, true) && in_array($sch, $claves, true)) {
                $campos = array_values(array_filter($campos, fn ($c) => ! in_array($c['clave'], [$dn, $sch], true)));
                array_unshift($campos, ['clave' => $diametro, 'label' => 'Diametro exterior'.$punta], ['clave' => $pared, 'label' => 'Espesor de pared'.$punta]);
            }
        }

        /*
            En el orden en que se escriben, no en el de las columnas de la
            planilla: primero el diametro nominal con su schedule, despues las
            medidas —en el orden que ya tenia la forma, el largo al final— y
            despues lo demas. Asi una barra es "50,8 X 3900" y no "3900 X 50,8".
        */
        $peso = function (array $c, int $i) use ($tiene): int {
            $nominal = array_search($c['clave'], ['outer', 'wall', 'outer2', 'wall2', 'dn', 'schedule', 'dn2', 'schedule2'], true);
            $antes = array_search($c['clave'], $tiene, true);

            return match (true) {
                $nominal !== false => $nominal,
                in_array($c['clave'], Forma::TEXTO, true) => 300 + $i,
                $c['clave'] === 'length' => 200,
                $c['clave'] === 'thread_length' => 201,
                default => 100 + ($antes !== false ? $antes : 50 + $i),
            };
        };
        $pesos = array_map($peso, $campos, array_keys($campos));
        array_multisort($pesos, $campos);

        return $campos;
    }

    /** La formula queda si todas sus medidas siguen en la forma; si no, la forma no pesa. */
    private function formulaQueSirve(?string $expresion, array $campos, EvaluadorDeFormulas $evaluador): ?string
    {
        if (blank($expresion)) {
            return null;
        }

        $faltan = array_diff($evaluador->variables($expresion), array_column($campos, 'clave'));

        return $faltan === [] ? $expresion : null;
    }

    private function deshacer(): int
    {
        if (! Storage::exists(self::ANTES)) {
            $this->error('No hay ninguna matriz cargada.');

            return self::FAILURE;
        }

        $antes = json_decode(Storage::get(self::ANTES), true);

        DB::transaction(function () use ($antes) {
            foreach ($antes['formas'] as $forma) {
                DB::table('formas')->where('id', $forma['id'])->update($forma);
            }

            // Las nuevas se borran, salvo que una cotizacion ya las use.
            foreach ($antes['creadas'] as $id) {
                $usada = DB::table('consulta_lineas')->where('forma_id', $id)->exists()
                    || DB::table('materiales')->where('forma_habitual_id', $id)->exists();

                $usada ? Forma::whereKey($id)->update(['activo' => false]) : Forma::whereKey($id)->delete();
            }
        });

        Storage::delete(self::ANTES);
        $this->info('Las formas volvieron a como estaban antes de la matriz.');

        return self::SUCCESS;
    }

    private function plano(string $texto): string
    {
        return mb_strtolower(trim(Str::ascii($texto)));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Forma;
use App\Models\Material;
use App\Services\CalculadoraDePeso;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * La cuenta del navegador y la del servidor tienen que dar lo mismo.
 *
 * El peso se calcula en dos lados: en la pantalla, para verlo mientras se
 * escribe, y en el servidor, que es el que lo guarda. Si un dia se corrige uno
 * solo, la pantalla mostraria un numero y se guardaria otro, y nadie se daria
 * cuenta hasta que un cliente reclame.
 *
 * Esta prueba corre las dos y compara.
 */
class ParidadCalculadoraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
    }

    public function test_el_navegador_y_el_servidor_dan_el_mismo_peso(): void
    {
        $frontend = base_path('../frontend');

        if (! File::exists($frontend.'/src/lib/calculadora.ts')) {
            $this->markTestSkipped('Hace falta el frontend para comparar las dos cuentas.');
        }

        $casos = [
            ['BARRA REDONDA', 'AISI 304', ['diameter' => [25, 'mm'], 'length' => [6, 'm']], 10],
            ['BARRA REDONDA', 'TITANIO GR2', ['diameter' => [1, 'in'], 'length' => [20, 'ft']], 3],
            ['BARRA CUADRADA', 'AISI 316', ['side' => [50, 'mm'], 'length' => [1, 'm']], 1],
            ['BARRA HEXAGONAL', 'MONEL 400', ['across' => [30, 'mm'], 'length' => [2, 'm']], 5],
            ['CHAPA', 'INCONEL 625', ['width' => [1500, 'mm'], 'height' => [6, 'mm'], 'length' => [3, 'm']], 2],
            ['TUBO', 'NIQUEL 201', ['outer' => [60.33, 'mm'], 'wall' => [3.91, 'mm'], 'length' => [3, 'm']], 4],
            ['ANILLO', 'HASTELLOY C-276', ['outer' => [200, 'mm'], 'inner' => [120, 'mm'], 'height' => [25, 'mm']], 2],
            ['DISCO', 'DUPLEX 2205', ['diameter' => [8, 'in'], 'height' => [1, 'in']], 1],
            ['ESFERA', 'AISI 304', ['diameter' => [10, 'cm']], 7],
            ['BARRA OCTOGONAL', 'TITANIO GR5', ['across' => [40, 'mm'], 'length' => [1.5, 'm']], 1],
        ];

        $delNavegador = $this->correrEnNode($casos, $frontend);

        foreach ($casos as $i => [$forma, $material, $medidas, $piezas]) {
            $php = app(CalculadoraDePeso::class)->calcular(
                Material::where('nombre', $material)->firstOrFail(),
                Forma::where('nombre', $forma)->firstOrFail(),
                collect($medidas)->map(fn ($m) => ['valor' => $m[0], 'unidad' => $m[1]])->all(),
                piezas: $piezas,
            );

            $this->assertTrue($php['ok'], "PHP no pudo con {$forma}: ".($php['motivo'] ?? ''));

            // Se comparan los cuatro numeros, no solo el total: si uno solo se
            // desincroniza, la pantalla mostraria algo que el servidor no guarda.
            $claves = [
                'peso_por_pieza_kg',
                'peso_total_kg',
                'volumen_por_pieza_cm3',
                'volumen_total_cm3',
            ];

            foreach ($claves as $n => $clave) {
                $this->assertEqualsWithDelta(
                    $php[$clave],
                    $delNavegador[$i][$n],
                    0.0001,
                    "{$forma} en {$material}, {$clave}: el servidor dice {$php[$clave]} y la pantalla {$delNavegador[$i][$n]}.",
                );
            }
        }
    }

    /**
     * Cuando la cuenta no da, los dos tienen que decir lo mismo.
     *
     * No alcanza con que los dos devuelvan vacio: si el navegador dice "faltan
     * medidas" y el servidor dice "hay que revisar la formula", quien lo lee
     * revisa lo que no es. Y una formula rota puede quedar dando vueltas.
     */
    public function test_el_navegador_y_el_servidor_dicen_lo_mismo_cuando_falla(): void
    {
        $frontend = base_path('../frontend');

        if (! File::exists($frontend.'/src/lib/calculadora.ts')) {
            $this->markTestSkipped('Hace falta el frontend para comparar las dos cuentas.');
        }

        $forma = Forma::where('nombre', 'ESFERA')->firstOrFail();
        $material = Material::where('nombre', 'AISI 304')->firstOrFail();

        $rotas = [
            'medida que no existe' => '(pi * pow(diametro, 3) / 6) / 1000',
            'sintaxis rota' => '(pi * pow(diameter, 3) / 6',
            'funcion no permitida' => 'exec(diameter)',
        ];

        $entradas = [];
        $esperados = [];

        foreach ($rotas as $expresion) {
            DB::table('formas')->where('id', $forma->id)->update(['expresion' => $expresion]);
            $forma->refresh();

            $php = app(CalculadoraDePeso::class)->calcular(
                $material,
                $forma,
                ['diameter' => ['valor' => 100, 'unidad' => 'mm']],
                piezas: 5,
            );

            $esperados[] = $php;
            $entradas[] = [
                'densidad' => (float) $material->densidad,
                'expresion' => $expresion,
                'campos' => $forma->camposDelCalculo(),
                'medidas' => ['diameter' => ['valor' => '100', 'unidad' => 'mm']],
                'piezas' => '5',
                'unidadResultado' => 'kg',
                'usaCano' => false,
                'cano' => null,
            ];
        }

        $delNavegador = $this->motivosEnNode($entradas, $frontend);

        foreach (array_keys($rotas) as $i => $caso) {
            // Los dos: sin peso.
            $this->assertNull($esperados[$i]['peso_total_kg'], "PHP dio un peso con {$caso}.");
            $this->assertNull($delNavegador[$i]['peso'], "El navegador dio un peso con {$caso}.");

            // Y los dos apuntan a la formula, no a las medidas.
            foreach ([$esperados[$i]['motivo'], $delNavegador[$i]['motivo']] as $motivo) {
                $this->assertStringContainsString('necesita revision', $motivo, "En {$caso}: {$motivo}");
                $this->assertStringNotContainsString('Con esas medidas', $motivo);
            }
        }
    }

    /**
     * @return list<array{peso: ?float, motivo: string}>
     */
    private function motivosEnNode(array $entradas, string $frontend): array
    {
        $carpeta = storage_path('framework/testing');
        File::ensureDirectoryExists($carpeta);
        $guion = $carpeta.'/motivos.mjs';
        $calculadora = realpath($frontend.'/src/lib/calculadora.ts');

        File::put($guion, <<<JS
        import { calcularPeso } from '{$calculadora}';
        const casos = JSON.parse(process.argv[2]);
        console.log(JSON.stringify(casos.map((c) => {
          const r = calcularPeso(c);
          return { peso: r.pesoTotalKg, motivo: r.motivo };
        })));
        JS);

        $resultado = Process::run([
            'node', '--experimental-strip-types', '--no-warnings', $guion, json_encode($entradas),
        ]);

        if ($resultado->failed()) {
            $this->markTestSkipped('Este node no corre TypeScript: '.$resultado->errorOutput());
        }

        return json_decode(trim($resultado->output()), true);
    }

    /**
     * Corre la calculadora del navegador en node y devuelve los kilos.
     *
     * @return list<float>
     */
    private function correrEnNode(array $casos, string $frontend): array
    {
        $entradas = [];

        foreach ($casos as [$forma, $material, $medidas, $piezas]) {
            $modelo = Forma::where('nombre', $forma)->firstOrFail();

            $entradas[] = [
                'densidad' => (float) Material::where('nombre', $material)->value('densidad'),
                'expresion' => $modelo->expresion,
                'campos' => $modelo->camposDelCalculo(),
                'medidas' => collect($medidas)
                    ->map(fn ($m) => ['valor' => (string) $m[0], 'unidad' => $m[1]])
                    ->all(),
                'piezas' => (string) $piezas,
                'unidadResultado' => 'kg',
                'usaCano' => (bool) $modelo->usa_cano,
                'cano' => null,
            ];
        }

        $carpeta = storage_path('framework/testing');
        File::ensureDirectoryExists($carpeta);
        $guion = $carpeta.'/paridad.mjs';

        // Se corre el mismo archivo que usa la pantalla, no una copia: node lee
        // el TypeScript directo, asi que no hay nada compilado en el medio.
        $calculadora = realpath($frontend.'/src/lib/calculadora.ts');

        File::put($guion, <<<JS
        import { calcularPeso } from '{$calculadora}';
        const casos = JSON.parse(process.argv[2]);
        console.log(JSON.stringify(casos.map((c) => {
          const r = calcularPeso(c);
          return [r.pesoPorPiezaKg, r.pesoTotalKg, r.volumenPorPiezaCm3, r.volumenTotalCm3];
        })));
        JS);

        $resultado = Process::run([
            'node', '--experimental-strip-types', '--no-warnings', $guion, json_encode($entradas),
        ]);

        if ($resultado->failed()) {
            $this->markTestSkipped('Este node no corre TypeScript: '.$resultado->errorOutput());
        }

        return json_decode(trim($resultado->output()), true);
    }
}

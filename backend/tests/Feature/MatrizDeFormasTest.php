<?php

namespace Tests\Feature;

use App\Console\Commands\CargarMatrizDeFormas;
use App\Models\Forma;
use App\Models\Material;
use App\Services\CalculadoraDePeso;
use App\Services\PlanillaXlsx;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La matriz de parametros de formas de CORDES (21-10-2026), cargada para
 * verla en la pantalla y deshecha despues.
 */
class MatrizDeFormasTest extends TestCase
{
    use RefreshDatabase;

    private string $archivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        Storage::fake();

        // Como la mando CORDES: una fila de titulos y un 1 en cada parametro.
        $titulos = ['Categoría', 'Forma', 'Cantidad de parametros', 'Espesor', 'Ancho', 'Largo',
            'Diámetro nominal', 'Diámetro nominal 2', 'Schedule', 'Schedule 2', 'Tipo', 'Aclaraciones'];
        $this->archivo = tempnam(sys_get_temp_dir(), 'matriz').'.xlsx';
        file_put_contents($this->archivo, app(PlanillaXlsx::class)->escribir([
            $titulos,
            ['Semielaborados', 'Chapa', '3', '1', '1', '1', '', '', '', '', '', ''],
            ['Caños', 'Reducción BW', '5', '', '', '', '1', '1', '1', '1', '1', 'Tipo: concéntrica / excéntrica'],
            ['VARIOS', 'VARIOS', '', '', '', '', '', '', '', '', '', 'TEXTOLIBRE'],
            ['El material se especifica para todas las formas.'],
        ]));
    }

    public function test_carga_la_matriz_y_la_deshace(): void
    {
        $chapa = Forma::where('nombre', 'CHAPA')->firstOrFail();
        $formula = $chapa->expresion;
        $medidas = array_column($chapa->campos, 'clave');
        $otras = Forma::where('activo', true)->count();

        $this->artisan('formas:matriz', ['archivo' => $this->archivo])->assertSuccessful();

        // La que ya estaba se actualiza y sigue pesando: sus medidas son las de la formula.
        $chapa->refresh();
        $this->assertSame('Chapa', $chapa->nombre);
        // Con sus mismas medidas, en el orden que ya tenia.
        $this->assertSame($medidas, array_column($chapa->camposDelCalculo(), 'clave'));
        $this->assertSame($formula, $chapa->expresion);

        // La nueva trae sus parametros de texto y lo que se elige.
        $reduccion = Forma::where('nombre', 'Reducción BW')->firstOrFail();
        $campos = collect($reduccion->camposDelCalculo())->keyBy('clave');
        // Diametro nominal con schedule es un caño de la tabla, como hasta ahora:
        // la reduccion sigue con sus dos caños, y la matriz le suma el tipo.
        $this->assertTrue($reduccion->usa_cano);
        $this->assertSame(['outer', 'wall', 'outer2', 'wall2', 'type'], $campos->keys()->all());
        $this->assertSame('numero', $campos['wall']['tipo']);
        $this->assertSame('texto', $campos['type']['tipo']);
        $this->assertSame(['Concéntrica', 'Excéntrica'], $campos['type']['opciones']);
        $this->assertNull(Forma::where('nombre', 'VARIOS')->first()->campos);

        // Las que no estan en la matriz, apagadas.
        $this->assertSame(3, Forma::where('activo', true)->count());

        $this->artisan('formas:matriz', ['--deshacer' => true])->assertSuccessful();

        $chapa->refresh();
        $this->assertSame('CHAPA', $chapa->nombre);
        $this->assertSame($otras, Forma::where('activo', true)->count());
        $this->assertNull(Forma::where('nombre', 'Reducción BW')->first());
        Storage::assertMissing(CargarMatrizDeFormas::ANTES);
    }

    public function test_no_se_carga_dos_veces_encima(): void
    {
        $this->artisan('formas:matriz', ['archivo' => $this->archivo])->assertSuccessful();
        $this->artisan('formas:matriz', ['archivo' => $this->archivo])->assertFailed();
    }

    /** "Concéntrica" no son milimetros: queda escrito, y no es una medida que falte. */
    public function test_el_parametro_de_texto_queda_escrito_y_no_cuenta_para_el_peso(): void
    {
        $this->artisan('formas:matriz', ['archivo' => $this->archivo])->assertSuccessful();

        $foto = app(CalculadoraDePeso::class)->calcular(
            Material::where('nombre', 'AISI 304')->firstOrFail(),
            Forma::where('nombre', 'Reducción BW')->firstOrFail(),
            ['type' => ['valor' => 'Concéntrica', 'unidad' => 'mm']],
        );

        $this->assertNull($foto['peso_total_kg']);
        $this->assertSame('Concéntrica', $foto['medidas']['type']['valor']);
        $this->assertNull($foto['medidas']['type']['valor_mm']);
    }
}

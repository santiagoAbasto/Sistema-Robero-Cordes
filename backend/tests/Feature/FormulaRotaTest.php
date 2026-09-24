<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Material;
use App\Models\Unidad;
use App\Models\User;
use App\Services\CalculadoraDePeso;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Que pasa si una formula queda rota directamente en la base.
 *
 * No deberia pasar: al guardar una forma se valida. Pero una migracion, una
 * importacion o alguien tocando la base pueden dejarla rota, y ahi el sistema
 * tiene que seguir cotizando y avisar — no explotar ni devolver un numero
 * equivocado.
 *
 * Se rompen a proposito, con SQL directo, saltando el validador.
 */
class FormulaRotaTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        $this->usuario = User::factory()->create();
        $this->empresa = Empresa::create(['nombre' => 'CLIENTE']);
        Sanctum::actingAs($this->usuario);
    }

    private function romper(string $expresion): Forma
    {
        DB::table('formas')->where('nombre', 'ESFERA')->update(['expresion' => $expresion]);

        return Forma::where('nombre', 'ESFERA')->firstOrFail();
    }

    /** @return array<string, array{0: string}> */
    public static function rotas(): array
    {
        return [
            'sin cerrar el parentesis' => ['(pi * pow(diameter, 3) / 6'],
            'medida que no existe' => ['(pi * pow(diametro, 3) / 6) / 1000'],
            'caracter prohibido' => ['(pi * $x) / 1000'],
            'funcion no permitida' => ['exec(diameter)'],
            'division por cero' => ['diameter / 0'],
            'basura al final' => ['(pi * pow(diameter, 3) / 6) / 1000)'],
            'vacia con espacios' => ['   '],
        ];
    }

    /**
     * 1 y 2 · no explota, y el resultado viene vacio.
     */
    #[DataProvider('rotas')]
    public function test_una_formula_rota_no_explota_y_devuelve_vacio(string $expresion): void
    {
        $r = app(CalculadoraDePeso::class)->calcular(
            Material::where('nombre', 'AISI 304')->firstOrFail(),
            $this->romper($expresion),
            ['diameter' => ['valor' => 100, 'unidad' => 'mm']],
            piezas: 5,
        );

        $this->assertFalse($r['ok']);

        foreach ([
            'peso_total_kg', 'peso_por_pieza_kg',
            'volumen_total_cm3', 'volumen_por_pieza_cm3', 'resultado',
        ] as $campo) {
            $this->assertNull($r[$campo], "{$campo} tiene que venir vacio, no cero.");
            $this->assertNotSame(0, $r[$campo]);
            $this->assertNotSame(0.0, $r[$campo]);
        }
    }

    /**
     * 3 · el mensaje dice que hay que revisar la FORMULA.
     *
     * Y no que fallan las medidas: quien lo lea tiene que saber a quien avisar.
     */
    #[DataProvider('rotas')]
    public function test_el_mensaje_apunta_a_la_formula(string $expresion): void
    {
        $r = app(CalculadoraDePeso::class)->calcular(
            Material::where('nombre', 'AISI 304')->firstOrFail(),
            $this->romper($expresion),
            ['diameter' => ['valor' => 100, 'unidad' => 'mm']],
            piezas: 5,
        );

        // Una formula vacia no es una formula rota: es una forma sin calculo.
        $esperado = trim($expresion) === ''
            ? 'no tiene calculo automatico'
            : 'necesita revision';

        $this->assertStringContainsString($esperado, $r['motivo']);
        $this->assertStringNotContainsString('Con esas medidas', $r['motivo']);
    }

    public function test_dice_exactamente_que_medida_no_existe(): void
    {
        $r = app(CalculadoraDePeso::class)->calcular(
            Material::where('nombre', 'AISI 304')->firstOrFail(),
            $this->romper('(pi * pow(diametro, 3) / 6) / 1000'),
            ['diameter' => ['valor' => 100, 'unidad' => 'mm']],
        );

        $this->assertStringContainsString('nombra diametro', $r['motivo']);
        $this->assertStringContainsString('administra las formas', $r['motivo']);
    }

    /**
     * 1 y 4 · cotizar con esa forma no da 500, y no queda ningun peso.
     */
    public function test_se_puede_cotizar_igual_y_no_queda_ningun_peso(): void
    {
        $forma = $this->romper('(pi * pow(diametro, 3) / 6) / 1000');

        $this->postJson("/api/empresas/{$this->empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'lineas' => [[
                'descripcion' => 'ESFERA AISI 304 100mm',
                'forma_id' => $forma->id,
                'material_id' => Material::where('nombre', 'AISI 304')->value('id'),
                'cantidad' => 5,
                'precio_unitario' => 80,
                'calc_piezas' => 5,
                'calc_medidas' => ['diameter' => ['valor' => 100, 'unidad' => 'mm']],
            ]],
        ])->assertCreated();

        $linea = ConsultaLinea::latest('id')->firstOrFail();

        // Sin peso, y la linea se cotiza igual: 5 x 80 = 400.
        $this->assertNull($linea->peso_kg);
        $this->assertFalse($linea->calculo['ok']);
        $this->assertNull($linea->calculo['peso_total_kg']);
        $this->assertStringContainsString('necesita revision', $linea->calculo['motivo']);
        $this->assertEqualsWithDelta(400.0, (float) $linea->importe, 0.01);
    }

    /** 1 · abrir e imprimir esa cotizacion tampoco rompe. */
    public function test_la_cotizacion_se_abre_y_se_imprime(): void
    {
        $forma = $this->romper('(pi * pow(diametro, 3) / 6) / 1000');

        $id = $this->postJson("/api/empresas/{$this->empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'lineas' => [[
                'descripcion' => 'ESFERA 100mm',
                'forma_id' => $forma->id,
                'material_id' => Material::where('nombre', 'AISI 304')->value('id'),
                'cantidad' => 2,
                'precio_unitario' => 50,
                'calc_piezas' => 2,
                'calc_medidas' => ['diameter' => ['valor' => 100, 'unidad' => 'mm']],
            ]],
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/consultas/{$id}")->assertOk();

        $pdf = $this->get("/api/consultas/{$id}/pdf");
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
    }

    /** 3 · en administracion la forma queda marcada como invalida. */
    public function test_administracion_la_marca_invalida(): void
    {
        $this->romper('(pi * pow(diametro, 3) / 6) / 1000');

        $formas = collect($this->getJson('/api/formas')->json('formas'))->keyBy('nombre');

        $this->assertSame('invalida', $formas['ESFERA']['estado_formula']);
        $this->assertStringContainsString('diametro', $formas['ESFERA']['problema_formula']);
    }

    /**
     * 5 y 6 · un factor a mano queda marcado como tal, con nombre y fecha.
     */
    public function test_el_factor_a_mano_queda_identificado_con_quien_y_cuando(): void
    {
        $forma = $this->romper('(pi * pow(diametro, 3) / 6) / 1000');
        $metro = Unidad::where('codigo', 'MT')->value('id') ?? Unidad::value('id');
        $kilo = Unidad::where('codigo', 'KG')->value('id');

        $antes = now()->subSecond();

        $this->postJson("/api/empresas/{$this->empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'lineas' => [[
                'descripcion' => 'ESFERA con factor a mano',
                'forma_id' => $forma->id,
                'material_id' => Material::where('nombre', 'AISI 304')->value('id'),
                'cantidad' => 10,
                'unidad_venta_id' => $metro,
                'unidad_factura_id' => $kilo,
                // La formula no da: el factor lo pone una persona.
                'factor_conversion' => 7.5,
                'precio_por_kilo' => 12,
            ]],
        ])->assertCreated();

        $linea = ConsultaLinea::latest('id')->firstOrFail();

        // 5 · marcado como cargado a mano, no como calculado.
        $this->assertFalse($linea->factor_calculado);
        $this->assertEqualsWithDelta(7.5, (float) $linea->factor_conversion, 0.0001);

        // 6 · con quien y cuando.
        $this->assertSame($this->usuario->id, $linea->factor_cargado_por);
        $this->assertNotNull($linea->factor_cargado_el);
        $this->assertTrue($linea->factor_cargado_el->greaterThanOrEqualTo($antes));

        // Y sale por la API, para poder mostrarlo.
        $vista = $this->getJson("/api/consultas/{$linea->consulta_id}")->assertOk();
        $this->assertNotNull($vista->json('data.lineas.0.factor_cargado_por'));
        $this->assertNotNull($vista->json('data.lineas.0.factor_cargado_el'));
    }

    /**
     * El de la cuenta NO queda marcado como manual.
     *
     * Si todo factor quedara marcado a mano, la marca no distinguiria nada.
     */
    public function test_el_factor_calculado_no_queda_marcado_como_manual(): void
    {
        $metro = Unidad::where('codigo', 'MT')->value('id') ?? Unidad::value('id');
        $kilo = Unidad::where('codigo', 'KG')->value('id');

        $this->postJson("/api/empresas/{$this->empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'lineas' => [[
                'descripcion' => 'BARRA REDONDA TITANIO 50mm',
                'forma_id' => Forma::where('nombre', 'BARRA REDONDA')->value('id'),
                'material_id' => Material::where('nombre', 'TITANIO GR2')->value('id'),
                'diametro_mm' => 50,
                'cantidad' => 3,
                'unidad_venta_id' => $metro,
                'unidad_factura_id' => $kilo,
                'precio_por_kilo' => 48,
            ]],
        ])->assertCreated();

        $linea = ConsultaLinea::latest('id')->firstOrFail();

        // 8.8554 kg/m: lo saco la cuenta.
        $this->assertTrue($linea->factor_calculado);
        $this->assertEqualsWithDelta(8.8554, (float) $linea->factor_conversion, 0.0001);
        $this->assertNull($linea->factor_cargado_por);
        $this->assertNull($linea->factor_cargado_el);
    }

    /**
     * Escribir a mano el mismo numero que da la cuenta sigue siendo a mano.
     *
     * Es el caso que mas importa: la igualdad numerica no prueba el origen. Si
     * alguien tipea 8,8554 en una linea nueva, es un factor cargado a mano y
     * tiene que quedar auditado, aunque coincida con la formula al decimal.
     */
    public function test_escribir_el_mismo_numero_que_la_cuenta_sigue_siendo_a_mano(): void
    {
        $metro = Unidad::where('codigo', 'MT')->value('id') ?? Unidad::value('id');
        $kilo = Unidad::where('codigo', 'KG')->value('id');

        $this->postJson("/api/empresas/{$this->empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'lineas' => [[
                'descripcion' => 'BARRA REDONDA TITANIO 50mm',
                'forma_id' => Forma::where('nombre', 'BARRA REDONDA')->value('id'),
                'material_id' => Material::where('nombre', 'TITANIO GR2')->value('id'),
                'diametro_mm' => 50,
                'cantidad' => 3,
                'unidad_venta_id' => $metro,
                'unidad_factura_id' => $kilo,
                // Exactamente el que da la formula, escrito a mano.
                'factor_conversion' => 8.8554,
                'precio_por_kilo' => 48,
            ]],
        ])->assertCreated();

        $linea = ConsultaLinea::latest('id')->firstOrFail();

        $this->assertSame(ConsultaLinea::FACTOR_MANUAL, $linea->origen_factor);
        $this->assertFalse($linea->factor_calculado);
        $this->assertSame($this->usuario->id, $linea->factor_cargado_por);
    }

    private function consultaDePrueba(): Consulta
    {
        return Consulta::create([
            'empresa_id' => $this->empresa->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Borrador',
            'usuario_id' => $this->usuario->id,
        ]);
    }
}

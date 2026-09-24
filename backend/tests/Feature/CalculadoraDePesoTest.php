<?php

namespace Tests\Feature;

use App\Models\CanoEstandar;
use App\Models\Forma;
use App\Models\Material;
use App\Services\CalculadoraDePeso;
use App\Services\CalculadoraFactor;
use App\Services\EvaluadorDeFormulas;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La calculadora de peso.
 *
 * Lo que se prueba acá no es que el codigo corra: es que los kilos den bien.
 * Un peso mal calculado se convierte en un precio mal cotizado, y eso no lo
 * ve nadie hasta que llega la factura.
 */
class CalculadoraDePesoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
    }

    private function calc(): CalculadoraDePeso
    {
        return app(CalculadoraDePeso::class);
    }

    private function material(string $nombre): Material
    {
        return Material::where('nombre', $nombre)->firstOrFail();
    }

    private function forma(string $nombre): Forma
    {
        return Forma::where('nombre', $nombre)->firstOrFail();
    }

    /** @return array<string, array{valor: float, unidad: string}> */
    private function medidas(array $pares): array
    {
        return collect($pares)
            ->map(fn ($v) => ['valor' => $v[0], 'unidad' => $v[1] ?? 'mm'])
            ->all();
    }

    /**
     * Pesos sacados a mano, uno por forma.
     *
     * @return array<string, array{0: string, 1: array, 2: float}>
     */
    public static function pesos(): array
    {
        return [
            // Barra redonda de 50 mm y 1 m: pi*(2.5cm)^2*100cm = 1963.4954 cm3.
            'barra redonda' => ['BARRA REDONDA', ['diameter' => [50], 'length' => [1000]], 1963.4954],
            // Cuadrada de 50 mm: 5*5*100 = 2500 cm3.
            'barra cuadrada' => ['BARRA CUADRADA', ['side' => [50], 'length' => [1000]], 2500.0],
            // Hexagonal entre caras 30 mm: 0.866*(3cm)^2*100cm = 779.4225 cm3.
            'barra hexagonal' => ['BARRA HEXAGONAL', ['across' => [30], 'length' => [1000]], 779.4225],
            // Chapa 1000 x 3 x 2000 mm: 100*0.3*200 = 6000 cm3.
            'chapa' => ['CHAPA', ['width' => [1000], 'height' => [3], 'length' => [2000]], 6000.0],
            // Disco de 200 mm x 20 mm: pi*(10cm)^2*2cm = 628.3185 cm3.
            'disco' => ['DISCO', ['diameter' => [200], 'height' => [20]], 628.3185],
            // Esfera de 100 mm: (4/3)*pi*(5cm)^3 = 523.5988 cm3.
            'esfera' => ['ESFERA', ['diameter' => [100]], 523.5988],
            // Anillo 200/120 x 25 mm: pi/4*(20^2-12^2)*2.5 = 502.6548 cm3.
            'anillo' => ['ANILLO', ['outer' => [200], 'inner' => [120], 'height' => [25]], 502.6548],
        ];
    }

    #[DataProvider('pesos')]
    public function test_el_volumen_da_lo_que_da_la_cuenta_a_mano(
        string $forma,
        array $medidas,
        float $volumenEsperado,
    ): void {
        $r = $this->calc()->calcular(
            $this->material('AISI 304'),
            $this->forma($forma),
            $this->medidas($medidas),
        );

        $this->assertTrue($r['ok'], $r['motivo'] ?? '');
        $this->assertEqualsWithDelta($volumenEsperado, $r['volumen_por_pieza_cm3'], 0.001);
    }

    public function test_el_ejemplo_del_documento(): void
    {
        // Barra redonda de 25 mm x 6 m, 10 piezas.
        $r = $this->calc()->calcular(
            $this->material('AISI 304'),
            $this->forma('BARRA REDONDA'),
            $this->medidas(['diameter' => [25, 'mm'], 'length' => [6, 'm']]),
            piezas: 10,
        );

        $this->assertEqualsWithDelta(2945.2431, $r['volumen_por_pieza_cm3'], 0.001);
        // El peso sale de la densidad que tenga cargada el material.
        $esperado = 2945.2431 * (float) $this->material('AISI 304')->densidad * 10 / 1000;
        $this->assertEqualsWithDelta($esperado, $r['peso_total_kg'], 0.01);
    }

    public function test_el_volumen_unitario_y_el_total_son_distintos(): void
    {
        // Barra redonda de 25 mm x 6 m, 10 piezas.
        $r = $this->calc()->calcular(
            $this->material('AISI 304'),
            $this->forma('BARRA REDONDA'),
            $this->medidas(['diameter' => [25, 'mm'], 'length' => [6, 'm']]),
            piezas: 10,
        );

        // Lo que da la formula es una pieza; el total es eso por diez.
        $this->assertEqualsWithDelta(2945.2431, $r['volumen_por_pieza_cm3'], 0.001);
        $this->assertEqualsWithDelta(29452.4311, $r['volumen_total_cm3'], 0.01);

        // Y el peso sigue la misma logica.
        $this->assertEqualsWithDelta(
            $r['peso_por_pieza_kg'] * 10,
            $r['peso_total_kg'],
            0.001,
        );

        // El resultado en la unidad elegida es el TOTAL, no el unitario.
        $this->assertEqualsWithDelta($r['peso_total_kg'], $r['resultado'], 0.0001);
    }

    public function test_las_unidades_se_convierten_antes_de_la_cuenta(): void
    {
        $enPulgadas = $this->calc()->calcular(
            $this->material('AISI 304'),
            $this->forma('BARRA REDONDA'),
            $this->medidas(['diameter' => [1, 'in'], 'length' => [1, 'ft']]),
        );

        $enMilimetros = $this->calc()->calcular(
            $this->material('AISI 304'),
            $this->forma('BARRA REDONDA'),
            $this->medidas(['diameter' => [25.4, 'mm'], 'length' => [304.8, 'mm']]),
        );

        $this->assertEqualsWithDelta(
            $enMilimetros['peso_total_kg'],
            $enPulgadas['peso_total_kg'],
            0.0001,
        );
    }

    public function test_el_resultado_se_puede_pedir_en_otra_unidad(): void
    {
        $medidas = $this->medidas(['diameter' => [50], 'length' => [1000]]);
        $material = $this->material('AISI 304');
        $forma = $this->forma('BARRA REDONDA');

        $kg = $this->calc()->calcular($material, $forma, $medidas)['resultado'];
        $g = $this->calc()->calcular($material, $forma, $medidas, 1, 'g')['resultado'];
        $lb = $this->calc()->calcular($material, $forma, $medidas, 1, 'lb')['resultado'];

        // El kilo viene redondeado a 4 decimales, asi que multiplicarlo por mil
        // arrastra hasta 0.05 g: la tolerancia va sobre eso, no sobre el gramo.
        $this->assertEqualsWithDelta($kg * 1000, $g, 0.1);
        $this->assertEqualsWithDelta($kg * 2.2046226218, $lb, 0.01);
    }

    public function test_elegir_un_cano_da_lo_mismo_que_cargar_sus_medidas(): void
    {
        $cano = CanoEstandar::where('nombre', '4"')->where('schedule', '40')->firstOrFail();

        $deLaLista = $this->calc()->calcular(
            $this->material('NIQUEL 201'),
            $this->forma('CAÑO'),
            $this->medidas(['length' => [3, 'm']]),
            cano: $cano,
        );

        $aMano = $this->calc()->calcular(
            $this->material('NIQUEL 201'),
            $this->forma('CAÑO'),
            $this->medidas([
                'outer' => [(float) $cano->diametro_mm],
                'wall' => [(float) $cano->pared_mm],
                'length' => [3, 'm'],
            ]),
        );

        $this->assertTrue($deLaLista['ok']);
        $this->assertSame($deLaLista['peso_total_kg'], $aMano['peso_total_kg']);
    }

    /**
     * @return array<string, array{0: string, 1: array, 2: string}>
     */
    public static function invalidos(): array
    {
        return [
            'anillo al reves' => ['ANILLO', ['outer' => [100], 'inner' => [120], 'height' => [10]], 'interior'],
            'pared imposible' => ['TUBO', ['outer' => [20], 'wall' => [10], 'length' => [1000]], 'pared'],
            'falta una medida' => ['TUBO', ['outer' => [60], 'wall' => [0], 'length' => [1000]], 'Falta'],
            'todo en cero' => ['BARRA REDONDA', ['diameter' => [0], 'length' => [0]], 'Falta'],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_no_devuelve_un_peso_cuando_la_medida_no_cierra(
        string $forma,
        array $medidas,
        string $enElMotivo,
    ): void {
        $r = $this->calc()->calcular(
            $this->material('AISI 304'),
            $this->forma($forma),
            $this->medidas($medidas),
        );

        $this->assertFalse($r['ok']);
        $this->assertNull($r['peso_total_kg']);
        $this->assertStringContainsStringIgnoringCase($enElMotivo, $r['motivo']);
    }

    /**
     * Una forma sin formula no puede dar cero.
     *
     * Es la falla peligrosa: cero se ve como un numero, se suma sin protestar y
     * nadie lo revisa. Un null obliga a mirarlo. Se prueban las dos formas que
     * hoy no tienen cuenta —BRIDA y PERFIL— con medidas cargadas y sin ellas.
     *
     * @return array<string, array{0: string, 1: array}>
     */
    public static function sinFormula(): array
    {
        return [
            'brida con medidas' => ['BRIDA', ['diameter' => [100], 'height' => [20]]],
            'brida sin medidas' => ['BRIDA', []],
            'perfil con medidas' => ['PERFIL', ['width' => [80], 'height' => [40], 'length' => [6000]]],
            'perfil sin medidas' => ['PERFIL', []],
        ];
    }

    #[DataProvider('sinFormula')]
    public function test_una_forma_sin_formula_nunca_da_cero(string $forma, array $medidas): void
    {
        $r = $this->calc()->calcular(
            $this->material('AISI 304'),
            $this->forma($forma),
            $this->medidas($medidas),
            piezas: 5,
        );

        // No es un calculo valido.
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no tiene calculo automatico', $r['motivo']);

        // Y NINGUNO de los derivados es cero: todos vienen vacios.
        foreach ([
            'peso_total_kg', 'peso_por_pieza_kg',
            'volumen_total_cm3', 'volumen_por_pieza_cm3', 'resultado',
        ] as $campo) {
            $this->assertNull(
                $r[$campo],
                "{$campo} tiene que venir vacio, no cero: un cero se suma sin que nadie lo note."
            );
            $this->assertNotSame(0, $r[$campo]);
            $this->assertNotSame(0.0, $r[$campo]);
        }
    }

    /** Y lo mismo al guardar la linea: peso_kg queda vacio, no en cero. */
    public function test_al_guardar_una_forma_sin_formula_el_peso_queda_vacio(): void
    {
        $empresa = \App\Models\Empresa::create(['nombre' => 'CLIENTE']);
        $usuario = \App\Models\User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($usuario);

        $this->postJson("/api/empresas/{$empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'lineas' => [[
                'descripcion' => 'BRIDA AISI 304 4"',
                'forma_id' => $this->forma('BRIDA')->id,
                'material_id' => $this->material('AISI 304')->id,
                'cantidad' => 5,
                'precio_unitario' => 120,
                'calc_piezas' => 5,
                'calc_medidas' => ['diameter' => ['valor' => 100, 'unidad' => 'mm']],
            ]],
        ])->assertCreated();

        $linea = \App\Models\ConsultaLinea::latest('id')->firstOrFail();

        // Sin peso: vacio, no cero.
        $this->assertNull($linea->peso_kg);
        $this->assertFalse($linea->calculo['ok']);
        $this->assertNull($linea->calculo['peso_total_kg']);
        $this->assertStringContainsString('no tiene calculo automatico', $linea->calculo['motivo']);

        // Pero la linea se cotiza igual: 5 x 120 = 600.
        $this->assertEqualsWithDelta(600.0, (float) $linea->importe, 0.01);
    }

    public function test_el_factor_por_metro_sale_del_mismo_motor(): void
    {
        // Titanio GR2 (4.51 g/cm3) en barra redonda de 50 mm: 8.8554 kg/m.
        $factor = app(CalculadoraFactor::class)->calcular(
            $this->material('TITANIO GR2'),
            $this->forma('BARRA REDONDA'),
            50, null, null,
        );

        $this->assertEqualsWithDelta(8.8554, $factor['factor'], 0.0001);

        // Y tiene que ser exactamente el peso de un metro segun la calculadora.
        $unMetro = $this->calc()->calcular(
            $this->material('TITANIO GR2'),
            $this->forma('BARRA REDONDA'),
            $this->medidas(['diameter' => [50], 'length' => [1, 'm']]),
        );

        $this->assertEqualsWithDelta($unMetro['peso_por_pieza_kg'], $factor['factor'], 0.0001);
    }

    public function test_la_foto_del_calculo_guarda_con_que_se_calculo(): void
    {
        $r = $this->calc()->calcular(
            $this->material('AISI 304'),
            $this->forma('BARRA REDONDA'),
            $this->medidas(['diameter' => [25, 'mm'], 'length' => [6, 'm']]),
            piezas: 10,
        );

        // Sin esto, corregir una densidad mañana cambiaria lo cotizado ayer.
        $this->assertSame('AISI 304', $r['material']);
        $this->assertSame((float) $this->material('AISI 304')->densidad, $r['densidad_g_cm3']);
        $this->assertSame('S30400', $r['uns']);
        $this->assertStringContainsString('pi', $r['formula']);
        $this->assertSame(10.0, $r['piezas']);

        // Y como venia cargada cada medida, no solo el milimetro.
        $this->assertSame(6.0, $r['medidas']['length']['valor']);
        $this->assertSame('m', $r['medidas']['length']['unidad']);
        $this->assertSame(6000.0, $r['medidas']['length']['valor_mm']);
    }

    public function test_corregir_una_densidad_no_cambia_lo_ya_cotizado(): void
    {
        $empresa = \App\Models\Empresa::create(['nombre' => 'CLIENTE DE PRUEBA']);
        $usuario = \App\Models\User::factory()->create();

        $consulta = \App\Models\Consulta::create([
            'empresa_id' => $empresa->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'usuario_id' => $usuario->id,
            'estado' => 'Confirmada',
        ]);

        $r = $this->calc()->calcular(
            $this->material('AISI 304'),
            $this->forma('BARRA REDONDA'),
            $this->medidas(['diameter' => [25], 'length' => [6, 'm']]),
            piezas: 10,
        );

        // forceCreate: la foto del calculo la escribe el servidor, no el
        // navegador, y el modelo la tiene cerrada al fill.
        $linea = \App\Models\ConsultaLinea::forceCreate([
            'consulta_id' => $consulta->id,
            'orden' => 1,
            'descripcion' => 'AISI 304 BARRA REDONDA 25 X 6000',
            'calculo' => $r,
            'peso_kg' => $r['peso_total_kg'],
        ]);

        $pesoDelDia = (float) $linea->peso_kg;
        $densidadDelDia = $r['densidad_g_cm3'];

        // Alguien corrige la densidad del material meses despues.
        Material::where('nombre', 'AISI 304')->update(['densidad' => 7.93]);

        $linea->refresh();

        // La cotizacion vieja sigue diciendo lo mismo, y con que densidad.
        $this->assertSame($pesoDelDia, (float) $linea->peso_kg);
        // Al volver del JSON un 8.0 llega como 8: se compara el numero, no el tipo.
        $this->assertEqualsWithDelta($densidadDelDia, $linea->calculo['densidad_g_cm3'], 0.0001);
        $this->assertNotEquals(7.93, $linea->calculo['densidad_g_cm3']);

        // Y una cotizacion nueva ya usa la densidad corregida.
        $nuevo = $this->calc()->calcular(
            $this->material('AISI 304')->refresh(),
            $this->forma('BARRA REDONDA'),
            $this->medidas(['diameter' => [25], 'length' => [6, 'm']]),
            piezas: 10,
        );

        $this->assertSame(7.93, $nuevo['densidad_g_cm3']);
        $this->assertNotEqualsWithDelta($pesoDelDia, $nuevo['peso_total_kg'], 0.01);
    }

    /** @return array<string, array{0: string}> */
    public static function formulasPeligrosas(): array
    {
        return [
            'llamada a funcion' => ['exec("rm -rf /")'],
            'funcion no permitida' => ['system(1)'],
            'caracter raro' => ['1 + $x'],
            'parentesis sin cerrar' => ['(1 + 2'],
            'basura al final' => ['1 + 2)'],
        ];
    }

    #[DataProvider('formulasPeligrosas')]
    public function test_no_ejecuta_cualquier_cosa_que_venga_en_la_formula(string $formula): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(EvaluadorDeFormulas::class)->evaluar($formula, []);
    }
}

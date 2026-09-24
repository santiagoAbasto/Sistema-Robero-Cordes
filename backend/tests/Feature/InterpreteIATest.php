<?php

namespace Tests\Feature;

use App\Services\InterpreteIA;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La IA prepara, la persona confirma.
 *
 * Cubre los tres casos que importan: con credencial, sin credencial, y cuando
 * el servicio se cae. En ninguno de los tres el sistema puede quedar sin poder
 * cargar la cotización.
 */
class InterpreteIATest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
    }

    public function test_lee_el_pedido_con_la_ia_cuando_hay_credencial(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode(['lineas' => [[
                            'descripcion' => '6 UN HASTELLOY C-276 BAR RED 38.1 X 145MM',
                            'material' => 'HASTELLOY C-276',
                            'forma' => 'BARRA REDONDA',
                            'dimensiones' => '38.1 X 145 MM',
                            'diametro_mm' => 38.1,
                            'cantidad' => 6,
                            'unidad' => 'UN',
                        ]]]),
                    ],
                ]],
            ]),
        ]);

        $resultado = app(InterpreteIA::class)->interpretar('6 barras de hastelloy de 38 x 145');

        $this->assertTrue($resultado['con_ia']);
        $this->assertCount(1, $resultado['lineas']);

        $linea = $resultado['lineas'][0];
        $this->assertSame('HASTELLOY C-276', $linea['material']);
        $this->assertNotNull($linea['material_id']);
        $this->assertSame('BARRA REDONDA', $linea['forma']);
        $this->assertSame(6.0, $linea['cantidad']);
        $this->assertSame('UN', $linea['unidad']);
    }

    public function test_usa_las_reglas_cuando_no_hay_credencial(): void
    {
        config(['services.openai.key' => null]);
        Http::fake();

        $resultado = app(InterpreteIA::class)->interpretar(
            "6 UN HASTELLOY C-276 BAR RED 38.1 X 145MM\n2 caños de niquel 201 de 4\" SCH 40 X 3000 MM"
        );

        $this->assertFalse($resultado['con_ia']);
        $this->assertStringContainsString('no esta configurada', $resultado['aviso']);
        $this->assertCount(2, $resultado['lineas']);

        // Sin credencial no se llama a nadie.
        Http::assertNothingSent();
    }

    public function test_si_la_ia_falla_sigue_andando_con_las_reglas(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);
        Http::fake(['*/chat/completions' => Http::response('se cayo', 500)]);

        $resultado = app(InterpreteIA::class)->interpretar('6 UN HASTELLOY C-276 BAR RED 38.1 X 145MM');

        $this->assertFalse($resultado['con_ia']);
        $this->assertCount(1, $resultado['lineas']);
    }

    #[DataProvider('fallas')]
    public function test_el_aviso_dice_por_que_fallo(int $estado, string $esperado): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'x']], $estado)]);

        $resultado = app(InterpreteIA::class)->interpretar('6 UN HASTELLOY C-276 BAR RED 38.1 X 145MM');

        $this->assertFalse($resultado['con_ia']);
        $this->assertStringContainsString($esperado, $resultado['aviso']);
        // Pase lo que pase, la linea se tiene que poder cargar igual.
        $this->assertCount(1, $resultado['lineas']);
    }

    public static function fallas(): array
    {
        return [
            'sin credito' => [429, 'no tiene credito'],
            'credencial mala' => [401, 'credencial'],
            'servicio caido' => [500, 'no esta respondiendo'],
        ];
    }

    /**
     * El mail del cliente ya dice que quiere las dos vias: si el sistema lo
     * lee, nadie tiene que volver a escribirlo.
     */
    public function test_arma_las_alternativas_que_pidio_el_cliente(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['lineas' => [[
                    'descripcion' => '200 caños HASTELLOY C-276 1" sch 40 x 327mm',
                    'material' => 'HASTELLOY C-276',
                    'cantidad' => 200,
                    'unidad' => 'UN',
                    'alternativas' => [
                        ['etiqueta' => 'Maritimo', 'tipo' => 'Transporte'],
                        ['etiqueta' => 'Aereo', 'tipo' => 'Transporte'],
                    ],
                ]]])]]],
            ]),
        ]);

        $linea = app(InterpreteIA::class)
            ->interpretar('200 caños hastelloy, coticen aerea y maritima')['lineas'][0];

        $this->assertCount(2, $linea['alternativas']);
        $this->assertSame('Maritimo', $linea['alternativas'][0]['etiqueta']);
        $this->assertSame('Transporte', $linea['alternativas'][0]['tipo']);
        // Alguna tiene que contar para el total: la primera queda de base.
        $this->assertTrue($linea['alternativas'][0]['es_base']);
        $this->assertFalse($linea['alternativas'][1]['es_base']);

        // Sin precio: el cliente pide, la persona cotiza.
        $this->assertNull($linea['alternativas'][0]['precio_unitario']);
    }

    public function test_una_sola_variante_no_es_una_alternativa(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['lineas' => [[
                    'descripcion' => '10 barras',
                    'cantidad' => 10,
                    'alternativas' => [['etiqueta' => 'Maritimo', 'tipo' => 'Transporte']],
                ]]])]]],
            ]),
        ]);

        $linea = app(InterpreteIA::class)->interpretar('10 barras')['lineas'][0];

        // Con una sola opcion no hay nada que elegir: es la linea y ya.
        $this->assertSame([], $linea['alternativas']);
    }

    public function test_no_acepta_un_tipo_de_alternativa_inventado(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['lineas' => [[
                    'descripcion' => '10 barras',
                    'cantidad' => 10,
                    'alternativas' => [
                        ['etiqueta' => 'Una', 'tipo' => 'ColorDeLaCaja'],
                        ['etiqueta' => 'Otra', 'tipo' => 'Cantidad'],
                    ],
                ]]])]]],
            ]),
        ]);

        $linea = app(InterpreteIA::class)->interpretar('10 barras')['lineas'][0];

        // Un tipo que no existe cae en "Otra", no rompe ni se guarda como vino.
        $this->assertSame('Otra', $linea['alternativas'][0]['tipo']);
        $this->assertSame('Cantidad', $linea['alternativas'][1]['tipo']);
    }

    public function test_no_inventa_un_material_que_no_existe(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['lineas' => [[
                    'descripcion' => '3 UN MATERIAL RARO 10 MM',
                    'material' => 'ALEACION INVENTADA',
                    'forma' => 'BARRA',
                    'cantidad' => 3,
                    'unidad' => 'UN',
                ]]])]]],
            ]),
        ]);

        $resultado = app(InterpreteIA::class)->interpretar('3 un material raro');

        // El material no está en el catálogo: queda vacío, no se inventa.
        $this->assertNull($resultado['lineas'][0]['material']);
        $this->assertNull($resultado['lineas'][0]['material_id']);
        // Pero la descripción del cliente se conserva tal cual.
        $this->assertSame('3 UN MATERIAL RARO 10 MM', $resultado['lineas'][0]['descripcion']);
        // Y la linea queda sin dar por buena, para que alguien la mire: cotizar
        // una aleacion parecida a la pedida es peor que no cotizar.
        $this->assertFalse($resultado['lineas'][0]['igual_a_lo_pedido']);
    }
}

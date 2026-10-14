<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El pedido que llega como captura de pantalla.
 *
 * La IA solo transcribe la imagen; lo que es forma, medida o material lo
 * deciden las mismas reglas que con el texto pegado. Una captura llega en
 * vertical —la forma en un renglon, y abajo cada medida y el material con su
 * rotulo— y es UN item, no tres renglones sueltos.
 */
class CapturaDePedidoTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSCRIPCION = "BARRA CUADRADA\nSeccion: 12 mm\nLongitud: 1000 mm\nMaterial: Acero ASTM A276 420";

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));
    }

    public function test_lee_una_captura_vertical_como_un_item(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => self::TRANSCRIPCION]]],
        ])]);

        $r = $this->post('/api/consultas/interpretar',
            ['imagen' => UploadedFile::fake()->image('captura.png', 500, 400)],
            ['Accept' => 'application/json'],
        )->assertOk();

        // La transcripcion vuelve para revisarla.
        $r->assertJsonPath('texto', self::TRANSCRIPCION);

        // Es UN item, con su forma, su medida y el material como lo que pidio.
        $lineas = $r->json('lineas');
        $this->assertCount(1, $lineas);
        $this->assertSame('BARRA CUADRADA', $lineas[0]['forma']);
        $this->assertEqualsWithDelta(1000.0, $lineas[0]['largo_mm'], 0.001);
        $this->assertFalse($lineas[0]['igual_a_lo_pedido']);
        $this->assertSame('Acero ASTM A276 420', $lineas[0]['pedido_material']);
    }

    /**
     * La tabla de TOSAMA (14-10), copiada por la IA de a una celda por
     * renglon. Leida renglon por renglon cada "114 mm" era un item con
     * cantidad 114; son cuatro items, uno por fila.
     */
    public function test_una_tabla_copiada_en_vertical_es_un_item_por_fila(): void
    {
        $vertical = "Item\nDiámetro externo\nLargo\nMaterial\ncant\n"
            ."1\n114 mm\n105 mm\nINCONEL\n1\n"
            ."2\n140 mm\n150 mm\nINCONEL\n3\n"
            ."3\n94 mm\n81 mm\nINCONEL\n2\n"
            ."4\n195 mm\n62 mm\nINCONEL\n2";

        config(['services.openai.key' => 'clave-de-prueba']);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => $vertical]]],
        ])]);

        $lineas = $this->post('/api/consultas/interpretar',
            ['imagen' => UploadedFile::fake()->image('tabla.png', 500, 200)],
            ['Accept' => 'application/json'],
        )->assertOk()->json('lineas');

        $this->assertCount(4, $lineas);
        $this->assertSame([1, 3, 2, 2], array_map(fn ($l) => (int) $l['cantidad'], $lineas));
        $this->assertEqualsWithDelta(114.0, $lineas[0]['diametro_mm'], 0.001);
        $this->assertEqualsWithDelta(105.0, $lineas[0]['largo_mm'], 0.001);
        $this->assertEqualsWithDelta(62.0, $lineas[3]['largo_mm'], 0.001);

        // "INCONEL" a secas no es ninguno de los Inconel del catalogo: queda
        // como lo escribio el cliente, para que alguien elija.
        $this->assertSame('INCONEL', $lineas[0]['pedido_material']);
        $this->assertNull($lineas[0]['material_id']);

        // Y el pedido a la IA dice que las tablas van fila por fila.
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'separadas por \" | \"'));
    }

    /** Cada medida va a su columna por el titulo, no por el orden en que viene. */
    public function test_una_tabla_por_filas_se_lee_por_titulo(): void
    {
        $lineas = $this->postJson('/api/consultas/interpretar', [
            'texto' => "Material | Largo (m) | Diámetro | Cantidad\nINCONEL | 3 | 25 | 4",
        ])->assertOk()->json('lineas');

        $this->assertCount(1, $lineas);
        $this->assertEqualsWithDelta(25.0, $lineas[0]['diametro_mm'], 0.001);
        // Sin unidad en la celda vale la del titulo: 3 metros, no 3 mm.
        $this->assertEqualsWithDelta(3000.0, $lineas[0]['largo_mm'], 0.001);
        $this->assertSame(4, (int) $lineas[0]['cantidad']);
    }

    /**
     * La orden de compra del codo (Roberto, 14-10): la IA copia las celdas
     * vacias del final como "| | |", que se pierden, y la columna "Producto"
     * trae el item entero. Salia un renglon con barras, el numero de OT y la
     * fecha adentro, sin material.
     */
    public function test_una_orden_de_compra_con_producto_y_celdas_vacias(): void
    {
        // La forma esta entre las del sistema anterior; prendida, se reconoce
        // aunque el cliente escriba 90º con el ordinal.
        \App\Models\Forma::create(['nombre' => 'CODO 90°', 'clave' => 'codo_90', 'activo' => true]);

        $lineas = $this->postJson('/api/consultas/interpretar', [
            'texto' => "Nro | Producto | Unid | Cant. | Pto-OT | F. Necesidad del Producto | Precio U. | Precio Tot. (S/IVA) | Plazo Entrega\n"
                .'1 | (28862) CODO 90º RL S/COSTURA P/SOLDAR ASME B16.9 ASTM A403 AISI 310 ØN6" (168,3MM) SCH40S (ESP 7,11MM) | Un. | 1 | 9138 | 29/10/2026 | | |',
        ])->assertOk()->json('lineas');

        $this->assertCount(1, $lineas);
        $this->assertSame(1, (int) $lineas[0]['cantidad']);
        $this->assertSame('UN', $lineas[0]['unidad']);
        $this->assertSame('CODO 90°', $lineas[0]['forma']);
        $this->assertSame('6" (168,3MM) SCH40S', $lineas[0]['dimensiones']);
        // El material que nombro queda como lo que pidio, sin elegir un grado.
        $this->assertSame('AISI 310', $lineas[0]['pedido_material']);
        $this->assertFalse($lineas[0]['igual_a_lo_pedido']);
        // Ni las barras, ni la OT, ni la fecha en la descripcion.
        $this->assertStringNotContainsString('|', $lineas[0]['descripcion']);
        $this->assertStringNotContainsString('9138', $lineas[0]['descripcion']);
    }

    /** A una fila le falta una celda: la tabla termina ahi, sin armar items corridos. */
    public function test_una_tabla_corrida_no_inventa_items(): void
    {
        $lineas = $this->postJson('/api/consultas/interpretar', [
            'texto' => "Item\nDiámetro externo\nLargo\nMaterial\ncant\n"
                ."1\n114 mm\n105 mm\nINCONEL\n1\n"
                // A esta fila le falta el largo.
                ."2\n140 mm\nINCONEL\n3\n"
                ."3\n94 mm\n81 mm\nINCONEL\n2",
        ])->assertOk()->json('lineas');

        $this->assertCount(1, $lineas);
        $this->assertSame(1, (int) $lineas[0]['cantidad']);
    }

    public function test_sin_credencial_no_lee_la_imagen_y_lo_dice(): void
    {
        config(['services.openai.key' => null]);
        Http::fake();

        $this->post('/api/consultas/interpretar',
            ['imagen' => UploadedFile::fake()->image('captura.png')],
            ['Accept' => 'application/json'],
        )->assertStatus(422);

        Http::assertNothingSent();
    }
}

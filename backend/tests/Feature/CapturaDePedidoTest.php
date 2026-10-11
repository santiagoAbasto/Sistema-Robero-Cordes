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

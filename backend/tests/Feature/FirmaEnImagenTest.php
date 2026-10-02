<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La firma que llega como imagen: la IA solo copia el texto, y lo que se
 * reconoce lo deciden las mismas reglas que con el texto pegado.
 */
class FirmaEnImagenTest extends TestCase
{
    use RefreshDatabase;

    /** Lo que copia la IA de una firma armada como imagen, con iconos en vez de rotulos. */
    private const TRANSCRIPCION = "Lucas Ferrari\nGerente Comercial\nTel: +54 11 4321-9876\nCel: +54 9 11 6543-2109\nlferrari@valvulasdelsur.com.ar\nwww.valvulasdelsur.com.ar";

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));
    }

    private function leer(array $cuerpo)
    {
        return $this->post('/api/empresas/leer-firma', $cuerpo, ['Accept' => 'application/json']);
    }

    public function test_lee_la_firma_de_una_imagen_con_las_mismas_reglas(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => self::TRANSCRIPCION]]],
        ])]);

        $this->leer(['imagen' => UploadedFile::fake()->image('firma.png', 600, 200)])
            ->assertOk()
            ->assertJsonPath('texto', self::TRANSCRIPCION)
            ->assertJsonPath('con_ia', true)
            ->assertJsonPath('datos.contacto', 'Lucas Ferrari')
            ->assertJsonPath('datos.mail', 'lferrari@valvulasdelsur.com.ar')
            ->assertJsonPath('datos.telefono', '+54 11 4321-9876');

        // La imagen viaja dentro del pedido, y a la IA solo se le pide copiar.
        Http::assertSent(fn ($r) => str_starts_with(
            (string) data_get($r->data(), 'messages.1.content.0.image_url.url'),
            'data:image/png;base64,',
        ));
    }

    public function test_sin_credencial_no_llama_a_nadie_y_lo_dice(): void
    {
        config(['services.openai.key' => null]);
        Http::fake();

        $this->leer(['imagen' => UploadedFile::fake()->image('firma.png')])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Para leer una imagen hace falta la IA, y no esta configurada. Pega el texto o cargalo a mano.');

        Http::assertNothingSent();
    }

    public function test_si_la_ia_falla_dice_por_que(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);
        Http::fake(['*/chat/completions' => Http::response('sin credito', 429)]);

        $this->leer(['imagen' => UploadedFile::fake()->image('firma.png')])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La cuenta de la IA no tiene credito o llego al limite de consultas. Pega el texto o cargalo a mano.');
    }

    public function test_si_se_corta_la_conexion_no_tira_error_500(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);
        Http::fake(['*/chat/completions' => Http::failedConnection()]);

        $this->leer(['imagen' => UploadedFile::fake()->image('firma.png')])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El servicio de IA no esta respondiendo. Pega el texto o cargalo a mano.');
    }

    public function test_solo_imagenes_y_chicas(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);
        Http::fake();

        $this->leer(['imagen' => UploadedFile::fake()->create('pedido.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('imagen');
        $this->leer(['imagen' => UploadedFile::fake()->image('enorme.png')->size(3000)])
            ->assertStatus(422)->assertJsonValidationErrors('imagen');

        Http::assertNothingSent();
    }

    /** Mas grande que upload_max_filesize: PHP la corta antes de Laravel. */
    public function test_la_imagen_que_php_no_dejo_subir_dice_el_motivo(): void
    {
        $cortada = new UploadedFile('', 'a.png', 'image/png', UPLOAD_ERR_INI_SIZE, true);

        $this->leer(['imagen' => $cortada])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'La imagen pesa mas de 2 MB'));
    }

    public function test_el_texto_se_sigue_leyendo_sin_ia(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);
        Http::fake();

        $this->leer(['texto' => "Juan Perez\nCel 1145672389"])
            ->assertOk()
            ->assertJsonPath('con_ia', false)
            ->assertJsonPath('datos.contacto', 'Juan Perez');

        $this->leer([])->assertStatus(422)->assertJsonValidationErrors('texto');

        Http::assertNothingSent();
    }
}

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

    /** Respuesta simulada de la IA cuando se le pide completar la firma. */
    private function iaResponde(array $datos): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode($datos)]]],
        ])]);
    }

    /**
     * La IA apoya a las reglas pero no puede inventar.
     *
     * Lo que propone y no esta escrito en el texto se descarta: aca la IA
     * "deduce" una empresa, un mail y un cargo que el texto no dice.
     */
    public function test_la_ia_no_agrega_nada_que_no_este_escrito(): void
    {
        $this->iaResponde(['empresa' => 'ACME', 'mail' => 'jperez@acme.com.ar', 'cargo' => 'Compras']);

        $this->leer(['texto' => "Juan Perez\nCel 1145672389"])
            ->assertOk()
            ->assertJsonPath('con_ia', false)
            ->assertJsonPath('datos.contacto', 'Juan Perez')
            ->assertJsonPath('datos.empresa', null)
            ->assertJsonPath('datos.mail', null)
            ->assertJsonPath('datos.cargo', null);

        // Solo se le preguntan los que faltan: el contacto ya lo leyeron las reglas.
        Http::assertSent(fn ($r) => ! str_contains($r['messages'][0]['content'], 'contacto,')
            && str_contains($r['messages'][0]['content'], 'empresa'));

        $this->leer([])->assertStatus(422)->assertJsonValidationErrors('texto');
    }

    /** Lo que las reglas no leen y esta escrito, lo completa la IA, y se avisa. */
    public function test_la_ia_completa_lo_que_las_reglas_no_leyeron(): void
    {
        // Sin rotulos ni formas conocidas: las reglas no sacan el cargo ni la direccion.
        $texto = "Juan Perez\nresponsable de compras y abastecimiento\nMetalurgica del Plata\nTel. 4555-3700\nSan Martin 455 piso 2";
        $this->iaResponde([
            'cargo' => 'responsable de compras y abastecimiento',
            'empresa' => 'Metalurgica del Plata',
            'direccion' => 'San Martin 455',
        ]);

        $r = $this->leer(['texto' => $texto])->assertOk()->assertJsonPath('con_ia', true);

        $this->assertSame('responsable de compras y abastecimiento', $r->json('datos.cargo'));
        $this->assertSame('Metalurgica del Plata', $r->json('datos.empresa'));
        $this->assertStringContainsString('La IA completo', $r->json('mensaje'));
    }

    /** Sin clave, el texto se lee como siempre, solo con reglas, y no se llama a nadie. */
    public function test_sin_ia_el_texto_se_lee_igual(): void
    {
        config(['services.openai.key' => null]);

        $this->leer(['texto' => "Juan Perez\nCel 1145672389"])
            ->assertOk()
            ->assertJsonPath('con_ia', false)
            ->assertJsonPath('datos.contacto', 'Juan Perez');

        Http::assertNothingSent();
    }

    /**
     * Las dos lecturas de la imagen no coinciden en el mail: se avisa con las dos.
     *
     * Paso con la firma de JMH: una lectura salio "gmenendez@" y la otra bien.
     */
    public function test_si_las_dos_lecturas_no_coinciden_avisa_que_revisar(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);
        $lectura = fn (string $mail) => Http::response(['choices' => [['message' => ['content' => "Lucas Ferrari\nTel: +54 11 4321-9876\n{$mail}"]]]]);
        Http::fake(['*/chat/completions' => Http::sequence()
            ->pushResponse($lectura('lferari@acme.com.ar'))
            ->pushResponse($lectura('lferrari@acme.com.ar'))
            ->whenEmpty(Http::response(['choices' => [['message' => ['content' => '{}']]]]))]);

        $r = $this->leer(['imagen' => UploadedFile::fake()->image('firma.png', 600, 200)])->assertOk();

        $this->assertStringContainsString('lferari@acme.com.ar', $r->json('mensaje'));
        $this->assertStringContainsString('lferrari@acme.com.ar', $r->json('mensaje'));
    }

    /**
     * Una tercera lectura desempata: gana el mail que coincide dos veces.
     *
     * JMH en local: gpt-4o-mini leyo "gmenendez@", gpt-4.1-mini "gmendez@".
     */
    public function test_la_tercera_lectura_desempata(): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);
        $lectura = fn (string $mail) => Http::response(['choices' => [['message' => ['content' => "Lucas Ferrari\nTel: +54 11 4321-9876\n{$mail}"]]]]);
        $nada = Http::response(['choices' => [['message' => ['content' => '{}']]]]);
        Http::fake(['*/chat/completions' => Http::sequence()
            ->pushResponse($lectura('lferari@acme.com.ar'))    // la principal, con la letra de menos
            ->pushResponse($lectura('lferrari@acme.com.ar'))   // la de control
            ->pushResponse($nada)                              // la IA no completa nada mas
            ->pushResponse($lectura('lferrari@acme.com.ar'))]); // el desempate

        $r = $this->leer(['imagen' => UploadedFile::fake()->image('firma.png', 600, 200)])->assertOk();

        $this->assertSame('lferrari@acme.com.ar', $r->json('datos.mail'));
        $this->assertStringNotContainsString('Mira la imagen', $r->json('mensaje'));
    }
}

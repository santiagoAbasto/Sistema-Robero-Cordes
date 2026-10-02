<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Localidad;
use App\Models\Pais;
use App\Models\Provincia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Del pie del mail a la ficha, sin huecos.
 *
 * La localidad que no esta en la lista se ubica con Google, y la web y las
 * redes quedan como enlaces de la ficha. Las direcciones y personas son
 * inventadas.
 */
class AltaDesdeLaFirmaTest extends TestCase
{
    use RefreshDatabase;

    private Provincia $bsas;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));
        $argentina = Pais::create(['nombre' => 'Argentina']);
        $this->bsas = Provincia::create(['nombre' => 'Buenos Aires', 'pais_id' => $argentina->id]);
    }

    /** Google responde por la direccion que se le pregunta, con la altura que se le pase. */
    private function googleDice(string $altura): void
    {
        config(['services.google.places_key' => 'clave-de-prueba']);
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => [['addressComponents' => [
            ['longText' => $altura, 'types' => ['street_number']],
            ['longText' => 'Avenida Siempreviva', 'types' => ['route']],
            ['longText' => 'San Justo', 'types' => ['locality']],
            ['longText' => 'Partido de La Matanza', 'types' => ['administrative_area_level_2']],
            ['longText' => 'Provincia de Buenos Aires', 'types' => ['administrative_area_level_1']],
            ['longText' => 'Argentina', 'types' => ['country']],
            ['longText' => 'B1754', 'types' => ['postal_code']],
        ]]]])]);
    }

    private function leer(string $texto)
    {
        return $this->postJson('/api/empresas/leer-firma', ['texto' => $texto])->assertOk();
    }

    /** "Av. Siempreviva 742, San Justo": San Justo no esta en la lista y Google la ubica. */
    public function test_la_localidad_que_no_esta_la_ubica_google(): void
    {
        $this->googleDice('742');

        $r = $this->leer("Lucia Benitez\nAv. Siempreviva 742, San Justo\nWhatsApp: 11-4321-9876");

        $id = $r->json('datos.localidad_id');
        $this->assertNotNull($id);
        $this->assertSame('San Justo', Localidad::find($id)->nombre);
        $this->assertSame($this->bsas->id, $r->json('datos.provincia_id'));
        $this->assertSame('B1754', $r->json('datos.codigo_postal'));
        $this->assertSame('Av. Siempreviva 742', $r->json('datos.direccion'));
        $this->assertArrayNotHasKey('direccion_completa', $r->json('datos'));
    }

    /** Si Google encontro otra altura, no es esa direccion: no se toca nada. */
    public function test_otra_altura_no_cambia_la_localidad(): void
    {
        $this->googleDice('1500');

        $this->leer("Lucia Benitez\nAv. Siempreviva 742, San Justo\nWhatsApp: 11-4321-9876")
            ->assertJsonPath('datos.localidad_id', null);

        $this->assertSame(0, Localidad::count());
    }

    /** Sin la ciudad despues de la calle no hay a quien preguntarle: no se llama a Google. */
    public function test_sin_ciudad_no_se_pregunta(): void
    {
        $this->googleDice('742');

        $this->leer("Lucia Benitez\nAv. Siempreviva 742\nWhatsApp: 11-4321-9876");

        Http::assertNothingSent();
    }

    /** La web y las redes del mail se guardan como enlaces al crear la empresa. */
    public function test_los_enlaces_se_guardan_con_la_empresa(): void
    {
        $r = $this->postJson('/api/empresas', [
            'nombre' => 'ACME SRL',
            'enlaces' => [
                ['tipo' => 'Web', 'url' => 'www.acme.com.ar'],
                ['tipo' => 'Instagram', 'url' => 'instagram.com/acme.argentina'],
            ],
        ])->assertCreated();

        $enlaces = Empresa::find($r->json('data.id'))->enlaces()->orderBy('orden')->get();

        $this->assertSame(['Web', 'Instagram'], $enlaces->pluck('tipo')->all());
        $this->assertSame('https://www.acme.com.ar', $enlaces[0]->url_completa);

        $this->postJson('/api/empresas', ['nombre' => 'OTRA', 'enlaces' => [['tipo' => 'Fax', 'url' => 'x']]])
            ->assertStatus(422);
    }

    /** Agregar contacto con la firma de una empresa que ya tiene su web: no la repite. */
    public function test_el_enlace_que_ya_estaba_no_se_repite(): void
    {
        $empresa = Empresa::create(['nombre' => 'ACME SRL']);
        $empresa->enlaces()->create(['tipo' => 'Web', 'url' => 'https://www.acme.com.ar/', 'orden' => 1]);

        $this->postJson("/api/empresas/{$empresa->id}/enlaces", ['tipo' => 'Web', 'url' => 'www.acme.com.ar'])
            ->assertOk()->assertJsonPath('mensaje', 'Ese enlace ya estaba.');
        $this->postJson("/api/empresas/{$empresa->id}/enlaces", ['tipo' => 'Instagram', 'url' => 'instagram.com/acme'])
            ->assertOk();

        $this->assertSame(2, $empresa->enlaces()->count());
    }
}

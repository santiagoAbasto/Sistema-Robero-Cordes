<?php

namespace Tests\Feature;

use App\Models\Localidad;
use App\Models\Pais;
use App\Models\Provincia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Elegir una direccion de Google y que calce con nuestras listas.
 *
 * THORSA, General Deheza 3146: Google dice "Provincia de Buenos Aires" y
 * "Remedios de Escalada". Ninguna de las dos calzaba y habia que elegirlas
 * a mano, sin poder agregar la localidad.
 */
class DireccionDetalleTest extends TestCase
{
    use RefreshDatabase;

    private Provincia $bsas;

    protected function setUp(): void
    {
        parent::setUp();

        $argentina = Pais::create(['nombre' => 'Argentina']);
        $this->bsas = Provincia::create(['pais_id' => $argentina->id, 'nombre' => 'Buenos Aires']);
        Provincia::create(['pais_id' => $argentina->id, 'nombre' => 'Ciudad Autónoma de Buenos Aires']);

        Sanctum::actingAs(User::factory()->create());
        config(['services.google.places_key' => 'una-clave']);
    }

    private function elegir(array $componentes)
    {
        Http::fake(['places.googleapis.com/*' => Http::response([
            'formattedAddress' => 'x',
            'addressComponents' => collect($componentes)
                ->map(fn ($texto, $tipo) => ['longText' => $texto, 'shortText' => $texto, 'types' => [$tipo]])
                ->values()->all(),
        ])]);

        return $this->getJson('/api/direcciones/detalle?id=ChIJthorsa')->assertOk();
    }

    private const THORSA = [
        'street_number' => '3146',
        'route' => 'General Deheza',
        'locality' => 'Remedios de Escalada',
        'administrative_area_level_2' => 'Partido de Lanús',
        'administrative_area_level_1' => 'Provincia de Buenos Aires',
        'country' => 'Argentina',
        'postal_code' => 'B1823',
    ];

    public function test_provincia_de_buenos_aires_calza_con_buenos_aires(): void
    {
        $this->elegir(self::THORSA)->assertJsonPath('provincia_id', $this->bsas->id);
    }

    public function test_la_ciudad_que_no_esta_se_agrega_una_sola_vez(): void
    {
        $id = $this->elegir(self::THORSA)->json('localidad_id');

        $this->assertNotNull($id);
        $this->assertSame($this->bsas->id, Localidad::find($id)->provincia_id);
        $this->assertSame('Remedios de Escalada', Localidad::find($id)->nombre);

        // La segunda vez la encuentra: no la repite.
        $this->elegir(self::THORSA)->assertJsonPath('localidad_id', $id);
        $this->assertSame(1, Localidad::count());
    }

    public function test_el_partido_no_se_agrega_como_localidad(): void
    {
        $sinCiudad = self::THORSA;
        unset($sinCiudad['locality']);

        $this->elegir($sinCiudad)->assertJsonPath('localidad_id', null);
        $this->assertSame(0, Localidad::count());
    }

    public function test_sin_provincia_conocida_no_se_agrega_nada(): void
    {
        $this->elegir(['administrative_area_level_1' => 'Provincia de Narnia'] + self::THORSA)
            ->assertJsonPath('localidad_id', null);
        $this->assertSame(0, Localidad::count());
    }
}

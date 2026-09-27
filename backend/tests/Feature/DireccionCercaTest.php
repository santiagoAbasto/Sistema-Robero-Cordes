<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dónde busca Google la dirección.
 *
 * Decirle nada más "Argentina" es decirle poco. Con la ficha de APEX marcada
 * en Santa Rosa, La Pampa, escribir "Parque Industrial, Calle 9 esq. 10"
 * devolvía Bariloche, Villalonga, Victorica y San Luis: ninguna servía.
 * La ciudad ya elegida en la ficha viaja en la consulta.
 */
class DireccionCercaTest extends TestCase
{
    use RefreshDatabase;

    /** Lo que Google recibió como texto a buscar. */
    private function loBuscado(array $params): string
    {
        Sanctum::actingAs(User::factory()->create());
        config(['services.google.places_key' => 'una-clave']);
        Http::fake(['places.googleapis.com/*' => Http::response(['suggestions' => []])]);

        $this->getJson('/api/direcciones/sugerencias?'.http_build_query($params))->assertOk();

        $enviado = '';
        Http::assertSent(function ($peticion) use (&$enviado) {
            $enviado = $peticion->data()['input'] ?? '';

            return true;
        });

        return $enviado;
    }

    public function test_la_ciudad_de_la_ficha_acota_la_busqueda(): void
    {
        $this->assertSame(
            'Parque Industrial, Calle 9 esq. 10, Santa Rosa, La Pampa',
            $this->loBuscado([
                'q' => 'Parque Industrial, Calle 9 esq. 10',
                'cerca' => 'Santa Rosa, La Pampa',
            ]),
        );
    }

    public function test_sin_ciudad_elegida_busca_como_antes(): void
    {
        $this->assertSame(
            'Cid Campeador 375',
            $this->loBuscado(['q' => 'Cid Campeador 375']),
        );
    }

    public function test_no_repite_la_ciudad_si_ya_esta_escrita(): void
    {
        // Mayúsculas y puntuación no cuentan: se compara sólo la localidad.
        $this->assertSame(
            'Mitre 300 - SAN CARLOS DE BARILOCHE',
            $this->loBuscado([
                'q' => 'Mitre 300 - SAN CARLOS DE BARILOCHE',
                'cerca' => 'San Carlos de Bariloche, Rio Negro',
            ]),
        );
    }
}

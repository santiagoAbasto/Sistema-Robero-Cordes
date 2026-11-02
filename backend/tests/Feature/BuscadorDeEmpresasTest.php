<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El buscador del indice ordena por RELEVANCIA, no solo alfabetico.
 *
 * Buscar una empresa por su nombre costaba horrores: el buscador matchea
 * tambien el material de las lineas de cotizacion, y al ordenar alfabetico
 * cualquier cotizacion con ese texto quedaba antes que la empresa cuyo nombre
 * EMPIEZA con lo buscado, a varias paginas de distancia. (Nombres ficticios: el
 * repo es publico.)
 */
class BuscadorDeEmpresasTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_empresa_cuyo_nombre_coincide_va_primero(): void
    {
        $usuario = User::factory()->create(['role' => 'Administrador']);
        Sanctum::actingAs($usuario);

        // Alfabeticamente primera, pero solo matchea por una linea de cotizacion.
        $acme = Empresa::create(['nombre' => 'ACME METALURGICA']);
        $consulta = Consulta::create([
            'empresa_id' => $acme->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-11-01',
            'estado' => 'Confirmada',
            'usuario_id' => $usuario->id,
        ]);
        ConsultaLinea::create([
            'consulta_id' => $consulta->id,
            'orden' => 1,
            'descripcion' => 'BARRA ZORZAL 20MM',
        ]);

        // Matchea por el nombre, pero en el medio (contiene "zor").
        Empresa::create(['nombre' => 'BAZOR SRL']);

        // Matchea por el nombre y lo EMPIEZA: tiene que ir primera.
        Empresa::create(['nombre' => 'ZORBA FUNDICIONES']);

        $datos = $this->getJson('/api/empresas?buscar=zor')
            ->assertOk()
            ->json('data');

        $nombres = array_column($datos, 'nombre');

        // Las tres matchean; el orden por relevancia: empieza con > contiene > por una linea.
        $this->assertSame('ZORBA FUNDICIONES', $nombres[0], 'la que empieza con lo buscado tiene que ir primera');
        $this->assertContains('ACME METALURGICA', $nombres, 'la que matchea por la cotizacion igual aparece');
        $this->assertGreaterThan(
            array_search('BAZOR SRL', $nombres, true),
            array_search('ACME METALURGICA', $nombres, true),
            'la que sólo matchea por una línea de cotización va después de la que coincide por nombre',
        );
    }
}

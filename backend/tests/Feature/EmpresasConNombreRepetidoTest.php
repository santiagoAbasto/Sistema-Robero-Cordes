<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El indice viejo trae 66 nombres repetidos en 173 fichas.
 *
 * El nombre unico se controlaba en cada guardado, y esas fichas no se podian
 * modificar: "Ya existe una empresa con ese nombre", aunque solo se tocara la
 * observacion.
 */
class EmpresasConNombreRepetidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_ficha_repetida_se_modifica_sin_cambiarle_el_nombre(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));

        Empresa::create(['nombre' => 'FABESA']);
        $repetida = Empresa::create(['nombre' => 'FABESA']);

        $this->putJson("/api/empresas/{$repetida->id}", ['nombre' => 'FABESA', 'observacion_general' => 'Atiende de 8 a 12'])
            ->assertOk();

        $this->assertSame('Atiende de 8 a 12', $repetida->fresh()->observacion_general);
    }

    public function test_no_se_le_puede_poner_el_nombre_de_otra(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));

        Empresa::create(['nombre' => 'FABESA']);
        $otra = Empresa::create(['nombre' => 'FERRUM']);

        $this->putJson("/api/empresas/{$otra->id}", ['nombre' => 'FABESA'])
            ->assertStatus(422)
            ->assertJsonFragment(['nombre' => ['Ya existe una empresa con ese nombre.']]);

        $this->postJson('/api/empresas', ['nombre' => 'FABESA'])->assertStatus(422);
    }
}

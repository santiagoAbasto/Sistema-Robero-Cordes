<?php

namespace Tests\Feature;

use App\Models\Forma;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alta de un material desde la cotizacion, con todos sus datos.
 *
 * Antes nacia solo con el nombre (densidad null). Ahora se cargan nombre,
 * familia, forma habitual y densidad de una, para que el catalogo quede
 * completo. (MONEL 400 es un grado de aleacion, no un cliente.)
 */
class AltaDeMaterialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));
    }

    public function test_crea_el_material_con_todos_sus_datos(): void
    {
        $forma = Forma::create(['nombre' => 'BARRA REDONDA']);

        $this->postJson('/api/catalogos/materiales', [
            'nombre' => 'MONEL 400',
            'familia' => 'NIQUEL',
            'forma_habitual_id' => $forma->id,
            'densidad' => 8.83,
        ])->assertCreated()
            ->assertJsonPath('nombre', 'MONEL 400')
            ->assertJsonPath('familia', 'NIQUEL');

        $material = Material::where('nombre', 'MONEL 400')->firstOrFail();
        $this->assertSame('NIQUEL', $material->familia);
        $this->assertSame($forma->id, $material->forma_habitual_id);
        $this->assertEquals(8.83, (float) $material->densidad);
        $this->assertTrue((bool) $material->activo);
    }

    public function test_no_duplica_y_completa_los_datos_que_falten(): void
    {
        // Como los que nacieron al vuelo: con nombre pero sin densidad.
        $previo = Material::create(['nombre' => 'MONEL 400', 'densidad' => null, 'activo' => true]);

        $this->postJson('/api/catalogos/materiales', [
            'nombre' => 'monel 400', // misma, en minúscula
            'densidad' => 8.83,
        ])->assertOk()
            ->assertJsonPath('id', $previo->id);

        $this->assertSame(1, Material::where('nombre', 'MONEL 400')->count(), 'no se duplica');
        $this->assertEquals(8.83, (float) $previo->fresh()->densidad, 'completó la densidad que faltaba');
    }

    public function test_no_pisa_un_dato_que_ya_tiene(): void
    {
        $previo = Material::create(['nombre' => 'MONEL 400', 'densidad' => 8.83, 'activo' => true]);

        $this->postJson('/api/catalogos/materiales', ['nombre' => 'MONEL 400', 'densidad' => 9.99])
            ->assertOk();

        $this->assertEquals(8.83, (float) $previo->fresh()->densidad, 'no pisa la densidad ya cargada');
    }

    /** Completar la densidad de un material que ya existe, desde la cotización. */
    public function test_completa_la_densidad_de_un_material_existente(): void
    {
        $material = Material::create(['nombre' => 'ACERO 1045', 'densidad' => null, 'activo' => true]);

        $this->putJson("/api/catalogos/materiales/{$material->id}", ['densidad' => 7.85])
            ->assertOk()
            ->assertJsonPath('id', $material->id);

        $this->assertEquals(7.85, (float) $material->fresh()->densidad, 'se guardó la densidad que faltaba');
    }
}

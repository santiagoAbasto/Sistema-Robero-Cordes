<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Una observacion de la empresa (una llamada, una visita) no es una oferta.
 *
 * Se cargaba como una cotizacion: vencia a los 7 dias, aparecia en
 * Seguimiento como por vencer, y "Emitir e imprimir" le daba un numero de la
 * serie de las cotizaciones.
 */
class ObservacionesDeLaEmpresaTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_observacion_no_vence_ni_se_emite(): void
    {
        $this->seed(CatalogosSeeder::class);
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));
        $empresa = Empresa::create(['nombre' => 'APEX']);

        $id = $this->postJson("/api/empresas/{$empresa->id}/consultas", [
            'tipo' => 'Observacion',
            'fecha' => '2026-10-01',
            'texto' => 'Llamó Pablo: pide que le coticemos en pesos.',
        ])->assertCreated()->json('data.id');

        $this->assertNull(Consulta::find($id)->vence_el);

        $this->postJson("/api/consultas/{$id}/emitir")->assertStatus(422);

        // Ni por el otro camino que numera: cambiarle el estado.
        $this->postJson("/api/consultas/{$id}/estado", ['estado' => 'Confirmada'])->assertOk();
        $this->assertNull(Consulta::find($id)->numero);

        // Copiada salia vacia en las otras empresas: el texto no se copia.
        $otra = Empresa::create(['nombre' => 'FERRUM']);
        $this->postJson("/api/consultas/{$id}/copiar", ['empresas' => [$otra->id]])->assertStatus(422);
        $this->assertSame(0, Consulta::where('empresa_id', $otra->id)->count());
    }
}

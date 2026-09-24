<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\HistorialCambio;
use App\Models\Observacion;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cerrar una cotizacion deja dicho cuando y por que.
 *
 * Un estado suelto no sirve seis meses despues: "cerrada" no distingue entre
 * que el cliente eligio a otro, que se cayo el proyecto o que el precio no
 * daba, y eso es lo que se necesita para volver a cotizarle.
 */
class EstadoYSeguimientoTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Consulta $consulta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);

        $this->usuario = User::factory()->create();
        Sanctum::actingAs($this->usuario);

        $this->consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'FRANOR SRL'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Confirmada',
            'usuario_id' => $this->usuario->id,
        ]);
    }

    private function cambiar(array $datos)
    {
        return $this->postJson("/api/consultas/{$this->consulta->id}/estado", $datos);
    }

    /** Los dos finales dicen por que se llego a ellos. */
    public function test_los_estados_de_cierre_son_dos_y_estan_en_el_catalogo(): void
    {
        $this->assertSame(
            ['Cerrada por declinacion', 'Vencida por tiempo'],
            Consulta::ESTADOS_DE_CIERRE,
        );

        $this->getJson('/api/catalogos')
            ->assertOk()
            ->assertJsonFragment(['estados_de_cierre' => Consulta::ESTADOS_DE_CIERRE]);
    }

    public function test_cerrar_sin_comentario_no_se_puede(): void
    {
        $this->cambiar(['estado' => Consulta::CERRADA])
            ->assertStatus(422)
            ->assertJsonValidationErrors('comentario');

        $this->assertSame('Confirmada', $this->consulta->fresh()->estado);
    }

    public function test_cerrar_con_comentario_deja_el_motivo_en_el_hilo(): void
    {
        $this->cambiar([
            'estado' => Consulta::CERRADA,
            'comentario' => 'Compraron en Europa, 12% mas barato.',
        ])->assertOk();

        $this->assertSame(Consulta::CERRADA, $this->consulta->fresh()->estado);

        $obs = Observacion::where('consulta_id', $this->consulta->id)->first();

        $this->assertNotNull($obs, 'el motivo entra al hilo de observaciones');
        $this->assertStringContainsString('Compraron en Europa', $obs->texto);
        $this->assertStringContainsString('Confirmada -> '.Consulta::CERRADA, $obs->texto);
        $this->assertSame($this->usuario->id, $obs->usuario_id);
    }

    /** El cliente avisa el lunes algo que decidio el viernes. */
    public function test_se_puede_fechar_cuando_paso_de_verdad(): void
    {
        $this->cambiar([
            'estado' => Consulta::VENCIDA,
            'comentario' => 'No contestaron el seguimiento.',
            'fecha' => '2026-09-18',
        ])->assertOk();

        $obs = Observacion::where('consulta_id', $this->consulta->id)->firstOrFail();

        $this->assertSame('2026-09-18', $obs->fecha->toDateString());
    }

    /** Un estado que no existe no entra. */
    public function test_un_estado_inventado_se_rechaza(): void
    {
        $this->cambiar(['estado' => 'Cancelada por lluvia'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    /** Volver a Confirmada no obliga a comentar: no es un cierre. */
    public function test_reabrir_no_pide_comentario(): void
    {
        $this->consulta->update(['estado' => Consulta::VENCIDA]);

        $this->cambiar(['estado' => 'Confirmada'])->assertOk();

        $this->assertSame('Confirmada', $this->consulta->fresh()->estado);
        $this->assertSame(0, Observacion::where('consulta_id', $this->consulta->id)->count());
    }

    /** Quien lo cambio y de que a que lo escribe solo el historial. */
    public function test_el_cambio_de_estado_queda_en_el_historial(): void
    {
        $this->cambiar([
            'estado' => Consulta::CERRADA,
            'comentario' => 'Se cayo el proyecto.',
        ])->assertOk();

        $this->assertTrue(
            HistorialCambio::where('campo', 'estado')->where('valor_nuevo', Consulta::CERRADA)->exists(),
            'el historial registra el cambio de estado sin que nadie se lo pida',
        );
    }

    /** El hilo numera: seguimiento 1, 2, 3, y el cierre sigue la cuenta. */
    public function test_el_cierre_se_numera_despues_del_seguimiento(): void
    {
        $this->postJson("/api/consultas/{$this->consulta->id}/observaciones",
            ['texto' => 'Lo llame, pidio una semana.'])->assertCreated();

        $this->cambiar([
            'estado' => Consulta::CERRADA,
            'comentario' => 'Nunca volvio.',
        ])->assertOk();

        $numeros = Observacion::where('consulta_id', $this->consulta->id)
            ->orderBy('numero')->pluck('numero')->all();

        $this->assertSame([1, 2], $numeros);
    }
}

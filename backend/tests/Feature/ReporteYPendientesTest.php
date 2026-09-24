<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\Observacion;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El aviso de lo que hay que seguir, y el balance de lo que termino.
 *
 * Los dos salen de la misma pregunta y de las mismas tablas: no hay tabla de
 * notificaciones, porque "esta por vencer" no es un hecho que haya que
 * guardar sino una consecuencia de la fecha.
 */
class ReporteYPendientesTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);

        $this->usuario = User::factory()->create();
        Sanctum::actingAs($this->usuario);
        $this->empresa = Empresa::create(['nombre' => 'FRANOR SRL']);
    }

    private function cotizacion(array $datos = []): Consulta
    {
        return Consulta::create(array_merge([
            'empresa_id' => $this->empresa->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Confirmada',
            'usuario_id' => $this->usuario->id,
        ], $datos));
    }

    // ------------------------------------------------------------- campanita

    public function test_la_campanita_cuenta_lo_que_necesita_atencion(): void
    {
        $this->cotizacion(['vence_el' => now()->addDays(2)->toDateString()]);
        $this->cotizacion(['vence_el' => now()->addDays(5)->toDateString()]);
        $this->cotizacion(['vence_el' => now()->subDays(3)->toDateString()]);
        // Ruido que no tiene que contar.
        $this->cotizacion(['vence_el' => now()->addDays(40)->toDateString()]);
        $this->cotizacion(['vence_el' => now()->addDay()->toDateString(), 'estado' => 'Vendida']);

        $r = $this->getJson('/api/seguimiento/pendientes')->assertOk()->json();

        $this->assertSame(2, $r['por_vencer']['cuantas']);
        $this->assertSame(1, $r['vencidas_sin_cerrar']['cuantas']);
        $this->assertSame(7, $r['por_vencer']['dias']);
    }

    /** El desplegable muestra las mas urgentes primero, no cualquiera. */
    public function test_la_campanita_trae_las_mas_urgentes_primero(): void
    {
        $this->cotizacion(['vence_el' => now()->addDays(6)->toDateString()]);
        $urgente = $this->cotizacion(['vence_el' => now()->addDay()->toDateString()]);

        $primeras = $this->getJson('/api/seguimiento/pendientes')
            ->assertOk()->json('por_vencer.primeras');

        $this->assertSame($urgente->id, $primeras[0]['id']);
        $this->assertSame(1, $primeras[0]['dias_para_vencer']);
        $this->assertSame('FRANOR SRL', $primeras[0]['empresa']);
    }

    /** Nada pendiente es un cero, no un error. */
    public function test_sin_nada_pendiente_devuelve_cero(): void
    {
        $r = $this->getJson('/api/seguimiento/pendientes')->assertOk()->json();

        $this->assertSame(0, $r['por_vencer']['cuantas']);
        $this->assertSame([], $r['por_vencer']['primeras']);
    }

    // --------------------------------------------------------------- reporte

    /** Un reporte sin fecha de generacion no se puede comparar con otro. */
    public function test_el_reporte_dice_cuando_se_genero_y_de_que_periodo_es(): void
    {
        $r = $this->getJson('/api/reportes/seguimiento?desde=2026-01-01&hasta=2026-09-30')
            ->assertOk()->json();

        $this->assertNotEmpty($r['generado_el']);
        $this->assertSame('2026-01-01', $r['periodo']['desde']);
        $this->assertSame('2026-09-30', $r['periodo']['hasta']);
    }

    public function test_las_cerradas_se_separan_por_motivo(): void
    {
        $this->cotizacion(['estado' => Consulta::CERRADA]);
        $this->cotizacion(['estado' => Consulta::CERRADA]);
        $this->cotizacion(['estado' => Consulta::VENCIDA]);
        $this->cotizacion(['estado' => 'Vendida']);

        $r = $this->getJson('/api/reportes/seguimiento')->assertOk()->json();

        $this->assertSame(3, $r['cerradas']['total']);
        $this->assertSame(1, $r['vendidas']['total']);

        $porMotivo = collect($r['cerradas']['por_motivo'])->keyBy('motivo');

        $this->assertSame(2, $porMotivo[Consulta::CERRADA]['cuantas']);
        $this->assertSame(1, $porMotivo[Consulta::VENCIDA]['cuantas']);
    }

    /**
     * El numero que duele: se perdieron sin que nadie las llamara.
     */
    public function test_cuenta_las_cerradas_que_nunca_tuvieron_seguimiento(): void
    {
        $conSeguimiento = $this->cotizacion(['estado' => Consulta::CERRADA]);
        Observacion::create([
            'consulta_id' => $conSeguimiento->id,
            'numero' => 1,
            'fecha' => now(),
            'usuario_id' => $this->usuario->id,
            'texto' => 'Lo llame dos veces.',
        ]);

        $this->cotizacion(['estado' => Consulta::CERRADA]);
        $this->cotizacion(['estado' => Consulta::VENCIDA]);

        $r = $this->getJson('/api/reportes/seguimiento')->assertOk()->json();

        $this->assertSame(2, $r['seguimiento']['sin_ningun_seguimiento']);
        $this->assertEqualsWithDelta(0.3, $r['seguimiento']['promedio_por_cerrada'], 0.05);
    }

    /** Lo de afuera del periodo no entra. */
    public function test_el_periodo_recorta(): void
    {
        $this->cotizacion(['estado' => Consulta::CERRADA, 'fecha' => '2025-03-10']);
        $this->cotizacion(['estado' => Consulta::CERRADA, 'fecha' => '2026-03-10']);

        $r = $this->getJson('/api/reportes/seguimiento?desde=2026-01-01&hasta=2026-12-31')
            ->assertOk()->json();

        $this->assertSame(1, $r['cerradas']['total']);
    }

    public function test_un_periodo_al_reves_se_rechaza(): void
    {
        $this->getJson('/api/reportes/seguimiento?desde=2026-09-30&hasta=2026-01-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('hasta');
    }
}

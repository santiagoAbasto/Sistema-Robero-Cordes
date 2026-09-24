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
 * Las dos listas con las que se trabaja el seguimiento.
 *
 * "Por vencer" es la util: son las que todavia se pueden salvar llamando.
 * "Cerradas" es la de consulta: por que se perdieron, para saber a quien
 * volver a ofrecerle y a quien no.
 */
class PorVencerYCerradasTest extends TestCase
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

    private function lista(array $query): array
    {
        return $this->getJson('/api/consultas?'.http_build_query($query))
            ->assertOk()->json('data');
    }

    public function test_por_vencer_trae_las_que_vencen_dentro_del_plazo(): void
    {
        $pronto = $this->cotizacion(['vence_el' => now()->addDays(3)->toDateString()]);
        $this->cotizacion(['vence_el' => now()->addDays(30)->toDateString()]);
        $this->cotizacion(['vence_el' => now()->subDay()->toDateString()]);

        $ids = array_column($this->lista(['por_vencer' => 7]), 'id');

        $this->assertSame([$pronto->id], $ids);
    }

    /** Una cerrada o una vendida no se sigue, aunque tenga fecha. */
    public function test_por_vencer_no_trae_las_que_ya_terminaron(): void
    {
        $this->cotizacion(['vence_el' => now()->addDays(2)->toDateString(), 'estado' => Consulta::CERRADA]);
        $this->cotizacion(['vence_el' => now()->addDays(2)->toDateString(), 'estado' => 'Vendida']);

        $this->assertSame([], $this->lista(['por_vencer' => 7]));
    }

    /** Las 7.148 traidas del sistema anterior no tienen vencimiento cargado. */
    public function test_sin_fecha_de_vencimiento_no_aparece_en_por_vencer(): void
    {
        $this->cotizacion(['vence_el' => null]);

        $this->assertSame([], $this->lista(['por_vencer' => 7]));
    }

    public function test_cerradas_trae_los_dos_motivos_y_nada_mas(): void
    {
        $declinada = $this->cotizacion(['estado' => Consulta::CERRADA]);
        $vencida = $this->cotizacion(['estado' => Consulta::VENCIDA]);
        $this->cotizacion(['estado' => 'Confirmada']);
        $this->cotizacion(['estado' => 'Vendida']);

        $ids = array_column($this->lista(['cerradas' => 1]), 'id');

        sort($ids);
        $esperado = [$declinada->id, $vencida->id];
        sort($esperado);

        $this->assertSame($esperado, $ids);
    }

    /** Cuantas veces se le siguio el rastro: el hilo mas los envios. */
    public function test_la_lista_dice_cuantos_seguimientos_tiene(): void
    {
        $c = $this->cotizacion(['vence_el' => now()->addDays(2)->toDateString()]);

        foreach (['Lo llame, pidio precio en dolares.', 'Mande la hoja de nuevo.'] as $i => $texto) {
            Observacion::create([
                'consulta_id' => $c->id,
                'numero' => $i + 1,
                'fecha' => now(),
                'usuario_id' => $this->usuario->id,
                'texto' => $texto,
            ]);
        }

        $fila = $this->lista(['por_vencer' => 7])[0];

        $this->assertSame(2, $fila['seguimientos']);
    }

    public function test_dice_cuantos_dias_faltan_y_es_negativo_si_ya_paso(): void
    {
        $this->cotizacion(['vence_el' => now()->addDays(3)->toDateString()]);

        $this->assertSame(3, $this->lista(['por_vencer' => 7])[0]['dias_para_vencer']);

        $vencida = $this->cotizacion(['vence_el' => now()->subDays(5)->toDateString()]);

        $this->assertSame(-5, $this->getJson("/api/consultas/{$vencida->id}")
            ->assertOk()->json('data.dias_para_vencer'));
    }
}

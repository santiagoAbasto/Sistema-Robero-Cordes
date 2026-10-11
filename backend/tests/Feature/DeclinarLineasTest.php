<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\Moneda;
use App\Models\Unidad;
use App\Models\User;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Items que no se cotizan.
 *
 * Hay items que no se trabajan —no es el material, el plazo, la medida—. La
 * línea queda en la cotización marcada "no se cotiza", con su motivo: sale
 * impresa, no suma al total. Si no se cotiza NINGUNA, la cotización queda
 * "Declinada" (ni borrador ni emitida); si vuelve a cotizarse alguna, se reabre.
 */
class DeclinarLineasTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        Sanctum::actingAs(User::factory()->create());
        $this->empresa = Empresa::create(['nombre' => 'MECANIZADOS MAK']);
    }

    private function cotizar(array $lineas): Consulta
    {
        $un = Unidad::where('codigo', 'UN')->value('id');

        $r = $this->postJson("/api/empresas/{$this->empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'moneda_id' => Moneda::where('nombre', 'DOLAR BILLETE BNA VENDEDOR')->value('id'),
            'lineas' => collect($lineas)->map(fn ($l) => array_merge(['unidad_venta_id' => $un], $l))->all(),
        ])->assertCreated();

        return Consulta::with('lineas')->findOrFail($r->json('data.id'));
    }

    public function test_una_linea_no_cotizada_no_suma_al_total(): void
    {
        $c = $this->cotizar([
            ['descripcion' => 'Barra que sí', 'cantidad' => 2, 'precio_unitario' => 100],
            ['descripcion' => 'Barra que no', 'cantidad' => 1, 'precio_unitario' => 999,
                'no_cotizada' => true, 'motivo_no_cotiza' => 'Por el plazo de entrega'],
        ]);

        // El total toma solo la que se cotiza.
        $this->assertEqualsWithDelta(200.0, $c->fresh('lineas')->total, 0.01);

        $noVa = $c->lineas->firstWhere('no_cotizada', true);
        $this->assertSame('Por el plazo de entrega', $noVa->motivo_no_cotiza);
        $this->assertFalse($noVa->cuentaParaElTotal());

        // Mixta: no es declinada (hay algo cotizado).
        $this->assertNotSame(Consulta::DECLINADA, $c->estado);
    }

    public function test_si_no_se_cotiza_ninguna_queda_declinada(): void
    {
        $c = $this->cotizar([
            ['descripcion' => 'Titanio que no trabajamos', 'cantidad' => 1,
                'no_cotizada' => true, 'motivo_no_cotiza' => 'No trabajamos ese material'],
            ['descripcion' => 'Otra que tampoco', 'cantidad' => 1,
                'no_cotizada' => true, 'motivo_no_cotiza' => 'Por las dimensiones'],
        ]);

        $this->assertSame(Consulta::DECLINADA, $c->fresh()->estado);
        $this->assertEqualsWithDelta(0.0, $c->fresh('lineas')->total, 0.01);
    }

    public function test_se_reabre_si_vuelve_a_cotizarse_alguna(): void
    {
        $c = $this->cotizar([
            ['descripcion' => 'Caño', 'cantidad' => 1, 'no_cotizada' => true,
                'motivo_no_cotiza' => 'No conseguimos el material'],
        ]);
        $this->assertSame(Consulta::DECLINADA, $c->fresh()->estado);

        // Se vuelve a cotizar: PUT con la línea ya cotizada.
        $un = Unidad::where('codigo', 'UN')->value('id');
        $linea = $c->lineas->first();

        $this->putJson("/api/consultas/{$c->id}", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'moneda_id' => $c->moneda_id,
            'lineas' => [[
                'id' => $linea->id, 'descripcion' => 'Caño', 'cantidad' => 1,
                'precio_unitario' => 50, 'unidad_venta_id' => $un, 'no_cotizada' => false,
            ]],
        ])->assertOk();

        $this->assertSame('Confirmada', $c->fresh()->estado);
        $this->assertNull($c->fresh('lineas')->lineas->first()->motivo_no_cotiza);
    }
}

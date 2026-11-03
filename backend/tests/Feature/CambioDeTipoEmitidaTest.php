<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Corregir el tipo (Cotizacion <-> Pedido) de una ya emitida.
 *
 * Al emitir, el selector de tipo queda bloqueado. Si se eligio mal —es facil
 * confundir "Pedido" el tipo con el "N° de pedido" del cliente— hay que poder
 * arreglarlo sin rehacer la cotizacion. El tipo es una clasificacion, no parte
 * de la hoja que ve el cliente.
 */
class CambioDeTipoEmitidaTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);

        $this->usuario = User::factory()->create(['role' => 'Administrador']);
        Sanctum::actingAs($this->usuario);
        $this->empresa = Empresa::create(['nombre' => 'APEX']);
    }

    private function cotizacion(string $tipo = 'Pedido'): Consulta
    {
        $c = Consulta::create([
            'empresa_id' => $this->empresa->id,
            'tipo' => $tipo,
            'fecha' => '2026-09-30',
            'estado' => 'Borrador',
            'usuario_id' => $this->usuario->id,
        ]);

        ConsultaLinea::create([
            'consulta_id' => $c->id,
            'orden' => 1,
            'descripcion' => 'Barra redonda Ø20',
            'cantidad' => 3,
            'precio_unitario' => 100,
            'importe' => 300,
        ]);

        $this->postJson("/api/consultas/{$c->id}/emitir")->assertOk();

        return $c->fresh();
    }

    public function test_una_emitida_cargada_como_pedido_pasa_a_cotizacion(): void
    {
        $c = $this->cotizacion('Pedido');

        $this->postJson("/api/consultas/{$c->id}/tipo", ['tipo' => 'Cotizacion'])
            ->assertOk()
            ->assertJsonPath('data.tipo', 'Cotizacion');

        // Sigue emitida: cambia la clasificacion, no la hoja.
        $this->assertTrue($c->fresh()->estaEmitida());
        $this->assertSame('Cotizacion', $c->fresh()->tipo);
    }

    public function test_cambia_toda_la_familia_de_revisiones(): void
    {
        $c = $this->cotizacion('Pedido');
        $r1 = Consulta::find($this->postJson("/api/consultas/{$c->id}/revision")->json('data.id'));

        // Se corrige desde la R0; la R1 (borrador) tambien queda en Cotizacion.
        $this->postJson("/api/consultas/{$c->id}/tipo", ['tipo' => 'Cotizacion'])->assertOk();

        $this->assertSame('Cotizacion', $c->fresh()->tipo);
        $this->assertSame('Cotizacion', $r1->fresh()->tipo, 'la revision quedo en otra seccion que su original');
    }

    public function test_una_observacion_no_cambia_de_tipo(): void
    {
        $obs = Consulta::create([
            'empresa_id' => $this->empresa->id,
            'tipo' => 'Observacion',
            'fecha' => '2026-09-30',
            'estado' => 'Borrador',
            'texto' => 'Llamó por el pedido de níquel.',
            'usuario_id' => $this->usuario->id,
        ]);

        $this->postJson("/api/consultas/{$obs->id}/tipo", ['tipo' => 'Cotizacion'])
            ->assertStatus(422);

        $this->assertSame('Observacion', $obs->fresh()->tipo);
    }

    public function test_no_acepta_un_tipo_invalido(): void
    {
        $c = $this->cotizacion('Pedido');

        $this->postJson("/api/consultas/{$c->id}/tipo", ['tipo' => 'Observacion'])
            ->assertStatus(422);

        $this->assertSame('Pedido', $c->fresh()->tipo);
    }
}

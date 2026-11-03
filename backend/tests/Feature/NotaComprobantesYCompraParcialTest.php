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
 * Lo que Roberto pidio el 03-11-2026 para la venta:
 * - la NOTA interna se corrige en una emitida sin generar una revision;
 * - los comprobantes de la compra son renglones sin tope (anticipo, saldo,
 *   remito, nota de credito, vale del expreso);
 * - una compra parcial (1 de 3 items) se distingue de una total.
 */
class NotaComprobantesYCompraParcialTest extends TestCase
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

    /** @return array{0: Consulta, 1: array<int, ConsultaLinea>} una emitida con tres items */
    private function emitidaConTresLineas(): array
    {
        $c = Consulta::create([
            'empresa_id' => $this->empresa->id, 'tipo' => 'Cotizacion', 'fecha' => '2026-11-03',
            'estado' => 'Borrador', 'usuario_id' => $this->usuario->id, 'nota' => 'de stock',
        ]);

        $lineas = [];
        foreach ([100, 200, 300] as $i => $importe) {
            $lineas[] = ConsultaLinea::create([
                'consulta_id' => $c->id, 'orden' => $i + 1, 'descripcion' => 'Item '.($i + 1),
                'cantidad' => 1, 'precio_unitario' => $importe, 'importe' => $importe,
            ]);
        }

        $this->postJson("/api/consultas/{$c->id}/emitir")->assertOk();

        return [$c->fresh(), $lineas];
    }

    private function vendida(Consulta $c, array $extra = []): void
    {
        $this->postJson("/api/consultas/{$c->id}/estado", ['estado' => 'Vendida'] + $extra)->assertOk();
    }

    public function test_la_nota_de_una_emitida_se_corrige_sin_revision_nueva(): void
    {
        [$c] = $this->emitidaConTresLineas();

        $this->putJson("/api/consultas/{$c->id}/nota", ['nota' => 'del proveedor X'])->assertOk();

        $this->assertSame('del proveedor X', $c->fresh()->nota);
        $this->assertSame(0, Consulta::where('revision_de_id', $c->id)->count());
        $this->assertTrue($c->fresh()->estaEmitida());

        $this->putJson("/api/consultas/{$c->id}/nota", ['nota' => ''])->assertOk();
        $this->assertNull($c->fresh()->nota);
    }

    public function test_los_comprobantes_se_agregan_editan_y_borran_sin_tope(): void
    {
        [$c] = $this->emitidaConTresLineas();
        $this->vendida($c);

        foreach (['Factura de anticipo', 'Factura de saldo', 'Remito', 'Nota de crédito', 'Vale de retiro (expreso)'] as $i => $tipo) {
            $this->postJson("/api/consultas/{$c->id}/comprobantes", [
                'tipo' => $tipo, 'numero' => '0001-0000'.$i, 'fecha' => '2026-11-0'.($i + 1), 'nota' => 'x',
            ])->assertOk();
        }

        $resp = $this->getJson("/api/consultas/{$c->id}")->assertOk();
        $this->assertCount(5, $resp->json('data.comprobantes'));
        // Las facturas se ven en las listas, no el remito ni la nota de credito.
        $this->assertSame('0001-00000, 0001-00001', $resp->json('data.nro_factura'));

        $id = $resp->json('data.comprobantes.2.id');
        $this->putJson("/api/consultas/{$c->id}/comprobantes/{$id}", ['tipo' => 'Remito', 'numero' => 'R-9', 'nota' => 'llegó de menos'])
            ->assertOk()->assertJsonPath('data.comprobantes.2.numero', 'R-9');

        $this->deleteJson("/api/consultas/{$c->id}/comprobantes/{$id}")->assertOk();
        $this->assertCount(4, $this->getJson("/api/consultas/{$c->id}")->json('data.comprobantes'));
    }

    public function test_un_comprobante_pide_tipo_y_solo_va_en_una_vendida(): void
    {
        [$c] = $this->emitidaConTresLineas();

        $this->postJson("/api/consultas/{$c->id}/comprobantes", ['tipo' => 'Remito'])->assertStatus(422);

        $this->vendida($c);
        $this->postJson("/api/consultas/{$c->id}/comprobantes", ['numero' => '1'])->assertStatus(422);
    }

    public function test_un_comprobante_ajeno_no_se_toca(): void
    {
        [$a] = $this->emitidaConTresLineas();
        [$b] = $this->emitidaConTresLineas();
        $this->vendida($a);
        $this->vendida($b);

        $id = $this->postJson("/api/consultas/{$a->id}/comprobantes", ['tipo' => 'Remito'])->json('data.comprobantes.0.id');

        $this->putJson("/api/consultas/{$b->id}/comprobantes/{$id}", ['tipo' => 'Factura'])->assertNotFound();
        $this->deleteJson("/api/consultas/{$b->id}/comprobantes/{$id}")->assertNotFound();
    }

    public function test_vender_sin_decir_que_lineas_es_compra_total(): void
    {
        [$c] = $this->emitidaConTresLineas();
        $this->vendida($c);

        $compra = $this->getJson("/api/consultas/{$c->id}")->json('data.compra');

        $this->assertFalse($compra['parcial']);
        $this->assertSame(3, $compra['compradas']);
        $this->assertSame(3, $compra['total']);
    }

    public function test_comprar_un_item_de_tres_es_compra_parcial_y_se_corrige(): void
    {
        [$c, $lineas] = $this->emitidaConTresLineas();
        $this->vendida($c, ['lineas_compradas' => [$lineas[1]->id]]);

        $compra = $this->getJson("/api/consultas/{$c->id}")->json('data.compra');
        $this->assertTrue($compra['parcial']);
        $this->assertSame(1, $compra['compradas']);
        $this->assertSame(3, $compra['total']);
        $this->assertEquals(200, $compra['importe']);

        // Se corrige despues: ahora compraron dos.
        $this->putJson("/api/consultas/{$c->id}/compra", ['lineas' => [$lineas[0]->id, $lineas[2]->id]])
            ->assertOk()->assertJsonPath('data.compra.compradas', 2);

        // Y las tres: vuelve a ser total.
        $this->putJson("/api/consultas/{$c->id}/compra", ['lineas' => array_map(fn ($l) => $l->id, $lineas)])
            ->assertOk()->assertJsonPath('data.compra.parcial', false);
    }

    public function test_el_dashboard_dice_si_la_compra_fue_parcial(): void
    {
        [$c, $lineas] = $this->emitidaConTresLineas();
        $this->vendida($c, ['lineas_compradas' => [$lineas[0]->id]]);

        $fila = collect($this->getJson('/api/resumen')->json('ultimas'))->firstWhere('id', $c->id);

        $this->assertTrue($fila['compra']['parcial']);
        // Lo vendido tiene su tarjeta en el dashboard, con su cuenta.
        $this->assertSame(1, $this->getJson('/api/resumen')->json('totales.vendidas'));
    }

    public function test_una_revision_nace_sin_nada_comprado(): void
    {
        [$c, $lineas] = $this->emitidaConTresLineas();
        $this->vendida($c, ['lineas_compradas' => [$lineas[0]->id]]);

        $revId = $this->postJson("/api/consultas/{$c->id}/revision")->json('data.id');

        $this->assertSame(0, ConsultaLinea::where('consulta_id', $revId)->where('comprada', true)->count());
    }

    public function test_los_tipos_de_comprobante_vienen_de_una_lista_editable(): void
    {
        $tipos = $this->getJson('/api/catalogos')->json('tipos_comprobante');

        $this->assertContains('Factura de anticipo', $tipos);
        $this->assertContains('Vale de retiro (expreso)', $tipos);
        $this->getJson('/api/config/listas')->assertJsonStructure(['tipos_comprobante' => [['id', 'nombre', 'orden', 'activo']]]);
    }
}

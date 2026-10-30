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

    /**
     * "¿Como siguio?" (29-10-2026): "La compro" guarda la factura aunque la
     * cotizacion este emitida, y la deja en el hilo con la fecha en que se vendio.
     */
    public function test_vendida_guarda_la_factura_y_la_anota_en_el_hilo(): void
    {
        $this->consulta->forceFill(['emitida_el' => now(), 'numero' => '2026-0001', 'estado' => 'Confirmada'])->save();

        $this->cambiar(['estado' => 'Vendida', 'nro_factura' => '0001-00001234', 'fecha' => '2026-10-28'])->assertOk();

        $this->consulta->refresh();
        $this->assertSame('Vendida', $this->consulta->estado);
        $this->assertSame('0001-00001234', $this->consulta->nro_factura);

        $nota = $this->consulta->observaciones()->latest('id')->first();
        $this->assertStringContainsString('Confirmada -> Vendida. Factura 0001-00001234.', $nota->texto);
        $this->assertSame('2026-10-28', $nota->fecha->toDateString());
    }

    public function test_vendida_sin_factura_no_pide_nada_mas(): void
    {
        $this->consulta->forceFill(['emitida_el' => now(), 'numero' => '2026-0001', 'estado' => 'Confirmada'])->save();

        $this->cambiar(['estado' => 'Vendida'])->assertOk();

        $this->assertSame('Vendida', $this->consulta->fresh()->estado);
        $this->assertNull($this->consulta->fresh()->nro_factura);
    }

    /**
     * Los datos de la venta se van completando (29-10-2026): primero el pedido
     * interno, despues el remito, al final la factura con su tipo de cambio.
     */
    public function test_los_datos_de_la_venta_se_van_completando_y_corrigiendo(): void
    {
        $this->consulta->forceFill(['emitida_el' => now(), 'numero' => '2026-0001', 'estado' => 'Confirmada'])->save();
        $id = $this->consulta->id;

        // Sin vender, no.
        $this->putJson("/api/consultas/{$id}/venta", ['nro_pedido_interno' => '12345'])->assertStatus(422);

        $this->cambiar(['estado' => 'Vendida'])->assertOk();

        $this->putJson("/api/consultas/{$id}/venta", ['nro_pedido_interno' => '12345'])->assertOk();
        $this->putJson("/api/consultas/{$id}/venta", [
            'nro_pedido_interno' => '12345', 'nro_remito' => '0001-00004321',
            'nro_factura' => '0001-00001234', 'tipo_cambio_factura' => '1050.50',
        ])->assertOk();

        $r = $this->getJson("/api/consultas/{$id}")->assertOk();
        $r->assertJsonPath('data.nro_pedido_interno', '12345');
        $r->assertJsonPath('data.nro_remito', '0001-00004321');
        $r->assertJsonPath('data.nro_factura', '0001-00001234');
        $this->assertEquals(1050.5, $r->json('data.tipo_cambio_factura'));

        // Se corrige: el remito estaba mal.
        $this->putJson("/api/consultas/{$id}/venta", [
            'nro_pedido_interno' => '12345', 'nro_remito' => '0001-00004322',
            'nro_factura' => '0001-00001234', 'tipo_cambio_factura' => '1050.50',
        ])->assertOk();
        $this->assertSame('0001-00004322', $this->consulta->fresh()->nro_remito);

        $this->putJson("/api/consultas/{$id}/venta", ['tipo_cambio_factura' => 'mil'])->assertStatus(422);
    }

    /**
     * Cada dato de la venta con su fecha (pedido del cliente, 30-10-2026): la
     * del remito y la factura pueden no coincidir, y al reabrir la cotizacion
     * las fechas tienen que estar.
     */
    public function test_los_datos_de_la_venta_llevan_cada_uno_su_fecha(): void
    {
        $this->consulta->forceFill(['emitida_el' => now(), 'numero' => '2026-0001', 'estado' => 'Confirmada'])->save();
        $id = $this->consulta->id;

        $this->cambiar(['estado' => 'Vendida'])->assertOk();

        $this->putJson("/api/consultas/{$id}/venta", [
            'nro_pedido_interno' => '12345', 'fecha_pedido' => '2026-10-20',
            'nro_remito' => '0001-00004321', 'fecha_remito' => '2026-10-27',
            'nro_factura' => '0001-00001234', 'fecha_factura' => '2026-10-29',
            'tipo_cambio_factura' => '1050.50',
        ])->assertOk();

        $r = $this->getJson("/api/consultas/{$id}")->assertOk();
        $r->assertJsonPath('data.fecha_pedido', '2026-10-20');
        $r->assertJsonPath('data.fecha_remito', '2026-10-27');
        $r->assertJsonPath('data.fecha_factura', '2026-10-29');

        // Una fecha mal escrita no pasa.
        $this->putJson("/api/consultas/{$id}/venta", ['fecha_remito' => 'el martes'])->assertStatus(422);

        // Se puede borrar una fecha que estaba.
        $this->putJson("/api/consultas/{$id}/venta", ['fecha_factura' => null])->assertOk();
        $this->assertNull($this->consulta->fresh()->fecha_factura);
    }

    /** "La compro" con lo que ya se sepa de la venta, en un solo paso (29-10-2026). */
    public function test_la_compro_guarda_los_datos_de_la_venta_en_el_mismo_paso(): void
    {
        $this->consulta->forceFill(['emitida_el' => now(), 'numero' => '2026-0001', 'estado' => 'Confirmada'])->save();

        $this->cambiar([
            'estado' => 'Vendida', 'comentario_venta' => 'encargado al proveedor, OC-1234',
            'nro_pedido_interno' => '88231', 'fecha_pedido' => '2026-10-20',
            'nro_remito' => '0001-00004321', 'tipo_cambio_factura' => '1050.5',
        ])->assertOk();

        $c = $this->consulta->fresh();
        $this->assertSame('Vendida', $c->estado);
        $this->assertSame('88231', $c->nro_pedido_interno);
        $this->assertSame('2026-10-20', $c->fecha_pedido->toDateString());
        $this->assertSame('0001-00004321', $c->nro_remito);
        $this->assertEquals(1050.5, (float) $c->tipo_cambio_factura);
        $this->assertSame('encargado al proveedor, OC-1234', $c->comentario_venta);
        $this->assertNull($c->nro_factura, 'lo que no se escribio queda vacio');
        $this->assertNull($c->fecha_remito, 'la fecha que no se cargo queda vacia');

        // El comentario del pedido es un campo propio, editable: no es una
        // observacion del hilo (pedido del cliente, 30-10-2026).
        $this->assertSame(0, $c->observaciones()->count());
    }

    /**
     * El comentario del pedido se escribe, se actualiza y se puede borrar, y
     * siempre viaja en la cotizacion para mostrarlo (cliente, 30-10-2026:
     * "poner cosas del pedido ... y se pueda modificar/actualizar").
     */
    public function test_el_comentario_del_pedido_se_edita_y_se_actualiza(): void
    {
        $this->consulta->forceFill(['emitida_el' => now(), 'numero' => '2026-0001', 'estado' => 'Confirmada'])->save();
        $id = $this->consulta->id;

        $this->cambiar(['estado' => 'Vendida'])->assertOk();

        $this->putJson("/api/consultas/{$id}/venta", ['comentario_venta' => 'encargado al proveedor, OC-1234'])->assertOk();
        $this->getJson("/api/consultas/{$id}")->assertJsonPath('data.comentario_venta', 'encargado al proveedor, OC-1234');

        $this->putJson("/api/consultas/{$id}/venta", ['comentario_venta' => 'entregado, factura en camino'])->assertOk();
        $this->assertSame('entregado, factura en camino', $this->consulta->fresh()->comentario_venta);

        $this->putJson("/api/consultas/{$id}/venta", ['comentario_venta' => null])->assertOk();
        $this->assertNull($this->consulta->fresh()->comentario_venta);
    }

    /**
     * Con factura la venta se anota en el hilo; sin un "cuando" suelto, esa nota
     * se fecha cuando entro el pedido (30-10-2026).
     */
    public function test_la_nota_de_la_venta_se_fecha_en_el_pedido_si_no_hay_fecha_suelta(): void
    {
        $this->consulta->forceFill(['emitida_el' => now(), 'numero' => '2026-0001', 'estado' => 'Confirmada'])->save();

        $this->cambiar(['estado' => 'Vendida', 'nro_factura' => '0001-00001234', 'fecha_pedido' => '2026-10-20'])->assertOk();

        $nota = $this->consulta->observaciones()->latest('id')->first();
        $this->assertStringContainsString('Confirmada -> Vendida. Factura 0001-00001234.', $nota->texto);
        $this->assertSame('2026-10-20', $nota->fecha->toDateString());
    }
}

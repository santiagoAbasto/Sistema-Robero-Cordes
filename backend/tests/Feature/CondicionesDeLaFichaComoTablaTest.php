<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\HistorialCambio;
use App\Models\OpcionDeCondicion;
use App\Models\TipoDeCondicion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Las condiciones de trabajo de la ficha: sólo se eligen de la lista. Nombres ficticios.
 */
class CondicionesDeLaFichaComoTablaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private TipoDeCondicion $flete;

    private OpcionDeCondicion $cargoDelCliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flete = TipoDeCondicion::firstOrCreate(['nombre' => 'Flete']);
        $this->cargoDelCliente = $this->flete->opciones()->create(['texto' => 'A cargo del cliente']);
        $this->empresa = Empresa::create(['nombre' => 'ALFA SA']);
        $this->entrar('Administrador');
    }

    private function entrar(string $rol): User
    {
        $u = User::factory()->create(['role' => $rol]);
        Sanctum::actingAs($u);

        return $u;
    }

    private function cargar(array $datos)
    {
        return $this->postJson("/api/empresas/{$this->empresa->id}/campos", $datos);
    }

    /* ------------------------------------------------------------ cargar en la ficha */

    public function test_se_carga_una_condicion_eligiendo_de_la_lista(): void
    {
        $this->cargar([
            'tipo_de_condicion_id' => $this->flete->id,
            'opcion_de_condicion_id' => $this->cargoDelCliente->id,
            'usar_al_cotizar' => true,
        ])->assertOk();

        $campo = $this->empresa->campos()->first();
        // El campo guarda el texto de los dos (de ahí sale la cotización) y el vínculo con la lista.
        $this->assertSame(['Flete', 'A cargo del cliente', true], [$campo->titulo, $campo->valor, $campo->usar_al_cotizar]);
        $this->assertSame([$this->flete->id, $this->cargoDelCliente->id], [$campo->tipo_de_condicion_id, $campo->opcion_de_condicion_id]);
    }

    public function test_ya_no_se_acepta_un_titulo_escrito_a_mano(): void
    {
        $this->cargar(['titulo' => 'Lo que se me ocurra', 'valor' => 'Cualquier cosa'])
            ->assertUnprocessable()->assertJsonValidationErrors('tipo_de_condicion_id');

        $this->assertSame(0, $this->empresa->campos()->count());
    }

    public function test_un_titulo_desactivado_o_inexistente_no_se_puede_usar(): void
    {
        $this->flete->update(['activo' => false]);

        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $this->cargoDelCliente->id])
            ->assertUnprocessable()->assertJsonValidationErrors('tipo_de_condicion_id');
        $this->cargar(['tipo_de_condicion_id' => 99999, 'opcion_nueva' => 'x'])->assertUnprocessable();
    }

    public function test_hay_que_elegir_un_dato_o_agregar_uno(): void
    {
        $this->cargar(['tipo_de_condicion_id' => $this->flete->id])
            ->assertUnprocessable()->assertJsonValidationErrors('opcion_de_condicion_id');
    }

    public function test_un_dato_de_otra_condicion_o_desactivado_no_vale(): void
    {
        $pago = TipoDeCondicion::firstOrCreate(['nombre' => 'Tipo de pago']);
        $contado = $pago->opciones()->create(['texto' => 'Contado']);

        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $contado->id])
            ->assertUnprocessable()->assertJsonValidationErrors('opcion_de_condicion_id');

        $this->cargoDelCliente->update(['activo' => false]);
        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $this->cargoDelCliente->id])
            ->assertUnprocessable();
    }

    public function test_un_dato_nuevo_entra_a_la_lista_sin_repetir_uno_que_ya_esta(): void
    {
        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_nueva' => 'Lo trae el chofer'])->assertOk();

        $this->assertSame('Lo trae el chofer', $this->empresa->campos()->first()->valor);
        $this->assertSame(2, $this->flete->opciones()->count());

        // Otra empresa, escrito distinto: es el mismo dato.
        $otra = Empresa::create(['nombre' => 'BETA SA']);
        $this->postJson("/api/empresas/{$otra->id}/campos", ['tipo_de_condicion_id' => $this->flete->id, 'opcion_nueva' => '  lo trae  EL chofer '])->assertOk();

        $this->assertSame(2, $this->flete->opciones()->count());
        $this->assertSame($otra->campos()->first()->opcion_de_condicion_id, $this->empresa->campos()->first()->opcion_de_condicion_id);
    }

    public function test_cada_empresa_tiene_una_sola_vez_cada_condicion(): void
    {
        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $this->cargoDelCliente->id])->assertOk();

        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_nueva' => 'Otro'])
            ->assertUnprocessable()->assertJsonValidationErrors('tipo_de_condicion_id');
    }

    public function test_una_condicion_vieja_escrita_a_mano_tambien_cuenta_como_ya_cargada(): void
    {
        $this->empresa->campos()->create(['titulo' => 'flete', 'valor' => 'algo viejo']);

        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $this->cargoDelCliente->id])
            ->assertUnprocessable();
    }

    public function test_modificar_cambia_el_dato_y_se_queda_en_su_lugar(): void
    {
        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $this->cargoDelCliente->id])->assertOk();
        $otro = $this->flete->opciones()->create(['texto' => 'Incluido']);
        $campo = $this->empresa->campos()->first();
        $orden = $campo->orden;

        $this->putJson("/api/empresas/{$this->empresa->id}/campos/{$campo->id}", ['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $otro->id])->assertOk();

        $campo->refresh();
        $this->assertSame(['Incluido', $orden], [$campo->valor, $campo->orden]);
        $this->assertSame(1, $this->empresa->campos()->count());
    }

    public function test_un_campo_viejo_se_ve_como_fuera_de_la_lista_hasta_que_se_lo_elige(): void
    {
        $this->empresa->campos()->create(['titulo' => 'Flete', 'valor' => 'Texto de antes de la lista']);

        $campo = $this->getJson("/api/empresas/{$this->empresa->id}")->assertOk()->json('data.campos.0');

        $this->assertTrue($campo['fuera_de_la_lista']);
        $this->assertNull($campo['opcion_de_condicion_id']);

        $this->putJson("/api/empresas/{$this->empresa->id}/campos/{$campo['id']}", ['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $this->cargoDelCliente->id])->assertOk();

        $this->assertFalse($this->getJson("/api/empresas/{$this->empresa->id}")->json('data.campos.0.fuera_de_la_lista'));
    }

    public function test_la_cotizacion_sigue_leyendo_titulo_y_dato_de_la_ficha(): void
    {
        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $this->cargoDelCliente->id, 'usar_al_cotizar' => true])->assertOk();

        $campo = $this->empresa->campos()->where('usar_al_cotizar', true)->first();

        $this->assertSame(['Flete', 'A cargo del cliente'], [$campo->titulo, $campo->valor]);
    }

    public function test_los_vinculos_no_ensucian_el_control_de_cambios(): void
    {
        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $this->cargoDelCliente->id])->assertOk();
        $campo = $this->empresa->campos()->first();
        $otro = $this->flete->opciones()->create(['texto' => 'Incluido']);

        $this->putJson("/api/empresas/{$this->empresa->id}/campos/{$campo->id}", ['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $otro->id])->assertOk();

        $campos = HistorialCambio::where('tabla', 'empresa_campos')->pluck('campo')->all();
        $this->assertContains('valor', $campos);
        $this->assertNotContains('opcion_de_condicion_id', $campos);
        $this->assertNotContains('tipo_de_condicion_id', $campos);
    }

    /* ---------------------------------------------------------------- el catálogo */

    public function test_la_pantalla_recibe_las_condiciones_activas_con_sus_datos_activos(): void
    {
        $this->flete->opciones()->create(['texto' => 'Viejo', 'activo' => false]);
        TipoDeCondicion::create(['nombre' => 'Apagada', 'activo' => false]);

        $lista = collect($this->getJson('/api/catalogos')->json('condiciones_de_la_ficha'));

        $flete = $lista->firstWhere('nombre', 'Flete');
        $this->assertSame(['A cargo del cliente'], array_column($flete['opciones'], 'texto'));
        $this->assertNull($lista->firstWhere('nombre', 'Apagada'));
    }

    /* ------------------------------------------------------------- Configuración */

    public function test_configuracion_muestra_cada_condicion_con_sus_datos_y_cuantas_fichas_la_usan(): void
    {
        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $this->cargoDelCliente->id])->assertOk();
        $this->empresa->campos()->create(['titulo' => 'Otro', 'valor' => 'Suelto']);

        $r = $this->getJson('/api/config/condiciones-de-la-ficha')->assertOk();

        $flete = collect($r->json('tipos'))->firstWhere('nombre', 'Flete');
        $this->assertSame(1, $flete['fichas']);
        $this->assertSame(1, $flete['opciones'][0]['fichas']);
        $this->assertSame(1, $r->json('sueltos.fichas'));
        $this->assertSame('Suelto', $r->json('sueltos.datos.0.valor'));
    }

    public function test_el_administrador_arma_la_lista(): void
    {
        $id = $this->postJson('/api/config/condiciones-de-la-ficha', ['nombre' => 'Embalaje'])->assertCreated()->json('id');
        $this->postJson("/api/config/condiciones-de-la-ficha/{$id}/opciones", ['texto' => 'En cajón de madera'])->assertCreated();
        $this->putJson("/api/config/condiciones-de-la-ficha/{$id}", ['proponer_al_cotizar' => true])->assertOk();

        $tipo = TipoDeCondicion::find($id);
        $this->assertTrue($tipo->proponer_al_cotizar);
        $this->assertSame(['En cajón de madera'], $tipo->opciones->pluck('texto')->all());

        // Escrita distinto, es la misma.
        $this->postJson('/api/config/condiciones-de-la-ficha', ['nombre' => ' EMBALAJE '])->assertCreated();
        $this->assertSame(1, TipoDeCondicion::where('nombre', 'like', 'embalaje')->count());
    }

    public function test_los_demas_pueden_ver_la_lista_pero_no_cambiarla(): void
    {
        $this->entrar('Vendedor');

        $this->getJson('/api/config/condiciones-de-la-ficha')->assertOk();
        $this->postJson('/api/config/condiciones-de-la-ficha', ['nombre' => 'Otra'])->assertForbidden();
        $this->putJson("/api/config/condiciones-de-la-ficha/{$this->flete->id}", ['activo' => false])->assertForbidden();
        $this->deleteJson("/api/config/opciones-de-condicion/{$this->cargoDelCliente->id}")->assertForbidden();
        $this->postJson('/api/config/condiciones-de-la-ficha/adoptar', ['titulo' => 'Flete', 'valor' => 'x'])->assertForbidden();

        // En cambio sí pueden cargar una ficha, incluso con un dato nuevo.
        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_nueva' => 'Lo trae el chofer'])->assertOk();
    }

    public function test_renombrar_un_dato_o_una_condicion_cambia_las_fichas(): void
    {
        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $this->cargoDelCliente->id])->assertOk();

        $this->putJson("/api/config/opciones-de-condicion/{$this->cargoDelCliente->id}", ['texto' => 'Lo paga el cliente'])->assertOk();
        $this->putJson("/api/config/condiciones-de-la-ficha/{$this->flete->id}", ['nombre' => 'Transporte'])->assertOk();

        $campo = $this->empresa->campos()->first();
        $this->assertSame(['Transporte', 'Lo paga el cliente'], [$campo->titulo, $campo->valor]);
    }

    public function test_no_se_borra_lo_que_esta_en_uso_pero_si_se_desactiva(): void
    {
        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $this->cargoDelCliente->id])->assertOk();

        $this->deleteJson("/api/config/opciones-de-condicion/{$this->cargoDelCliente->id}")->assertUnprocessable();
        $this->deleteJson("/api/config/condiciones-de-la-ficha/{$this->flete->id}")->assertUnprocessable();
        $this->putJson("/api/config/opciones-de-condicion/{$this->cargoDelCliente->id}", ['activo' => false])->assertOk();

        // Sin uso, sí.
        $libre = $this->flete->opciones()->create(['texto' => 'Sin uso']);
        $this->deleteJson("/api/config/opciones-de-condicion/{$libre->id}")->assertNoContent();
        $sola = TipoDeCondicion::create(['nombre' => 'Sola']);
        $this->deleteJson("/api/config/condiciones-de-la-ficha/{$sola->id}")->assertNoContent();
    }

    public function test_unir_dos_datos_y_dos_condiciones(): void
    {
        $this->cargar(['tipo_de_condicion_id' => $this->flete->id, 'opcion_de_condicion_id' => $this->cargoDelCliente->id])->assertOk();
        $mismo = $this->flete->opciones()->create(['texto' => 'A cargo del comprador']);

        $this->postJson("/api/config/opciones-de-condicion/{$this->cargoDelCliente->id}/unir", ['con' => $mismo->id])->assertOk()->assertJsonPath('fichas', 1);
        $this->assertSame('A cargo del comprador', $this->empresa->campos()->first()->valor);

        // Datos de otra condición no se unen.
        $pago = TipoDeCondicion::firstOrCreate(['nombre' => 'Tipo de pago']);
        $contado = $pago->opciones()->create(['texto' => 'Contado']);
        $this->postJson("/api/config/opciones-de-condicion/{$mismo->id}/unir", ['con' => $contado->id])->assertUnprocessable();

        $fletes = TipoDeCondicion::create(['nombre' => 'Fletes']);
        $this->postJson("/api/config/condiciones-de-la-ficha/{$fletes->id}/unir", ['con' => $this->flete->id])->assertOk();
        $this->assertNull(TipoDeCondicion::find($fletes->id));
    }

    public function test_un_dato_suelto_se_suma_a_la_lista_desde_configuracion(): void
    {
        $this->empresa->campos()->create(['titulo' => 'Flete', 'valor' => 'Lo trae el chofer']);

        $this->postJson('/api/config/condiciones-de-la-ficha/adoptar', ['titulo' => 'Flete', 'valor' => 'Lo trae el chofer'])
            ->assertOk()->assertJsonPath('fichas', 1);

        $this->assertFalse($this->getJson("/api/empresas/{$this->empresa->id}")->json('data.campos.0.fuera_de_la_lista'));
        $this->assertSame(0, $this->getJson('/api/config/condiciones-de-la-ficha')->json('sueltos.fichas'));
    }

    public function test_hay_que_iniciar_sesion(): void
    {
        $this->app['auth']->forgetGuards();
        $this->refreshApplication();

        $this->getJson('/api/config/condiciones-de-la-ficha')->assertUnauthorized();
    }
}

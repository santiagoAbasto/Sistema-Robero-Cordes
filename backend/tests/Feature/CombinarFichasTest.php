<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Contacto;
use App\Models\Empresa;
use App\Models\EmpresaRelacion;
use App\Models\HistorialCambio;
use App\Models\Permiso;
use App\Models\RazonSocial;
use App\Models\User;
use App\Services\FusionDeEmpresas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Combinar dos fichas de la misma empresa, desde la pantalla.
 *
 * Roberto (04-11-2026): buscando un cliente salían dos fichas, porque el
 * nombre de antes de migrar no era exactamente igual ("…ELECTRICAS S" y
 * "…ELECTRICAS SRL"). Lo que no puede pasar: perder una cotización, un contacto
 * o un dato al combinar. (Nombres ficticios: el repo es público.)
 */
class CombinarFichasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'Administrador']);
        Sanctum::actingAs($this->admin);
    }

    private function cotizacion(Empresa $e, string $tipo = 'Cotizacion', string $estado = 'Confirmada'): Consulta
    {
        return Consulta::create([
            'empresa_id' => $e->id, 'tipo' => $tipo, 'fecha' => '2026-10-01',
            'estado' => $estado, 'usuario_id' => $this->admin->id,
        ]);
    }

    // --- Cuándo dos nombres son la misma empresa ----------------------------

    public function test_el_nombre_pelado_ignora_la_forma_legal_y_los_signos(): void
    {
        $this->assertSame('alfa resistencias electricas', FusionDeEmpresas::clave('ALFA RESISTENCIAS ELECTRICAS S'));
        $this->assertSame('alfa resistencias electricas', FusionDeEmpresas::clave('ALFA RESISTENCIAS ELECTRICAS SRL'));
        $this->assertSame('alfa resistencias electricas', FusionDeEmpresas::clave('Alfa Resistencias Eléctricas S.R.L.'));
        $this->assertSame('medint', FusionDeEmpresas::clave('MEDINT S.A.'));
        $this->assertSame('', FusionDeEmpresas::clave('S.A.'));
    }

    public function test_cuando_dos_nombres_son_la_misma_empresa(): void
    {
        $misma = fn (string $a, string $b) => FusionDeEmpresas::sonLaMisma(FusionDeEmpresas::clave($a), FusionDeEmpresas::clave($b));

        $this->assertTrue($misma('ALFA RESISTENCIAS ELECTRICAS S', 'ALFA RESISTENCIAS ELECTRICAS SRL'));
        $this->assertTrue($misma('ALFA RESISTENCIAS', 'ALFA RESISTENCIAS ELECTRICAS SRL'), 'uno es el comienzo del otro, con dos palabras');
        $this->assertFalse($misma('BANCO', 'BANCO NACION'), 'una sola palabra no alcanza');
        $this->assertFalse($misma('ALFA RESISTENCIAS ELECTRICAS SRL', 'ALFARO EDUARDO ALBERTO'));
        $this->assertFalse($misma('MEDINT S.A.', 'BETA MOLINOS'));
    }

    public function test_las_parecidas_son_las_activas_del_mismo_nombre_y_no_ella_misma(): void
    {
        $s = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS S']);
        $srl = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS SRL']);
        Empresa::create(['nombre' => 'ALFARO EDUARDO ALBERTO']);
        Empresa::create(['nombre' => 'BETA MOLINOS']);
        Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS SA', 'activa' => false]);
        $this->cotizacion($srl);

        $r = $this->getJson("/api/empresas/{$s->id}/parecidas")->assertOk();

        $this->assertTrue($r->json('puede_combinar'));
        $this->assertSame([$srl->id], array_column($r->json('data'), 'id'), 'solo la activa que es la misma');
        $this->assertSame(1, $r->json('data.0.cotizaciones'));
    }

    // --- Quién puede ---------------------------------------------------------

    public function test_sin_el_permiso_de_archivar_no_se_combina_ni_se_ofrece(): void
    {
        $a = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS S']);
        $b = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS SRL']);

        Sanctum::actingAs(User::factory()->create(['role' => 'Vendedor']));

        $this->getJson("/api/empresas/{$a->id}/parecidas")->assertOk()->assertJson(['puede_combinar' => false, 'data' => []]);
        $this->getJson("/api/empresas/{$a->id}/combinar/{$b->id}")->assertForbidden();
        $this->postJson("/api/empresas/{$a->id}/combinar", ['otra_id' => $b->id, 'queda' => 'esta'])->assertForbidden();

        $this->assertTrue($b->fresh()->activa, 'no se tocó nada');
    }

    public function test_con_la_casilla_archiva_un_usuario_comun_si_puede(): void
    {
        $a = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS S']);
        $b = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS SRL']);

        $comun = User::factory()->create(['role' => 'Vendedor']);
        Permiso::create(['user_id' => $comun->id, 'puede_archivar' => true]);
        Sanctum::actingAs($comun);

        $this->postJson("/api/empresas/{$a->id}/combinar", ['otra_id' => $b->id, 'queda' => 'esta'])->assertOk();
    }

    // --- Comparar ------------------------------------------------------------

    public function test_comparar_muestra_las_dos_y_recomienda_la_de_mas_cotizaciones(): void
    {
        $s = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS S', 'cuit' => '30-12345678-1']);
        $srl = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS SRL']);
        $this->cotizacion($s);
        $this->cotizacion($srl);
        $this->cotizacion($srl);
        Contacto::create(['empresa_id' => $srl->id, 'nombre' => 'Contacto Demo']);

        $r = $this->getJson("/api/empresas/{$s->id}/combinar/{$srl->id}")->assertOk();

        $this->assertSame(1, $r->json('esta.cotizaciones'));
        $this->assertSame(2, $r->json('otra.cotizaciones'));
        $this->assertSame(1, $r->json('otra.contactos'));
        $this->assertSame('30-12345678-1', $r->json('esta.cuit'));
        $this->assertSame($srl->id, $r->json('recomendada'));
    }

    // --- Combinar: que no se pierda nada -------------------------------------

    public function test_combinar_pasa_todo_a_la_que_queda_y_archiva_la_otra(): void
    {
        $s = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS S']);
        $srl = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS SRL', 'cuit' => '30-12345678-1', 'observacion_general' => 'Paga a 30 días.']);

        // La que va a sobrar trae de todo: cotizaciones (una en la papelera, un pedido y una observación).
        $c1 = $this->cotizacion($s);
        $c2 = $this->cotizacion($srl);
        $c3 = $this->cotizacion($srl, 'Pedido');
        $obs = $this->cotizacion($srl, 'Observacion');
        $papelera = $this->cotizacion($srl);
        $papelera->delete();

        Contacto::create(['empresa_id' => $s->id, 'nombre' => 'Ana', 'principal' => true]);
        Contacto::create(['empresa_id' => $srl->id, 'nombre' => 'Beto', 'principal' => true]);
        RazonSocial::create(['empresa_id' => $srl->id, 'razon_social' => 'ALFA SRL', 'cuit' => '30-12345678-1', 'habitual' => true]);
        EmpresaRelacion::create(['empresa_id' => $s->id, 'relacion' => 'Cliente']);
        EmpresaRelacion::create(['empresa_id' => $srl->id, 'relacion' => 'Cliente']);
        EmpresaRelacion::create(['empresa_id' => $srl->id, 'relacion' => 'Proveedor']);

        $r = $this->postJson("/api/empresas/{$s->id}/combinar", ['otra_id' => $srl->id, 'queda' => 'esta'])
            ->assertOk();

        $this->assertSame($s->id, $r->json('empresa_id'));
        $this->assertStringContainsString('no se borró nada', $r->json('mensaje'));

        // Todas las consultas están en la que queda, incluida la de la papelera.
        $this->assertSame(
            [$c1->id, $c2->id, $c3->id, $obs->id, $papelera->id],
            Consulta::withTrashed()->where('empresa_id', $s->id)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(0, Consulta::withTrashed()->where('empresa_id', $srl->id)->count());
        $this->assertTrue(Consulta::withTrashed()->find($papelera->id)->trashed(), 'la de la papelera sigue en la papelera');

        // Contactos y razones, con un solo principal y una sola habitual.
        $this->assertSame(['Ana', 'Beto'], Contacto::where('empresa_id', $s->id)->orderBy('id')->pluck('nombre')->all());
        $this->assertSame(1, Contacto::where('empresa_id', $s->id)->where('principal', true)->count());
        $this->assertSame(1, RazonSocial::where('empresa_id', $s->id)->count());

        // Relaciones: la repetida no se duplica y la que faltaba se suma.
        $this->assertEqualsCanonicalizing(['Cliente', 'Proveedor'], EmpresaRelacion::where('empresa_id', $s->id)->pluck('relacion')->all());

        // El dato que le faltaba a la que queda se completa; la observación se conserva.
        $queda = $s->fresh();
        $this->assertSame('30-12345678-1', $queda->cuit);
        $this->assertStringContainsString('Paga a 30 días.', $queda->observacion_general);

        // La otra: archivada, renombrada, NO borrada.
        $archivada = $srl->fresh();
        $this->assertFalse($archivada->activa);
        $this->assertSame("ALFA RESISTENCIAS ELECTRICAS SRL (fusionada en #{$s->id})", $archivada->nombre);
    }

    public function test_se_puede_elegir_que_quede_la_otra_ficha(): void
    {
        $s = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS S']);
        $srl = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS SRL']);
        $c = $this->cotizacion($s);

        $r = $this->postJson("/api/empresas/{$s->id}/combinar", ['otra_id' => $srl->id, 'queda' => 'otra'])->assertOk();

        $this->assertSame($srl->id, $r->json('empresa_id'));
        $this->assertSame($srl->id, Consulta::find($c->id)->empresa_id);
        $this->assertFalse($s->fresh()->activa);
        $this->assertTrue($srl->fresh()->activa);
        $this->assertSame('ALFA RESISTENCIAS ELECTRICAS SRL', $srl->fresh()->nombre, 'conserva el nombre de la que queda');
    }

    public function test_queda_constancia_en_el_historial_de_la_ficha(): void
    {
        $s = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS S']);
        $srl = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS SRL']);
        $this->cotizacion($srl);

        $this->postJson("/api/empresas/{$s->id}/combinar", ['otra_id' => $srl->id, 'queda' => 'esta'])->assertOk();

        $fila = HistorialCambio::where('tabla', 'empresas')->where('registro_id', $s->id)->where('campo', 'fusion')->first();

        $this->assertNotNull($fila, 'tiene que figurar en "Ver cambios"');
        $this->assertStringContainsString("(#{$srl->id})", $fila->valor_anterior);
        $this->assertStringContainsString('1 consultas', $fila->valor_nuevo);
        $this->assertSame($this->admin->id, $fila->usuario_id, 'quién la combinó');
    }

    public function test_despues_de_combinar_la_busqueda_muestra_una_sola(): void
    {
        $s = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS S']);
        $srl = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS SRL']);

        $this->postJson("/api/empresas/{$s->id}/combinar", ['otra_id' => $srl->id, 'queda' => 'otra'])->assertOk();

        $nombres = array_column($this->getJson('/api/empresas?buscar=alfa')->assertOk()->json('data'), 'nombre');

        $this->assertSame(['ALFA RESISTENCIAS ELECTRICAS SRL'], $nombres);
    }

    // --- Lo que se rechaza ---------------------------------------------------

    public function test_no_se_puede_combinar_una_ficha_consigo_misma(): void
    {
        $s = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS S']);

        $this->postJson("/api/empresas/{$s->id}/combinar", ['otra_id' => $s->id, 'queda' => 'esta'])->assertStatus(422);
        $this->assertTrue($s->fresh()->activa);
    }

    public function test_una_ficha_inactiva_no_entra_en_la_combinacion(): void
    {
        $s = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS S']);
        $cerrada = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS SRL', 'activa' => false]);
        $c = $this->cotizacion($cerrada);

        $this->postJson("/api/empresas/{$s->id}/combinar", ['otra_id' => $cerrada->id, 'queda' => 'esta'])->assertStatus(422);

        $this->assertSame($cerrada->id, Consulta::find($c->id)->empresa_id, 'no se movió nada');
    }

    public function test_faltan_datos_y_el_mensaje_esta_en_castellano(): void
    {
        $s = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS S']);

        $r = $this->postJson("/api/empresas/{$s->id}/combinar", [])->assertStatus(422);

        $this->assertSame('Elegí con qué ficha se combina.', $r->json('errors.otra_id.0'));
        $this->assertSame('Elegí cuál de las dos fichas queda.', $r->json('errors.queda.0'));
    }

    public function test_una_que_no_existe_da_error_y_no_rompe(): void
    {
        $s = Empresa::create(['nombre' => 'ALFA RESISTENCIAS ELECTRICAS S']);

        $this->postJson("/api/empresas/{$s->id}/combinar", ['otra_id' => 99999, 'queda' => 'esta'])->assertStatus(422);
    }
}

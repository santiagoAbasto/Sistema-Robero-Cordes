<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Contacto;
use App\Models\ContactoMedio;
use App\Models\Empresa;
use App\Models\TipoMedio;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lo que Roberto pidio el 03-11-2026 ademas del lector:
 * - el celular marcado como principal es el que sale en la hoja;
 * - el PDF se abre con el mismo nombre con el que se descarga;
 * - una empresa que cerro se da de baja y se puede reactivar.
 */
class TelefonoPrincipalHojaConNombreYBajaTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->usuario = User::factory()->create(['role' => 'Administrador']);
        Sanctum::actingAs($this->usuario);
    }

    private function medio(Contacto $c, string $tipo, string $valor, bool $principal): void
    {
        ContactoMedio::create([
            'contacto_id' => $c->id,
            'tipo_medio_id' => TipoMedio::where('nombre', $tipo)->value('id'),
            'valor' => $valor,
            'principal' => $principal,
            'activo' => true,
        ]);
    }

    private function contacto(): Contacto
    {
        $empresa = Empresa::create(['nombre' => 'APEX']);

        return Contacto::create(['empresa_id' => $empresa->id, 'nombre' => 'Ana']);
    }

    public function test_el_celular_marcado_como_principal_gana_al_fijo(): void
    {
        $c = $this->contacto();
        $this->medio($c, 'Telefono', '03571 40-0010', false);
        $this->medio($c, 'Celular', '351 555-0101', true);

        $this->assertSame('351 555-0101', $c->fresh('medios.tipoMedio')->telefonoParaLaHoja()->valor);
    }

    public function test_sin_ninguno_marcado_sigue_el_orden_de_siempre(): void
    {
        $c = $this->contacto();
        $this->medio($c, 'Celular', '351 555-0101', false);
        $this->medio($c, 'Telefono', '03571 40-0010', false);

        $this->assertSame('03571 40-0010', $c->fresh('medios.tipoMedio')->telefonoParaLaHoja()->valor);
    }

    public function test_el_pdf_se_abre_con_el_nombre_del_archivo(): void
    {
        $empresa = Empresa::create(['nombre' => 'APEX']);
        $consulta = Consulta::create([
            'empresa_id' => $empresa->id, 'tipo' => 'Cotizacion', 'fecha' => '2026-11-03',
            'estado' => 'Borrador', 'usuario_id' => $this->usuario->id,
        ]);
        ConsultaLinea::create([
            'consulta_id' => $consulta->id, 'orden' => 1, 'descripcion' => 'Barra',
            'cantidad' => 1, 'precio_unitario' => 10, 'importe' => 10,
        ]);

        $ruta = $this->getJson("/api/consultas/{$consulta->id}/pdf?enlace=1&registrar=0")
            ->assertOk()->json('ruta');

        $this->assertStringStartsWith('hojas/', $ruta);
        $this->assertStringEndsWith('.pdf', $ruta);

        $hoja = $this->get('/api/'.$ruta)->assertOk();
        $this->assertSame('application/pdf', $hoja->headers->get('content-type'));
        $this->assertStringContainsString('filename="Cotizacion', (string) $hoja->headers->get('content-disposition'));

        // Una dirección inventada no abre nada.
        $this->get('/api/hojas/'.str_repeat('a', 40).'/x.pdf')->assertNotFound();
    }

    public function test_dar_de_baja_la_empresa_la_saca_de_la_lista_y_se_reactiva(): void
    {
        $empresa = Empresa::create(['nombre' => 'CERRADA SA']);

        $this->postJson("/api/empresas/{$empresa->id}/archivar")->assertOk();

        $this->assertCount(0, $this->getJson('/api/empresas?buscar=CERRADA')->json('data'));
        $this->assertCount(1, $this->getJson('/api/empresas?buscar=CERRADA&inactivas=1')->json('data'));

        // Con ?con_inactivas=1 salen las dos clases, para poder verla.
        $this->assertCount(1, $this->getJson('/api/empresas?buscar=CERRADA&con_inactivas=1')->json('data'));

        $this->postJson("/api/empresas/{$empresa->id}/restaurar")->assertOk();

        $this->assertCount(1, $this->getJson('/api/empresas?buscar=CERRADA')->json('data'));
        $this->assertCount(0, $this->getJson('/api/empresas?buscar=CERRADA&inactivas=1')->json('data'));
    }

    public function test_a_una_empresa_inactiva_no_se_le_cotiza_pero_se_ve_su_historia(): void
    {
        $empresa = Empresa::create(['nombre' => 'CERRADA SA']);
        $vieja = Consulta::create([
            'empresa_id' => $empresa->id, 'tipo' => 'Cotizacion', 'fecha' => '2026-09-01',
            'estado' => 'Borrador', 'usuario_id' => $this->usuario->id,
        ]);
        ConsultaLinea::create(['consulta_id' => $vieja->id, 'orden' => 1, 'descripcion' => 'Barra', 'cantidad' => 1, 'precio_unitario' => 10, 'importe' => 10]);
        $this->postJson("/api/consultas/{$vieja->id}/emitir")->assertOk();

        $this->postJson("/api/empresas/{$empresa->id}/archivar")->assertOk();

        // La historia se ve.
        $this->getJson("/api/empresas/{$empresa->id}")->assertOk()->assertJsonPath('data.activa', false);
        $this->getJson("/api/consultas/{$vieja->id}")->assertOk();

        // Cotizaciones y pedidos nuevos, no.
        $nueva = ['tipo' => 'Cotizacion', 'fecha' => '2026-11-03', 'lineas' => [['descripcion' => 'Barra']]];
        $this->postJson("/api/empresas/{$empresa->id}/consultas", $nueva)->assertStatus(422);
        $this->postJson("/api/empresas/{$empresa->id}/consultas", ['tipo' => 'Pedido'] + $nueva)->assertStatus(422);
        // Ni una revision de las que ya tenia.
        $this->postJson("/api/consultas/{$vieja->id}/revision")->assertStatus(422);
        // Ni copiarle una de otra empresa.
        $otra = Empresa::create(['nombre' => 'ACTIVA SA']);
        $deOtra = Consulta::create([
            'empresa_id' => $otra->id, 'tipo' => 'Cotizacion', 'fecha' => '2026-11-03',
            'estado' => 'Borrador', 'usuario_id' => $this->usuario->id,
        ]);
        $this->postJson("/api/consultas/{$deOtra->id}/copiar", ['empresas' => [$empresa->id]])->assertStatus(422);

        // Una observacion si: anotar por que cerro es parte de la historia.
        $this->postJson("/api/empresas/{$empresa->id}/consultas", ['tipo' => 'Observacion', 'fecha' => '2026-11-03', 'texto' => 'Cerró en octubre'])
            ->assertSuccessful();

        // Reactivada, vuelve a poder cotizarse.
        $this->postJson("/api/empresas/{$empresa->id}/restaurar")->assertOk();
        $this->postJson("/api/empresas/{$empresa->id}/consultas", $nueva)->assertSuccessful();
    }
}

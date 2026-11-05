<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cimientos del buscador único (F1): el importe de cada comprobante y los
 * índices para preguntar por orden de compra, factura, remito, pedido y diámetro.
 * (Nombres ficticios: el repo es público.)
 */
class IndicesEImporteDelComprobanteTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION = '2026_11_04_000200_indices_para_buscar_e_importe_del_comprobante.php';

    private User $usuario;

    private Consulta $vendida;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->usuario = User::factory()->create(['role' => 'Administrador']);
        Sanctum::actingAs($this->usuario);

        $this->vendida = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'Empresa Demo SA'])->id,
            'tipo' => 'Cotizacion', 'fecha' => '2026-11-03', 'estado' => 'Borrador', 'usuario_id' => $this->usuario->id,
        ]);
        ConsultaLinea::create([
            'consulta_id' => $this->vendida->id, 'orden' => 1, 'descripcion' => 'Item 1',
            'cantidad' => 1, 'precio_unitario' => 100, 'importe' => 100,
        ]);
        $this->postJson("/api/consultas/{$this->vendida->id}/emitir")->assertOk();
        $this->postJson("/api/consultas/{$this->vendida->id}/estado", ['estado' => 'Vendida'])->assertOk();
    }

    private function agregar(array $datos)
    {
        return $this->postJson("/api/consultas/{$this->vendida->id}/comprobantes", $datos + ['tipo' => 'Factura']);
    }

    // --- El importe ---------------------------------------------------------

    public function test_un_comprobante_guarda_su_importe_y_lo_devuelve(): void
    {
        $r = $this->agregar(['numero' => '0001-00000123', 'importe' => 125000.5])->assertOk();

        $this->assertSame(125000.5, $r->json('data.comprobantes.0.importe'));
    }

    public function test_el_importe_es_opcional_y_los_viejos_quedan_sin_importe(): void
    {
        $r = $this->agregar(['numero' => '0001-00000124'])->assertOk();

        $this->assertNull($r->json('data.comprobantes.0.importe'));
    }

    public function test_se_corrige_el_importe_y_se_puede_sacar(): void
    {
        $id = $this->agregar(['importe' => 100])->json('data.comprobantes.0.id');

        $this->putJson("/api/consultas/{$this->vendida->id}/comprobantes/{$id}", ['tipo' => 'Factura', 'importe' => 250.75])
            ->assertOk()->assertJsonPath('data.comprobantes.0.importe', 250.75);

        $this->putJson("/api/consultas/{$this->vendida->id}/comprobantes/{$id}", ['tipo' => 'Factura', 'importe' => null])
            ->assertOk()->assertJsonPath('data.comprobantes.0.importe', null);
    }

    public function test_un_importe_negativo_o_que_no_es_numero_se_rechaza_en_castellano(): void
    {
        $negativo = $this->agregar(['importe' => -5])->assertStatus(422);
        $this->assertStringContainsString('no puede ser negativo', $negativo->json('errors.importe.0'));

        $texto = $this->agregar(['importe' => 'mucho'])->assertStatus(422);
        $this->assertStringContainsString('tiene que ser un número', $texto->json('errors.importe.0'));

        $this->assertSame(0, $this->vendida->comprobantes()->count(), 'no se guardó nada');
    }

    // --- Los índices --------------------------------------------------------

    public function test_estan_los_indices_que_el_buscador_va_a_necesitar(): void
    {
        foreach ([
            'consultas' => ['consultas_solicitud_nro_idx', 'consultas_nro_factura_idx', 'consultas_nro_remito_idx',
                'consultas_nro_pedido_interno_idx', 'consultas_fecha_factura_idx', 'consultas_fecha_remito_idx', 'consultas_fecha_pedido_idx'],
            'consulta_comprobantes' => ['consulta_comprobantes_numero_idx', 'consulta_comprobantes_fecha_idx'],
            'consulta_lineas' => ['consulta_lineas_diametro_idx'],
        ] as $tabla => $indices) {
            foreach ($indices as $indice) {
                $this->assertTrue(Schema::hasIndex($tabla, $indice), "falta el índice {$indice} en {$tabla}");
            }
        }
    }

    public function test_la_migracion_se_puede_correr_dos_veces_sin_romper_nada(): void
    {
        $migracion = require database_path('migrations/'.self::MIGRACION);

        $migracion->up();
        $migracion->up();

        $this->assertTrue(Schema::hasColumn('consulta_comprobantes', 'importe'));
        $this->assertTrue(Schema::hasIndex('consultas', 'consultas_solicitud_nro_idx'));
    }

    public function test_deshacer_la_migracion_saca_lo_que_puso_y_se_puede_volver_a_poner(): void
    {
        $migracion = require database_path('migrations/'.self::MIGRACION);

        $migracion->down();

        $this->assertFalse(Schema::hasColumn('consulta_comprobantes', 'importe'));
        $this->assertFalse(Schema::hasIndex('consultas', 'consultas_nro_factura_idx'));

        $migracion->up();

        $this->assertTrue(Schema::hasColumn('consulta_comprobantes', 'importe'));
        $this->assertTrue(Schema::hasIndex('consultas', 'consultas_nro_factura_idx'));
    }
}

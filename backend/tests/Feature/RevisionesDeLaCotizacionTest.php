<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El historial de UNA cotizacion, en revisiones.
 *
 * Antes solo se podia pedir por empresa —una empresa con cien cotizaciones
 * devolvia el revuelto de las cien— y llegaba una fila por campo cambiado.
 * Cambiar tres precios y guardar daba tres renglones sueltos.
 *
 * Una revision es un guardado: lo que cambio la misma persona en el mismo
 * momento, resumido en una linea.
 */
class RevisionesDeLaCotizacionTest extends TestCase
{
    use RefreshDatabase;

    private Consulta $consulta;

    private User $roberto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roberto = User::factory()->create(['name' => 'Roberto Cordes', 'role' => 'Administrador']);
        Sanctum::actingAs($this->roberto);

        $this->travelTo('2026-09-26 09:00:00');

        $this->consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'FERRUM S.A.'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-09-26',
            'estado' => 'Borrador',
            'usuario_id' => $this->roberto->id,
        ]);

        foreach ([2, 5] as $cantidad) {
            ConsultaLinea::create([
                'consulta_id' => $this->consulta->id,
                'descripcion' => 'TITANIO GR2 BARRA',
                'cantidad' => $cantidad,
            ]);
        }
    }

    private function revisiones(): array
    {
        return $this->getJson("/api/consultas/{$this->consulta->id}/revisiones")
            ->assertOk()
            ->json();
    }

    /** El alta es una sola revisión, no una fila por línea. */
    public function test_cargar_la_cotizacion_es_una_revision(): void
    {
        $r = $this->revisiones();

        $this->assertSame(1, $r['total']);
        $this->assertSame('Roberto Cordes', $r['revisiones'][0]['quien']);
        $this->assertStringContainsString('Se cargo la cotizacion', $r['revisiones'][0]['que_paso']);
        $this->assertStringContainsString('2 lineas', $r['revisiones'][0]['que_paso']);
    }

    /**
     * Cambiar dos cantidades y guardar es UNA revisión, no dos renglones.
     *
     * Es la diferencia entre una revisión y un log.
     */
    public function test_un_guardado_con_dos_cambios_es_una_sola_revision(): void
    {
        $this->travelTo('2026-09-26 11:30:00');

        foreach ($this->consulta->lineas as $linea) {
            $linea->update(['cantidad' => $linea->cantidad + 1]);
        }

        $r = $this->revisiones();

        $this->assertSame(2, $r['total']);

        // La última primero: es la que se quiere ver al abrir.
        $ultima = $r['revisiones'][0];

        $this->assertSame('2026-09-26 11:30', $ultima['fecha']);
        $this->assertStringContainsString('cantidad', $ultima['que_paso']);
        $this->assertStringContainsString('2 lineas', $ultima['que_paso']);
        // Y el detalle sigue estando, para quien lo quiera abrir.
        $this->assertCount(2, $ultima['cambios']);
        $this->assertSame('Linea de cotizacion · cantidad', $ultima['cambios'][0]['que']);
    }

    /** Dos personas en el mismo momento son dos revisiones, no una. */
    public function test_cada_persona_tiene_su_revision(): void
    {
        $this->travelTo('2026-09-26 15:00:00');
        $this->consulta->update(['nota' => 'Hablar con compras']);

        $juan = User::factory()->create(['name' => 'Juan Roberti']);
        Sanctum::actingAs($juan);
        $this->consulta->update(['lista_precios' => 'Mayorista']);

        $r = $this->revisiones();
        $quienes = array_column($r['revisiones'], 'quien');

        $this->assertContains('Roberto Cordes', $quienes);
        $this->assertContains('Juan Roberti', $quienes);
    }

    /** El historial de esta cotización no trae el de las otras de la empresa. */
    public function test_no_se_mezcla_con_otras_cotizaciones_de_la_empresa(): void
    {
        $otra = Consulta::create([
            'empresa_id' => $this->consulta->empresa_id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-09-26',
            'estado' => 'Borrador',
            'usuario_id' => $this->roberto->id,
        ]);

        $this->travelTo('2026-09-26 16:00:00');
        $otra->update(['nota' => 'Otra cosa']);

        // La de esta cotización sigue siendo una sola: su alta.
        $this->assertSame(1, $this->revisiones()['total']);
    }
}

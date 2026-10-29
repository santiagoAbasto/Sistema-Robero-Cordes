<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Observacion;
use App\Models\Unidad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pedidos del cliente del 29-10-2026 sobre la hoja y las observaciones:
 *  - "Aprox. 6 MT" en lugar de "6 MT (aprox.)", y "Total (aproximado)";
 *  - el transporte y el plazo, como ultimo renglon del item, con "Para este item";
 *  - las observaciones de un borrador se pueden corregir; las de una emitida, no.
 */
class ArreglosDeLaHojaYObservacionesTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Consulta $consulta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create(['role' => 'Administrador']);
        $this->consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'CLIENTE'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-10-29',
            'estado' => 'Borrador',
            'usuario_id' => $this->usuario->id,
        ]);
    }

    private function linea(array $datos): void
    {
        ConsultaLinea::forceCreate($datos + [
            'consulta_id' => $this->consulta->id,
            'orden' => 1,
            'unidad_venta_id' => Unidad::firstOrCreate(['codigo' => 'MT'], ['nombre' => 'MT'])->id,
        ]);
    }

    private function hoja(): string
    {
        $lineas = $this->consulta->fresh()->lineas()->with(['unidadVenta', 'unidadFactura'])->get();

        return view('pdf.cotizacion', [
            'consulta' => $this->consulta->fresh(),
            'lineas' => $lineas,
            'condiciones' => collect(),
            'total' => (float) $lineas->sum('importe'),
            'titulo' => 'Cotizacion',
            'marca' => null,
            'simbolo' => 'US$',
            'fecha' => '29/10/2026',
            'nombreEnPdf' => 'CLIENTE',
            'contacto' => null,
            'telefono' => null,
            'mail' => null,
            'incluyeImportes' => true,
            'incluyeNota' => false,
        ])->render();
    }

    public function test_aproximado_va_delante_de_la_cantidad_y_en_el_total(): void
    {
        $this->linea(['descripcion' => 'BARRA REDONDA 28,6', 'cantidad' => 6, 'precio_unitario' => 10, 'importe' => 60, 'aprox' => true]);

        $html = $this->hoja();

        $this->assertMatchesRegularExpression('/Aprox\.<\/span>\s*6/', $html);
        $this->assertStringNotContainsString('(aprox.)', $html);
        $this->assertStringContainsString('Total (aproximado)', $html);
    }

    public function test_sin_lineas_aproximadas_el_total_no_cambia(): void
    {
        $this->linea(['descripcion' => 'BRIDA CIEGA', 'cantidad' => 2, 'precio_unitario' => 10, 'importe' => 20]);

        $html = $this->hoja();

        $this->assertStringNotContainsString('<span class="aprox">', $html);
        $this->assertStringNotContainsString('(aproximado)', $html);
    }

    public function test_el_transporte_y_el_plazo_son_el_ultimo_renglon_del_item(): void
    {
        $this->linea([
            'descripcion' => 'VARILLA DE APORTE', 'cantidad' => 1, 'precio_unitario' => 10, 'importe' => 10,
            'transporte' => 'Aereo', 'plazo_dias' => 60, 'nota' => 'Para aplicar sobre la brida.',
        ]);

        $html = $this->hoja();

        $this->assertStringContainsString('Para este ítem: Aereo · entrega aprox. 60 días', $html);
        $this->assertGreaterThan(strpos($html, 'Para aplicar sobre la brida.'), strpos($html, 'Para este ítem'));
        // Antes el plazo solo salia dos veces: arriba y como nota.
        $this->assertSame(1, substr_count(mb_strtolower($html), 'entrega aprox.'));
    }

    public function test_una_observacion_del_borrador_se_corrige_y_la_de_una_emitida_no(): void
    {
        Sanctum::actingAs($this->usuario);

        $observacion = Observacion::create([
            'consulta_id' => $this->consulta->id, 'numero' => 1, 'fecha' => now(),
            'usuario_id' => $this->usuario->id, 'texto' => 'Quedo en confirmar el lunes',
        ]);

        $this->putJson("/api/observaciones/{$observacion->id}", ['texto' => 'Quedo en confirmar el martes'])->assertOk();
        $this->assertSame('Quedo en confirmar el martes', $observacion->fresh()->texto);

        $this->consulta->forceFill(['emitida_el' => now(), 'numero' => '2026-0001'])->save();

        $this->putJson("/api/observaciones/{$observacion->id}", ['texto' => 'Otra cosa'])->assertStatus(422);
        $this->assertSame('Quedo en confirmar el martes', $observacion->fresh()->texto);
    }
}

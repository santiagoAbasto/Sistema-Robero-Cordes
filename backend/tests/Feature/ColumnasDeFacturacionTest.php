<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Unidad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La cantidad y la unidad de facturacion, en columnas (lista del cliente,
 * 14-10-2026).
 *
 * "Cuando la unidad de entrega y la de facturacion difieren se agregan
 * columnas: antes del precio unitario faltan la cantidad y la unidad de
 * facturacion." Iba como nota dentro de la descripcion, y el precio unitario
 * era el de la unidad de venta mientras el importe salia de los kilos: la
 * fila no se podia leer como una factura.
 */
class ColumnasDeFacturacionTest extends TestCase
{
    use RefreshDatabase;

    private Consulta $consulta;

    protected function setUp(): void
    {
        parent::setUp();

        $usuario = User::factory()->create();
        $this->consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'APEX'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-10-14',
            'estado' => 'Borrador',
            'usuario_id' => $usuario->id,
        ]);
    }

    private function unidad(string $codigo): int
    {
        return Unidad::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo])->id;
    }

    private function linea(array $datos): ConsultaLinea
    {
        return ConsultaLinea::create(['consulta_id' => $this->consulta->id] + $datos);
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
            'fecha' => '14/10/2026',
            'nombreEnPdf' => 'APEX',
            'contacto' => null,
            'telefono' => null,
            'mail' => null,
            'incluyeImportes' => true,
            'incluyeNota' => false,
        ])->render();
    }

    public function test_por_pieza_y_por_kilo_la_fila_se_lee_como_factura(): void
    {
        // 6 barras que se facturan 45,30 kg a 12 el kilo: 543,60.
        $this->linea([
            'descripcion' => 'TITANIO GR2 BARRA REDONDA 20 X 3000',
            'cantidad' => 6, 'unidad_venta_id' => $this->unidad('UN'),
            'unidad_factura_id' => $this->unidad('KG'), 'factor_conversion' => 7.55,
            'cantidad_facturar' => 45.30, 'precio_por_kilo' => 12, 'precio_unitario' => 90.60, 'importe' => 543.60,
        ]);
        // Otra que no cambia de unidad: repite las suyas.
        $this->linea([
            'descripcion' => 'BRIDA CIEGA 4" CLASE 300',
            'cantidad' => 2, 'unidad_venta_id' => $this->unidad('UN'),
            'precio_unitario' => 100, 'importe' => 200,
        ]);

        $hoja = $this->hoja();

        $this->assertStringContainsString('Cant. fact.', $hoja);
        $this->assertStringContainsString('Unid. fact.', $hoja);
        $this->assertMatchesRegularExpression('~<td>45,3</td>\s*<td>KG</td>~', $hoja);
        $this->assertMatchesRegularExpression('~<td>2</td>\s*<td>UN</td>~', $hoja);
        // El precio de la fila es el del kilo, y la nota de la descripcion ya no va.
        $this->assertStringNotContainsString('90,60', $hoja);
        $this->assertStringNotContainsString('Se factura', $hoja);
    }

    public function test_sin_cambio_de_unidad_la_tabla_queda_como_siempre(): void
    {
        $this->linea([
            'descripcion' => 'BRIDA CIEGA 4" CLASE 300',
            'cantidad' => 2, 'unidad_venta_id' => $this->unidad('UN'),
            'precio_unitario' => 100, 'importe' => 200,
        ]);

        $this->assertStringNotContainsString('Cant. fact.', $this->hoja());
    }

    /** Debajo del material va solo el motivo: "no se cotiza" ya esta en el importe. */
    public function test_no_cotizada_el_motivo_solo(): void
    {
        $this->linea([
            'descripcion' => 'INCONEL 625 BARRA 50',
            'cantidad' => 1, 'unidad_venta_id' => $this->unidad('UN'),
            'no_cotizada' => true, 'motivo_no_cotiza' => 'Sin stock del proveedor',
        ]);

        $hoja = $this->hoja();

        $this->assertStringContainsString('Sin stock del proveedor', $hoja);
        $texto = mb_strtolower(strip_tags(preg_replace('~<style.*?</style>~s', '', $hoja)));
        $this->assertSame(1, substr_count($texto, 'no se cotiza'));
    }
}

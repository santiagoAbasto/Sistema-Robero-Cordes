<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\RazonSocial;
use App\Models\Unidad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La condicion frente al IVA y la de pago, en la ficha (pedido del cliente,
 * 29-10-2026). Segun el IVA la hoja sale mas IVA o con el IVA incluido; la
 * condicion de pago la trae sola la cotizacion.
 */
class IvaYCondicionDePagoDeLaFichaTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = User::factory()->create(['role' => 'Administrador']);
        Sanctum::actingAs($this->usuario);
    }

    private function cotizacion(Empresa $empresa): Consulta
    {
        $consulta = Consulta::create([
            'empresa_id' => $empresa->id, 'tipo' => 'Cotizacion', 'fecha' => '2026-10-29',
            'estado' => 'Borrador', 'usuario_id' => $this->usuario->id,
        ]);
        ConsultaLinea::forceCreate([
            'consulta_id' => $consulta->id, 'orden' => 1, 'descripcion' => 'BARRA', 'cantidad' => 2,
            'unidad_venta_id' => Unidad::firstOrCreate(['codigo' => 'UN'], ['nombre' => 'UN'])->id,
            'precio_unitario' => 50, 'importe' => 100,
        ]);

        return $consulta;
    }

    private function hoja(Consulta $consulta): string
    {
        $consulta = $consulta->fresh(['empresa', 'razonSocial']);
        $lineas = $consulta->lineas()->with(['unidadVenta', 'unidadFactura'])->get();
        [$factorIva, $leyendaIva] = $consulta->ivaDeLaHoja();

        return view('pdf.cotizacion', [
            'consulta' => $consulta, 'lineas' => $lineas, 'condiciones' => collect(),
            'total' => (float) $lineas->sum(fn ($l) => $l->importeEnLaHoja($factorIva, false)),
            'factorIva' => $factorIva, 'leyendaIva' => $leyendaIva,
            'titulo' => 'Cotizacion', 'marca' => null, 'simbolo' => 'US$', 'fecha' => '29/10/2026',
            'nombreEnPdf' => 'CLIENTE', 'contacto' => null, 'telefono' => null, 'mail' => null,
            'incluyeImportes' => true, 'incluyeNota' => false,
        ])->render();
    }

    public function test_la_ficha_guarda_las_dos_condiciones(): void
    {
        $empresa = Empresa::create(['nombre' => 'CLIENTE']);

        $this->putJson("/api/empresas/{$empresa->id}", [
            'nombre' => 'CLIENTE', 'condicion_iva' => 'Consumidor Final', 'condicion_pago' => '30 dias fecha factura',
        ])->assertOk();

        $empresa->refresh();
        $this->assertSame('Consumidor Final', $empresa->condicion_iva);
        $this->assertSame('30 dias fecha factura', $empresa->condicion_pago);

        $this->putJson("/api/empresas/{$empresa->id}", ['nombre' => 'CLIENTE', 'condicion_iva' => 'Otra cosa'])
            ->assertStatus(422);
    }

    public function test_al_inscripto_se_le_cotiza_mas_iva(): void
    {
        $html = $this->hoja($this->cotizacion(Empresa::create(['nombre' => 'CLIENTE', 'condicion_iva' => 'Responsable Inscripto'])));

        $this->assertStringContainsString('Total (no incluye IVA)', $html);
        $this->assertStringContainsString('100,00', $html);
        $this->assertStringNotContainsString('121,00', $html);
    }

    public function test_al_consumidor_final_el_sistema_le_suma_el_iva(): void
    {
        $html = $this->hoja($this->cotizacion(Empresa::create(['nombre' => 'CLIENTE', 'condicion_iva' => 'Consumidor Final'])));

        $this->assertStringContainsString('Total (IVA incluido)', $html);
        $this->assertStringContainsString('60,50', $html, 'precio unitario con IVA');
        $this->assertStringContainsString('121,00', $html, 'importe y total con IVA');
    }

    /** El renglon cierra: 6 x 219,68 = 1.318,08, no el importe sin IVA por 1,21 (1.318,05). */
    public function test_con_iva_incluido_cada_renglon_cierra(): void
    {
        $consulta = $this->cotizacion(Empresa::create(['nombre' => 'CLIENTE', 'condicion_iva' => 'Consumidor Final']));
        $consulta->lineas()->update(['cantidad' => 6, 'precio_unitario' => 181.55, 'importe' => 1089.30]);

        $html = $this->hoja($consulta);

        $this->assertStringContainsString('219,68', $html);
        $this->assertStringContainsString('1.318,08', $html);
        $this->assertStringNotContainsString('1.318,05', $html);
    }

    public function test_manda_la_razon_social_elegida_y_sin_condicion_queda_como_siempre(): void
    {
        $empresa = Empresa::create(['nombre' => 'CLIENTE', 'condicion_iva' => 'Consumidor Final']);
        $consulta = $this->cotizacion($empresa);
        $razon = RazonSocial::create(['empresa_id' => $empresa->id, 'razon_social' => 'CLIENTE SA', 'cuit' => '30-00000000-0', 'condicion_iva' => 'Resp. Inscripto']);
        $consulta->update(['razon_social_id' => $razon->id]);

        $this->assertStringContainsString('Total (no incluye IVA)', $this->hoja($consulta));

        $sinCondicion = $this->hoja($this->cotizacion(Empresa::create(['nombre' => 'OTRO'])));
        $this->assertStringNotContainsString('IVA', $sinCondicion);
    }

    public function test_aproximado_e_iva_van_juntos_en_el_total(): void
    {
        $consulta = $this->cotizacion(Empresa::create(['nombre' => 'CLIENTE', 'condicion_iva' => 'Responsable Inscripto']));
        $consulta->lineas()->update(['aprox' => true]);

        $this->assertStringContainsString('Total (aproximado, no incluye IVA)', $this->hoja($consulta));
    }

    public function test_al_copiar_a_otra_empresa_trae_su_condicion_de_pago(): void
    {
        $origen = $this->cotizacion(Empresa::create(['nombre' => 'CLIENTE']));
        $destino = Empresa::create(['nombre' => 'OTRA', 'condicion_pago' => 'Contado']);

        $r = $this->postJson("/api/consultas/{$origen->id}/copiar", ['empresas' => [$destino->id]])->assertCreated();

        $this->assertSame('Contado', Consulta::find($r->json('borradores.0.id'))->condicion_pago);
    }
}

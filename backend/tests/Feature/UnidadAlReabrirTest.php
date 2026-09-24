<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Unidad;
use App\Models\User;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La unidad de venta tiene que seguir ahi al reabrir la cotizacion.
 *
 * La pantalla elige la unidad por su id. La respuesta traia solo el codigo
 * ("MT"), asi que el desplegable volvia en "Sin elegir" aunque la linea la
 * tuviera guardada — y como al guardar se manda lo que muestra la pantalla,
 * abrir una cotizacion y volver a guardarla le borraba la unidad.
 *
 * No es cosmetico: con MT el factor son los kilos de un metro y sin unidad son
 * los de la pieza. Es la misma diferencia de cuarenta veces que arreglamos en
 * FactorPorUnidadTest, entrando por otra puerta.
 */
class UnidadAlReabrirTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Consulta $consulta;

    private Unidad $metro;

    private Unidad $kilo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);

        $this->usuario = User::factory()->create();
        Sanctum::actingAs($this->usuario);

        $this->metro = Unidad::where('codigo', 'MT')->firstOrFail();
        $this->kilo = Unidad::where('codigo', 'KG')->firstOrFail();

        $this->consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'CLIENTE'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Borrador',
            'usuario_id' => $this->usuario->id,
        ]);

        ConsultaLinea::create([
            'consulta_id' => $this->consulta->id,
            'orden' => 1,
            'descripcion' => 'TITANIO GR2 BARRA Ø 127',
            'cantidad' => 3,
            'unidad_venta_id' => $this->metro->id,
            'unidad_factura_id' => $this->kilo->id,
            'precio_unitario' => 100,
        ]);
    }

    /** @return array<string, mixed> */
    private function abrir(): array
    {
        return $this->getJson("/api/consultas/{$this->consulta->id}")
            ->assertOk()
            ->json('data.lineas.0');
    }

    public function test_al_abrir_vuelve_el_id_de_la_unidad_y_no_solo_el_codigo(): void
    {
        $linea = $this->abrir();

        $this->assertSame('MT', $linea['unidad'], 'el codigo, para mostrar');
        $this->assertSame($this->metro->id, $linea['unidad_venta_id'], 'y el id, para el desplegable');
        $this->assertSame($this->kilo->id, $linea['unidad_factura_id']);
    }

    /**
     * Abrir y volver a guardar sin tocar nada no puede cambiar nada.
     *
     * Es el recorrido real: la pantalla manda de vuelta lo que recibio.
     */
    public function test_reabrir_y_guardar_no_borra_la_unidad(): void
    {
        $linea = $this->abrir();

        $this->putJson("/api/consultas/{$this->consulta->id}", [
            'tipo' => 'Cotizacion',
            'fecha' => $this->consulta->fecha->toDateString(),
            'lineas' => [[
                'id' => $linea['id'],
                'descripcion' => $linea['descripcion'],
                'cantidad' => $linea['cantidad'],
                'unidad_venta_id' => $linea['unidad_venta_id'],
                'unidad_factura_id' => $linea['unidad_factura_id'],
                'precio_unitario' => $linea['precio_unitario'],
            ]],
        ])->assertOk();

        $guardada = ConsultaLinea::findOrFail($linea['id']);

        $this->assertSame($this->metro->id, $guardada->unidad_venta_id, 'la unidad de venta sigue');
        $this->assertSame($this->kilo->id, $guardada->unidad_factura_id, 'y la de facturacion tambien');
    }
}

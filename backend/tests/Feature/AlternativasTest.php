<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Moneda;
use App\Models\Unidad;
use App\Models\User;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alternativas de una linea.
 *
 * Los dos casos salen de cotizaciones reales:
 *
 *  · HASTELLOY C22 — el mismo caño maritimo a 201 y 80 dias, o aereo a 210 y
 *    40 dias. Cambia el precio Y el plazo.
 *  · ALLOY 20 — el mismo tubo en tres tramos de cantidad, y a mas metros
 *    menos precio por metro.
 *
 * Lo que importa: que el total de la cotizacion tome UNA sola de ellas. Si se
 * sumaran todas, la cotizacion saldria por el triple.
 */
class AlternativasTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        $this->usuario = User::factory()->create();
        $this->empresa = Empresa::create(['nombre' => 'MECANIZADOS MAK']);
        Sanctum::actingAs($this->usuario);
    }

    /** El caso del mail de HASTELLOY: maritimo o aereo. */
    public function test_alternativa_por_transporte(): void
    {
        $consulta = $this->cotizar([[
            'descripcion' => 'HASTELLOY C22 Caño c/c 1" Sch. 40 x 327mm',
            'cantidad' => 200,
            'opciones' => [
                ['etiqueta' => 'Maritimo', 'tipo' => 'Transporte', 'precio_unitario' => 201, 'plazo_dias' => 80, 'es_base' => true],
                ['etiqueta' => 'Aereo', 'tipo' => 'Transporte', 'precio_unitario' => 210, 'plazo_dias' => 40, 'es_base' => false],
            ],
        ]]);

        $linea = $consulta->lineas->first();

        $this->assertCount(2, $linea->opciones);

        $maritimo = $linea->opciones->firstWhere('etiqueta', 'Maritimo');
        $aereo = $linea->opciones->firstWhere('etiqueta', 'Aereo');

        // Cada alternativa cotiza lo suyo, heredando la cantidad de la linea.
        $this->assertSame(200.0, $maritimo->laCantidad());
        $this->assertSame(200.0, $aereo->laCantidad());
        $this->assertEqualsWithDelta(40200.0, $maritimo->importe, 0.01);
        $this->assertEqualsWithDelta(42000.0, $aereo->importe, 0.01);

        // Y el plazo cambia con el transporte.
        $this->assertSame(80, $maritimo->plazo_dias);
        $this->assertSame(40, $aereo->plazo_dias);

        // El total toma la base, no la suma de las dos.
        $consulta->load('lineas.opciones');
        $this->assertEqualsWithDelta(40200.0, $consulta->total, 0.01);
    }

    /** El caso del mail de ALLOY 20: tres tramos de cantidad. */
    public function test_alternativa_por_cantidad(): void
    {
        $consulta = $this->cotizar([[
            'descripcion' => 'ALLOY 20 Tubo s/c 12,7 x 0,89 x 6090mm',
            'cantidad' => 36.6,
            'opciones' => [
                ['etiqueta' => '36,6 m', 'tipo' => 'Cantidad', 'cantidad' => 36.6, 'precio_unitario' => 141.60, 'es_base' => true],
                ['etiqueta' => '73,2 m', 'tipo' => 'Cantidad', 'cantidad' => 73.2, 'precio_unitario' => 96.10, 'es_base' => false],
                ['etiqueta' => '109,8 m', 'tipo' => 'Cantidad', 'cantidad' => 109.8, 'precio_unitario' => 81.00, 'es_base' => false],
            ],
        ]]);

        $opciones = $consulta->lineas->first()->opciones;

        $this->assertCount(3, $opciones);
        $this->assertEqualsWithDelta(5182.56, $opciones[0]->importe, 0.01);
        $this->assertEqualsWithDelta(7034.52, $opciones[1]->importe, 0.01);
        $this->assertEqualsWithDelta(8893.80, $opciones[2]->importe, 0.01);

        // A mas metros, menos precio por metro: es el sentido del tramo.
        $this->assertLessThan($opciones[0]->elPrecio(), $opciones[1]->elPrecio());
        $this->assertLessThan($opciones[1]->elPrecio(), $opciones[2]->elPrecio());

        $consulta->load('lineas.opciones');
        $this->assertEqualsWithDelta(5182.56, $consulta->total, 0.01);
    }

    public function test_la_alternativa_hereda_lo_que_no_cambia(): void
    {
        $consulta = $this->cotizar([[
            'descripcion' => 'Barra',
            'cantidad' => 100,
            'precio_unitario' => 50,
            'opciones' => [
                // Solo cambia el precio: la cantidad la toma de la linea.
                ['etiqueta' => 'Maritimo', 'tipo' => 'Transporte', 'es_base' => true],
                ['etiqueta' => 'Aereo', 'tipo' => 'Transporte', 'precio_unitario' => 60, 'es_base' => false],
            ],
        ]]);

        $opciones = $consulta->lineas->first()->opciones;

        $this->assertSame(100.0, $opciones[0]->laCantidad());
        $this->assertSame(50.0, $opciones[0]->elPrecio());
        $this->assertEqualsWithDelta(5000.0, $opciones[0]->importe, 0.01);

        // La segunda hereda la cantidad pero pisa el precio.
        $this->assertSame(100.0, $opciones[1]->laCantidad());
        $this->assertSame(60.0, $opciones[1]->elPrecio());
    }

    public function test_si_no_marcan_base_se_toma_la_primera(): void
    {
        $consulta = $this->cotizar([[
            'descripcion' => 'Barra',
            'cantidad' => 10,
            'opciones' => [
                ['etiqueta' => 'Una', 'precio_unitario' => 100, 'es_base' => false],
                ['etiqueta' => 'Otra', 'precio_unitario' => 200, 'es_base' => false],
            ],
        ]]);

        $opciones = $consulta->lineas->first()->opciones;

        // Algo tiene que contar para el total: no se puede quedar sin base.
        $this->assertTrue($opciones[0]->es_base);
        $this->assertFalse($opciones[1]->es_base);
        $consulta->load('lineas.opciones');
        $this->assertEqualsWithDelta(1000.0, $consulta->total, 0.01);
    }

    public function test_hay_una_sola_base_aunque_marquen_varias(): void
    {
        $consulta = $this->cotizar([[
            'descripcion' => 'Barra',
            'cantidad' => 10,
            'opciones' => [
                ['etiqueta' => 'Una', 'precio_unitario' => 100, 'es_base' => true],
                ['etiqueta' => 'Otra', 'precio_unitario' => 200, 'es_base' => true],
            ],
        ]]);

        $opciones = $consulta->lineas->first()->opciones;

        $this->assertSame(1, $opciones->where('es_base', true)->count());
        // Si hubiera dos bases, el total contaria el item dos veces.
        $consulta->load('lineas.opciones');
        $this->assertEqualsWithDelta(1000.0, $consulta->total, 0.01);
    }

    public function test_una_linea_sin_alternativas_sigue_andando_igual(): void
    {
        $consulta = $this->cotizar([[
            'descripcion' => 'Barra comun',
            'cantidad' => 4,
            'precio_unitario' => 25,
        ]]);

        $linea = $consulta->lineas->first();

        $this->assertCount(0, $linea->opciones);
        $this->assertFalse($linea->tieneAlternativas());
        $this->assertEqualsWithDelta(100.0, (float) $linea->importe, 0.01);
    }

    public function test_borrar_la_linea_se_lleva_sus_alternativas(): void
    {
        $consulta = $this->cotizar([[
            'descripcion' => 'Barra',
            'cantidad' => 10,
            'opciones' => [['etiqueta' => 'Una', 'precio_unitario' => 100, 'es_base' => true]],
        ]]);

        $this->assertDatabaseCount('consulta_linea_opciones', 1);

        ConsultaLinea::query()->delete();

        // Sin esto quedarian alternativas colgadas de una linea que ya no esta.
        $this->assertDatabaseCount('consulta_linea_opciones', 0);
        $this->assertNotNull($consulta->id);
    }

    /**
     * Lo que el sistema propone la próxima vez.
     *
     * Si el aereo salio a 40 dias, la proxima cotizacion ya lo trae puesto:
     * ese es el tiempo que se ahorra.
     */
    public function test_propone_el_plazo_que_se_uso_la_ultima_vez(): void
    {
        $this->cotizar([[
            'descripcion' => 'Caño',
            'cantidad' => 200,
            'opciones' => [
                ['etiqueta' => 'Maritimo', 'tipo' => 'Transporte', 'precio_unitario' => 201, 'plazo_dias' => 80, 'es_base' => true],
                ['etiqueta' => 'Aereo', 'tipo' => 'Transporte', 'precio_unitario' => 210, 'plazo_dias' => 40, 'es_base' => false],
            ],
        ]]);

        $r = $this->getJson('/api/alternativas/sugerencias')->assertOk();

        $vias = collect($r->json('transporte'))->keyBy('etiqueta');

        $this->assertSame(80, $vias['Maritimo']['plazo_dias']);
        $this->assertSame(40, $vias['Aereo']['plazo_dias']);
    }

    public function test_sin_historial_propone_las_vias_pero_no_inventa_plazos(): void
    {
        $r = $this->getJson('/api/alternativas/sugerencias')->assertOk();

        $vias = collect($r->json('transporte'));

        // Las dos vias son siempre las mismas: eso se puede proponer.
        $this->assertSame(['Maritimo', 'Aereo'], $vias->pluck('etiqueta')->all());

        // El plazo no: un plazo inventado se convierte en un compromiso que
        // nadie asumio.
        $this->assertNull($vias[0]['plazo_dias']);
        $this->assertNull($vias[1]['plazo_dias']);
    }

    /** @param  list<array<string, mixed>>  $lineas */
    private function cotizar(array $lineas): Consulta
    {
        $un = Unidad::where('codigo', 'UN')->value('id') ?? Unidad::value('id');

        $r = $this->postJson("/api/empresas/{$this->empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            // Por nombre, no por id: el id depende del orden de insercion y
            // cambia entre motores.
            'moneda_id' => Moneda::where('nombre', 'DOLAR BILLETE BNA VENDEDOR')->value('id'),
            'lineas' => collect($lineas)
                ->map(fn ($l) => array_merge(['unidad_venta_id' => $un], $l))
                ->all(),
        ]);

        $r->assertCreated();

        return Consulta::with('lineas.opciones')->findOrFail($r->json('data.id'));
    }
}

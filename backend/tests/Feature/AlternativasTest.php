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
 * Una alternativa ya no es una "opcion" que solo cambia el precio o el plazo:
 * es una linea entera que cuelga de otra (alternativa_de_id). Su material,
 * medidas, unidad, precio, transporte y plazo son propios. En la hoja sale
 * como 1.1, 1.2 debajo de su linea.
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

    /** El caso del mail de HASTELLOY: maritimo o aereo, con su plazo. */
    public function test_alternativa_por_transporte(): void
    {
        $consulta = $this->cotizar([
            ['descripcion' => 'HASTELLOY C22 Caño c/c 1" Sch. 40 x 327mm', 'cantidad' => 200,
                'precio_unitario' => 201, 'transporte' => 'Marítimo', 'plazo_dias' => 80],
            ['descripcion' => 'HASTELLOY C22 Caño c/c 1" Sch. 40 x 327mm', 'cantidad' => 200,
                'precio_unitario' => 210, 'transporte' => 'Aéreo', 'plazo_dias' => 40, 'alternativa_de' => 0],
        ]);

        $lineas = $consulta->lineas->sortBy('orden')->values();
        [$maritimo, $aereo] = [$lineas[0], $lineas[1]];

        // La alternativa cuelga de su linea, no es una linea suelta.
        $this->assertNull($maritimo->alternativa_de_id);
        $this->assertSame($maritimo->id, $aereo->alternativa_de_id);
        $this->assertTrue($aereo->esAlternativa());

        // Cada una cotiza lo suyo, con su via y su plazo.
        $this->assertEqualsWithDelta(40200.0, (float) $maritimo->importe, 0.01);
        $this->assertEqualsWithDelta(42000.0, (float) $aereo->importe, 0.01);
        $this->assertSame('Aéreo', $aereo->transporte);
        $this->assertSame(40, $aereo->plazo_dias);

        // El total toma la linea, no la suma de las dos.
        $this->assertEqualsWithDelta(40200.0, $consulta->fresh('lineas')->total, 0.01);
    }

    /** El caso del mail de ALLOY 20: tres tramos de cantidad. */
    public function test_alternativa_por_cantidad(): void
    {
        $consulta = $this->cotizar([
            ['descripcion' => 'ALLOY 20 Tubo s/c 12,7 x 0,89 x 6090mm', 'cantidad' => 36.6, 'precio_unitario' => 141.60],
            ['descripcion' => 'ALLOY 20 Tubo s/c 12,7 x 0,89 x 6090mm', 'cantidad' => 73.2, 'precio_unitario' => 96.10, 'alternativa_de' => 0],
            ['descripcion' => 'ALLOY 20 Tubo s/c 12,7 x 0,89 x 6090mm', 'cantidad' => 109.8, 'precio_unitario' => 81.00, 'alternativa_de' => 0],
        ]);

        $madre = $consulta->lineas->firstWhere('alternativa_de_id', null);
        $alts = $consulta->lineas->where('alternativa_de_id', $madre->id)->sortBy('orden')->values();

        $this->assertCount(2, $alts);
        $this->assertEqualsWithDelta(5182.56, (float) $madre->importe, 0.01);
        $this->assertEqualsWithDelta(7034.52, (float) $alts[0]->importe, 0.01);
        $this->assertEqualsWithDelta(8893.80, (float) $alts[1]->importe, 0.01);

        // A mas metros, menos precio por metro: es el sentido del tramo.
        $this->assertLessThan((float) $madre->precio_unitario, (float) $alts[0]->precio_unitario);
        $this->assertLessThan((float) $alts[0]->precio_unitario, (float) $alts[1]->precio_unitario);

        // El total toma solo la linea madre.
        $this->assertEqualsWithDelta(5182.56, $consulta->fresh('lineas')->total, 0.01);
    }

    /** Una alternativa no arrastra lo pedido: es otra respuesta al mismo pedido. */
    public function test_la_alternativa_no_tiene_pedido_propio(): void
    {
        $consulta = $this->cotizar([
            ['descripcion' => 'Barra', 'cantidad' => 100, 'precio_unitario' => 50,
                'igual_a_lo_pedido' => false, 'pedido_material' => 'TITANIO', 'motivo_cambio' => 'stock'],
            ['descripcion' => 'Barra en otro largo', 'cantidad' => 100, 'precio_unitario' => 60,
                'alternativa_de' => 0, 'igual_a_lo_pedido' => false, 'pedido_material' => 'NO DEBERIA QUEDAR'],
        ]);

        $alt = $consulta->lineas->firstWhere('alternativa_de_id', '!=', null);

        $this->assertTrue((bool) $alt->igual_a_lo_pedido);
        $this->assertNull($alt->pedido_material);
        $this->assertNull($alt->motivo_cambio);
    }

    public function test_una_linea_sin_alternativas_sigue_andando_igual(): void
    {
        $consulta = $this->cotizar([
            ['descripcion' => 'Barra comun', 'cantidad' => 4, 'precio_unitario' => 25],
        ]);

        $linea = $consulta->lineas->first();

        $this->assertNull($linea->alternativa_de_id);
        $this->assertFalse($linea->esAlternativa());
        $this->assertEqualsWithDelta(100.0, (float) $linea->importe, 0.01);
        $this->assertEqualsWithDelta(100.0, $consulta->fresh('lineas')->total, 0.01);
    }

    public function test_borrar_la_linea_se_lleva_sus_alternativas(): void
    {
        $consulta = $this->cotizar([
            ['descripcion' => 'Barra', 'cantidad' => 10, 'precio_unitario' => 100],
            ['descripcion' => 'Barra aerea', 'cantidad' => 10, 'precio_unitario' => 110, 'alternativa_de' => 0],
        ]);

        $madre = $consulta->lineas->firstWhere('alternativa_de_id', null);
        $this->assertDatabaseCount('consulta_lineas', 2);

        $madre->delete();

        // La foreign key cascadea: sin esto quedaria una alternativa colgando
        // de una linea que ya no esta.
        $this->assertDatabaseCount('consulta_lineas', 0);
    }

    /** Una alternativa va debajo de su linea, nunca de otra alternativa (1.1.1 no existe). */
    public function test_no_acepta_una_alternativa_de_una_alternativa(): void
    {
        $un = Unidad::where('codigo', 'UN')->value('id') ?? Unidad::value('id');

        $this->postJson("/api/empresas/{$this->empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'moneda_id' => Moneda::where('nombre', 'DOLAR BILLETE BNA VENDEDOR')->value('id'),
            'lineas' => [
                ['descripcion' => 'Barra', 'cantidad' => 10, 'precio_unitario' => 100, 'unidad_venta_id' => $un],
                ['descripcion' => 'Alt', 'cantidad' => 10, 'precio_unitario' => 110, 'unidad_venta_id' => $un, 'alternativa_de' => 0],
                ['descripcion' => 'Alt de la alt', 'cantidad' => 10, 'precio_unitario' => 120, 'unidad_venta_id' => $un, 'alternativa_de' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('lineas.2.alternativa_de');
    }

    /** Una alternativa no puede apuntar a una linea que viene despues. */
    public function test_la_alternativa_apunta_a_una_linea_anterior(): void
    {
        $un = Unidad::where('codigo', 'UN')->value('id') ?? Unidad::value('id');

        $this->postJson("/api/empresas/{$this->empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'moneda_id' => Moneda::where('nombre', 'DOLAR BILLETE BNA VENDEDOR')->value('id'),
            'lineas' => [
                ['descripcion' => 'Alt antes que su madre', 'cantidad' => 10, 'precio_unitario' => 100, 'unidad_venta_id' => $un, 'alternativa_de' => 1],
                ['descripcion' => 'Madre', 'cantidad' => 10, 'precio_unitario' => 110, 'unidad_venta_id' => $un],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('lineas.0.alternativa_de');
    }

    /**
     * Lo que el sistema propone la próxima vez.
     *
     * Si el aereo salio a 40 dias, la proxima cotizacion ya lo trae puesto:
     * ese es el tiempo que se ahorra.
     */
    public function test_propone_el_plazo_que_se_uso_la_ultima_vez(): void
    {
        $this->cotizar([
            ['descripcion' => 'Caño', 'cantidad' => 200, 'precio_unitario' => 201, 'transporte' => 'Marítimo', 'plazo_dias' => 80],
            ['descripcion' => 'Caño', 'cantidad' => 200, 'precio_unitario' => 210, 'transporte' => 'Aéreo', 'plazo_dias' => 40, 'alternativa_de' => 0],
        ]);

        $r = $this->getJson('/api/alternativas/sugerencias')->assertOk();

        $vias = collect($r->json('transporte'))->keyBy('etiqueta');

        $this->assertSame(80, $vias['Marítimo']['plazo_dias']);
        $this->assertSame(40, $vias['Aéreo']['plazo_dias']);
    }

    public function test_sin_historial_propone_las_vias_pero_no_inventa_plazos(): void
    {
        $r = $this->getJson('/api/alternativas/sugerencias')->assertOk();

        $vias = collect($r->json('transporte'));

        // Las dos vias son siempre las mismas: eso se puede proponer.
        $this->assertSame(['Marítimo', 'Aéreo'], $vias->pluck('etiqueta')->all());

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

        return Consulta::with('lineas')->findOrFail($r->json('data.id'));
    }
}

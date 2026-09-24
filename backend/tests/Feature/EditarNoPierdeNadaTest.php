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
 * Abrir una cotizacion, guardarla y que no se pierda nada.
 *
 * Es el riesgo mas caro del editor y ya paso una vez: el recurso devolvia el
 * codigo de la unidad pero no su id, el formulario la levantaba en null y al
 * guardar la borraba. Nadie se entera hasta que el cliente pregunta por que
 * la hoja salio distinta.
 *
 * Estos tests fijan las dos mitades del viaje:
 *  · que la API DEVUELVA cada campo que se guarda, para que el editor pueda
 *    mostrarlo;
 *  · que guardar SIN mandar un campo no lo borre, que es lo que pasa hoy con
 *    los que el editor todavia no muestra.
 */
class EditarNoPierdeNadaTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Consulta $consulta;

    private ConsultaLinea $linea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);

        $this->usuario = User::factory()->create();
        Sanctum::actingAs($this->usuario);

        $this->consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'FRANOR SRL'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Confirmada',
            'usuario_id' => $this->usuario->id,
        ]);

        $un = Unidad::where('codigo', 'UN')->value('id');

        $this->linea = ConsultaLinea::create([
            'consulta_id' => $this->consulta->id,
            'orden' => 1,
            'descripcion' => 'TITANIO GR2 BARRA REDONDA 25 X 300 MM',
            'cantidad' => 4,
            'unidad_venta_id' => $un,
            'precio_unitario' => 1500,
            'importe' => 6000,
            // Lo que el editor todavia no muestra, pero el sistema guarda.
            'aprox' => true,
            'idem' => true,
            'desde_stock' => true,
            'deposito' => 'PB Frente',
            'colada' => 'H19798',
            'cantidad_pedida' => 6,
            'unidad_pedida_id' => $un,
            'codigo_cliente' => 'SUO1413884-21',
            'item_cliente' => '21',
            'nota' => 'Plano SUO1413884/1 posicion 21.',
        ]);
    }

    /** Lo que se guarda tiene que poder volver: si no vuelve, no se puede editar. */
    public function test_la_api_devuelve_todo_lo_que_se_guarda(): void
    {
        $l = $this->getJson("/api/consultas/{$this->consulta->id}")
            ->assertOk()->json('data.lineas.0');

        foreach ([
            'aprox' => true,
            'idem' => true,
            'desde_stock' => true,
            'deposito' => 'PB Frente',
            'colada' => 'H19798',
            'codigo_cliente' => 'SUO1413884-21',
            'item_cliente' => '21',
        ] as $campo => $esperado) {
            $this->assertArrayHasKey($campo, $l, "la API no devuelve {$campo}");
            $this->assertSame($esperado, $l[$campo], "la API devuelve mal {$campo}");
        }
    }

    /**
     * Guardar como guarda el editor de hoy no puede borrar lo que no muestra.
     *
     * El formulario manda solo los campos que conoce. Los que no manda tienen
     * que quedar como estaban: lo contrario seria que abrir y guardar una
     * cotizacion le borre el numero de colada.
     */
    public function test_guardar_sin_mandar_un_campo_no_lo_borra(): void
    {
        $this->putJson("/api/consultas/{$this->consulta->id}", [
            'tipo' => 'Cotizacion',
            'fecha' => $this->consulta->fecha->toDateString(),
            'lineas' => [[
                // Exactamente lo que arma el editor hoy: sin aprox, sin colada,
                // sin deposito, sin desde_stock, sin idem.
                'id' => $this->linea->id,
                'descripcion' => $this->linea->descripcion,
                'cantidad' => 4,
                'unidad_venta_id' => $this->linea->unidad_venta_id,
                'precio_unitario' => 1500,
                'codigo_cliente' => 'SUO1413884-21',
                'item_cliente' => '21',
                'nota' => 'Plano SUO1413884/1 posicion 21.',
                'igual_a_lo_pedido' => true,
            ]],
        ])->assertOk();

        $l = $this->linea->fresh();

        $this->assertTrue((bool) $l->aprox, 'se borro aprox');
        $this->assertTrue((bool) $l->idem, 'se borro idem');
        $this->assertTrue((bool) $l->desde_stock, 'se borro desde_stock');
        $this->assertSame('PB Frente', $l->deposito, 'se borro el deposito');
        $this->assertSame('H19798', $l->colada, 'se borro la colada');
        $this->assertEquals(6, $l->cantidad_pedida, 'se borro la cantidad pedida');
        $this->assertNotNull($l->unidad_pedida_id, 'se borro la unidad pedida');
    }

    /**
     * La unidad de lo que habia pedido vuelve con su id, no solo el codigo.
     *
     * Es el mismo caso que la unidad de venta: con el codigo solo, el
     * desplegable no puede quedar elegido y al guardar se pierde.
     */
    public function test_la_unidad_pedida_vuelve_con_su_id(): void
    {
        $this->linea->update(['igual_a_lo_pedido' => false]);

        $pedido = $this->getJson("/api/consultas/{$this->consulta->id}")
            ->assertOk()->json('data.lineas.0.pedido');

        $this->assertSame($this->linea->unidad_pedida_id, $pedido['unidad_id']);
        $this->assertSame('UN', $pedido['unidad']);
        $this->assertEquals(6, $pedido['cantidad']);
    }

    /** Y lo que el editor SI muestra tiene que volver tal cual. */
    public function test_lo_que_el_editor_muestra_vuelve_igual(): void
    {
        $l = $this->getJson("/api/consultas/{$this->consulta->id}")
            ->assertOk()->json('data.lineas.0');

        $this->assertSame($this->linea->unidad_venta_id, $l['unidad_venta_id']);
        $this->assertEquals(4, $l['cantidad']);
        $this->assertEquals(1500, $l['precio_unitario']);
        $this->assertSame('TITANIO GR2 BARRA REDONDA 25 X 300 MM', $l['descripcion']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lo que pidio el cliente, medida por medida.
 *
 * Era una caja de texto libre llamada "Medidas" donde cada uno escribia lo que
 * le parecia, mientras que arriba —en lo que se cotiza— hay un campo por
 * medida con el nombre que le pone la forma. Las dos mitades de la misma linea
 * no se podian comparar de un vistazo, que es para lo que esta el bloque.
 */
class MedidasDeLoPedidoTest extends TestCase
{
    use RefreshDatabase;

    private Consulta $consulta;

    private Material $titanio;

    private Forma $barra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);

        Sanctum::actingAs($usuario = User::factory()->create());

        $this->titanio = Material::where('nombre', 'TITANIO GR2')->firstOrFail();
        $this->barra = Forma::where('nombre', 'BARRA REDONDA')->firstOrFail();

        $this->consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'APEX'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Borrador',
            'usuario_id' => $usuario->id,
        ]);
    }

    private function guardar(array $cambios = [])
    {
        return $this->putJson("/api/consultas/{$this->consulta->id}", [
            'tipo' => 'Cotizacion',
            'fecha' => $this->consulta->fecha->toDateString(),
            'lineas' => [array_merge([
                'descripcion' => 'Barra redonda Ø65',
                'material_id' => $this->titanio->id,
                'forma_id' => $this->barra->id,
                'cantidad' => 3,
                'igual_a_lo_pedido' => false,
                'pedido_material' => 'AISI 420',
                'pedido_forma' => 'BARRA REDONDA',
                'pedido_dimensiones' => 'Ø 10 X 3000 MM',
                'pedido_medidas' => [
                    'diameter' => ['valor' => 10, 'unidad' => 'mm'],
                    'length' => ['valor' => 3, 'unidad' => 'm'],
                ],
                'motivo_cambio' => 'No hay esa medida',
            ], $cambios)],
        ]);
    }

    private function laLinea(): ConsultaLinea
    {
        return $this->consulta->fresh('lineas')->lineas->first();
    }

    /** Las medidas de lo pedido se guardan campo por campo, con su unidad. */
    public function test_se_guardan_con_su_clave_y_su_unidad(): void
    {
        $this->guardar()->assertOk();

        $medidas = $this->laLinea()->pedido_medidas;

        $this->assertSame('10', (string) $medidas['diameter']['valor']);
        $this->assertSame('mm', $medidas['diameter']['unidad']);
        // El largo se pidio en metros y se guarda en metros: no se normaliza.
        $this->assertSame('3', (string) $medidas['length']['valor']);
        $this->assertSame('m', $medidas['length']['unidad']);
    }

    /** El texto impreso sigue saliendo de pedido_dimensiones. */
    public function test_lo_pedido_se_sigue_imprimiendo_en_una_linea(): void
    {
        $this->guardar()->assertOk();

        $this->assertStringContainsString('AISI 420', $this->laLinea()->pedido_texto);
        $this->assertStringContainsString('Ø 10 X 3000 MM', $this->laLinea()->pedido_texto);
    }

    /**
     * Cotizando tal cual lo pidieron, lo pedido se limpia entero.
     *
     * Las medidas tambien: si quedaran, una linea marcada como igual traeria
     * un pedido fantasma al reabrirla y el bloque amarillo apareceria solo.
     */
    public function test_al_cotizar_igual_se_limpian_tambien_las_medidas(): void
    {
        $this->guardar()->assertOk();
        $this->assertNotNull($this->laLinea()->pedido_medidas);

        $this->guardar(['igual_a_lo_pedido' => true])->assertOk();

        $linea = $this->laLinea();

        $this->assertNull($linea->pedido_medidas);
        $this->assertNull($linea->pedido_dimensiones);
        $this->assertNull($linea->pedido_material);
    }

    /** Una medida que no es un numero rebota: no se guarda basura. */
    public function test_una_medida_que_no_es_numero_rebota(): void
    {
        $this->guardar([
            'pedido_medidas' => ['diameter' => ['valor' => 'sesenta', 'unidad' => 'mm']],
        ])->assertStatus(422)->assertJsonValidationErrors('lineas.0.pedido_medidas.diameter.valor');
    }

    /** Una forma que no esta en el catalogo sigue usando la caja de texto. */
    public function test_sin_medidas_sueltas_queda_el_texto_como_antes(): void
    {
        $this->guardar([
            'pedido_forma' => 'PERFIL ESPECIAL DEL CLIENTE',
            'pedido_medidas' => null,
            'pedido_dimensiones' => 'segun plano 4471',
        ])->assertOk();

        $linea = $this->laLinea();

        $this->assertNull($linea->pedido_medidas);
        $this->assertSame('segun plano 4471', $linea->pedido_dimensiones);
    }
}

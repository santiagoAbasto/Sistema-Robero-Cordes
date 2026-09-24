<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Material;
use App\Models\Unidad;
use App\Models\User;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * De donde salio el factor de una linea.
 *
 * La procedencia depende de la OPERACION, no del valor. Que un numero coincida
 * con el que da la formula no prueba de donde vino: si alguien escribe 8,8554
 * a mano, es un factor cargado a mano y hay que poder preguntarle de donde lo
 * saco.
 *
 *  · calculadora — el servidor lo genero y lo aplico
 *  · manual      — lo escribio una persona, con nombre y fecha
 */
class ProcedenciaDelFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Empresa $empresa;

    private int $metro;

    private int $kilo;

    /** El factor que da la cuenta para titanio GR2 en barra redonda de 50 mm. */
    private const EL_QUE_DA_LA_CUENTA = 8.8554;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        $this->usuario = User::factory()->create();
        $this->empresa = Empresa::create(['nombre' => 'CLIENTE']);
        $this->metro = Unidad::where('codigo', 'MT')->value('id') ?? Unidad::value('id');
        $this->kilo = Unidad::where('codigo', 'KG')->value('id');
        Sanctum::actingAs($this->usuario);
    }

    /** Una linea de titanio que se vende por metro y se factura por kilo. */
    private function linea(array $extra = []): array
    {
        return array_merge([
            'descripcion' => 'BARRA REDONDA TITANIO GR2 50mm',
            'forma_id' => Forma::where('nombre', 'BARRA REDONDA')->value('id'),
            'material_id' => Material::where('nombre', 'TITANIO GR2')->value('id'),
            'diametro_mm' => 50,
            'cantidad' => 3,
            'unidad_venta_id' => $this->metro,
            'unidad_factura_id' => $this->kilo,
            'precio_por_kilo' => 48,
        ], $extra);
    }

    private function crear(array $linea): Consulta
    {
        $r = $this->postJson("/api/empresas/{$this->empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'lineas' => [$linea],
        ])->assertCreated();

        return Consulta::with('lineas')->findOrFail($r->json('data.id'));
    }

    private function volverAGuardar(Consulta $consulta, array $linea): ConsultaLinea
    {
        $this->putJson("/api/consultas/{$consulta->id}", [
            'tipo' => 'Cotizacion',
            'fecha' => $consulta->fecha->toDateString(),
            'lineas' => [$linea],
        ])->assertOk();

        return $consulta->lineas()->firstOrFail();
    }

    /** 1 · escrito a mano, igual al calculado, sigue siendo manual. */
    public function test_un_factor_manual_identico_al_calculado_sigue_siendo_manual(): void
    {
        $linea = $this->crear($this->linea([
            'factor_conversion' => self::EL_QUE_DA_LA_CUENTA,
        ]))->lineas->first();

        $this->assertSame(ConsultaLinea::FACTOR_MANUAL, $linea->origen_factor);
        $this->assertFalse($linea->factor_calculado);
        $this->assertSame($this->usuario->id, $linea->factor_cargado_por);
        $this->assertNotNull($linea->factor_cargado_el);

        // Y el valor es el que escribieron.
        $this->assertEqualsWithDelta(self::EL_QUE_DA_LA_CUENTA, (float) $linea->factor_conversion, 0.0001);
    }

    /** 2 · aplicado por la calculadora, queda calculado y sin responsable. */
    public function test_el_factor_aplicado_por_la_calculadora_queda_calculado(): void
    {
        $linea = $this->crear($this->linea([
            'aplicar_calculo_al_factor' => true,
            // Aunque manden un valor, el servidor usa el suyo.
            'factor_conversion' => 999,
        ]))->lineas->first();

        $this->assertSame(ConsultaLinea::FACTOR_CALCULADORA, $linea->origen_factor);
        $this->assertTrue($linea->factor_calculado);
        $this->assertNull($linea->factor_cargado_por);
        $this->assertNull($linea->factor_cargado_el);

        // El 999 no sobrevivio: el numero lo saco el servidor.
        $this->assertEqualsWithDelta(self::EL_QUE_DA_LA_CUENTA, (float) $linea->factor_conversion, 0.0001);
    }

    /** 3 · reabrir y guardar sin tocar nada conserva la procedencia. */
    public function test_reabrir_y_guardar_sin_cambios_conserva_el_origen(): void
    {
        // Una calculada.
        $consulta = $this->crear($this->linea(['aplicar_calculo_al_factor' => true]));
        $linea = $consulta->lineas->first();
        $this->assertSame(ConsultaLinea::FACTOR_CALCULADORA, $linea->origen_factor);

        // Se reabre y se guarda tal cual, como hace la pantalla.
        $despues = $this->volverAGuardar($consulta, $this->linea([
            'id' => $linea->id,
            'factor_conversion' => (float) $linea->factor_conversion,
        ]));

        $this->assertSame($linea->id, $despues->id);
        $this->assertSame(ConsultaLinea::FACTOR_CALCULADORA, $despues->origen_factor);
        $this->assertTrue($despues->factor_calculado);
        $this->assertNull($despues->factor_cargado_por);
    }

    public function test_reabrir_y_guardar_sin_cambios_tambien_conserva_el_manual(): void
    {
        $consulta = $this->crear($this->linea(['factor_conversion' => 7.5]));
        $linea = $consulta->lineas->first();
        $cuandoSeCargo = $linea->factor_cargado_el;

        $despues = $this->volverAGuardar($consulta, $this->linea([
            'id' => $linea->id,
            'factor_conversion' => 7.5,
        ]));

        $this->assertSame(ConsultaLinea::FACTOR_MANUAL, $despues->origen_factor);
        $this->assertSame($this->usuario->id, $despues->factor_cargado_por);
        // La fecha es la de cuando lo cargaron, no la de este guardado.
        $this->assertSame(
            $cuandoSeCargo->toDateTimeString(),
            $despues->factor_cargado_el->toDateTimeString(),
        );
    }

    /** 4 · modificar un factor calculado lo pasa a manual y lo audita. */
    public function test_modificar_un_factor_calculado_lo_pasa_a_manual(): void
    {
        $consulta = $this->crear($this->linea(['aplicar_calculo_al_factor' => true]));
        $linea = $consulta->lineas->first();

        $otro = User::factory()->create();
        Sanctum::actingAs($otro);

        $despues = $this->volverAGuardar($consulta, $this->linea([
            'id' => $linea->id,
            'factor_conversion' => 9.2,
        ]));

        $this->assertSame(ConsultaLinea::FACTOR_MANUAL, $despues->origen_factor);
        $this->assertFalse($despues->factor_calculado);
        $this->assertSame($otro->id, $despues->factor_cargado_por);
        $this->assertNotNull($despues->factor_cargado_el);
        $this->assertEqualsWithDelta(9.2, (float) $despues->factor_conversion, 0.0001);
    }

    /** 5 · volver a aplicar la calculadora limpia la auditoria manual. */
    public function test_volver_a_aplicar_la_calculadora_limpia_la_auditoria(): void
    {
        $consulta = $this->crear($this->linea(['factor_conversion' => 9.2]));
        $linea = $consulta->lineas->first();

        $this->assertSame(ConsultaLinea::FACTOR_MANUAL, $linea->origen_factor);
        $this->assertNotNull($linea->factor_cargado_por);

        $despues = $this->volverAGuardar($consulta, $this->linea([
            'id' => $linea->id,
            'aplicar_calculo_al_factor' => true,
            'factor_conversion' => 9.2,
        ]));

        $this->assertSame(ConsultaLinea::FACTOR_CALCULADORA, $despues->origen_factor);
        $this->assertTrue($despues->factor_calculado);
        // Ya no hay responsable: no lo puso una persona.
        $this->assertNull($despues->factor_cargado_por);
        $this->assertNull($despues->factor_cargado_el);
        $this->assertEqualsWithDelta(self::EL_QUE_DA_LA_CUENTA, (float) $despues->factor_conversion, 0.0001);
    }

    /** 6 · el navegador no puede dictar el origen ni la auditoria. */
    public function test_el_navegador_no_puede_falsificar_el_origen(): void
    {
        $impostor = User::factory()->create(['name' => 'NO FUI YO']);

        $linea = $this->crear($this->linea([
            'factor_conversion' => 7.5,
            // Todo esto es mentira y el servidor lo ignora.
            'factor_calculado' => true,
            'origen_factor' => ConsultaLinea::FACTOR_CALCULADORA,
            'factor_cargado_por' => $impostor->id,
            'factor_cargado_el' => '2020-01-01 00:00:00',
        ]))->lineas->first();

        // Lo escribio una persona: manual, con el usuario autenticado.
        $this->assertSame(ConsultaLinea::FACTOR_MANUAL, $linea->origen_factor);
        $this->assertFalse($linea->factor_calculado);
        $this->assertSame($this->usuario->id, $linea->factor_cargado_por);
        $this->assertNotSame($impostor->id, $linea->factor_cargado_por);

        // Y la fecha es del servidor, no la que mandaron.
        $this->assertNotSame('2020-01-01 00:00:00', $linea->factor_cargado_el->toDateTimeString());
        $this->assertTrue($linea->factor_cargado_el->isToday());
    }

    public function test_tampoco_puede_falsificar_el_origen_al_aplicar_la_calculadora(): void
    {
        $linea = $this->crear($this->linea([
            'aplicar_calculo_al_factor' => true,
            'origen_factor' => ConsultaLinea::FACTOR_MANUAL,
            'factor_cargado_por' => User::factory()->create()->id,
        ]))->lineas->first();

        $this->assertSame(ConsultaLinea::FACTOR_CALCULADORA, $linea->origen_factor);
        $this->assertNull($linea->factor_cargado_por);
    }

    /**
     * Sin factor cargado y sin pedir el calculo, el servidor lo propone.
     *
     * Lo genera y lo aplica el, asi que es de la calculadora: nadie lo escribio.
     */
    public function test_el_que_propone_el_servidor_es_de_la_calculadora(): void
    {
        $linea = $this->crear($this->linea())->lineas->first();

        $this->assertSame(ConsultaLinea::FACTOR_CALCULADORA, $linea->origen_factor);
        $this->assertTrue($linea->factor_calculado);
        $this->assertNull($linea->factor_cargado_por);
        $this->assertEqualsWithDelta(self::EL_QUE_DA_LA_CUENTA, (float) $linea->factor_conversion, 0.0001);
    }

    /** Sin formula no hay factor: no se inventa ni se marca de ningun origen. */
    public function test_sin_formula_no_queda_ningun_origen(): void
    {
        $linea = $this->crear($this->linea([
            'descripcion' => 'BRIDA AISI 304',
            'forma_id' => Forma::where('nombre', 'BRIDA')->value('id'),
            'aplicar_calculo_al_factor' => true,
        ]))->lineas->first();

        $this->assertNull($linea->factor_conversion);
        $this->assertNull($linea->origen_factor);
        $this->assertFalse($linea->factor_calculado);
        $this->assertNull($linea->factor_cargado_por);
    }

    /** El origen sale por la API, para poder mostrarlo. */
    public function test_el_origen_sale_por_la_api(): void
    {
        $consulta = $this->crear($this->linea(['factor_conversion' => 7.5]));

        $linea = $this->getJson("/api/consultas/{$consulta->id}")
            ->assertOk()
            ->json('data.lineas.0');

        $this->assertSame(ConsultaLinea::FACTOR_MANUAL, $linea['origen_factor']);
        $this->assertFalse($linea['factor_calculado']);
        $this->assertNotNull($linea['factor_cargado_por']);
        $this->assertNotNull($linea['factor_cargado_el']);
    }
}

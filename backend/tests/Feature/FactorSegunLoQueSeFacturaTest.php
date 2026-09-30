<?php

namespace Tests\Feature;

use App\Models\Consulta;
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
 * El factor es de la unidad en la que se FACTURA, no siempre kilos.
 *
 * El campo dice "MT por UN" y devolvia kilos. Vendiendo por unidad y
 * facturando en metros, una barra de 3,21 m daba 2,0169 —sus kilos, rotulados
 * como metros— y con eso salian mal la cantidad a facturar, el precio
 * unitario y el importe. Roberto lo vio asi: "hay MT pero pareciera tomarla
 * como otra cosa, hace una conversion rara".
 *
 * El servidor es el que manda: aunque el navegador mande otro factor, con
 * aplicar_calculo_al_factor lo recalcula y lo pisa. Por eso se prueba por la
 * API y no contra la calculadora.
 */
class FactorSegunLoQueSeFacturaTest extends TestCase
{
    use RefreshDatabase;

    private Consulta $consulta;

    private Material $acero;

    private Forma $barra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);

        Sanctum::actingAs($usuario = User::factory()->create());

        $this->acero = Material::where('nombre', 'TITANIO GR2')->firstOrFail();
        $this->barra = Forma::where('nombre', 'BARRA REDONDA')->firstOrFail();

        $this->consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'APEX'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Borrador',
            'usuario_id' => $usuario->id,
        ]);
    }

    /**
     * El id de una unidad. El seeder de pruebas trae UN, C/U, MT, KG y TN;
     * las demas que existen en produccion —FT, M2— se crean acá.
     */
    private function unidad(string $codigo): int
    {
        return Unidad::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo])->id;
    }

    /** Guarda una linea Ø10 x 3,21 m y devuelve el factor que saco el servidor. */
    private function factorCon(string $venta, string $factura, float $largoMm = 3210): ?float
    {
        $this->putJson("/api/consultas/{$this->consulta->id}", [
            'tipo' => 'Cotizacion',
            'fecha' => $this->consulta->fecha->toDateString(),
            'lineas' => [[
                'descripcion' => 'Barra redonda Ø10 x 3,21 m',
                'material_id' => $this->acero->id,
                'forma_id' => $this->barra->id,
                'diametro_mm' => 10,
                'largo_mm' => $largoMm,
                'cantidad' => 1,
                'unidad_venta_id' => $this->unidad($venta),
                'unidad_factura_id' => $this->unidad($factura),
                // Que lo saque el servidor, que es lo que pasa en la pantalla.
                'aplicar_calculo_al_factor' => true,
            ]],
        ])->assertOk();

        $factor = $this->consulta->fresh('lineas')->lineas->first()->factor_conversion;

        return $factor === null ? null : (float) $factor;
    }

    /**
     * El caso que reportaron: se vende por pieza y se factura por metro.
     *
     * El factor es el largo de la barra, 3,21 m. Antes devolvia 2,0169, que
     * son los kilos de esa misma barra.
     */
    public function test_facturando_en_metros_el_factor_es_el_largo(): void
    {
        $this->assertEqualsWithDelta(3.21, $this->factorCon('UN', 'MT'), 0.0001);
    }

    /** Sin largo no hay metros que facturar: no se inventa un numero. */
    public function test_sin_largo_no_hay_factor_en_metros(): void
    {
        $this->assertNull($this->factorCon('UN', 'MT', largoMm: 0));
    }

    /** Facturando por kilo sigue siendo el peso, como siempre. */
    public function test_facturando_en_kilos_el_factor_sigue_siendo_el_peso(): void
    {
        $factor = $this->factorCon('UN', 'KG');

        // Ø10 x 3210 mm de titanio: 252,15 mm³ por mm de largo, 4,51 g/cm³.
        $this->assertNotNull($factor);
        $this->assertEqualsWithDelta(1.1374, $factor, 0.01);
    }

    /** Vendiendo por metro y facturando por tonelada, el peso pasa a toneladas. */
    public function test_facturando_en_toneladas_el_peso_va_en_toneladas(): void
    {
        $enKg = $this->factorCon('MT', 'KG');
        $enTn = $this->factorCon('MT', 'TN');

        $this->assertNotNull($enKg);
        $this->assertEqualsWithDelta($enKg / 1000, $enTn, 0.0001);
    }

    /** Se vende y se factura por largo: es pasar de una unidad a la otra. */
    public function test_de_metros_a_pies_es_la_conversion_de_unidades(): void
    {
        $this->assertEqualsWithDelta(3.2808, $this->factorCon('MT', 'FT'), 0.001);
    }

    /** Lo que no sabemos convertir queda sin factor, para escribirlo a mano. */
    public function test_una_unidad_que_no_sabemos_convertir_no_se_inventa(): void
    {
        $this->assertNull($this->factorCon('UN', 'M2'));
    }
}

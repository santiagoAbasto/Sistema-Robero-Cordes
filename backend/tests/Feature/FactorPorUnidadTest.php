<?php

namespace Tests\Feature;

use App\Models\Forma;
use App\Models\Material;
use App\Services\CalculadoraFactor;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El factor son los kilos de UNA unidad de venta.
 *
 * Y depende de en que se vende. Una barra de titanio Ø127 pesa 57,13 kg el
 * metro; si la pieza mide 25,4 mm pesa 1,451 kg. Usar el de metro para algo que
 * se vende por unidad multiplica la factura por cuarenta: tres piezas pasarian
 * de 4,35 kg a 171,39 kg, de US$ 435 a US$ 17.139.
 *
 * Eso es exactamente lo que hacia el sistema: el largo estaba fijo en 1000 mm
 * sin mirar la unidad de venta.
 */
class FactorPorUnidadTest extends TestCase
{
    use RefreshDatabase;

    private CalculadoraFactor $factor;

    private Material $titanio;

    private Forma $barra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);

        $this->factor = app(CalculadoraFactor::class);
        $this->titanio = Material::where('nombre', 'TITANIO GR2')->firstOrFail();
        $this->barra = Forma::where('nombre', 'BARRA REDONDA')->firstOrFail();
    }

    private function calcular(?float $largoMm): array
    {
        return $this->factor->calcular(
            $this->titanio, $this->barra, 127.0, null, null, null, $largoMm,
        );
    }

    /** Vendiendo por metro, el factor son los kilos de un metro. */
    public function test_por_metro_pesa_un_metro(): void
    {
        $this->assertEqualsWithDelta(57.1313, $this->calcular(null)['factor'], 0.001);
    }

    /** Vendiendo por unidad, el factor son los kilos de esa pieza. */
    public function test_por_unidad_pesa_la_pieza(): void
    {
        $this->assertEqualsWithDelta(1.4511, $this->calcular(25.4)['factor'], 0.001);
    }

    /**
     * El caso que se vio en pantalla, de punta a punta.
     *
     * 3 barras Ø127 x 25,4 mm de titanio a US$ 100 el kilo.
     */
    public function test_el_caso_de_las_tres_barras_de_titanio(): void
    {
        $porPieza = $this->calcular(25.4)['factor'];

        $kilos = 3 * $porPieza;

        $this->assertEqualsWithDelta(4.353, $kilos, 0.01);
        $this->assertEqualsWithDelta(435.34, $kilos * 100, 0.5);

        // Y no lo que salia antes.
        $this->assertNotEqualsWithDelta(171.39, $kilos, 1.0);
    }

    /**
     * Sin largo no hay factor, y se dice por que.
     *
     * Antes se usaba un metro por defecto: salia un numero grande, plausible,
     * y nadie tenia como notar que estaba mal.
     */
    public function test_sin_largo_no_inventa_un_metro(): void
    {
        $r = $this->calcular(0.0);

        $this->assertNull($r['factor']);
        $this->assertStringContainsString('largo', mb_strtolower((string) $r['motivo']));
    }

    /** Una forma sin largo no lo necesita: el disco ya pesa lo que pesa. */
    public function test_una_forma_sin_largo_calcula_igual(): void
    {
        $disco = Forma::where('nombre', 'DISCO')->firstOrFail();

        $r = $this->factor->calcular($this->titanio, $disco, 127.0, 25.4, null, null, 0.0);

        $this->assertNotNull($r['factor']);
        $this->assertEqualsWithDelta(1.4511, $r['factor'], 0.001);
    }
}

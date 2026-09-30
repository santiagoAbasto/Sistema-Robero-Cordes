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
 * Largos variables: las barras no vienen todas del mismo largo.
 *
 * Se ofrecen "de 2,80 a 3,20 m". El peso, el factor y la cantidad a facturar
 * necesitan UN largo, y el que corresponde es el promedio: por arriba se cobra
 * de mas y por abajo se entrega de mas.
 *
 * Lo que se prueba acá es que el promedio llegue a TODAS las cuentas. Si
 * llegara a una sola, el peso y el factor de la misma linea saldrian de
 * largos distintos y nadie lo notaria hasta comparar la hoja con la factura.
 */
class LargosVariablesTest extends TestCase
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

    private function unidad(string $codigo): int
    {
        return Unidad::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo])->id;
    }

    /** Guarda una barra Ø20 y devuelve la respuesta. */
    private function guardar(array $cambios = [])
    {
        return $this->putJson("/api/consultas/{$this->consulta->id}", [
            'tipo' => 'Cotizacion',
            'fecha' => $this->consulta->fecha->toDateString(),
            'lineas' => [array_merge([
                'descripcion' => 'Barra redonda Ø20, largos variables',
                'material_id' => $this->titanio->id,
                'forma_id' => $this->barra->id,
                'diametro_mm' => 20,
                'largo_mm' => 3200,
                'cantidad' => 1,
                'unidad_venta_id' => $this->unidad('UN'),
                'unidad_factura_id' => $this->unidad('MT'),
                'aplicar_calculo_al_factor' => true,
                'calc_medidas' => [
                    'diameter' => ['valor' => 20, 'unidad' => 'mm'],
                    'length' => ['valor' => 3200, 'unidad' => 'mm'],
                ],
                'calc_piezas' => 1,
            ], $cambios)],
        ]);
    }

    private function laLinea(): ConsultaLinea
    {
        return $this->consulta->fresh('lineas')->lineas->first();
    }

    /** El largo con el que se calcula es el del medio, no el que vino escrito. */
    public function test_el_largo_que_queda_es_el_promedio_del_rango(): void
    {
        $this->guardar(['largo_min_mm' => 2800, 'largo_max_mm' => 3200])->assertOk();

        $linea = $this->laLinea();

        $this->assertEqualsWithDelta(3000, (float) $linea->largo_mm, 0.01);
        $this->assertTrue($linea->largoEsVariable());
        $this->assertEqualsWithDelta(3000, $linea->largoPromedioMm(), 0.01);
    }

    /** El factor en metros sale del promedio: ni 2,80 ni 3,20. */
    public function test_el_factor_sale_del_promedio(): void
    {
        $this->guardar(['largo_min_mm' => 2800, 'largo_max_mm' => 3200])->assertOk();

        $this->assertEqualsWithDelta(3.0, (float) $this->laLinea()->factor_conversion, 0.0001);
    }

    /**
     * El peso también. Es la otra mitad: el peso sale de la calculadora y el
     * factor de la columna suelta, y tienen que decir lo mismo.
     */
    public function test_el_peso_sale_del_promedio(): void
    {
        $this->guardar(['largo_min_mm' => 2800, 'largo_max_mm' => 3200])->assertOk();
        $conRango = (float) $this->laLinea()->peso_kg;

        // La misma barra, con el largo fijo en el promedio, tiene que pesar igual.
        $this->guardar([
            'largo_mm' => 3000,
            'calc_medidas' => [
                'diameter' => ['valor' => 20, 'unidad' => 'mm'],
                'length' => ['valor' => 3000, 'unidad' => 'mm'],
            ],
        ])->assertOk();

        $this->assertEqualsWithDelta((float) $this->laLinea()->peso_kg, $conRango, 0.001);
    }

    /** Sin los dos extremos el largo es uno solo, como siempre. */
    public function test_sin_rango_el_largo_es_el_que_se_cargo(): void
    {
        $this->guardar(['largo_min_mm' => 2800])->assertOk();

        $linea = $this->laLinea();

        $this->assertFalse($linea->largoEsVariable());
        $this->assertEqualsWithDelta(3200, (float) $linea->largo_mm, 0.01);
    }

    /**
     * Un rango al reves rebota.
     *
     * "De 3,20 a 2,80" da un promedio de 3,00 que parece razonable, y nadie
     * volveria a mirar el rango. Se corrige al cargarlo o no se carga.
     */
    public function test_el_maximo_no_puede_ser_menor_que_el_minimo(): void
    {
        $this->guardar(['largo_min_mm' => 3200, 'largo_max_mm' => 2800])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lineas.0.largo_max_mm');
    }
}

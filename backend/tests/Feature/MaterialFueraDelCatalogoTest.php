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
 * Cotizar un material que todavia no esta en el catalogo.
 *
 * El catalogo del sistema anterior —1249 materiales— no esta cargado, asi que
 * los clientes piden cosas que la lista no tiene. Hasta ahora eso dejaba la
 * linea sin material: sin densidad, sin peso, sin factor y sin importe, aunque
 * el pedido dijera clarito "Titanio Grado 7".
 *
 * Ahora el nombre escrito se da de alta al guardar, SIN densidad. La linea
 * queda con su material y sale impresa bien; el peso sigue sin calcularse
 * hasta que la empresa cargue la densidad, porque el sistema no la inventa.
 */
class MaterialFueraDelCatalogoTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Consulta $consulta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);

        $this->usuario = User::factory()->create();
        Sanctum::actingAs($this->usuario);

        $this->consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'PROFERTIL'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Borrador',
            'usuario_id' => $this->usuario->id,
        ]);
    }

    private function guardar(array $linea)
    {
        return $this->putJson("/api/consultas/{$this->consulta->id}", [
            'tipo' => 'Cotizacion',
            'fecha' => $this->consulta->fecha->toDateString(),
            'lineas' => [$linea],
        ]);
    }

    private function unidad(string $codigo): int
    {
        return Unidad::where('codigo', $codigo)->value('id');
    }

    /** Lo que pidio el cliente y no esta en la lista queda igual en la linea. */
    public function test_un_material_que_no_esta_en_el_catalogo_se_da_de_alta(): void
    {
        $this->assertNull(Material::where('nombre', 'Titanio Grado 7')->first());

        $this->guardar([
            'descripcion' => 'BARRA REDONDA 127 X 25,4 MM',
            'material_nuevo' => 'Titanio Grado 7',
            'cantidad' => 3,
        ])->assertOk();

        $nuevo = Material::where('nombre', 'Titanio Grado 7')->first();

        $this->assertNotNull($nuevo, 'El material que pidio el cliente tiene que quedar dado de alta');
        $this->assertSame($nuevo->id, $this->consulta->fresh('lineas')->lineas->first()->material_id);
    }

    /**
     * Sin densidad: el sistema no inventa el dato que define el peso facturado.
     *
     * Es la misma regla que con los 1167 materiales sin densidad del sistema
     * viejo. La densidad la confirma la empresa, no la literatura ni el agente.
     */
    public function test_el_material_nuevo_queda_sin_densidad(): void
    {
        $this->guardar([
            'descripcion' => 'BARRA REDONDA 127 X 25,4 MM',
            'material_nuevo' => 'Titanio Grado 7',
            'cantidad' => 3,
        ])->assertOk();

        $this->assertNull(Material::where('nombre', 'Titanio Grado 7')->value('densidad'));
    }

    /** Escrito de otra manera es el mismo material, no uno nuevo. */
    public function test_no_se_duplica_un_material_que_ya_existe_escrito_distinto(): void
    {
        $antes = Material::count();

        $this->guardar([
            'descripcion' => 'BARRA REDONDA',
            'material_nuevo' => 'titanio gr2',
            'cantidad' => 1,
        ])->assertOk();

        $this->assertSame($antes, Material::count(), 'No tiene que dar de alta un duplicado');
        $this->assertSame(
            Material::where('nombre', 'TITANIO GR2')->value('id'),
            $this->consulta->fresh('lineas')->lineas->first()->material_id,
        );
    }

    /** Y tampoco si lo escribieron con uno de sus alias conocidos. */
    public function test_se_reusa_el_material_cuando_coincide_con_un_alias(): void
    {
        $antes = Material::count();

        $this->guardar([
            'descripcion' => 'BARRA REDONDA',
            'material_nuevo' => 'TIT GR2',
            'cantidad' => 1,
        ])->assertOk();

        $this->assertSame($antes, Material::count());
        $this->assertSame(
            Material::where('nombre', 'TITANIO GR2')->value('id'),
            $this->consulta->fresh('lineas')->lineas->first()->material_id,
        );
    }

    /**
     * El largo de la pieza tiene que llegar al servidor.
     *
     * Antes no estaba entre los campos validados, asi que se descartaba en
     * silencio: la linea quedaba con largo_mm en null y la pantalla avisaba
     * "falta el largo de la pieza" con el largo cargado a la vista.
     *
     * Una barra Ø127 de titanio pesa 57,13 kg el metro y 1,451 kg si la pieza
     * mide 25,4 mm. Sin el largo, el factor sale por metro y la factura se va
     * a cuarenta veces lo que corresponde.
     */
    public function test_el_largo_de_la_pieza_llega_y_el_factor_sale_por_pieza(): void
    {
        $this->guardar([
            'descripcion' => 'TITANIO GR2 BARRA REDONDA 127 X 25,4 MM',
            'material_id' => Material::where('nombre', 'TITANIO GR2')->value('id'),
            'forma_id' => Forma::where('nombre', 'BARRA REDONDA')->value('id'),
            'diametro_mm' => 127,
            'largo_mm' => 25.4,
            'cantidad' => 3,
            'unidad_venta_id' => $this->unidad('UN'),
            'unidad_factura_id' => $this->unidad('KG'),
            'aplicar_calculo_al_factor' => true,
        ])->assertOk();

        $linea = $this->consulta->fresh('lineas')->lineas->first();

        $this->assertEquals(25.4, (float) $linea->largo_mm);
        $this->assertEqualsWithDelta(1.451, (float) $linea->factor_conversion, 0.001);
    }

    /** Sin densidad no hay peso, y sin peso no se inventa un factor. */
    public function test_un_material_sin_densidad_no_saca_factor(): void
    {
        $this->guardar([
            'descripcion' => 'BARRA REDONDA 127 X 25,4 MM',
            'material_nuevo' => 'Titanio Grado 7',
            'forma_id' => Forma::where('nombre', 'BARRA REDONDA')->value('id'),
            'diametro_mm' => 127,
            'largo_mm' => 25.4,
            'cantidad' => 3,
            'unidad_venta_id' => $this->unidad('UN'),
            'unidad_factura_id' => $this->unidad('KG'),
            'aplicar_calculo_al_factor' => true,
        ])->assertOk();

        $linea = $this->consulta->fresh('lineas')->lineas->first();

        $this->assertNull($linea->factor_conversion);
        $this->assertNull($linea->peso_kg);
    }

    /**
     * Cobrando por la unidad en que se vende, el importe sale sin peso.
     *
     * Es la salida del callejon: si se factura en la misma unidad que se
     * vende no hace falta ni densidad ni factor — importe = cantidad x precio.
     */
    public function test_se_puede_cobrar_por_unidad_sin_densidad_ni_factor(): void
    {
        $this->guardar([
            'descripcion' => 'BARRA REDONDA 127 X 25,4 MM',
            'material_nuevo' => 'Titanio Grado 7',
            'cantidad' => 3,
            'unidad_venta_id' => $this->unidad('UN'),
            'unidad_factura_id' => null,
            'precio_unitario' => 1250,
        ])->assertOk();

        $linea = $this->consulta->fresh('lineas')->lineas->first();

        $this->assertEquals(3750, (float) $linea->importe);
    }
}

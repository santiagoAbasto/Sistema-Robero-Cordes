<?php

namespace Tests\Feature;

use App\Models\CanoEstandar;
use App\Models\Forma;
use App\Models\Material;
use App\Services\CalculadoraDePeso;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los codos se miden con la tabla del caño (Roberto, 14-10-2026).
 *
 * "En el caso de los codos, debiera ingresar las medidas por la misma tabla
 * que los caños, pero no muestra en pantalla la tabla." El CODO 90° vino del
 * Access sin medidas y caia a DIMENSIONES como texto.
 *
 * Y al arreglarlo aparecio otro: sin peso —el codo no tiene formula, y 1062
 * materiales no tienen densidad— la foto del calculo no guardaba las medidas,
 * y la linea se reabria con los casilleros en blanco.
 */
class CodosConLaTablaDelCanoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
    }

    private function forma(string $nombre, array $datos = []): Forma
    {
        return Forma::firstOrCreate(['nombre' => $nombre], ['activo' => true] + $datos);
    }

    private function migrar(): void
    {
        (require database_path('migrations/2026_10_14_000200_codos_curva_y_casquete_con_la_tabla_del_cano.php'))->up();
    }

    public function test_los_accesorios_de_una_medida_usan_la_tabla_y_los_demas_no(): void
    {
        $codos = collect(['CODO 45°', 'CODO 90°', 'CODO 180°', 'CURVA', 'CASQUETE'])
            ->map(fn ($n) => $this->forma($n));
        // Dos medidas, por clase, o por rosca: no caben en un renglon de la tabla.
        $otras = collect(['REDUCCIÓN CONCENTRICA', 'TE DE REDUCCIÓN', 'BRIDA CIEGA', 'CASQUETE ROSCADO'])
            ->map(fn ($n) => $this->forma($n));

        $this->migrar();

        foreach ($codos as $forma) {
            $forma->refresh();
            $this->assertTrue($forma->usa_cano, $forma->nombre);
            $this->assertSame(['outer', 'wall'], array_column($forma->camposDelCalculo(), 'clave'), $forma->nombre);
            // Sin formula: un codo no pesa lo que un tramo de caño.
            $this->assertNull($forma->expresion, $forma->nombre);
        }

        foreach ($otras as $forma) {
            $forma->refresh();
            $this->assertFalse($forma->usa_cano, $forma->nombre);
            $this->assertEmpty($forma->campos, $forma->nombre);
        }
    }

    public function test_no_pisa_un_codo_que_ya_configuraron_desde_la_pantalla(): void
    {
        $propio = [['clave' => 'diameter', 'label' => 'Diametro']];
        $codo = $this->forma('CODO 90°', ['campos' => $propio]);

        $this->migrar();

        $this->assertSame($propio, $codo->fresh()->campos);
        $this->assertFalse($codo->fresh()->usa_cano);
    }

    /** El codo de la orden de compra: 6" SCH 40S, sin peso pero con sus medidas. */
    public function test_el_codo_guarda_las_medidas_del_cano_aunque_no_tenga_peso(): void
    {
        $codo = $this->forma('CODO 90°');
        $this->migrar();
        $cano = CanoEstandar::where('nombre', '6"')->where('schedule', '40S')->firstOrFail();

        $foto = app(CalculadoraDePeso::class)->calcular(
            Material::where('nombre', 'AISI 304')->firstOrFail(),
            $codo->fresh(),
            [],
            cano: $cano,
        );

        $this->assertFalse($foto['ok']);
        $this->assertNull($foto['peso_total_kg']);
        $this->assertSame($cano->id, $foto['cano_id']);
        $this->assertEquals(168.3, $foto['medidas']['outer']['valor_mm']);
        $this->assertEquals(7.11, $foto['medidas']['wall']['valor_mm']);
    }

    /** Lo mismo pasaba hoy con cualquier caño de un material sin densidad. */
    public function test_sin_densidad_el_cano_no_pierde_las_medidas(): void
    {
        $foto = app(CalculadoraDePeso::class)->calcular(
            Material::create(['nombre' => 'Acero ASTM A182 F11', 'activo' => true]),
            Forma::where('nombre', 'CAÑO')->firstOrFail(),
            [
                'outer' => ['valor' => '60.3', 'unidad' => 'mm'],
                'wall' => ['valor' => '3.91', 'unidad' => 'mm'],
                'length' => ['valor' => '6', 'unidad' => 'm'],
            ],
        );

        $this->assertFalse($foto['ok']);
        $this->assertStringContainsString('densidad', $foto['motivo']);
        $this->assertEquals(60.3, $foto['medidas']['outer']['valor']);
        // La unidad que se cargo se respeta: 6 m sigue siendo 6 m.
        $this->assertEquals(6, $foto['medidas']['length']['valor']);
        $this->assertSame('m', $foto['medidas']['length']['unidad']);
    }
}

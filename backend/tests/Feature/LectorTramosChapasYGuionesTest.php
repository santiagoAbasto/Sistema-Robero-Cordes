<?php

namespace Tests\Feature;

use App\Services\LectorDeSolicitud;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los tres casos que Roberto mando el 03-11-2026.
 *
 * 1) "BARRA RED MZA Ø 25,4 = Un tramo de 0,5 Metro": salia sin largo ni
 *    cantidad y "distinto a lo que pidio".
 * 2) Una tabla con "0001 Chapa de aluminio 6061 T6 - Espesor 1mm, dimensiones
 *    1220x3660mm. 2 Cada Uno": el numero de item se leia como cantidad, el
 *    espesor como 1220 y el material se perdia.
 * 3) "sería 1 unidad XLPE-VitE Ø 90 x 1524mm": sin cantidad ni material.
 */
class LectorTramosChapasYGuionesTest extends TestCase
{
    use RefreshDatabase;

    private LectorDeSolicitud $lector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        $this->lector = new LectorDeSolicitud();
    }

    private function lineas(string $texto): array
    {
        return $this->lector->interpretar($texto)['lineas'];
    }

    public function test_un_tramo_de_medio_metro_es_una_pieza_de_500_mm(): void
    {
        $l = $this->lineas("SUPER DUPLEX 2507 S32750\n\nBARRA  RED MZA Ø 25,4  = Un tramo de 0,5 Metro.\nBARRA  RED MZA Ø 63,5 = Un tramo de 1 Metro.");

        $this->assertCount(2, $l);
        $this->assertSame(25.4, $l[0]['diametro_mm']);
        $this->assertEquals(500, $l[0]['largo_mm']);
        $this->assertEquals(1, $l[0]['cantidad']);
        $this->assertEquals(1000, $l[1]['largo_mm']);
        // El material de arriba vale para las dos, aunque no sea del catalogo.
        $this->assertSame('SUPER DUPLEX 2507 S32750', $l[0]['pedido_material'] ?? $l[0]['material']);
    }

    public function test_tabla_de_chapas_con_numero_de_item_espesor_y_cada_uno(): void
    {
        $l = $this->lineas(
            "0001 Chapa de aluminio 6061 T6 - Espesor 1mm, dimensiones 1220x3660mm. 2 Cada Uno\n".
            "0004 Chapa de aluminio 5052 H32 - Espesor 2mm, dimensiones 1220x3660mm. 3 Cada Uno"
        );

        $this->assertCount(2, $l);
        // 1 de espesor, 1220 de ancho y 3660 de largo: no 1220 de espesor.
        $this->assertEquals(1, $l[0]['espesor_mm']);
        $this->assertEquals(1220, $l[0]['ancho_mm']);
        $this->assertEquals(3660, $l[0]['largo_mm']);
        // La cantidad es la de "Cada Uno", no el numero de item.
        $this->assertEquals(2, $l[0]['cantidad']);
        $this->assertEquals(3, $l[1]['cantidad']);
        // Aleacion y temple que el catalogo no tiene: queda como lo que pidio.
        $this->assertSame('ALUMINIO 5052 H32', $l[1]['pedido_material'] ?? null);
    }

    public function test_material_con_guion_y_una_unidad_en_medio_del_renglon(): void
    {
        $l = $this->lineas('sería 1 unidad XLPE-VitE Ø 90 x 1524mm')[0];

        $this->assertEquals(1, $l['cantidad']);
        $this->assertEquals(90, $l['diametro_mm']);
        $this->assertEquals(1524, $l['largo_mm']);
        $this->assertSame('XLPE-VITE', $l['pedido_material']);
    }

    public function test_una_norma_con_guion_no_es_un_material(): void
    {
        $l = $this->lineas('2 barras Ø 20 x 1000mm ASTM-B348')[0];

        $this->assertArrayNotHasKey('pedido_material', $l);
    }
}

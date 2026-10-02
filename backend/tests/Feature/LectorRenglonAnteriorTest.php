<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialAlias;
use App\Services\LectorDeSolicitud;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LectorRenglonAnteriorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El material en un renglon y las medidas en el de abajo, y el guion
     * largo que pone Outlook: "Monel K –500" es el K-500, no el Monel a secas.
     */
    public function test_el_material_del_renglon_anterior_y_el_guion_largo(): void
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        $lector = new LectorDeSolicitud();

        Material::create(['nombre' => 'Monel', 'activo' => true]);
        $k500 = Material::create(['nombre' => 'Monel K500', 'activo' => true]);
        MaterialAlias::create(['material_id' => $k500->id, 'alias' => 'MON K500']);

        $r = $lector->interpretar("Buen día por la presente les solicito precio y plazo de entrega por material Monel K –500\ndiametro exterior 76 mm largo 970 mm.");

        $this->assertCount(1, $r['lineas']);
        $this->assertSame($k500->id, $r['lineas'][0]['material_id']);
        // Sin palabra de forma no se adivina: puede ser barra o tubo.
        $this->assertNull($r['lineas'][0]['forma_id']);

        // El mismo guion en un solo renglon, largo, raya o con espacios.
        foreach (['Monel K –500', 'Monel K—500', 'Monel K - 500', 'Monel K-500'] as $escrito) {
            $l = $lector->interpretar("2 barras {$escrito} 76 x 970")['lineas'][0];
            $this->assertSame($k500->id, $l['material_id'], $escrito);
        }

        // Un renglon con su propio material no toma el de arriba.
        $r = $lector->interpretar("Cotizar en Monel K-500:\n2 barras Inconel 600 76 x 970\n3 barras 20 x 500");
        $this->assertSame('INCONEL 600', $r['lineas'][0]['material']);
        $this->assertSame($k500->id, $r['lineas'][1]['material_id']);
    }

    /**
     * La cantidad se lee como se escribio.
     *
     * Del texto sin espacios ni comas se pegaba al numero de al lado: con
     * "10 Ø4,76" Belen tuvo una linea de 10476 piezas.
     */
    public function test_la_cantidad_no_se_pega_a_la_medida(): void
    {
        $this->seed(CatalogosSeeder::class);
        $lector = new LectorDeSolicitud();

        $this->assertSame(10.0, $lector->interpretar('10 Ø4,76')['lineas'][0]['cantidad']);
        $this->assertSame(1.5, $lector->interpretar('1,5 MT barra 20 mm')['lineas'][0]['cantidad']);
        $this->assertSame(1000.0, $lector->interpretar('1.000 KG barra 20 mm')['lineas'][0]['cantidad']);
        $this->assertSame('MT', $lector->interpretar('3 metros barra 20 mm')['lineas'][0]['unidad']);
    }
}

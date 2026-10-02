<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialAlias;
use App\Services\LectorDeSolicitud;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los pedidos que mando Roberto el 01-10-2026, con la misma forma.
 *
 * Las personas y los telefonos son inventados: los de los mails reales no
 * van al repositorio.
 */
class PedidosDelClienteTest extends TestCase
{
    use RefreshDatabase;

    private Material $gr4;

    private Material $k500;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);

        // Como en el catalogo real: Titanio Grado 4 solo tiene el alias TIT GR4.
        $this->gr4 = Material::create(['nombre' => 'Titanio Grado 4', 'activo' => true]);
        MaterialAlias::create(['material_id' => $this->gr4->id, 'alias' => 'TIT GR4']);
        Material::create(['nombre' => 'Monel', 'activo' => true]);
        $this->k500 = Material::create(['nombre' => 'Monel K500', 'activo' => true]);
    }

    private function leer(string $texto): array
    {
        return (new LectorDeSolicitud)->interpretar($texto)['lineas'];
    }

    /**
     * "Ti. Gr. 4 Ø4,76 ASTM F 67 10 Barras".
     *
     * Las reglas no lo leian y quedaba en manos de la IA, que no siempre
     * contesta lo mismo: con el mismo texto Roberto y Belen vieron cosas
     * distintas. Ahora lo leen las reglas, con o sin saltos.
     */
    public function test_titanio_grado_4_siempre_igual(): void
    {
        foreach ([
            'Ti. Gr. 4 Ø4,76 ASTM F 67 10 Barras',
            "Hola, necesito cotizar:\nTi. Gr. 4 Ø4,76 ASTM F 67 10 Barras\nSaludos",
            "Ti. Gr. 4 Ø4,76 ASTM F 67\n10 Barras",
            "Ti. Gr. 4\tØ4,76\tASTM F 67\t10 Barras",
            // Cada dato en su renglon: el Ø corto ya no se descarta por ruido.
            "Ti. Gr. 4\nØ4,76\nASTM F 67\n10 Barras",
        ] as $texto) {
            $lineas = $this->leer($texto);

            $this->assertCount(1, $lineas, $texto);
            $this->assertSame($this->gr4->id, $lineas[0]['material_id'], $texto);
            $this->assertSame('BARRA REDONDA', $lineas[0]['forma'], $texto);
            $this->assertEquals(4.76, $lineas[0]['diametro_mm'], $texto);
            $this->assertNull($lineas[0]['largo_mm'], 'ASTM F 67 es una norma, no un largo');
            $this->assertEquals(10, $lineas[0]['cantidad'], $texto);
        }

        // El grado no es la cantidad, y un "ti" suelto no es titanio.
        $this->assertNull($this->leer('Ø6 x 3000 titanio grado 2 barras')[0]['cantidad']);
        $this->assertNull($this->leer('10 barras Ø6 para ti')[0]['material_id']);
    }

    /**
     * El mail entero, con la ficha de contacto pegada al final y el "Nombre:"
     * en el mismo renglon que las medidas.
     */
    public function test_el_pedido_con_la_ficha_de_contacto_pegada(): void
    {
        $lineas = $this->leer(
            "Buen día por la presente les solicito precio y plazo de entrega por material Monel K –500\n"
            ."diametro exterior 76 mm largo 970 mm.Nombre: Pablo Ferreyra\n"
            ."Cargo / Área: Compras\nEmpresa: ACME\n"
            ."Dirección: Calle Falsa 1234, Lanús, Buenos Aires\n"
            ."Teléfono / WhatsApp: (+54-9) 11-4567-2389\nWeb: www.acme.com.ar"
        );

        $this->assertCount(1, $lineas);
        $this->assertSame($this->k500->id, $lineas[0]['material_id']);
        $this->assertEquals(76, $lineas[0]['diametro_mm']);
        $this->assertEquals(970, $lineas[0]['largo_mm']);
        $this->assertStringNotContainsString('Nombre', $lineas[0]['descripcion'], 'la ficha no se imprime');
    }
}

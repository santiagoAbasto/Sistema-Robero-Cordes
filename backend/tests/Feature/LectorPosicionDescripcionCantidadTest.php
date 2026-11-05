<?php

namespace Tests\Feature;

use App\Services\LectorDeSolicitud;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El pedido que Roberto mandó el 04-11-2026 ("otra: lo que pidió el cliente").
 *
 * Una tabla con tres columnas —Posición, Descripción, Cantidad— donde la
 * descripción trae todo con guiones: "Barra AISI 431 - Diámetro 80mm - Largo
 * 200mm". Hay que sacar de cada renglón el material, la forma, el diámetro, el
 * largo, la cantidad y la posición del cliente (10, 20, 30...).
 */
class LectorPosicionDescripcionCantidadTest extends TestCase
{
    use RefreshDatabase;

    private LectorDeSolicitud $lector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        $this->lector = new LectorDeSolicitud;
    }

    /** El pedido, con las columnas separadas como las deja copiar de un mail. */
    private function pedido(string $separador): string
    {
        $filas = [
            ['Posición', 'Descripción', 'Cantidad'],
            ['10', 'Barra AISI 431 - Diámetro 80mm - Largo 200mm', '1'],
            ['20', 'Barra AISI 431 - Diámetro 50mm  - Largo 300mm', '1'],
            ['30', 'Barra AISI 431 - Diámetro 30mm  - Largo 400mm', '1'],
            ['40', 'Barra AISI 321 - Diámetro 100mm  - Largo 300mm', '1'],
            ['50', 'Barra Nitronic 60 - Diámetro 12.7mm  - Largo 500mm', '1'],
        ];

        return implode("\n", array_map(fn ($f) => implode($separador, $f), $filas));
    }

    public static function separadores(): array
    {
        return [
            'con tabuladores' => ["\t"],
            'con varios espacios' => ['   '],
            'con un espacio' => [' '],
        ];
    }

    #[DataProvider('separadores')]
    public function test_saca_las_cinco_lineas_con_su_medida_cantidad_y_posicion(string $separador): void
    {
        $l = $this->lector->interpretar($this->pedido($separador))['lineas'];

        $this->assertCount(5, $l, 'son cinco renglones; la fila de títulos no es una línea');

        $esperado = [
            [80, 200, 'AISI 431', '10'],
            [50, 300, 'AISI 431', '20'],
            [30, 400, 'AISI 431', '30'],
            [100, 300, 'AISI 321', '40'],
            [12.7, 500, 'NITRONIC 60', '50'],
        ];

        foreach ($esperado as $i => [$diametro, $largo, $material, $posicion]) {
            $this->assertEquals($diametro, $l[$i]['diametro_mm'], 'línea '.($i + 1).': diámetro');
            $this->assertEquals($largo, $l[$i]['largo_mm'], 'línea '.($i + 1).': largo');
            $this->assertEquals(1, $l[$i]['cantidad'], 'línea '.($i + 1).': cantidad (no es la posición)');
            $this->assertStringContainsStringIgnoringCase(
                $material,
                (string) (($l[$i]['material'] ?? '').' '.($l[$i]['pedido_material'] ?? '')),
                'línea '.($i + 1).': material',
            );
            $this->assertSame($posicion, (string) ($l[$i]['item_cliente'] ?? ''), 'línea '.($i + 1).': posición del cliente');
        }
    }

    // --- Lo que no se puede perder en silencio ------------------------------

    public function test_una_fila_que_no_cierra_se_avisa_en_vez_de_perderse(): void
    {
        // La tercera fila trae una cantidad que no es número: la tabla se corta ahí.
        $texto = "Posición\tDescripción\tCantidad\n"
            ."10\tBarra AISI 431 - Diámetro 80mm - Largo 200mm\t1\n"
            ."20\tBarra AISI 431 - Diámetro 50mm - Largo 300mm\tuna\n"
            ."30\tBarra AISI 431 - Diámetro 30mm - Largo 400mm\t1";

        $r = $this->lector->interpretar($texto);

        $this->assertCount(1, $r['lineas']);
        $this->assertCount(2, $r['sin_reconocer'], 'las dos filas que no entraron tienen que figurar');
        $this->assertStringContainsString('Diámetro 50mm', $r['sin_reconocer'][0]);
    }

    public function test_una_firma_o_un_saludo_despues_de_la_tabla_no_se_avisa_como_item(): void
    {
        $texto = $this->pedido("\t")."\nSaludos cordiales,\nMaría Pérez\nTel: 011 5555-0100\n011 5555 0100";

        $r = $this->lector->interpretar($texto);

        $this->assertCount(5, $r['lineas']);
        $this->assertSame([], $r['sin_reconocer']);
    }

    public function test_lo_que_trae_celdas_de_mas_en_la_descripcion_se_junta(): void
    {
        $texto = "Item\tDescripción\tCant.\n"
            ."1\tBarra AISI 304\t- Diámetro 20mm\t- Largo 500mm\t2";

        $l = $this->lector->interpretar($texto)['lineas'];

        $this->assertCount(1, $l);
        $this->assertEquals(20, $l[0]['diametro_mm']);
        $this->assertEquals(500, $l[0]['largo_mm']);
        $this->assertEquals(2, $l[0]['cantidad']);
    }

    public function test_el_item_del_cliente_se_corta_a_lo_que_admite_el_campo(): void
    {
        $texto = "Posición\tDescripción\tCantidad\n"
            ."ABCDEFGHIJKLMNOPQRSTUVWXYZ\tBarra AISI 304 - Diámetro 20mm - Largo 500mm\t1";

        $l = $this->lector->interpretar($texto)['lineas'];

        $this->assertSame('ABCDEFGHIJKLMNOPQRST', $l[0]['item_cliente']);
    }

    public function test_una_tabla_sin_posicion_no_inventa_item_del_cliente(): void
    {
        $texto = "Descripción\tCantidad\tUnidad\n"
            ."Barra AISI 304 - Diámetro 20mm - Largo 500mm\t3\tUN";

        $l = $this->lector->interpretar($texto)['lineas'];

        $this->assertCount(1, $l);
        $this->assertArrayNotHasKey('item_cliente', $l[0]);
    }

    // --- El largo con nombre, fuera de las tablas ---------------------------

    public function test_diametro_y_largo_con_nombre_y_guion_en_un_renglon_suelto(): void
    {
        $l = $this->lector->interpretar('2 Barra AISI 431 - Diámetro 80mm - Largo 200mm')['lineas'];

        $this->assertCount(1, $l);
        $this->assertEquals(80, $l[0]['diametro_mm']);
        $this->assertEquals(200, $l[0]['largo_mm']);
        $this->assertEquals(2, $l[0]['cantidad']);
    }

    public function test_el_largo_con_nombre_en_metros_y_con_dos_puntos(): void
    {
        $l = $this->lector->interpretar('1 Barra AISI 304 Ø 25 mm, Largo: 3 m')['lineas'];

        $this->assertEquals(25, $l[0]['diametro_mm']);
        $this->assertEquals(3000, $l[0]['largo_mm']);
    }

    public function test_el_orden_de_las_medidas_con_nombre_no_importa(): void
    {
        $l = $this->lector->interpretar('1 Barra AISI 304 Largo 500mm - Diámetro 20mm')['lineas'];

        $this->assertEquals(20, $l[0]['diametro_mm']);
        $this->assertEquals(500, $l[0]['largo_mm']);
    }

    public function test_lo_que_ya_se_leia_con_x_o_con_largo_pegado_sigue_igual(): void
    {
        $a = $this->lector->interpretar('2 Barra AISI 304 Ø 20 x 500 mm')['lineas'][0];
        $b = $this->lector->interpretar('2 Barra AISI 304 76 mm largo 970 mm')['lineas'][0];

        $this->assertEquals([20, 500], [$a['diametro_mm'], $a['largo_mm']]);
        $this->assertEquals([76, 970], [$b['diametro_mm'], $b['largo_mm']]);
    }

    // --- Aleaciones con nombre propio ---------------------------------------

    public function test_nitronic_queda_como_lo_pedido_con_su_numero(): void
    {
        foreach (['Barra Nitronic 60 - Diámetro 12.7mm - Largo 500mm', 'Barra Nitronic-60 Ø 12.7 x 500 mm', '1 barra NITRONIC 60 Ø12,7 x 500mm'] as $texto) {
            $l = $this->lector->interpretar('1 '.$texto)['lineas'];

            $this->assertCount(1, $l, $texto);
            $this->assertSame('NITRONIC 60', $l[0]['pedido_material'], $texto);
            $this->assertFalse($l[0]['igual_a_lo_pedido'], 'el catálogo no lo tiene: alguien tiene que elegir');
            $this->assertNull($l[0]['material_id']);
        }
    }

    public function test_una_medida_pegada_al_nombre_no_es_parte_del_nombre(): void
    {
        $l = $this->lector->interpretar('1 Barra Nitronic 12.7mm x 500mm')['lineas'];

        $this->assertSame('NITRONIC', $l[0]['pedido_material']);
    }

    public function test_otra_aleacion_con_nombre_tambien_se_conserva(): void
    {
        $l = $this->lector->interpretar('1 Barra Invar Ø 20 x 300 mm')['lineas'];

        $this->assertSame('INVAR', $l[0]['pedido_material']);
    }
}

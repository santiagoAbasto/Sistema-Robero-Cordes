<?php

namespace Tests\Feature;

use App\Services\Migracion\MedidasDelTexto;
use PHPUnit\Framework\TestCase;

/**
 * Leer las medidas dentro de la descripcion de una linea historica.
 *
 * Los casos son textos reales del sistema anterior. El criterio, el mismo que
 * en todo lo que toca la migracion: si no se puede leer con seguridad, no se
 * carga. Una medida equivocada sale en el peso que se factura.
 */
class MedidasDelTextoTest extends TestCase
{
    private MedidasDelTexto $medidor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medidor = new MedidasDelTexto();
    }

    public function test_un_redondo_es_diametro_y_largo(): void
    {
        $this->assertSame(
            ['diameter' => 1.6, 'length' => 915.0],
            $this->medidor->leer('10 UN TIT GR1 BARRA 1.60 X 915 MM, C/U (+IVA)', 'BARRA'),
        );
    }

    public function test_un_cano_es_exterior_pared_y_largo(): void
    {
        $this->assertSame(
            ['outer' => 60.3, 'wall' => 3.9, 'length' => 30.0],
            $this->medidor->leer('1 C/U CAÑO 60.3 X 3.9 X 30 MM C/U(+IVA)', 'CAÑO'),
        );
    }

    public function test_un_disco_es_diametro_y_espesor(): void
    {
        $this->assertSame(
            ['diameter' => 114.3, 'height' => 25.4],
            $this->medidor->leer('ALLOY 20 DISCO Ø 114.3 X 25.4MM  C/U(+IVA)', 'DISCO'),
        );
    }

    /** El simbolo de diametro y la coma decimal son los del archivo. */
    public function test_lee_el_simbolo_de_diametro_y_la_coma(): void
    {
        $this->assertSame(
            ['diameter' => 25.4, 'length' => 900.0],
            $this->medidor->leer('HASTELLOY C-276 BARRA Ø 25,4 X 900 MM', 'BARRA'),
        );
    }

    /**
     * Los planos quedan afuera a proposito.
     *
     * El archivo escribe "CHAPA 1 X 1220 X 3048" con el espesor primero y
     * "CHAPA DE 910 X 600 X 16 MM" con el espesor ultimo. Sin un orden estable
     * no se puede leer, y el espesor es de donde sale el peso.
     */
    public function test_los_planos_no_se_leen(): void
    {
        $this->assertSame([], $this->medidor->leer('AISI 321 CHAPA 3 X 1250 X 2500MM', 'CHAPA'));
        $this->assertSame([], $this->medidor->leer('PLANCHUELA 3.18 X 15 X 500 MM', 'PLANCHUELA'));
    }

    /** Una forma que no esta en la lista no se toca. */
    public function test_una_forma_desconocida_no_se_lee(): void
    {
        $this->assertSame([], $this->medidor->leer('BRIDA 150 X 25 MM', 'BRIDA'));
    }

    /**
     * Un redondo es mas largo que grueso.
     *
     * "TUNGSTENO ALAMBRE 3 X 0,76 MM" leido como diametro y largo da un alambre
     * de 0,76 mm de largo, que no existe: es un fleje con el nombre de otra
     * forma. Sin saber cual de las dos cosas es, no se carga ninguna.
     */
    public function test_un_largo_menor_que_el_diametro_se_descarta(): void
    {
        $this->assertSame([], $this->medidor->leer('TUNGSTENO, ALAMBRE 3 X 0,76 MM P/KG', 'ALAMBRE'));
    }

    /**
     * La medida va pegada a la forma, no treinta palabras despues.
     *
     * Sin esto, una frase que nombra la forma al pasar daba medidas sacadas de
     * la prosa.
     */
    public function test_los_numeros_lejos_de_la_forma_no_cuentan(): void
    {
        $this->assertSame([], $this->medidor->leer(
            'MONEL K500 - CONT: (LAS BARRAS MACIZAS SE PODRIAN ENTREGAR EN DOS TRAMOS DE 170 A 300 MM)',
            'BARRA',
        ));
    }

    /** Sin unidad ni simbolo, un par de numeros puede ser cualquier cosa. */
    public function test_sin_unidad_no_se_lee(): void
    {
        $this->assertSame([], $this->medidor->leer(
            'COTIZA 20552299 AISI 310  2 U RECTANGULO 50 X 3000 C/U(+IVA)',
            'BARRA',
        ));
    }

    /** Las pulgadas necesitan convertir fracciones: no vale el riesgo. */
    public function test_las_pulgadas_no_se_leen(): void
    {
        $this->assertSame([], $this->medidor->leer('BARRA 1"1/4 X 3/8" P/M', 'BARRA'));
    }

    /** Un rango no es una medida: son dos opciones. */
    public function test_un_rango_no_se_lee(): void
    {
        $this->assertSame([], $this->medidor->leer('BARRA 5/7 X 45/50 MM', 'BARRA'));
    }

    /** Solo el diametro, cuando es lo unico que dice. */
    public function test_lee_solo_el_diametro_si_esta_marcado(): void
    {
        $this->assertSame(
            ['diameter' => 4.75],
            $this->medidor->leer('KANTHAL, ALAMBRE Ø 4,75 MM OFRECE UN ROLLO', 'ALAMBRE'),
        );
    }
}

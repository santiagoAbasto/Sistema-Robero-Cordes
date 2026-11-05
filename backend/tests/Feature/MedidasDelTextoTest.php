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
        $this->medidor = new MedidasDelTexto;
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

    /**
     * Un caño en pulgadas con schedule no tiene exterior y pared en milimetros.
     *
     * `CAÑO 2" SCH 40 X 760MM` se leia como exterior 40 y pared 760: el 40 es la serie y el 760
     * el largo. Era lo mas comun del archivo y el peso que se factura sale de esa medida.
     */
    public function test_un_cano_en_pulgadas_con_schedule_no_se_lee(): void
    {
        foreach ([
            '1 CU CAÑO SMLS 2" SCH 40 X 760MM C/U',
            '1 CU CAÑO WLD 2" S10 X 400MM C/U',
            'CAÑO C/C 2" SCH. 10 X 400MM, 1 C/U',
            'CAÑO 1" S40 X 6.000 MM C/U',
        ] as $texto) {
            $this->assertSame([], $this->medidor->leer($texto, 'CAÑO'), "se leyo algo en: {$texto}");
        }
    }

    /** El ultimo numero se lee entero aunque caiga lejos de la forma. */
    public function test_el_ultimo_numero_no_se_corta(): void
    {
        $this->assertSame(
            ['outer' => 114.3, 'wall' => 6.07, 'length' => 1524.0],
            $this->medidor->leer('TITANIO, CAÑO SIN COSTURA Ø114,3 X 6,07 X 1524 MM C/U(+IVA)', 'CAÑO'),
        );
        $this->assertSame(
            ['outer' => 6.35, 'wall' => 0.89],
            $this->medidor->leer('TUBO SIN COSTURA Ø 6,35 X 0,89 MM X 1 METRO C/U (+IVA)', 'TUBO'),
        );
    }

    /** "ØE" y "ØEXT" son el diametro exterior: no parten el numero. */
    public function test_el_diametro_exterior_abreviado(): void
    {
        $this->assertSame(
            ['outer' => 25.4, 'wall' => 1.65, 'length' => 3600.0],
            $this->medidor->leer('ACERO INOX. 254-SMO TUBO C/COST A249 ØE25.4 X 1.65 X 3600MM, C/U', 'TUBO'),
        );
    }

    /** Si lo que sigue es otra unidad, la ultima cifra no son milimetros. */
    public function test_los_metros_no_son_milimetros(): void
    {
        $this->assertSame([], $this->medidor->leer('PLATINO ALAMBRE Ø 0.5 X 3 METROS COTIZA', 'ALAMBRE'));
        $this->assertSame([], $this->medidor->leer('PLATINO ALAMBRE Ø 0.3 X 1 M 1.6 GR. P/GR', 'ALAMBRE'));
    }

    /** Un rango de largos —4.5/7 metros— son dos opciones. */
    public function test_un_rango_de_largos_no_se_lee(): void
    {
        $this->assertSame([], $this->medidor->leer('TUBO AISI 316 L Ø 8 X 2 X 4.5/7 M 1000 METROS P/M', 'TUBO'));
    }

    /** Un alambre es largo: los dos lados de una seccion rectangular no son diametro y largo. */
    public function test_un_alambre_rectangular_no_es_diametro_y_largo(): void
    {
        $this->assertSame([], $this->medidor->leer('ALAMBRE RECTANGULAR 0.52 X 0.72MM, X KG', 'ALAMBRE'));
    }

    /** Un largo con tres decimales es un punto de miles: no se carga como un tubo de 1,9 mm. */
    public function test_un_largo_con_punto_de_miles_no_se_carga(): void
    {
        $this->assertSame(
            ['outer' => 101.6, 'wall' => 5.74],
            $this->medidor->leer('1 TIT GR2 TUBO 101.6 X 5.74 X 1.906 MM C/U (+IVA)', 'TUBO'),
        );
    }

    /** Un hueco no puede tener la pared mas gruesa que su radio. */
    public function test_una_pared_imposible_no_se_carga(): void
    {
        $this->assertSame([], $this->medidor->leer('1.5 M AISI 321H TUBO 6.3 X 5.54 MM P/M (+IVA)', 'TUBO'));
        $this->assertSame([], $this->medidor->leer('1 C/U TIT GR2 TUBO S/C 9.53 X 265 X 2000MM C/U', 'TUBO'));
    }

    /** Una placa tubo (tubo-placa) es un disco: Ø1250 X 110 no es un tubo de pared 110. */
    public function test_una_placa_tubo_no_es_un_tubo(): void
    {
        $this->assertSame([], $this->medidor->leer('COBRE-NIQUEL 70/30: 2 PLACA TUBO Ø1250 X 110 MM C/U(+IVA)', 'TUBO'));
        $this->assertSame(
            ['outer' => 19.05, 'wall' => 1.65],
            $this->medidor->leer('1 TUBO PARA PLACA 19.05 X 1.65 MM P/M', 'TUBO'),
        );
    }

    /** Un numero suelto con su "MM" antes de la serie: no se sabe de cual de los dos habla. */
    public function test_dos_medidas_distintas_en_el_texto_no_se_adivinan(): void
    {
        $this->assertSame([], $this->medidor->leer('NIQUEL, ALAMBRE 1.5 MM (EN LUGAR DE Ø 2 MM) 2-3 KGS P/KG', 'ALAMBRE'));
    }
}

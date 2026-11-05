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

    /**
     * La barra redonda se escribe "BARRA", "BAR RED"...: casi nunca "BARRA REDONDA".
     *
     * Con solo buscar el nombre completo, las 2.457 barras de Monel 400 de la VM quedaban sin diámetro y «cuánto cuesta una barra de
     * 20 mm» decía que no había registros.
     */
    public function test_la_barra_redonda_se_reconoce_como_se_escribe(): void
    {
        $casos = [
            '1 UN MONEL 400 BARRA 76.2 X 500 MM, C/U (+IVA)' => ['diameter' => 76.2, 'length' => 500.0],
            '1 C/U MONEL 400 BARRA RED 165.1 X 185MM C/U(+IVA)' => ['diameter' => 165.1, 'length' => 185.0],
            '2 C/U MONEL 400 BAR RED Ø 25 X 4350MM 38 KG. P/KG(+IVA)' => ['diameter' => 25.0, 'length' => 4350.0],
            'MONEL 400 BAR.RED. Ø 35 X 550 MM C/U(+IVA)' => ['diameter' => 35.0, 'length' => 550.0],
            '1 BARRA Ø 31,75 X 45 MM C/U' => ['diameter' => 31.75, 'length' => 45.0],
            'TITANIO GRADO 2 BARRAS Ø63,5 X 107MM' => ['diameter' => 63.5, 'length' => 107.0],
            '1 UN MONEL 400 BARRA REDONDA Ø 20 X 1000 MM' => ['diameter' => 20.0, 'length' => 1000.0],
            '1 TIT GR2 BARRA D.25,40 X 100 MM. C/U(+IVA).' => ['diameter' => 25.4, 'length' => 100.0],
            // El largo en metros no son milímetros: solo el diámetro.
            '4.390 KG TIT GR5 BARRA Ø 5.0 MM L: 50.795 M, P/KG (+IVA)' => ['diameter' => 5.0],
        ];

        foreach ($casos as $texto => $esperado) {
            $this->assertSame($esperado, $this->medidor->leer($texto, 'BARRA REDONDA'), "mal leído: {$texto}");
        }
    }

    /** "BARRA" a secas también es la cuadrada, la hexagonal, la perforada: esas no tienen un diámetro. */
    public function test_una_barra_que_no_es_redonda_no_se_lee(): void
    {
        foreach ([
            'BARRA CUADRADA 25 X 1000 MM',
            '1 UN MONEL 400 BARRA HEXAGONAL 19.05 X 25 MM',
            'BARRA RECTANGULAR 25 X 3 X 1000 MM',
            'BARRA PERF ØE 127 X ØI 70 X 300MM, 1 C/U',
            // Tres números es una sección rectangular, no un diámetro y un largo.
            '1 UN MONEL 400 BARRA 10 X 25 X 1000 MM',
            // «40 X 40» es la sección de una barra cuadrada, no un diámetro de 40 y un largo de 40.
            '2 UN HASTELLOY C-276 BARRA 40 X 40 MM, C/U',
        ] as $texto) {
            $this->assertSame([], $this->medidor->leer($texto, 'BARRA REDONDA'), "se leyó algo en: {$texto}");
        }
    }

    /**
     * Lo pedido y lo ofrecido en la misma línea: la línea cotiza lo ofrecido y el primer par de números es lo pedido.
     */
    public function test_lo_pedido_y_lo_ofrecido_en_la_misma_linea_no_se_adivina(): void
    {
        $this->assertSame([], $this->medidor->leer('PIDEN AISI 630 (17-4PH) BARRA 55.56 X 1800 MM COND H1025 SE OFRECE AISI 630 BARRA 63.5 X 1767 MM', 'BARRA REDONDA'));
        $this->assertSame([], $this->medidor->leer('PIDE INCONEL X-750 ALAMBRE 14.3 X 2200 MM, SE OFRECE INCONEL X-750 ALAMBRE 15.88 X 2200 MM, P/M', 'ALAMBRE'));
        $this->assertSame([], $this->medidor->leer('MONEL 400 DIA 133.3X 136MM(2UN) OFRECEMOS: 1UN MONEL 400 BARRA 139.7 X 504MM + BARRA 76 X 200MM', 'BARRA REDONDA'));
        // Con una sola medida, aunque diga "ofrece", es la de la línea.
        $this->assertSame(['diameter' => 127.0, 'length' => 160.0], $this->medidor->leer('OFRECE: 316L BARRA Ø127 X 160MM, X KG (+IVA)', 'BARRA REDONDA'));
    }

    /** Un diámetro marcado sin su "MM" y después otra serie: no se sabe cuál es del artículo. */
    public function test_un_diametro_suelto_antes_de_la_serie_no_se_adivina(): void
    {
        $this->assertSame([], $this->medidor->leer('CONSULTA COBRE/TUNGSTENO BARRA Ø 13 COBRE/TUNGSTENO Ø 12.7 X 205MM X 5 U (1025 MM)', 'BARRA REDONDA'));
    }

    /** Una cesta, un canasto o una serpentina nombran una barra que es solo una pieza de otra cosa. */
    public function test_un_armado_no_toma_la_medida_de_una_de_sus_piezas(): void
    {
        $this->assertSame([], $this->medidor->leer('TITANIO,CESTA CONFORMADA DE 470/235/285 X 180 X 60 MM CON GANCHOS PARA CALZAR BARRA 19X45 MM', 'BARRA REDONDA'));
        $this->assertSame([], $this->medidor->leer('1 CONJUNTO DE TUBO Ø 25.4 X 1.65 X 1000 MM CON BRIDAS', 'TUBO'));
    }

    /** Un texto que dice "BARRAS" en medio de una frase no es la medida de nada. */
    public function test_la_palabra_barra_en_una_frase_no_es_una_medida(): void
    {
        $this->assertSame([], $this->medidor->leer('MONEL K500 - CONT: (LAS BARRAS MACIZAS SE PODRIAN ENTREGAR EN DOS TRAMOS DE 170 A 300 MM)', 'BARRA REDONDA'));
    }

    /**
     * Lo ofrecido puede venir con un solo diámetro, sin "X": igual no se adivina cuál es el de la línea.
     *
     * Hallado en el informe en seco de la VM: «BARRA Ø152X500MM: OFRECEMOS Ø165MM» cotiza el Ø165 y se cargaba el Ø152.
     */
    public function test_un_pedido_con_un_diametro_ofrecido_distinto_no_se_adivina(): void
    {
        $this->assertSame([], $this->medidor->leer('BARRA Ø152X500MM:  OFRECEMOS Ø165MM, X KG', 'BARRA REDONDA'));
        $this->assertSame([], $this->medidor->leer('PC 38897 - INCONEL 602CA, 15M BARRA Ø5MM: OFRECEMOS APROX. 15M Ø9.53 X APROX. 1.83M, X M (+IVA)', 'BARRA REDONDA'));
        $this->assertSame([], $this->medidor->leer('BUSCAN ACERO 15-5PH AMS 5659 BARRA Ø20MM PARA FABRICAR BUJES, OFRECIDO 17-4PH HH1150 Ø22MM', 'BARRA REDONDA'));
    }

    /**
     * Una línea con dos piezas no tiene una medida: «BARRA 95.25 X 150 MM + 95.25 X 300 MM» es un lote.
     */
    public function test_dos_piezas_en_la_misma_linea_no_se_cargan(): void
    {
        $this->assertSame([], $this->medidor->leer('50% COMPRA 17-4PH BARRA 95.25 X 150 MM + 95.25 X 300 MM 0.500 ANTICIPO, P/LOTE (+IVA)', 'BARRA REDONDA'));
        $this->assertSame([], $this->medidor->leer('COTIZADO ALAMBRE DE PLATINO 0.5 X 160 MM + 0.3 X 200 MM P/LOTE', 'ALAMBRE'));
        $this->assertSame([], $this->medidor->leer('1 BARRA Ø 31,75 X 45 MM C/U 53,-- 5 BARRAS Ø63,5 X 107MM', 'BARRA REDONDA'));
        $this->assertSame([], $this->medidor->leer('1 UN MONEL 400 BARRA 50.8 X 200 MM Y 2 UN MONEL 400 BARRA 25.4 X 100 MM', 'BARRA REDONDA'));

        // Una sola pieza, aunque la linea tenga mas numeros, se sigue leyendo.
        $this->assertSame(['diameter' => 25.4, 'length' => 100.0], $this->medidor->leer('1 UN MONEL 400 BARRA 25.4 X 100 MM, 2 C/U, PESO 2.5 KG', 'BARRA REDONDA'));
        $this->assertSame(['diameter' => 305.0, 'length' => 500.0], $this->medidor->leer('NITRONIC 60: BARRA Ø305 X500MM (Ø270X500MM), 1 C/U (+IVA)', 'BARRA REDONDA'));
    }

    /**
     * Hallado en el segundo informe en seco de la VM: «BARRA Ø31.75MM + 2500 KG Ø45MM» son dos diámetros, y
     * «BARRA PERF OD 108 X ID 61.98 X 2100» es una barra hueca cuya medida «61.98» es el interior.
     */
    public function test_dos_diametros_con_su_cantidad_y_las_barras_huecas_no_se_cargan(): void
    {
        $this->assertSame([], $this->medidor->leer('ASI 630 (17-4PH) H1150: 640 KG BARRA Ø31.75MM + 2500 KG Ø45MM, X KG (+IVA) ENTREGA 180 DIAS APROX.', 'BARRA REDONDA'));
        $this->assertSame([], $this->medidor->leer('SUPER CROMO 13 - 110 KSI 2 CU 13CRS BARRA PERF OD 108 X ID 61.98 X 2100 MM   C/U', 'BARRA REDONDA'));
        $this->assertSame([], $this->medidor->leer('AISI 4140 BARRA PERFORADA 108 X 62 X 2100 MM', 'BARRA REDONDA'));

        // Un solo diametro con su "+IVA" o con "KG" de peso se sigue leyendo.
        $this->assertSame(['diameter' => 31.75], $this->medidor->leer('AISI 630 BARRA Ø31.75MM, 640 KG, X KG (+IVA)', 'BARRA REDONDA'));
    }

    /**
     * Hallado en el informe completo de la VM (21.579 líneas, filtrado por patrones de riesgo): una barra hueca,
     * dos ítems en una línea o dos diámetros distintos no tienen una sola medida.
     */
    public function test_una_barra_hueca_o_con_dos_items_no_carga_medida(): void
    {
        $r = 'BARRA REDONDA';

        // Hueca o canulada: su medida es el exterior de un tubo.
        $this->assertSame([], $this->medidor->leer('COTIZADO: TITANIO GR 5, BARRA Ø4MM X 16 MM C/PERF 0.9MM 50 C/U (+IVA)', $r));
        $this->assertSame([], $this->medidor->leer('ALTERNATIVA #3 BARRA AISI 410 QDT Ø406MM CON PERFORADO ØI 250 +/-1MM X 500MM DE LARGO, 1 C/U', $r));
        $this->assertSame([], $this->medidor->leer('2.865 ACE INOX F138 BARRA CANUL. ØE 13MM OD3.4MM P/M (+IVA)', $r));

        // Dos ítems, con su precio en el medio o dos diámetros distintos.
        $this->assertSame([], $this->medidor->leer('ACME 764-2306: BARRA Ø10 X 300MM $470  Ø12X300 MM C/U', $r));
        $this->assertSame([], $this->medidor->leer('BARRA Ø 76,2 X 100 MM $ 541 C/U Y Ø76,2 X 150 MM C/U (+IVA)', $r));
        $this->assertSame([], $this->medidor->leer('ACME PREGUNTA POR 2 MTS BARRA Ø 19,1 MM (5.7.94). SON 10 C/U Ø19,05 ó 15,88MM X 2600M', $r));
        $this->assertSame([], $this->medidor->leer('INTERESADOS EN PEEK BARRA Ø8MM Y MENOR INDICADO PRECIO ESTIMADO Ø12MM, XM (+IVA)', $r));
        $this->assertSame([], $this->medidor->leer('G41830+G41807 - MONEL K500 BARRA Ø9.53 X 800MM, 1 C/U - NO 12.7 X 800MM, 1 C/U', $r));

        // Una opción, o un precio de otra medida.
        $this->assertSame([], $this->medidor->leer('6. 1 C/U BARRA Ø50.8MM X 3000MM (O 57.15MM), X KG', $r));
        $this->assertSame([], $this->medidor->leer('BARRA Ø25.4 X 2500MM, 1 C/U     (COTIZADO POR 2000MM)!!!!', $r));

        // Lo ofrecido va despues: la serie es lo pedido.
        $this->assertSame([], $this->medidor->leer('BARRA Ø38.1MM, OFRECEMOS 40MM, 1M, X M (+IVA)', $r));
    }

    /**
     * Lo que se mantiene: el tamaño final, lo pedido y las notas que no son otra pieza.
     *
     * «(PARA 19.05)» es el diámetro al que se mecaniza: lo que se cotiza es la barra de 19.18. «(PIDE 22MM)» y
     * «EN LUGAR DE 2.5MM» nombran lo pedido y la serie es lo que se ofrece.
     */
    public function test_el_tamano_final_y_lo_pedido_no_anulan_la_medida(): void
    {
        $r = 'BARRA REDONDA';

        $this->assertSame(['diameter' => 19.18], $this->medidor->leer('ACERO 17-4PH, SOLUTION TREATED: 12 M BARRA Ø19.18MM (PARA 19.05), X KG (+IVA)', $r));
        $this->assertSame(['diameter' => 70.0, 'length' => 420.0], $this->medidor->leer('RECOTIZADO BARRA Ø 70 X 420 MM (PARA 66) 1 C/U(+IVA)', $r));
        $this->assertSame(['diameter' => 25.0], $this->medidor->leer('INCONEL 602 BARRA Ø25MM (PIDE 22MM), 3M, X M (+IVA)', $r));
        $this->assertSame(['diameter' => 2.36], $this->medidor->leer('5-10M   BARRA Ø2.36MM (EN LUGAR DE 2.5MM), X M', $r));
        $this->assertSame(['diameter' => 360.0], $this->medidor->leer('ALEACION C-276 BARRA Ø360MM (PEDIAN 350) X 1000MM, 1 C/U', $r));
        $this->assertSame(['diameter' => 15.88, 'length' => 100.0], $this->medidor->leer('(SE OFRECEN RECORTES 203 / 218 MM) 1 UN MONEL 400 BARRA 15.88 X 100 MM (RECORTE,SE COBRA 115MM POR CORTE) C/U', $r));
        $this->assertSame(['diameter' => 76.2], $this->medidor->leer('OFRECEMOS: MONEL K-500, BARRA Ø76.2MM X 176MM, X KG', $r));
        // El "idem" del sistema viejo no son pulgadas.
        $this->assertSame(['diameter' => 22.2, 'length' => 60.0], $this->medidor->leer('1 CU HASTELLOY C-276 BARRA 22.2 X 60MM " " "', $r));
        // Entre parentesis es el tamaño terminado de la misma pieza.
        $this->assertSame(['diameter' => 305.0, 'length' => 500.0], $this->medidor->leer('NITRONIC 60: BARRA Ø305 X500MM (Ø270X500MM), 1 C/U (+IVA)', $r));
    }

    /** Un largo en metros mal escrito, o de más de 12 metros, no se carga; el diámetro sí. Las pulgadas tampoco. */
    public function test_un_largo_en_metros_o_de_mas_de_doce_metros_no_se_carga(): void
    {
        $r = 'BARRA REDONDA';

        $this->assertSame(['diameter' => 2.36], $this->medidor->leer('4 TIT GR2 BARRA 2.36 X 9.15 MM C/U (+IVA)', $r));
        $this->assertSame(['diameter' => 1.6], $this->medidor->leer('1 NIQUEL 61 BARRA 1.6 X 1.95 MM C/U', $r));
        $this->assertSame(['diameter' => 6.35], $this->medidor->leer('25-3-4 6 TIT GR2 BARRA 6.35 X 41055 MM C/U (+IVA)', $r));
        $this->assertSame(['diameter' => 114.3], $this->medidor->leer('BARRA Ø114.3 X 60000MM, X KG', $r));
        $this->assertSame([], $this->medidor->leer('INCONEL 625 BARRA Ø5.25 X 10", 1 C/U (+IVA)', $r));

        // Un largo corto entero es una pieza corta de verdad.
        $this->assertSame(['diameter' => 6.35, 'length' => 15.0], $this->medidor->leer('1 TIT GR5 BARRA 6.35 X 15 MM C/U (+IVA)', $r));
        // Y un fleje escrito como alambre sigue sin cargarse: el largo no se descarta antes de comprobarlo.
        $this->assertSame([], $this->medidor->leer('TUNGSTENO, ALAMBRE 3 X 0,76 MM P/KG (+IVA)', 'ALAMBRE'));
        $this->assertSame([], $this->medidor->leer('2 TIT GR5 BARRA 63.5 X 16.2 MM C/U (+IVA)', $r));
        // Un tubo de 4.5 a 7 metros: el largo en metros no es un largo en milimetros.
        $this->assertSame(['outer' => 19.05, 'wall' => 1.65], $this->medidor->leer('DUPLEX S31803 TUBO Ø 19.05 X 1.65 X 4.5 A 7 MTS P/M(+IVA)', 'TUBO'));
    }
}

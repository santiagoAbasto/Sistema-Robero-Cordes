<?php

namespace Tests\Feature;

use App\Services\Migracion\EnlazadorDeLineas;
use PHPUnit\Framework\TestCase;

/**
 * Reconocer el material dentro del texto de una linea historica.
 *
 * Los casos son todos textos reales del sistema anterior. El criterio que
 * fijan: ante la duda NO se enlaza. Una linea sin material se completa
 * despues; una enlazada al material equivocado ensucia el historial de precios
 * sobre el que se cotiza y nadie la revisa.
 */
class EnlazadorDeLineasTest extends TestCase
{
    /**
     * Un catalogo de prueba con la forma del real.
     *
     * Tiene que parecerse en algo mas que en los nombres: el peso de cada
     * palabra sale de en cuantas entradas aparece, asi que si "inoxidable"
     * figura una sola vez pesa como una designacion y gana donde no deberia.
     * En el catalogo real hay decenas de inoxidables y el peso queda bajo. Por
     * eso van varios, y van los alias cortos: el texto de una cotizacion dice
     * "AISI 316", nunca "Acero AISI 316".
     */
    private function materiales(): EnlazadorDeLineas
    {
        return new EnlazadorDeLineas([
            [1, 'Acero Inoxidable F138'],
            [1, 'ACE F138'],
            [2, 'Acero Inoxidable'],
            [2, 'ACE INOX'],
            [3, 'Titanio Grado 2'],
            [3, 'TIT GR2'],
            [4, 'Titanio'],
            [5, 'Acero AISI 316'],
            [5, 'AISI 316'],
            [5, 'INOX 316'],
            [6, 'Acero AISI 316L'],
            [6, 'AISI 316L'],
            [7, 'Hastelloy C-276'],
            [8, 'Inconel X-750'],
            // Basura del catalogo: no identifica nada por si sola.
            [9, '50'],
            [10, 'SC'],
            // Los otros inoxidables del catalogo. Estan para que "acero" e
            // "inoxidable" pesen lo que pesan de verdad: casi nada.
            [30, 'Acero Inoxidable 303'],
            [30, 'INOX 303'],
            [31, 'Acero Inoxidable 310'],
            [31, 'INOX 310'],
            [32, 'Acero Inoxidable 321'],
            [32, 'INOX 321'],
            [33, 'Acero Inoxidable 347'],
            [33, 'INOX 347'],
            [34, 'Acero Inoxidable 410'],
            [35, 'Acero Inoxidable 430'],
            [36, 'Acero Inoxidable Duplex'],
            [37, 'Acero Carbono'],
            [38, 'Acero Aleado'],
        ]);
    }

    private function elige(string $texto): ?int
    {
        return $this->materiales()->elegir($texto);
    }

    /**
     * Gana el que cubre la designacion, no el generico que la contiene.
     *
     * Buscando por substring, "ACE INOX" aparecia dentro de "ACE INOX F138" y
     * se llevaba el enlace al inoxidable generico: se perdia justo el F138,
     * que es lo unico que distingue a ese material.
     */
    public function test_el_especifico_le_gana_al_generico(): void
    {
        $this->assertSame(1, $this->elige('6.038 M ACE INOX F138 BARRA 3.00 MM P/M (+IVA)'));
    }

    /** Sin designacion, el generico esta bien. */
    public function test_sin_designacion_vale_el_generico(): void
    {
        $this->assertSame(2, $this->elige('ACE INOX CHAPA 2 X 1000 X 2000'));
    }

    /**
     * Si el texto nombra algo preciso que el candidato no cubre, no se enlaza.
     *
     * "TITANIO F136" no es "Titanio": F136 es un grado. Que no este en el
     * catalogo no lo vuelve menos especifico.
     */
    public function test_una_designacion_que_no_cubre_frena_el_enlace(): void
    {
        $this->assertNull($this->elige('TITANIO F136 BARRA Ø1MM, 30.5M, X M EUROS'));
    }

    /** "AISI 316 L" es 316L, que no es 316. */
    public function test_el_sufijo_del_grado_distingue(): void
    {
        $this->assertSame(6, $this->elige('AISI 316 L  Ø10 RED.'));
        $this->assertSame(5, $this->elige('AISI 316 CHAPA 1 X 1000 X 2000 P/KG'));
    }

    /** El mismo grado escrito de tres maneras es el mismo grado. */
    public function test_gr2_y_grado_2_y_gr_2_son_lo_mismo(): void
    {
        foreach (['TIT GR2 BARRA 4.76 MM', 'TITANIO GRADO 2 BARRA 4.76 MM', 'TITANIO GR 2 BARRA 4.76'] as $t) {
            $this->assertSame(3, $this->elige($t), "fallo con: {$t}");
        }
    }

    /**
     * La X de las medidas no es el sufijo de un grado.
     *
     * "1.60 X 915" daba el token "160x", que parece una designacion y hacia
     * que la guarda descartara la linea entera. Se perdia la mitad de los
     * enlaces buenos por una equis.
     */
    public function test_la_equis_de_las_medidas_no_rompe_el_enlace(): void
    {
        $this->assertSame(3, $this->elige('10 UN TIT GR2 BARRA 1.60 X 915 MM, C/U (+IVA)'));
    }

    /**
     * El simbolo de diametro tampoco.
     *
     * Convertirlo en "o" lo pegaba al numero: "Ø38.1" quedaba en "o381", que
     * parece un grado. En el Access el Ø quedo guardado como Ý.
     */
    public function test_el_simbolo_de_diametro_no_rompe_el_enlace(): void
    {
        $this->assertSame(3, $this->elige('TITANIO GR 2 Ø38.1 X 2000MM, 1 C/U (+IVA)'));
        $this->assertSame(3, $this->elige('50 C/U TIT GR2 BAR Ý 1.6 X 915MM  C/U(+IVA)'));
    }

    /** El guion del medio no separa: C-276 y C276 son el mismo. */
    public function test_el_guion_de_la_designacion_no_importa(): void
    {
        $this->assertSame(7, $this->elige('HASTELLOY C276 CHAPA 3.18 X 50.8 MM'));
        $this->assertSame(8, $this->elige('35 INCONEL X750 ALA Ø 1.6 APR. 0.600 KG'));
    }

    /**
     * Las entradas basura del catalogo no enganchan ruido.
     *
     * Un material llamado "50" se llevaba el "RECTANGULO 50 X 3000" de
     * cualquier descripcion, y uno llamado "SC" el "S/C" de los caños sin
     * costura. Siguen en el catalogo y se eligen a mano; lo que no se puede es
     * adivinarlas desde el texto.
     */
    public function test_las_entradas_basura_no_enganchan(): void
    {
        $this->assertNull($this->elige('COTIZA 20552299 AISI 310  2 U RECTANGULO 50 X 3000 C/U(+IVA)'));
        $this->assertNull($this->elige('1 CU CAñO S/C 1" SCH 80 X 1 M'));
    }

    /** Nada reconocible es null, no una adivinanza. */
    public function test_sin_material_reconocible_no_inventa(): void
    {
        $this->assertNull($this->elige('Ø130 X 1700MM, 1 C/U'));
        $this->assertNull($this->elige('20 C/U 4.76 X 25.4 C/U(+IVA)'));
    }

    /** Dos materiales distintos igual de buenos: no se elige ninguno. */
    public function test_el_empate_no_se_resuelve_a_la_suerte(): void
    {
        $enlazador = new EnlazadorDeLineas([
            [11, 'Bronce Fosforoso'],
            [12, 'Bronce Fosforoso'],
        ]);

        $this->assertNull($enlazador->elegir('BRONCE FOSFOROSO ALAMBRE 1.5 MM'));
    }

    /** "Monel 400" es un material y a la vez un alias de "Monel": la línea es del que lleva ese nombre. */
    public function test_el_nombre_propio_le_gana_al_alias_de_otro_material(): void
    {
        // Con el relleno del catálogo de prueba: sin él los pesos son casi cero y nada llega al mínimo.
        $enlazador = new EnlazadorDeLineas([
            [1, 'Acero Inoxidable F138'], [2, 'Acero Inoxidable'], [4, 'Titanio'], [5, 'Acero AISI 316'],
            [7, 'Hastelloy C-276'], [8, 'Inconel X-750'], [30, 'Acero Inoxidable 303'], [31, 'Acero Inoxidable 310'],
            [3, 'Monel'],
            [39, 'Monel 400'],
            [3, 'MONEL 400', true],
        ]);

        $this->assertSame(39, $enlazador->elegir('MONEL 400 BAR RED Ø 15.88 X 4900 C/U(+IVA)'));
        $this->assertSame(3, $enlazador->elegir('MONEL BARRA 10 MM'));
    }

    /** "TUBO EN U" se reduce a "tubo" como "TUBO": la línea que no dice "en" es del tubo común. */
    public function test_el_que_pide_palabras_que_la_linea_no_dice_no_empata(): void
    {
        $formas = new EnlazadorDeLineas([
            [5, 'TUBO'], [249, 'TUBO EN U'], [206, 'TUBO ALETADO'], [225, 'TUBO ESPIRALADO'], [226, 'TUBO ROLLO'],
            [20, 'BARRA REDONDA'], [21, 'CHAPA'], [22, 'ALAMBRE'], [23, 'PERFIL'], [24, 'BRIDA'], [25, 'DISCO'], [26, 'ANILLO'],
            [27, 'ANGULO'], [28, 'PLANCHUELA'], [29, 'CODO'], [30, 'CUPLA'], [31, 'FLEJE'], [32, 'MALLA'], [33, 'RESORTE'],
            [34, 'ARANDELA'], [35, 'JUNTA'], [36, 'BUJE'], [37, 'NIPLE'], [38, 'VALVULA'], [39, 'TAPON'], [40, 'REMACHE'],
        ], material: false, exigencia: 0.42);

        $this->assertSame(5, $formas->elegir('1 C/U TUBO 26.67 X 2.87 X 100 MM, (+IVA)'));
        // Si la línea dice todo, los dos entran completos y sigue el empate: no se adivina.
        $this->assertNull($formas->elegir('1 TUBO EN U 3/4'));
    }

    /** Dos alias iguales de materiales distintos siguen siendo un empate: no se adivina. */
    public function test_dos_alias_iguales_de_materiales_distintos_siguen_empatados(): void
    {
        $enlazador = new EnlazadorDeLineas([
            [11, 'Bronce Fosforoso', true],
            [12, 'Bronce Fosforoso', true],
        ]);

        $this->assertNull($enlazador->elegir('BRONCE FOSFOROSO ALAMBRE 1.5 MM'));
    }

    /** Buscando forma, el nombre de la forma NO es ruido. */
    public function test_la_forma_se_busca_con_su_propio_vocabulario(): void
    {
        $formas = new EnlazadorDeLineas([
            [20, 'BARRA REDONDA'],
            [21, 'CHAPA'],
            [22, 'ALAMBRE'],
        ], material: false, exigencia: 0.42);

        $this->assertSame(21, $formas->elegir('AISI 316 CHAPA 1 X 1000 X 2000 P/KG'));
        $this->assertSame(22, $formas->elegir('INCONEL X-750 ALAMBRE 3.96 MM'));
    }

    /**
     * Un catalogo con familias, para probar lo que pasa cuando una linea nombra dos materiales.
     *
     * Tiene que tener varias aleaciones de aluminio y varios hastelloy: es lo que hace que
     * "aluminio" y "hastelloy" pesen poco, como en el catalogo real.
     */
    private function catalogoConFamilias(): EnlazadorDeLineas
    {
        $entradas = [
            [1, 'Aluminio'], [2, 'Aluminio 6061'], [3, 'Aluminio 1100'], [4, 'Aluminio 7075'],
            [5, 'Berilio'],
            [6, 'Hastelloy C-276'], [7, 'Hastelloy X'], [8, 'Hastelloy W'], [9, 'Hastelloy B-2'],
            [10, 'Acero Corten A'], [11, 'Acero Corten B'],
            [12, 'Monel 400'], [13, 'Platino'], [14, 'Cobalto'],
            [15, 'Carburo de Tungsteno'],
        ];

        // Relleno: entradas sueltas para que el catalogo tenga el tamaño en que los pesos se parecen a los reales.
        foreach (['Plomo', 'Zinc', 'Estaño', 'Litio', 'Telurio', 'Bismuto', 'Cadmio', 'Galio', 'Indio', 'Niobio', 'Tantalio', 'Circonio'] as $i => $nombre) {
            $entradas[] = [100 + $i, $nombre];
        }

        return new EnlazadorDeLineas($entradas);
    }

    /**
     * Una aleacion de aluminio con berilio no es berilio.
     *
     * Ganaba el berilio por ser la palabra mas rara y la linea quedaba en el historial de precios del
     * berilio. Si hay otro material nombrado con firmeza, es una duda y no se enlaza.
     */
    public function test_dos_materiales_nombrados_con_firmeza_no_se_adivinan(): void
    {
        $e = $this->catalogoConFamilias();

        $this->assertNull($e->elegir('QUIEREN HACER 300 KG DE ALEACION DE ALUMINIO CON 0.04-0.07% DE BERILIO'));
        $this->assertNull($e->elegir('MUESTRA RECIBIDA 1 M ALAMBRE PLATINO 0.3 MM ENTREGADA 1 MONEL 400 CHAPA 0.101'));
        // Uno solo, sigue enlazando.
        $this->assertSame(5, $e->elegir('BERILIO LINGOTE 2 KG P/KG'));
        $this->assertSame(12, $e->elegir('MONEL 400 CHAPA 0.46 X 1220 X 1560 MM'));
    }

    /** Un material mas general dentro del especifico no es "otro material". */
    public function test_el_general_dentro_del_especifico_no_es_otro_material(): void
    {
        $this->assertSame(3, $this->elige('10 UN TIT GR2 BARRA 1.60 X 915 MM, C/U (+IVA)'));
        $this->assertSame(1, $this->elige('6.038 M ACE INOX F138 BARRA 3.00 MM P/M (+IVA)'));
    }

    /**
     * Entre dos grados que solo se distinguen por una letra, esa letra cuenta si esta dicha.
     *
     * "Hastelloy X" y "Hastelloy W" se reducen al mismo token. "HASTELLOY B Ø 31.75 X 70 MM" traia una "x"
     * —la de las medidas— que le daba la victoria a la X, y era un Hastelloy B.
     */
    public function test_la_x_de_las_medidas_no_es_el_grado_x(): void
    {
        $e = $this->catalogoConFamilias();

        $this->assertNull($e->elegir('HASTELLOY B Ø 31.75 X 70 MM 1 C/U(+IVA)'));
        $this->assertNull($e->elegir('HASTELLOY Ø 50 X 200 MM P/KG(+IVA)'));
        $this->assertNull($e->elegir('COTIZADO HASTELLOY 50.8 X 497 MM C/U'));
        // La X que viene despues de una unidad tambien separa medidas.
        $this->assertNull($e->elegir('HASTELLOY C=276 Ø 25,4 MM X 2 MTS. EL METRO (+IVA)'));
        $this->assertNull($e->elegir('HASTELLOY 2-276: TUBO 12,7 X 0,89 MM X 400 MM EL METRO'));
        // Si el grado esta dicho, se enlaza.
        $this->assertSame(7, $e->elegir('HASTELLOY X CHAPA 4.76 X 600 X 740MM, 1 C/U'));
        $this->assertSame(8, $e->elegir('HASTELLOY W VARILLA 1.6 X 915 MM C/U'));
    }

    /** La letra que distingue de verdad sigue valiendo: Corten A y Corten B. */
    public function test_una_letra_dicha_distingue_el_grado(): void
    {
        $e = $this->catalogoConFamilias();

        $this->assertSame(11, $e->elegir('1920 KG ACERO CORTEN B CHAPA 8 MM EUROS P/KG(+IVA)'));
        $this->assertSame(10, $e->elegir('1920 KG ACERO CORTEN A CHAPA 8 MM EUROS P/KG(+IVA)'));
    }
}

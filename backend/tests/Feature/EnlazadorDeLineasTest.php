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

    /**
     * Un catalogo con "Cobre" y "Plata" dentro de nombres de otros materiales, y con las palabras de las
     * formas como ruido ("ALAMBRE CON ALMA DE COBRE", "FUNDENTE SOLD. PLATA"), como en el catalogo real.
     */
    private function catalogoConCobreYPlata(): EnlazadorDeLineas
    {
        return new EnlazadorDeLineas([
            [1, 'Tungsteno'],
            [2, 'Tungsteno Plata 70/30'],
            [3, 'Cobre Tungsteno 70W/30Cu'],
            [4, 'Cobre Cromo'],
            [5, 'Cobre'],
            [6, 'Plata'],
            [7, 'Soldadura de Plata'],
            [8, 'Cobre Zirconio 10%'],
            [9, 'Zirconio 702'],
            [10, 'Cobalto'],
            [11, 'Cobre Plata 20S'],
            // El resto del catalogo, para que las palabras pesen lo que pesan.
            [20, 'Titanio Grado 2'], [21, 'Titanio Grado 5'], [22, 'Niquel 200'], [23, 'Niquel 201'],
            [24, 'Molibdeno'], [25, 'Tantalio'], [26, 'Niobio'], [27, 'Hafnio'], [28, 'Renio'],
            [29, 'Acero Inoxidable 316'], [30, 'Acero Inoxidable 304'], [31, 'Bronce'], [32, 'Laton'],
            [33, 'Zinc'], [34, 'Plomo'], [35, 'Estano'],
        ], ruidoExtra: ['cobre', 'plata', 'alambre', 'alma', 'fundente', 'sold'], formasEnteras: ['crisol']);
    }

    /**
     * "COBRE TUNGSTENO 70/30" no es "Tungsteno Plata 70/30": plata no cuenta y cobre si.
     *
     * Hay formas que se llaman "ALAMBRE CON ALMA DE COBRE" y "FUNDENTE SOLD. PLATA" y su vocabulario entero es
     * ruido al buscar material: ni "cobre" ni "plata" contaban, y la linea caia en el material que quedaba.
     */
    public function test_cobre_y_plata_dentro_del_nombre_se_exigen(): void
    {
        $e = $this->catalogoConCobreYPlata();

        // Antes: el tungsteno, o la plata, sin mirar el cobre.
        $this->assertSame(1, $e->elegir('1 UN COBRE TUNGSTENO 20/80 BARRA 6.5 X 500 MM C/U', false));
        $this->assertSame(2, $e->elegir('1 UN COBRE TUNGSTENO 70/30 BARRA 30 X 65 MM C/U', false));
        $this->assertSame(4, $e->elegir('CROMO PIEDRAS P/KG(+IVA)', false));

        // Ahora: otro material, que el catalogo trae con su composicion: no se adivina.
        $this->assertNull($e->elegir('1 UN COBRE TUNGSTENO 20/80 BARRA 6.5 X 500 MM C/U'));
        $this->assertNull($e->elegir('1 UN COBRE TUNGSTENO 70/30 BARRA 30 X 65 MM C/U'));
        $this->assertNull($e->elegir('CROMO PIEDRAS P/KG(+IVA)'));

        // Con su nombre completo, se enlaza.
        $this->assertSame(2, $e->elegir('1 UN TUNGSTENO PLATA 70/30 BARRA 30 X 65 MM C/U'));
        $this->assertSame(3, $e->elegir('COBRE TUNGSTENO 70W/30CU BARRA 22.22 X 300 MM C/U'));
        $this->assertSame(1, $e->elegir('TUNGSTENO 99.95 BARRA 10 X 150 MM C/U'));
    }

    /**
     * Una designacion fuerte ya dice que es cobre; un schedule de caño no.
     *
     * "TUNGSTENO 70W/30CU" es el compuesto de cobre y tungsteno aunque no diga "cobre". En cambio "20S" es
     * el schedule de un caño: con el enlazador de antes, todo "SCH 20S" caia en "Cobre Plata 20S".
     */
    public function test_una_designacion_fuerte_alcanza_y_un_schedule_no(): void
    {
        $e = $this->catalogoConCobreYPlata();

        $this->assertSame(3, $e->elegir('TUNGSTENO 70W/30CU BARRA 22.22 X 300 MM C/U'));

        $this->assertSame(11, $e->elegir('CAÑO 2" SCH 20S X 6000 MM C/U', false));
        $this->assertNull($e->elegir('CAÑO 2" SCH 20S X 6000 MM C/U'));
        $this->assertNull($e->elegir('TUBO 1 1/2" SCH 20S C/U'));
        // Con su nombre completo, si.
        $this->assertSame(11, $e->elegir('COBRE PLATA 20S BARRA 10 X 100 MM'));
    }

    /** Un texto que nombra cobre o plata y un ganador que no los dice: son dos materiales. */
    public function test_el_texto_con_cobre_o_plata_no_va_a_un_material_que_no_los_dice(): void
    {
        $e = $this->catalogoConCobreYPlata();

        $this->assertNull($e->elegir('FILM DE POLIESTER 12 MICRONES Y PLATA $8/9 400 KG ZINC'));
        $this->assertNull($e->elegir('SOLDADURA AL 40% 3.7 KG P/KG'));
        // La plata dicha, con su soldadura: se enlaza.
        $this->assertSame(7, $e->elegir('SOLDADURA DE PLATA AL 40% 3.7 KG P/KG'));
    }

    /**
     * Lo que antes quedaba sin enlazar por ser dudoso no empieza a enlazarse por esta regla.
     *
     * "Cobre Zirconio 10%" pide cobre y la linea no lo dice, asi que no puede ganar: pero sigue siendo un rival
     * y la linea queda como estaba. "CROMO COBALTO" es una aleacion de dos metales, no cobalto.
     */
    public function test_un_candidato_que_pide_cobre_no_gana_pero_sigue_bloqueando(): void
    {
        $e = $this->catalogoConCobreYPlata();

        $this->assertNull($e->elegir('ALFA LE COTIZA CROMO COBALTO 8 X 3300 MM EUROS'));
        $this->assertNull($e->elegir('10 UN ZIRCONIO 702 BARRA 3.18 MM'));
        // Y el modo anterior seguia igual, que es lo que permite revisar lo ya escrito.
        $this->assertNull($e->elegir('10 UN ZIRCONIO 702 BARRA 3.18 MM', false));
    }

    /**
     * Una palabra que es una forma entera sigue siendo ruido, aunque tambien sea un material.
     *
     * "CRISOL" es una forma y tambien una entrada del catalogo de materiales: nombrar un crisol no es nombrar
     * un material. "Cobre" y "Plata", en cambio, solo estan dentro de nombres mas largos de formas.
     */
    public function test_una_forma_entera_sigue_siendo_ruido(): void
    {
        $entradas = [
            [1, 'Crisol'], [2, 'Crisol Grafito'], [3, 'Zafiro'], [4, 'Titanio'], [5, 'Niobio'], [6, 'Hafnio'], [7, 'Renio'],
        ];

        $conCrisolForma = new EnlazadorDeLineas($entradas, ruidoExtra: ['crisol'], formasEnteras: ['crisol']);
        $sinCrisolForma = new EnlazadorDeLineas($entradas, ruidoExtra: ['crisol']);

        // Crisol es una forma: no se le exige a "Crisol Grafito".
        $this->assertSame(2, $conCrisolForma->elegir('GRAFITO PARA 5 KG'));
        // Si no lo fuera, sera una palabra de material y se le exigiria.
        $this->assertNull($sinCrisolForma->elegir('GRAFITO PARA 5 KG'));
        $this->assertContains('crisol', $conCrisolForma->palabrasSolas());
    }

    /**
     * Los numeros de las formas ("CODO 90", "CURVA 45") tambien eran ruido: tiraban el 90 de "Nimonic 90" y el 45
     * de "Cu-Ni 45", y "CUNI 90/10" caia en "Cu-Ni 45".
     *
     * El vocabulario de las formas llega como claves de un array: un "90" es un entero, y asi se lo prueba.
     */
    public function test_el_grado_dentro_del_nombre_se_exige_aunque_una_forma_use_ese_numero(): void
    {
        $e = new EnlazadorDeLineas([
            [1, 'Nimonic 90'], [2, 'Nimonic 80A'], [3, 'Cu-Ni 45'], [4, 'CUNI 90 Cobre Niquel 90/10', true], [4, 'Cobre Niquel 90/10'],
            [10, 'Titanio Grado 2'], [11, 'Titanio Grado 5'], [12, 'Niquel 200'], [13, 'Niquel 201'], [14, 'Molibdeno'],
            [15, 'Tantalio'], [16, 'Niobio'], [17, 'Hafnio'], [18, 'Renio'], [19, 'Zinc'], [20, 'Plomo'], [21, 'Estano'],
        ], ruidoExtra: [90, 45, 'cobre'], formasEnteras: []);

        $this->assertSame(1, $e->elegir('NIMONIC 90 CHAPA 4.76 X 175 X 1200MM, 1 C/U (+IVA)'));
        $this->assertSame(1, $e->elegir('NIMONIC 90 CHAPA 4.76 X 175 X 1200MM, 1 C/U (+IVA)', false));

        // "CUNI 90/10" no es "Cu-Ni 45": el 45 no esta dicho.
        $this->assertSame(3, $e->elegir('CUNI 90/10 TUBO 25 X 2 MM C/U', false));
        $this->assertNotSame(3, $e->elegir('CUNI 90/10 TUBO 25 X 2 MM C/U'));
        $this->assertSame(3, $e->elegir('CU-NI 45 TUBO 25 X 2 MM C/U'));
    }

    /**
     * El catalogo deja marcados los materiales que no se usan ("Acero Duplex 2507 NO USAR"): el enlace nunca
     * los elige. Pero un duplicado marcado ya no empata con el bueno ni lo deja sin enlazar.
     */
    public function test_un_material_marcado_no_usar_no_se_elige(): void
    {
        $entradas = [
            [40, 'Acero Duplex 2507 NO USAR'], [41, 'Acero Duplex S32750 2507'],
            // Los dos tienen el mismo alias: sin el aviso del nombre, empatan.
            [42, 'Acero 16Mo3'], [42, '16Mo3', true], [43, 'Acero 16Mo3- Usar 15Mo3/1'], [43, '16Mo3', true],
            [10, 'Titanio Grado 2'], [11, 'Titanio Grado 5'], [12, 'Niquel 200'], [13, 'Niquel 201'], [14, 'Molibdeno'],
            [15, 'Tantalio'], [16, 'Niobio'], [17, 'Hafnio'], [18, 'Renio'], [19, 'Zinc'], [20, 'Plomo'], [21, 'Estano'],
        ];

        // "no" y "usar" son ruido en el catalogo real: hay formas con esas palabras.
        $e = new EnlazadorDeLineas($entradas, ruidoExtra: ['no', 'usar'], vetados: [40, 43]);

        // Antes caia en el marcado; ahora no se adivina otro.
        $this->assertSame(40, $e->elegir('ACERO SUPER DUPLEX 2507 BARRA Ø 101.6 X 600 MM', false));
        $this->assertNull($e->elegir('ACERO SUPER DUPLEX 2507 BARRA Ø 101.6 X 600 MM'));
        // Con su designacion, el bueno.
        $this->assertSame(41, $e->elegir('ACERO DUPLEX S32750 2507 BARRA Ø 101.6 X 600 MM'));
        // El duplicado marcado empataba con el bueno y no se enlazaba ninguno.
        $this->assertNull($e->elegir('16MO3 CHAPA 8 X 2000 X 6000 MM', false));
        $this->assertSame(42, $e->elegir('16MO3 CHAPA 8 X 2000 X 6000 MM'));
    }

    /**
     * "Cobre" o "plata" dichos de pasada no sueltan un enlace bueno; pegados al material, si.
     *
     * Hallado en el informe en seco de la VM: «CLIENTE DE MAR DEL PLATA: INCONEL 600», «MATRIZ DE EXTRUSION DE
     * COBRE: INCONEL 718» o «COLOR PLATA» perdian un enlace correcto. En cambio «MONEL Y COBRE» o «PLATA 99.85 +
     * 0.15 NIQUEL» nombran dos materiales y no se adivina.
     */
    public function test_cobre_o_plata_de_pasada_no_sueltan_un_enlace_bueno(): void
    {
        $e = new EnlazadorDeLineas([
            [1, 'Inconel 600'], [2, 'Poliester'], [3, 'Titanio Grado 2'], [3, 'TIT GR2', true],
            [4, 'Cobre Niquel 90/10'], [5, 'Cu-Ni 45'], [6, 'Monel'], [7, 'Niquel'], [8, 'Latón'],
            [20, 'Titanio Grado 5'], [21, 'Zinc'], [22, 'Plomo'], [23, 'Estano'], [24, 'Hafnio'], [25, 'Renio'],
            [26, 'Niobio'], [27, 'Tantalio'], [28, 'Molibdeno'], [29, 'Cobalto'],
            // Como en el catalogo real, "Cobre" y "Plata" son materiales por si solos.
            [30, 'Cobre'], [31, 'Plata'],
        ], ruidoExtra: ['cobre', 'plata', 90, 45]);

        // Lugares y colores: no es el metal.
        $this->assertSame(1, $e->elegir('COTIZAMOS PARA CLIENTE DE MAR DEL PLATA: INCONEL 600 Ø25.4MM, X KG (+IVA)'));
        $this->assertSame(2, $e->elegir('POLIESTER METALIZADO 23 MICRONES X 635 MM COLOR PLATA P/KG'));
        // De pasada: tras un ":" o un ")" ya es otra frase.
        $this->assertSame(1, $e->elegir('PARA MATRIZ DE EXTRUSION DE COBRE: INCONEL 600 DISCO Ø101.6 X 30MM, 1 C/U'));
        $this->assertSame(3, $e->elegir('(ANODO INSOLUBLE PARA COBRE ACIDO) 15-4-93 4 GANCHOS TIT GR2 C/U'));
        $this->assertSame(8, $e->elegir('LA PROXIMA COMPRA IRA CON 8% AUMENTO POR AUMENTO DEL COBRE 13.7.94: LATON 0,20 MM P/KG'));

        // Pegado al material: son dos materiales.
        $this->assertSame(6, $e->elegir('COTIZO PIEZAS EN MONEL Y COBRE PARA TALLERES', false));
        $this->assertNull($e->elegir('COTIZO PIEZAS EN MONEL Y COBRE PARA TALLERES'));
        $this->assertSame(7, $e->elegir('COTIZA PLATA 99.85 + 0.15 NIQUEL 13 C/U CHAPA 3 X 100 X 100 MM', false));
        $this->assertNull($e->elegir('COTIZA PLATA 99.85 + 0.15 NIQUEL 13 C/U CHAPA 3 X 100 X 100 MM'));
        $this->assertNull($e->elegir('14-3-97 2.600 KG NIQUEL ALEACION EN COBRE P/KG'));
    }

    /** "CUPRO NIQUEL 90/10" es cobre niquel 90/10: el enlace bueno sigue, y "CUNI 90/10" no cae en "Cu-Ni 45". */
    public function test_cupro_es_cobre(): void
    {
        $e = new EnlazadorDeLineas([
            [4, 'Cobre Niquel 90/10'], [5, 'Cu-Ni 45'],
            [20, 'Titanio Grado 5'], [21, 'Zinc'], [22, 'Plomo'], [23, 'Estano'], [24, 'Hafnio'], [25, 'Renio'],
            [26, 'Niobio'], [27, 'Tantalio'], [28, 'Molibdeno'], [29, 'Cobalto'],
        ], ruidoExtra: ['cobre', 'plata', 90, 45]);

        $this->assertSame(4, $e->elegir('TUBO CUPRO NIQUEL 90/10, W.NO. 2.0872 840 MTS 15.88 X 2.70 MM'));
        $this->assertSame(4, $e->elegir('OFRECITO TUBO CUPRO-NIQUEL 90/10 Ø19.05 X 1.5MM POR KG'));
        $this->assertNull($e->elegir('COTIZADO BARRA CUNI 70/30 DIA. 125 X 500 MM, X KG (+IVA)'));
    }

    private function catalogoConGrados(): EnlazadorDeLineas
    {
        return new EnlazadorDeLineas([
            [1, 'Monel 60'], [2, 'Monel 400'], [3, 'Acero AISI 305'], [3, 'AISI 305', true], [4, 'Acero AISI 321'], [4, 'AISI 321', true],
            [5, 'Hastelloy X'], [6, 'Hastelloy B-2'], [13, 'Hastelloy W'], [7, 'Alferon 20'], [9, 'Inconel 600'], [10, 'Inconel X-750'],
            [11, 'Aluminio 99.99%'], [11, 'ALU 4X9', true], [12, 'Zinc 99.99%'], [12, 'ZIN 4X9', true],
            [20, 'Titanio Grado 2'], [21, 'Titanio Grado 5'], [22, 'Niquel 200'], [23, 'Niquel 201'], [24, 'Molibdeno'],
            [25, 'Tantalio'], [26, 'Niobio'], [27, 'Hafnio'], [28, 'Renio'], [29, 'Plomo'], [30, 'Estano'],
        ], ruidoExtra: ['cobre', 'plata', 'alambre', 90, 45]);
    }

    /**
     * Un numero de la medida no es el grado, ni la X del "por unidad" el grado X.
     *
     * Hallado en el informe completo de la VM: «AISI 321, BARRA Ø305 X 500» estaba en «Acero AISI 305»,
     * «MONEL 400 ... (TERMINADO A 60 X 203)» en «Monel 60», «HASTELLOY B BARRA Ø 31.75 X 50 MM, X C/U» en «Hastelloy X»,
     * «ALFERON 25 ALAMBRE Ø 1,20 MM» en «Alferon 20» y «INCONEL 750 ... X 600» en «Inconel 600».
     */
    public function test_el_numero_de_una_medida_no_es_el_grado(): void
    {
        $e = $this->catalogoConGrados();

        // Antes de esta regla: el grado equivocado.
        $this->assertSame(5, $e->elegir('1 C/U HASTELLOY B BARRA Ø 31.75 X 50 MM, X C/U', false));
        $this->assertSame(7, $e->elegir('ALFERON 25 ALAMBRE Ø 1,20 MM P/KG (+IVA)', false));
        $this->assertSame(9, $e->elegir('INCONEL 750 ALA Ø 3.76 X 600 C/U(+IVA)', false));

        // Ahora: sin enlazar, que es lo honesto; el grado dicho de verdad sigue enlazando.
        $this->assertNull($e->elegir('COTIZADO: AISI 321, BARRA Ø305 X 500 MM (290 KG) AEREO, X KG (+IVA)'));
        $this->assertNull($e->elegir('1 C/U HASTELLOY B BARRA Ø 31.75 X 50 MM, X C/U'));
        $this->assertNull($e->elegir('ALFERON 25 ALAMBRE Ø 1,20 MM P/KG (+IVA)'));
        $this->assertNull($e->elegir('INCONEL 750 ALA Ø 3.76 X 600 C/U(+IVA)'));
        $this->assertSame(5, $e->elegir('1 C/U HASTELLOY X BARRA Ø 31.75 X 50 MM, X C/U'));
        $this->assertSame(5, $e->elegir('HASTELLOY X CHAPA 4.76 X 600 X 740MM, 1 C/U'));
        $this->assertSame(10, $e->elegir('INCONEL X750 X 9.53 P/KG(+IVA)'));
        $this->assertSame(7, $e->elegir('ALAMBRE ALFERON 20 DIA. 0.60 MM, X KG'));
        $this->assertSame(3, $e->elegir('1 UN ACERO AISI 305 BARRA Ø25 X 1000 MM C/U'));
    }

    /** «ALU 4X9» son cuatro nueves de pureza: no es una serie de medidas. */
    public function test_cuatro_nueves_no_es_una_medida(): void
    {
        $e = $this->catalogoConGrados();

        $this->assertSame(11, $e->elegir('ALU 4X9 PLANCHA'));
        $this->assertSame(12, $e->elegir('1 KG ZIN 4X9 LINGOTE'));
    }

    /** Lo que ya estaba sin enlazar no empieza a enlazarse por quitar las medidas: eso se audita aparte. */
    public function test_quitar_las_medidas_no_enlaza_lo_que_no_se_enlazaba(): void
    {
        $e = $this->catalogoConGrados();

        // Con "X50" pegado la linea tenia una pseudo designacion y no se enlazaba: sigue igual.
        $this->assertNull($e->elegir('TIT GR2 BARRA Ø 12.7 X50 MM C/U'));
    }

    /** sostiene(): si el texto dice de verdad el material ya enlazado, sin contar las medidas. */
    public function test_sostiene_dice_si_el_texto_respalda_el_material(): void
    {
        $e = $this->catalogoConGrados();

        $this->assertFalse($e->sostiene(3, 'COTIZADO: AISI 321, BARRA Ø305 X 500 MM'));
        $this->assertTrue($e->sostiene(4, 'COTIZADO: AISI 321, BARRA Ø305 X 500 MM'));
        $this->assertFalse($e->sostiene(1, 'MONEL 400, BARRA Ø65 X 206 (TERMINADO A 60 X 203), X KG'));
        $this->assertTrue($e->sostiene(2, 'MONEL 400, BARRA Ø65 X 206 (TERMINADO A 60 X 203), X KG'));
        $this->assertFalse($e->sostiene(5, '1 C/U HASTELLOY B BARRA Ø 31.75 X 50 MM, X C/U'));
        $this->assertTrue($e->sostiene(5, 'HASTELLOY X BARRA Ø 31.75 X 50 MM, X C/U'));
        $this->assertTrue($e->sostiene(6, 'HASTELLOY B-2 BARRA Ø 31.75 X 50 MM, X C/U'));
        // Que el enlazador de hoy dude no quita el sustento: "Niquel 201" esta dicho aunque haya otro "201" cerca.
        $this->assertTrue($e->sostiene(23, 'PIDE NIQUEL BARRA 1" X 100 MM 1 UN NIQUEL 201 BARRA 38.1 X 200 MM'));
    }
}

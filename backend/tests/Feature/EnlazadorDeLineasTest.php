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
}

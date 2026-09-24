<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialAlias;
use App\Services\LectorDeSolicitud;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Leer el pedido del cliente sin IA.
 *
 * Es el camino que corre siempre: con credencial de IA se usa igual como
 * respaldo, y sin credencial es el unico. Lo que se arma es una propuesta que
 * la persona revisa, pero una propuesta vacia no ahorra nada y una propuesta
 * con el material equivocado hace perder mas tiempo que no proponer nada.
 */
class LectorDeSolicitudTest extends TestCase
{
    use RefreshDatabase;

    private LectorDeSolicitud $lector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        // Es el que carga los campos de cada forma: sin eso no hay orden de
        // medidas que leer.
        $this->seed(CalculadoraSeeder::class);
        $this->lector = new LectorDeSolicitud();
    }

    /** @return array<string, mixed>|null */
    private function leer(string $renglon): ?array
    {
        return $this->lector->interpretar("Hola, necesito cotizar:\n".$renglon)['lineas'][0] ?? null;
    }

    /**
     * Las formas salen del catalogo, no de una lista de ocho escrita a mano.
     *
     * Antes se reconocian barra, caño, chapa, planchuela, tubo y las tres
     * barras con perfil. Un disco, un anillo, un alambre o un perfil entraban
     * sin forma — y sin forma no hay donde poner las medidas, con lo cual no
     * hay peso y no hay precio.
     */
    #[DataProvider('pedidosConForma')]
    public function test_reconoce_las_formas_del_catalogo(string $renglon, string $forma): void
    {
        $linea = $this->leer($renglon);

        $this->assertNotNull($linea, "se descarto el renglon: {$renglon}");
        $this->assertSame($forma, $linea['forma'], "fallo con: {$renglon}");
    }

    public static function pedidosConForma(): array
    {
        return [
            'disco' => ['2 DISCOS de Inconel 600 de 200 x 12 mm', 'DISCO'],
            'alambre' => ['25 kg ALAMBRE de bronce fosforoso 0.70 mm', 'ALAMBRE'],
            'anillo' => ['12 un ANILLO de titanio gr2 de 50 x 30 mm', 'ANILLO'],
            'perfil' => ['8 un PERFIL de aluminio de 40 x 3000 mm', 'PERFIL'],
            'brida' => ['3 BRIDA de acero inoxidable 304 de 100 mm', 'BRIDA'],
            // Abreviada, como la escriben de verdad.
            'bar red' => ['6 UN HASTELLOY C-276 BAR RED 38.1 X 145MM', 'BARRA REDONDA'],
            'ala' => ['30 M TITANIO GR2 ALA 1.14 MM', 'ALAMBRE'],
        ];
    }

    /**
     * Un alias de dos letras no engancha cualquier cosa.
     *
     * El catalogo real tiene "SC" (Scandio) y "50". Buscando por pedazo de
     * texto, el SC se llevaba el "SCH 40" de todos los caños y devolvia
     * Scandio: un material carisimo en una linea de caño de inoxidable, con
     * el precio que eso arrastra.
     */
    public function test_un_alias_de_dos_letras_no_engancha_el_sch_de_los_canos(): void
    {
        $scandio = Material::where('activo', true)->firstOrFail();
        MaterialAlias::create(['material_id' => $scandio->id, 'alias' => 'SC']);

        $linea = $this->leer('1 caño de 4" SCH 40 x 6 m');

        $this->assertNotNull($linea, 'el renglon tiene forma y medidas: tiene que entrar');
        $this->assertNull($linea['material_id'], 'no hay material reconocible, no se inventa uno');
        $this->assertSame('CAÑO', $linea['forma']);
    }

    /**
     * Sin material reconocido, el renglon entra igual con lo demas cargado.
     *
     * Antes el material decidia solo: si no lo reconocia tiraba el renglon
     * entero y se iban con el la forma, la cantidad y las medidas ya leidas.
     * El material es un desplegable al lado; el resto hay que volver a
     * tipearlo mirando el mail.
     */
    public function test_sin_material_el_renglon_no_se_tira(): void
    {
        $linea = $this->leer('4 un CHAPA de vayaunoasaber de 2 x 1000 x 2000 mm');

        $this->assertNotNull($linea);
        $this->assertNull($linea['material_id']);
        $this->assertSame('CHAPA', $linea['forma']);
        $this->assertSame(4.0, $linea['cantidad']);
        $this->assertNotNull($linea['dimensiones']);
    }

    /** Gana el nombre mas largo: BARRA REDONDA y no BARRA. */
    public function test_el_nombre_mas_especifico_gana(): void
    {
        $this->assertSame('BARRA REDONDA', $this->leer('2 un BARRA REDONDA de titanio gr2 de 10 x 2000 mm')['forma']);
        $this->assertSame('BARRA HEXAGONAL', $this->leer('2 un BARRA HEXAGONAL de titanio gr2 de 10 x 2000 mm')['forma']);
    }

    /**
     * Cada medida escrita va al campo que le toca, segun la forma.
     *
     * Antes se tomaban siempre los dos primeros numeros como diametro y
     * largo. Una chapa "2 X 1000 X 2000" entraba con 2 mm de diametro y 1000
     * de largo, y el 2000 se perdia: el peso que salia de ahi no era el de
     * ninguna chapa.
     *
     * @param  array<string, float|null>  $espera
     */
    #[DataProvider('medidasPorForma')]
    public function test_cada_medida_va_al_campo_que_le_toca(string $renglon, array $espera): void
    {
        $linea = $this->leer($renglon);

        $this->assertNotNull($linea, "se descarto el renglon: {$renglon}");

        foreach ($espera as $campo => $valor) {
            $this->assertSame($valor, $linea[$campo], "{$campo} en: {$renglon}");
        }
    }

    public static function medidasPorForma(): array
    {
        return [
            // Diametro y largo: los dos numeros en el orden en que se escriben.
            'barra redonda' => [
                '6 UN HASTELLOY C-276 BAR RED 38.1 X 145 MM',
                ['diametro_mm' => 38.1, 'largo_mm' => 145.0, 'espesor_mm' => null, 'ancho_mm' => null],
            ],
            // El catalogo dice "Espesor x ancho x largo", no el orden del formulario.
            'chapa' => [
                '3 un CHAPA de acero inoxidable 304 de 2 x 1000 x 2000 mm',
                ['espesor_mm' => 2.0, 'ancho_mm' => 1000.0, 'largo_mm' => 2000.0, 'diametro_mm' => null],
            ],
            'planchuela' => [
                '4 un PLANCHUELA de acero inoxidable 304 de 3 x 25 x 2000 mm',
                ['espesor_mm' => 3.0, 'ancho_mm' => 25.0, 'largo_mm' => 2000.0],
            ],
            // Exterior, pared y largo: el largo es el tercero, no el segundo.
            'tubo' => [
                '1 TUBO de inconel 600 de 50 x 3 x 6000 mm',
                ['diametro_mm' => 50.0, 'espesor_mm' => 3.0, 'largo_mm' => 6000.0],
            ],
            'disco' => [
                '2 DISCO de inconel 600 de 200 x 12 mm',
                ['diametro_mm' => 200.0, 'espesor_mm' => 12.0, 'largo_mm' => null],
            ],
            // Una sola medida, con su unidad puesta.
            'alambre' => [
                '25 kg ALAMBRE de bronce fosforoso 0,70 mm',
                ['diametro_mm' => 0.7, 'largo_mm' => null],
            ],
        ];
    }

    /**
     * Sin forma reconocida, dos numeros son diametro y largo.
     *
     * Es lo que son en las formas del catalogo que piden dos medidas:
     * primero la seccion, despues el largo. Dejarlos vacios seria hacer
     * tipear de nuevo algo que estaba escrito.
     */
    public function test_sin_forma_dos_numeros_son_diametro_y_largo(): void
    {
        $linea = $this->leer('4 un AISI 316TI vayaunoasaber DIA 65 X 145MM');

        $this->assertNotNull($linea);
        $this->assertNull($linea['forma_id']);
        $this->assertSame(65.0, $linea['diametro_mm']);
        $this->assertSame(145.0, $linea['largo_mm']);
    }

    /**
     * Con tres numeros y sin forma, no se adivina.
     *
     * Cual de los tres es el espesor depende de la forma, y del espesor sale
     * el peso. Queda el texto entero a la vista para completarlo a mano.
     */
    public function test_con_tres_numeros_y_sin_forma_no_adivina(): void
    {
        $linea = $this->leer('2 un titanio gr2 vayaunoasaber de 2 x 1000 x 2000 mm');

        $this->assertNotNull($linea);
        $this->assertNull($linea['diametro_mm']);
        $this->assertNull($linea['espesor_mm']);
        $this->assertNull($linea['ancho_mm']);
        $this->assertNull($linea['largo_mm']);
        $this->assertNotNull($linea['dimensiones'], 'el texto de la medida queda igual');
    }

    /**
     * Los caños se guardan como los escriben: en pulgadas y con schedule.
     *
     * Pasarlos a milimetros es otra cosa; asi los pide el cliente y asi los
     * busca el proveedor.
     */
    public function test_el_cano_conserva_la_medida_comercial(): void
    {
        $linea = $this->leer('1 CAÑO de niquel 201 de 4" SCH 40 x 6 m');

        $this->assertNotNull($linea);
        $this->assertSame('CAÑO', $linea['forma']);
        $this->assertStringContainsString('SCH 40', $linea['dimensiones']);
        $this->assertNull($linea['diametro_mm'], 'una pulgada no es un milimetro');
    }

    /** Un saludo o una firma no son una linea de pedido. */
    public function test_el_ruido_del_mail_no_entra(): void
    {
        $r = $this->lector->interpretar(
            "Hola Roberto, como va?\n2 DISCOS de Inconel 600 de 200 x 12 mm\nGracias, saludos\nJuan",
        );

        $this->assertCount(1, $r['lineas']);
        $this->assertSame('DISCO', $r['lineas'][0]['forma']);
    }
}

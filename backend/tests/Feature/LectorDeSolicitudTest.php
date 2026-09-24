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

    /**
     * El ruido del mail no entra, aunque nombre un material sin querer.
     *
     * El catalogo tiene alias de dos y tres letras —RE es Renio, TAN es
     * Tantalio, GRA es Grafito, CUAL es Cobre Aluminio— y buscandolos por
     * pedazo de texto caen adentro de palabras comunes: "entREGA",
     * "TiTANiu", "GRAcias", "CUALquier". Un mail de seis renglones devolvia
     * seis lineas de materiales carisimos y ninguna era lo que se pidio.
     *
     * Un renglon es una linea de pedido si pide una cantidad o dice una
     * medida. Un saludo no pide nada.
     */
    #[DataProvider('ruidoDeMail')]
    public function test_el_ruido_del_mail_no_entra(string $renglon): void
    {
        $this->assertNull($this->leer($renglon), "entro como linea: {$renglon}");
    }

    public static function ruidoDeMail(): array
    {
        return [
            ['Buenos dias Roberto, espero que andes bien.'],
            ['entrega:'],
            ['Ante cualquier consulta estamos a disposicion.'],
            ['Desde ya, muchas gracias.'],
            ['Quedo a la espera de su respuesta.'],
            ['Att. Juan Perez - Compras'],
            ['Por favor cotizar con entrega en planta.'],
        ];
    }

    /**
     * Un alias corto no engancha adentro de una palabra.
     *
     * Aca el renglon SI es un pedido —tiene cantidad y medida—, asi que la
     * linea entra. Lo que no puede pasar es que "para cualquier uso" la
     * cargue con Cobre Aluminio.
     */
    public function test_un_alias_corto_no_cae_adentro_de_una_palabra(): void
    {
        $cobre = Material::where('activo', true)->firstOrFail();
        MaterialAlias::create(['material_id' => $cobre->id, 'alias' => 'CUAL']);
        MaterialAlias::create(['material_id' => $cobre->id, 'alias' => 'GRA']);

        $linea = $this->leer('3 DISCOS de 200 x 12 mm para cualquier uso, gracias');

        $this->assertNotNull($linea);
        $this->assertNull($linea['material_id'], 'no lo nombro: no se elige uno');
        $this->assertSame('DISCO', $linea['forma']);
    }

    /**
     * Una letra cambiada no deja la linea sin material.
     *
     * Los mails vienen con tipeos. "Titaniu Grado 2" es Titanio Grado 2 y no
     * hay otra cosa que pueda ser.
     */
    public function test_corrige_un_tipeo_de_una_letra(): void
    {
        $linea = $this->leer('3 Barras de Ø127mm x 25.4mm de largo. Material: Titaniu Grado 2');

        $this->assertNotNull($linea);
        $this->assertSame('TITANIO GR2', $linea['material']);
    }

    /**
     * Una designacion no se corrige nunca.
     *
     * En "AISI 317" esa ultima letra no es un error de tipeo: es otro acero,
     * con otro precio. Los codigos con numeros quedan como vinieron.
     */
    public function test_no_corrige_una_designacion(): void
    {
        $linea = $this->leer('2 CHAPA AISI 317 de 2 x 1000 x 2000 mm');

        $this->assertNotNull($linea);
        $this->assertNotSame('Acero AISI 316', $linea['material']);
    }

    /** La viñeta de la lista no se come la cantidad. */
    #[DataProvider('vinetas')]
    public function test_la_vineta_no_se_come_la_cantidad(string $renglon): void
    {
        $linea = $this->leer($renglon);

        $this->assertNotNull($linea, "se descarto: {$renglon}");
        $this->assertSame(3.0, $linea['cantidad'], "fallo con: {$renglon}");
    }

    public static function vinetas(): array
    {
        return [
            ['- 3 DISCOS de inconel 600 de 200 x 12 mm'],
            ['* 3 DISCOS de inconel 600 de 200 x 12 mm'],
            ['• 3 DISCOS de inconel 600 de 200 x 12 mm'],
            ['1) 3 DISCOS de inconel 600 de 200 x 12 mm'],
        ];
    }

    /**
     * "Barra" a secas con el diametro marcado es una barra redonda.
     *
     * El catalogo tiene BARRA desactivada y activas BARRA REDONDA y BARRA
     * RED. / VARILLA. Sin mas datos no se elige ninguna. Pero el Ø lo
     * escribio el cliente: una barra con diametro es redonda, una hexagonal
     * se escribe entre caras.
     */
    public function test_barra_con_diametro_marcado_es_redonda(): void
    {
        $this->assertSame('BARRA REDONDA', $this->leer('3 Barras de Ø127mm x 25.4mm de largo')['forma']);
        $this->assertSame('BARRA REDONDA', $this->leer('4 un barra DIA 65 X 145MM')['forma']);
    }

    /** Sin el diametro marcado, "barra" no dice cual es y queda vacia. */
    public function test_barra_sin_diametro_marcado_queda_sin_forma(): void
    {
        $linea = $this->leer('3 barras de titanio gr2 de 127 x 25.4 mm');

        $this->assertNotNull($linea);
        $this->assertNull($linea['forma_id']);
        $this->assertSame(3.0, $linea['cantidad'], 'lo demas se carga igual');
    }

    /**
     * El mail de verdad, entero.
     *
     * Es el que destapó todo esto: devolvia seis lineas —Renio, Tantalio,
     * Cobre Aluminio, Grafito— y ninguna era lo que el cliente pidio.
     */
    public function test_el_mail_de_un_cliente_entero(): void
    {
        $r = $this->lector->interpretar(<<<'MAIL'
            Buenos días Roberto, espero que andes bien. Quería solicitar si me podrían cotizar lo siguiente y confirmar si tienen stock o el plazo de
            entrega:

            - 3 Barras de Ø127mm x 25.4mm de largo. Material: Titaniu Grado 2

            - 1 Barras de Ø127mm x 26mm de largo. Material: Titaniu Grado 2

            - 3 Caño de 1.5" SCH40S x 3340mm. Material: Titaniu Grado 2

            Ante cualquier consulta estamos a disposición.

            Desde ya, muchas gracias.

            Saludos.!
            MAIL);

        $this->assertCount(3, $r['lineas'], 'tres renglones pidieron algo, tres lineas');

        [$una, $dos, $tres] = $r['lineas'];

        $this->assertSame(3.0, $una['cantidad']);
        $this->assertSame('TITANIO GR2', $una['material']);
        $this->assertSame('BARRA REDONDA', $una['forma']);
        $this->assertSame(127.0, $una['diametro_mm']);
        $this->assertSame(25.4, $una['largo_mm']);

        $this->assertSame(1.0, $dos['cantidad']);
        $this->assertSame(26.0, $dos['largo_mm']);

        $this->assertSame(3.0, $tres['cantidad']);
        $this->assertSame('CAÑO', $tres['forma']);
        // La medida comercial se guarda como la escriben, no en milimetros.
        $this->assertStringContainsString('SCH40S', $tres['dimensiones']);
    }
}

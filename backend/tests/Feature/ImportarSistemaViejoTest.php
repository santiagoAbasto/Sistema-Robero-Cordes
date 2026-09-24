<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Contacto;
use App\Models\ContactoMedio;
use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Localidad;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Unidad;
use App\Models\User;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La red debajo del importador del sistema anterior.
 *
 * Es el codigo mas destructivo que tiene el sistema —borra quince tablas y
 * escribe decenas de miles de filas— y hasta ahora no lo cubria un solo test.
 * Esto es el paso 1: fijar lo que hace HOY, antes de tocarlo.
 *
 * Los tests marcados BUG documentan algo que esta mal a proposito. Afirman el
 * comportamiento actual para que el dia que se arregle el test falle y haya
 * que venir a cambiarlo con los ojos abiertos. No son deuda: son el inventario.
 *
 * Los CSV de tests/Fixtures/migracion son chiquitos y estan armados a mano,
 * fila por fila, para pegarle a un comportamiento cada uno. No son una muestra
 * de los reales: son casos.
 */
class ImportarSistemaViejoTest extends TestCase
{
    use RefreshDatabase;

    private function carpeta(): string
    {
        return base_path('tests/Fixtures/migracion');
    }

    private function seedCatalogos(): void
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
    }

    private function importar(bool $aplicar = true)
    {
        return $this->artisan('importar:sistema-viejo', array_filter([
            '--carpeta' => $this->carpeta(),
            '--aplicar' => $aplicar,
        ]));
    }

    // ------------------------------------------------------------- el contrato

    /** Sin todos los archivos no arranca: media importacion es peor que ninguna. */
    public function test_si_falta_un_archivo_el_comando_aborta(): void
    {
        $this->artisan('importar:sistema-viejo', ['--carpeta' => base_path('tests/Fixtures')])
            ->assertFailed();
    }

    /** El modo informe promete no escribir. Esto lo fija. */
    public function test_el_modo_informe_no_escribe_nada(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $antes = [Material::count(), Forma::count(), Empresa::count(), Consulta::count()];

        $this->importar(aplicar: false)->assertSuccessful();

        $this->assertSame($antes, [Material::count(), Forma::count(), Empresa::count(), Consulta::count()]);
    }

    // -------------------------------------------------------------- el catalogo

    public function test_aplicar_carga_el_catalogo_del_access(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $this->assertNotNull(Material::where('nombre', 'Acero AISI 304')->first());
        $this->assertNotNull(Forma::where('nombre', 'BARRA CANULADA')->first());
        $this->assertNotNull(Unidad::where('codigo', 'M')->first());
    }

    /** El 0 del Access significa "no la cargaron", no "pesa cero". */
    public function test_la_densidad_cero_queda_en_null(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $this->assertNull(Material::where('nombre', 'Acero AISI 630 (17-4PH)')->value('densidad'));
        $this->assertNull(Material::where('nombre', 'Servicio de Corte')->value('densidad'));
    }

    /** El mismo nombre en otra caja es el mismo material: entra una sola vez. */
    public function test_el_material_repetido_se_ignora(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $this->assertSame(1, Material::whereRaw('LOWER(nombre) = ?', ['acero aisi 304'])->count());
    }

    /** El codigo del Access queda como alias para poder buscarlo. */
    public function test_el_codigo_del_material_queda_como_alias(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $this->assertNotNull(MaterialAlias::where('alias', 'TIT GR2')->first());
    }

    /** "METALES: Aceros Inoxidables" se guarda como "Aceros Inoxidables". */
    public function test_la_familia_pierde_el_prefijo_del_access(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $this->assertSame('Aceros Inoxidables', Material::where('nombre', 'Acero AISI 304')->value('familia'));
    }

    /** En el Access el simbolo Ø quedo guardado como Ý. Se traduce al importar. */
    public function test_la_y_del_access_vuelve_a_ser_el_simbolo_de_diametro(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $this->assertNotNull(ConsultaLinea::where('descripcion', 'BAR Ø 1.6 X 915MM')->first());
    }

    // ----------------------------------------------------------- las cotizaciones

    /** Una fecha anterior a 1938 no puede ser de CORDES: se marca, no se corrige. */
    public function test_la_fecha_imposible_queda_marcada_en_la_nota(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $linea = ConsultaLinea::where('descripcion', 'BAR Ø 1.6 X 915MM')->first();

        $this->assertStringContainsString('REVISAR LA FECHA', $linea->consulta->nota);
    }

    /**
     * Tampoco puede ser del futuro.
     *
     * El año tipeado de mas —"01/10/2424"— entraba como fecha buena y la
     * cotizacion se ordenaba arriba de todo en la ficha del cliente, delante
     * de la del mes pasado. En el archivo real son 17.
     */
    public function test_la_fecha_del_futuro_tambien_queda_marcada(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $consulta = ConsultaLinea::where('descripcion', 'CHAPA 3MM')->first()->consulta;

        $this->assertStringContainsString('REVISAR LA FECHA', $consulta->nota);
        $this->assertStringContainsString('2424', $consulta->nota, 'dice cual era');
        $this->assertSame('1900-01-01', $consulta->fecha->toDateString(),
            'no se guarda como fecha buena: se ordenaria delante de las de verdad');
    }

    /** Los australes no se convierten: se deja dicho en la nota cual era. */
    public function test_la_moneda_historica_no_se_convierte_y_queda_avisada(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $flete = ConsultaLinea::where('descripcion', 'FLETE AEREO')->first();

        $this->assertStringContainsString('Moneda original: AUSTRALES', $flete->consulta->nota);
    }

    /** La empresa que esta en otra letra del indice se crea con lo que trae la cotizacion. */
    public function test_la_empresa_que_falta_se_crea_desde_la_cotizacion(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $this->assertNotNull(Empresa::where('nombre', 'YPF S.A.')->first());
    }

    // ------------------------------------------------------------------- los BUG

    /**
     * Los cinco renglones se agrupan en articulos: el precio cierra uno.
     *
     * La primera fila del fixture es UN item cuya descripcion se derrama por
     * tres renglones, con el precio en el tercero, mas el plazo de entrega en
     * el cuarto. Antes entraban como cuatro productos y "110 DIAS" quedaba
     * cotizado en 0.
     */
    public function test_los_renglones_se_agrupan_en_un_solo_articulo(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $consulta = Consulta::whereHas('lineas', fn ($q) => $q->where('precio_unitario', 41.7))->first();

        $this->assertCount(1, $consulta->lineas, 'los tres renglones son un solo articulo');
        $this->assertStringContainsString('NITINOL', $consulta->lineas->first()->descripcion);
        $this->assertStringContainsString('PEDIDO MINIMO', $consulta->lineas->first()->descripcion);
        $this->assertStringNotContainsString('110 DIAS', $consulta->lineas->first()->descripcion);
    }

    /** El plazo no es un articulo, pero tampoco se tira: queda en la nota. */
    public function test_el_plazo_de_entrega_se_guarda_como_nota(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $consulta = Consulta::whereHas('lineas', fn ($q) => $q->where('precio_unitario', 41.7))->first();

        $this->assertStringContainsString('110 DIAS', $consulta->nota);
    }

    /** Pase lo que pase con la agrupacion, los cinco renglones quedan enteros. */
    public function test_el_texto_original_se_guarda_completo(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $consulta = Consulta::whereHas('lineas', fn ($q) => $q->where('precio_unitario', 41.7))->first();

        foreach (['NITINOL', 'OXIDO', 'PEDIDO MINIMO', '110 DIAS'] as $trozo) {
            $this->assertStringContainsString($trozo, $consulta->texto);
        }
    }

    /**
     * La cantidad no se inventa: o estaba escrita, o queda vacia.
     *
     * "FLETE AEREO" no dice cuantos, asi que la linea entra sin cantidad y sin
     * importe — el precio unitario, que es lo unico que se cotizo, queda.
     */
    public function test_la_cantidad_que_no_estaba_queda_en_null(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $linea = ConsultaLinea::where('descripcion', 'FLETE AEREO')->first();

        $this->assertNull($linea->cantidad);
        $this->assertNull($linea->importe);
        $this->assertEquals(9999, (float) $linea->precio_unitario);
    }

    /**
     * La mascara vacia del Access no es un telefono.
     *
     * TELEF5 del fixture es '-    -', el formulario sin llenar. En el archivo
     * real aparece 3667 veces sobre 4830 valores.
     */
    public function test_la_mascara_vacia_no_entra_como_telefono(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $this->assertNull(ContactoMedio::where('valor', '-    -')->first());
        $this->assertNotNull(ContactoMedio::where('valor', '4201-5000')->first());
    }

    /**
     * El guion de la casilla que quedo vacia no es parte del numero.
     *
     * El formulario partia el telefono en caracteristica, prefijo y numero, y
     * la casilla sin llenar dejaba igual su guion: "-    -2520" es el 2520 y
     * "4201-  -5090" es el 4201-5090. En el archivo real son 991.
     */
    public function test_el_telefono_no_se_queda_con_el_guion_de_la_casilla_vacia(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $this->assertNotNull(ContactoMedio::where('valor', '2520')->first());
        $this->assertNotNull(ContactoMedio::where('valor', '4201-5090')->first());
        $this->assertEmpty(
            ContactoMedio::pluck('valor')->filter(fn ($v) => preg_match('/^[- ]/', $v))->all(),
            'ninguno arranca con guion',
        );
    }

    /** La ficha guarda el pais: FEDERAL EXPRESS de Santiago es de Chile. */
    public function test_la_empresa_guarda_el_pais(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $ficha = Empresa::where('direccion', 'Ruta 8 Km 5')->first();

        $this->assertSame('Chile', $ficha->pais->nombre);
    }

    /**
     * La localidad sin provincia se rescata, sin inventarle una provincia.
     *
     * localidades.provincia_id es NOT NULL, asi que cuelga de una "Sin
     * determinar" del pais que corresponda. La ficha queda con la localidad y
     * SIN provincia: que falta el dato se ve. En el archivo real son 281.
     */
    public function test_la_localidad_sin_provincia_se_rescata(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $ficha = Empresa::where('direccion', 'Ruta 8 Km 5')->first();

        $this->assertSame('SANTIAGO', $ficha->localidad->nombre);
        $this->assertNull($ficha->provincia_id, 'no se le inventa una provincia a la ficha');
        $this->assertSame('Sin determinar', $ficha->localidad->provincia->nombre);
    }

    /**
     * Dos fichas con el mismo nombre son dos fichas distintas.
     *
     * Antes la cotizacion se buscaba por nombre y los repetidos colapsaban:
     * ganaba la ultima. Ahora se cuelga del ID del Access, que en el archivo
     * real acierta en 8044 de 8048 y nunca apunta a dos nombres distintos.
     */
    public function test_las_fichas_repetidas_se_distinguen_por_el_id_del_access(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $fichas = Empresa::where('nombre', 'FEDERAL EXPRESS')->get();

        $this->assertCount(2, $fichas, 'las dos fichas entran');

        // La cotizacion trae ID 102, que es la ficha de Santiago: va a esa y
        // no a la de Ezeiza, que es la que ganaba cuando se buscaba por nombre.
        $deSantiago = $fichas->firstWhere('codigo_indice', '102');

        $this->assertSame(1, $deSantiago->consultas()->count());
    }

    /**
     * Sin usuarios no arranca, y sobre todo no borra.
     *
     * consultas.usuario_id es NOT NULL. Antes la importacion arrancaba igual,
     * borraba las quince tablas y recien fallaba al escribir la primera
     * cotizacion: el catalogo se perdia y no entraba nada a cambio.
     */
    public function test_sin_usuarios_aborta_antes_de_borrar_nada(): void
    {
        $this->seedCatalogos();
        $materiales = Material::count();

        $this->importar()->assertFailed();

        $this->assertSame($materiales, Material::count(), 'no se toco el catalogo');
    }

    /**
     * Lo que curo la empresa sobrevive a la importacion.
     *
     * Es la garantia central: antes el comando borraba materiales y
     * material_alias y los reescribia con cuatro campos, asi que las
     * densidades confirmadas, las normas UNS/W.Nr y los 33 alias se perdian.
     *
     * El fixture trae "Titanio Grado 2" con codigo "TIT GR2", que ya es alias
     * de nuestro "TITANIO GR2": se reconocen y se fusionan, no se duplican.
     *
     * La ficha queda con el nombre de la empresa y el nuestro pasa a alias.
     */
    public function test_el_catalogo_curado_sobrevive_y_se_fusiona(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $curado = Material::where('nombre', 'TITANIO GR2')->firstOrFail();
        $this->assertNotNull($curado->uns, 'el fixture arranca con la norma cargada');

        $this->importar()->assertSuccessful();

        $despues = Material::where('nombre', 'Titanio Grado 2')->first();

        $this->assertNotNull($despues, 'el material curado sigue existiendo');
        $this->assertSame($curado->id, $despues->id, 'es el mismo, no uno nuevo');
        $this->assertSame($curado->uns, $despues->uns, 'la norma no se perdio');
        $this->assertEquals(4.51, (float) $despues->densidad, 'la densidad confirmada no se piso');
        $this->assertSame('44', $despues->origen_id, 'y ahora sabe de que fila del Access viene');
        $this->assertSame(1, Material::whereRaw("LOWER(nombre) LIKE '%titanio gr%2%'")->count(),
            'no quedo duplicado');
        $this->assertNotNull(
            MaterialAlias::where('material_id', $curado->id)->where('alias', 'TITANIO GR2')->first(),
            'el nombre viejo sigue encontrandolo',
        );
    }

    /**
     * "Acero AISI 304" y "AISI 304" son el mismo acero.
     *
     * Por nombre no se reconocen y por codigo tampoco, asi que entraban como
     * dos fichas: la nuestra con el 8,00 que confirmo CORDES para cotizar, y
     * la de ellos —la que su gente elige, porque asi lo llaman— sin densidad.
     * Con la ficha equivocada el sistema no calcula el peso, o lo calcula con
     * la densidad del inventario (7,80) y factura otra cosa.
     */
    public function test_el_acero_de_ellos_y_el_nuestro_son_una_sola_ficha(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $nuestro = Material::where('nombre', 'AISI 304')->firstOrFail();

        $this->importar()->assertSuccessful();

        $ficha = Material::where('nombre', 'Acero AISI 304')->first();

        $this->assertNotNull($ficha, 'queda con el nombre que usa la empresa');
        $this->assertSame($nuestro->id, $ficha->id, 'es la misma ficha, no una segunda');
        $this->assertEquals(8.00, (float) $ficha->densidad, 'la densidad de cotizacion no se piso');
        $this->assertNull(Material::where('nombre', 'AISI 304')->first(), 'no quedo una ficha suelta');
        $this->assertNotNull(
            MaterialAlias::where('material_id', $ficha->id)->where('alias', 'AISI 304')->first(),
            'el nombre viejo sigue encontrandola',
        );
    }

    /**
     * El codigo repetido no fusiona materiales distintos.
     *
     * "ACE 174P" es el codigo de los siete tratamientos del 17-4PH, que son
     * siete materiales con propiedades y precio distintos. Como el importador
     * usaba el codigo para reconocer fichas, los siete entraban como uno: en
     * el archivo real son 48 codigos repetidos sobre 103 materiales y se
     * perdian 56. Ahora cada fila del Access se queda con su propia ficha.
     */
    public function test_el_codigo_repetido_no_fusiona_materiales_distintos(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        foreach (['Acero AISI 630 (17-4PH)', 'Acero AISI 630 H900', 'Acero AISI 630 H1150'] as $nombre) {
            $this->assertNotNull(
                Material::where('nombre', $nombre)->first(),
                "{$nombre} tiene que existir por separado",
            );
        }

        // El codigo sigue sirviendo para buscar: los encuentra a los tres.
        $this->assertSame(3, MaterialAlias::where('alias', 'ACE 174P')->count());
    }

    /** Correrla dos veces tiene que dar lo mismo. */
    public function test_la_importacion_es_idempotente_en_el_catalogo(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();
        $primera = [Material::count(), Forma::count(), Unidad::count()];

        $this->importar()->assertSuccessful();

        $this->assertSame($primera, [Material::count(), Forma::count(), Unidad::count()]);
    }

    /** El contacto sale de RESPONS; sin RESPONS queda uno ficticio. */
    public function test_el_contacto_sin_nombre_queda_como_sin_contacto(): void
    {
        $this->seedCatalogos();
        User::factory()->create();

        $this->importar()->assertSuccessful();

        $ficha = Empresa::where('direccion', 'Ruta 8 Km 5')->first();

        $this->assertSame('Sin contacto', Contacto::where('empresa_id', $ficha->id)->value('nombre'));
    }
}

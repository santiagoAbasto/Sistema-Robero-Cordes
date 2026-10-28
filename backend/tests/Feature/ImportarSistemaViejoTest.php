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
use App\Models\Pais;
use App\Models\Provincia;
use App\Models\Unidad;
use App\Models\User;
use App\Services\Migracion\OriginalesDelAccess;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        // En Chile no hay ninguna SANTIAGO cargada: no se adivina, queda
        // escrita en la ficha. Ya no se cuelga de "Sin determinar", que la
        // limpieza del 26-09 borro por no ser una provincia.
        $this->assertNull($ficha->localidad_id);
        $this->assertNull($ficha->provincia_id, 'no se le inventa una provincia a la ficha');
        $this->assertStringContainsString('Localidad en el indice viejo: SANTIAGO', $ficha->observacion_general);
        $this->assertSame(0, Provincia::where('nombre', 'Sin determinar')->count());
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

    // ------------------------------------------------------------ la suma (--sumar)

    private function sumar(string $carpeta, bool $aplicar = true)
    {
        return $this->artisan('importar:sistema-viejo', array_filter([
            '--carpeta' => base_path("tests/Fixtures/{$carpeta}"),
            '--sumar' => true,
            '--aplicar' => $aplicar,
        ]));
    }

    /** @return list<int> */
    private function conteos(): array
    {
        return [Empresa::count(), Contacto::count(), ContactoMedio::count(), Consulta::count(), ConsultaLinea::count()];
    }

    /** Lo importado ya, mas una ficha y una cotizacion cargadas en el sistema nuevo. */
    private function sistemaEnUso(): Consulta
    {
        $this->seedCatalogos();
        $usuario = User::factory()->create();
        $this->importar()->assertSuccessful();

        $suya = Empresa::create(['nombre' => 'TALLERES SUR SA', 'activa' => true]);

        return Consulta::create([
            'empresa_id' => $suya->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-10-26',
            'estado' => 'Sin cotizar',
            'usuario_id' => $usuario->id,
            'texto' => 'Cargada en el sistema nuevo',
            'validez_dias' => 0,
        ]);
    }

    /** Correr la suma con lo mismo que ya entro no cambia nada: ni borra ni duplica. */
    public function test_sumar_lo_mismo_no_cambia_nada(): void
    {
        $suya = $this->sistemaEnUso();
        $antes = $this->conteos();

        $this->sumar('migracion')->assertSuccessful();

        $this->assertSame($antes, $this->conteos());
        $this->assertNotNull($suya->fresh(), 'lo cargado en el sistema nuevo sigue');
    }

    public function test_sumar_agrega_solo_lo_que_falta(): void
    {
        $suya = $this->sistemaEnUso();
        [$empresas, , , $cotizaciones] = $this->conteos();

        $this->sumar('migracion-completa')->assertSuccessful();

        // Entran ACME (104), Metales del Sur (106), Torneria Norte (107) y la
        // que solo aparece en una cotizacion (777). La fila repetida del 104
        // no, y Talleres Sur ya estaba: la cargaron ellos.
        $this->assertSame($empresas + 4, Empresa::count());
        $this->assertSame(1, Empresa::where('codigo_indice', '104')->count());
        $this->assertSame('ACME METALES S.R.L.', Empresa::where('codigo_indice', '104')->value('nombre'));
        $this->assertNotNull(Empresa::where('codigo_indice', '777')->first());

        // Seis nuevas: la de octubre de Ferrum, la de ACME, la de Talleres Sur,
        // la de la 777 y las dos de Metales del Sur. Las cinco que ya
        // estaban, no.
        $this->assertSame($cotizaciones + 6, Consulta::count());
        $this->assertSame(1, Consulta::where('texto', 'like', 'BARRA INOX 304%')->count());
    }

    /** La ficha que crearon a mano se queda con el ID del indice, y no hay dos. */
    public function test_sumar_reconoce_la_ficha_cargada_en_el_sistema_nuevo(): void
    {
        $suya = $this->sistemaEnUso();

        $this->sumar('migracion-completa')->assertSuccessful();

        $ficha = $suya->empresa->fresh();

        $this->assertSame('105', $ficha->codigo_indice);
        $this->assertSame('TALLERES SUR SA', $ficha->nombre, 'el nombre que pusieron ellos no se pisa');
        $this->assertSame(1, Empresa::where('nombre', 'like', 'Talleres Sur%')->orWhere('nombre', 'TALLERES SUR SA')->count());
        $this->assertSame(2, $ficha->consultas()->count(), 'la suya y la del Access, en la misma ficha');
    }

    public function test_sumar_dos_veces_no_duplica(): void
    {
        $this->sistemaEnUso();

        $this->sumar('migracion-completa')->assertSuccessful();
        $despues = $this->conteos();

        $this->sumar('migracion-completa')->assertSuccessful();

        $this->assertSame($despues, $this->conteos());
    }

    public function test_sumar_en_modo_informe_no_escribe_nada(): void
    {
        $this->sistemaEnUso();
        $antes = $this->conteos();

        $this->sumar('migracion-completa', aplicar: false)
            ->expectsOutputToContain('empresas nuevas')
            ->assertSuccessful();

        $this->assertSame($antes, $this->conteos());
        $this->assertNull(Empresa::where('nombre', 'TALLERES SUR SA')->value('codigo_indice'));
    }

    /**
     * Si el Access nuevo se renumero, no se suma nada.
     *
     * El ID es por donde se reconoce todo. Si en el nuevo el 101 ya no es
     * Ferrum, sumar colgaria las cotizaciones de una empresa en otra.
     */
    public function test_sumar_no_corre_si_el_access_se_renumero(): void
    {
        $this->sistemaEnUso();
        $antes = $this->conteos();

        $this->sumar('migracion-renumerada')->assertFailed();

        $this->assertSame($antes, $this->conteos());
    }

    /**
     * El indice completo entra con los catalogos como los dejo la limpieza.
     *
     * Sin esto volvian Buenos Aires escrita de otra forma, el codigo postal
     * en el pais, el rubro "??" y la unidad C/U que se apago por repetida.
     */
    public function test_sumar_entra_con_los_catalogos_limpios(): void
    {
        $this->sistemaEnUso();
        Unidad::where('codigo', 'C/U')->update(['activo' => false]);
        $ferrum = Empresa::where('codigo_indice', '101')->first();

        $this->sumar('migracion-completa')->assertSuccessful();

        $metales = Empresa::where('codigo_indice', '106')->first();
        $this->assertSame($ferrum->provincia_id, $metales->provincia_id, '"BS. AS." es la Buenos Aires que ya estaba');
        $this->assertNull($metales->rubro_id, '"??" no es un rubro');

        $torneria = Empresa::where('codigo_indice', '107')->first();
        $this->assertSame('B1646GEL', $torneria->codigo_postal);
        $this->assertSame('Argentina', $torneria->pais->nombre);
        $this->assertSame($ferrum->localidad_id, $torneria->localidad_id, 'la unica AVELLANEDA del pais');
        $this->assertSame($ferrum->provincia_id, $torneria->provincia_id);
        $this->assertSame($ferrum->rubro_id, $torneria->rubro_id, '"sanitarios" es SANITARIOS');

        $this->assertSame(0, Pais::where('nombre', 'like', 'B1646%')->count());
        $this->assertSame(0, Provincia::where('nombre', 'Sin determinar')->count());

        $linea = ConsultaLinea::where('descripcion', 'like', '%BRIDA SO%')->first();
        $this->assertSame(Unidad::where('codigo', 'UN')->value('id'), $linea->unidad_venta_id);
    }

    /** Lo historico ya salio: no queda como borrador para editar y numerar. */
    public function test_sumar_trae_las_cotizaciones_ya_emitidas(): void
    {
        $this->sistemaEnUso();

        $this->sumar('migracion-completa')->assertSuccessful();

        $cotizacion = Consulta::where('texto', 'like', 'BARRA INOX 304%')->first();
        $this->assertTrue($cotizacion->estaEmitida());
        $this->assertSame('2026-10-15', substr((string) $cotizacion->emitida_el, 0, 10));
        $this->assertNotNull($cotizacion->origen_clave);

        $observacion = Consulta::where('texto', 'INTERESADOS EN BRIDAS')->first();
        $this->assertNull($observacion->emitida_el);
    }

    /**
     * Una cotizacion corregida en el sistema nuevo no vuelve a entrar.
     *
     * Se la reconoce por la fila del Access, que no cambia, y no por como esta
     * hoy: la propia importacion pide corregir a mano las fechas imposibles.
     */
    public function test_sumar_no_vuelve_a_traer_lo_que_corrigieron(): void
    {
        $this->sistemaEnUso();
        $this->guardarOriginales('migracion');
        // Como en el servidor: las 8048 entraron antes de que existiera la huella.
        Consulta::query()->update(['origen_clave' => null]);

        Consulta::where('texto', 'CHAPA 3MM   77')->update(['fecha' => '2024-01-10']);
        Consulta::where('texto', 'like', 'FLETE AEREO%')->update(['nota' => DB::raw("CONCAT('Cliente pide recotizar. ', nota)")]);
        $antes = $this->conteos();

        $this->sumar('migracion')->assertSuccessful();
        $this->assertSame($antes, $this->conteos());

        // Y lo que entra con --sumar, aunque lo corrijan, tampoco.
        $this->sumar('migracion-completa')->assertSuccessful();
        Consulta::where('texto', 'like', 'BARRA INOX 304%')->update(['texto' => 'BARRA INOX 304 1 PULG (corregida)', 'fecha' => '2026-10-16']);
        $despues = $this->conteos();

        $this->sumar('migracion-completa')->assertSuccessful();
        $this->assertSame($despues, $this->conteos());
    }

    /**
     * Mismo ID, otra empresa: no se le cuelga a una ficha el historial de otra.
     *
     * Pasa si alguien tipeo mal el "ID del indice" de una ficha, o si el
     * Access reuso un numero. Por debajo del umbral de renumeracion no frena
     * todo: saltea esa ficha y sus cotizaciones, y lista el ID.
     */
    public function test_mismo_id_con_otro_nombre_no_se_mezcla(): void
    {
        $this->sistemaEnUso();

        foreach (range(301, 306) as $id) {
            Empresa::create(['nombre' => "CLIENTE {$id}", 'codigo_indice' => (string) $id, 'activa' => true]);
        }

        $ypf = Empresa::where('codigo_indice', '999')->first();
        $suyas = $ypf->consultas()->count();

        $carpeta = sys_get_temp_dir().'/indice-'.uniqid();
        mkdir($carpeta);
        $cabecera = 'EMPRESA,RESPONS,DIRECCION,LOCALIDAD,CODPOSTAL,PROVINCIA,PAIS,TELEF1,TELEF2,TELEF3,TELEF4,TELEF5,FAX,TELEX,TELEDISC,OBSERV,HORARIOS,RUBRO,RELAC,ID';
        $filas = ['FERRUM S.A.,,,,,,,,,,,,,,,,,,,101', 'FEDERAL EXPRESS,,,,,,,,,,,,,,,,,,,102', 'FEDERAL EXPRESS,,,,,,,,,,,,,,,,,,,103'];

        foreach (range(301, 306) as $id) {
            $filas[] = "CLIENTE {$id},,,,,,,,,,,,,,,,,,,{$id}";
        }

        $filas[] = 'ABRASIVOS DEL PLATA,,,,,,,,,,,,,,,,,,,999';
        file_put_contents("{$carpeta}/empresas.csv", $cabecera."\n".implode("\n", $filas)."\n");
        file_put_contents("{$carpeta}/cotizaciones.csv",
            "EMPRESA,CRESP,DOLAR,PE1,PE2,PE3,PE4,PE5,PR1,PR2,PR3,PR4,PR5,MON,FECHA,NOTA,NROFACT,ID\n"
            ."ABRASIVOS DEL PLATA,,0,DISCO DE CORTE,,,,,10,0,0,0,0,PESOS,09/01/26,,0,999\n");

        $this->artisan('importar:sistema-viejo', ['--carpeta' => $carpeta, '--sumar' => true, '--aplicar' => true])
            ->expectsOutputToContain('Mismo ID y otro nombre: 999')
            ->assertSuccessful();

        $this->assertSame($suyas, $ypf->consultas()->count(), 'el disco de corte no va a la ficha de YPF');
        $this->assertSame(0, Consulta::where('texto', 'like', 'DISCO DE CORTE%')->count());
        $this->assertSame(1, Empresa::where('codigo_indice', '999')->count());

        array_map('unlink', glob("{$carpeta}/*"));
        rmdir($carpeta);
    }

    /** Las filas del Access tal cual, en la base: como quedaron en el servidor. */
    private function guardarOriginales(string $carpeta): void
    {
        foreach (['empresas.csv', 'cotizaciones.csv'] as $archivo) {
            foreach (OriginalesDelAccess::delArchivo(base_path("tests/Fixtures/{$carpeta}/{$archivo}")) as $i => $fila) {
                DB::table('migracion_originales')->insert([
                    'archivo' => $archivo,
                    'fila' => $i,
                    'datos' => json_encode($fila, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
    }

    /**
     * Una carpeta con un empresas.csv y un cotizaciones.csv armados en la prueba.
     *
     * @param  list<string>  $empresas  filas con EMPRESA,PAIS,PROVINCIA,LOCALIDAD,ID
     * @param  list<string>  $cotizaciones  filas con EMPRESA,PE1,PR1,FECHA,ID
     */
    private function indice(array $empresas, array $cotizaciones): string
    {
        $carpeta = sys_get_temp_dir().'/indice-'.uniqid();
        mkdir($carpeta);
        file_put_contents("{$carpeta}/empresas.csv", "EMPRESA,PAIS,PROVINCIA,LOCALIDAD,ID\n".implode("\n", $empresas)."\n");
        file_put_contents("{$carpeta}/cotizaciones.csv", "EMPRESA,PE1,PR1,FECHA,ID\n".implode("\n", $cotizaciones)."\n");
        $this->beforeApplicationDestroyed(function () use ($carpeta) {
            array_map('unlink', glob("{$carpeta}/*"));
            rmdir($carpeta);
        });

        return $carpeta;
    }

    /**
     * La columna PAIS del indice completo tiene de todo: solo entran paises.
     *
     * Codigos postales, provincias, internos y nombres sueltos no se vuelven
     * paises: el CP va al CP, la provincia a la provincia, y lo demas queda
     * escrito en la ficha.
     */
    public function test_sumar_solo_crea_paises_y_provincias_que_existen(): void
    {
        $this->sistemaEnUso();
        $provincias = Provincia::count();

        $carpeta = $this->indice([
            'ALFA,USA 07054,,,401',
            'BETA,Cordoba,,,402',
            'GAMA,OSCAR,,,403',
            'DELTA,,PCIA. BS. AS.,,404',
            'EPSILON,,ROSARIO,,405',
            'ZETA,B7608v,,,406',
            // Una provincia que no se reconoce no se deduce de la localidad:
            // AVELLANEDA es de Buenos Aires, y no por eso GRAN ROSARIO lo es.
            'ETA,,GRAN ROSARIO,AVELLANEDA,407',
            'THETA,,,CAPITAL FEDERAL,408',
            'IOTA,Cordoba,-,,409',
            'KAPPA,Cordoba,X5000ABC,,410',
            'LAMBDA,Tucuman,S.M. DE TUCUMAN,,411',
            'MU,Cordoba,CAPITAL,,412',
            'NU,,BUENOS AIRES CAPITAL FEDERAL,,413',
            'XI,,SANTA CRUZ DE LA SIERRA,,414',
            'OMICRON,,PCIA. DE CORDOBA,SANTA ROSA,415',
            'PI,,BUENSO AIRES,,416',
            'RHO,,CHILE,SANTIAGO,417',
            'SIGMA,,ARGENTINA,,418',
        ], []);

        $this->artisan('importar:sistema-viejo', ['--carpeta' => $carpeta, '--sumar' => true, '--aplicar' => true])
            ->expectsOutputToContain('Pais sin reconocer (1 distintos): OSCAR (1)')
            ->assertSuccessful();

        $ficha = fn (string $id) => Empresa::where('codigo_indice', $id)->first();

        $this->assertSame('Estados Unidos', $ficha('401')->pais->nombre);
        $this->assertSame('Argentina', $ficha('402')->pais->nombre);
        $this->assertSame('Córdoba', $ficha('402')->provincia->nombre, 'la provincia cargada en el pais');
        $this->assertSame('Argentina', $ficha('403')->pais->nombre);
        $this->assertStringContainsString('Pais en el indice viejo: OSCAR', $ficha('403')->observacion_general);
        $this->assertSame('Buenos Aires', $ficha('404')->provincia->nombre);
        $this->assertNull($ficha('405')->provincia_id, 'ROSARIO no es una provincia');
        $this->assertStringContainsString('Provincia en el indice viejo: ROSARIO', $ficha('405')->observacion_general);
        $this->assertSame('B7608V', $ficha('406')->codigo_postal);

        $this->assertNull($ficha('407')->provincia_id);
        $this->assertNull($ficha('407')->localidad_id);
        $this->assertStringContainsString('Provincia en el indice viejo: GRAN ROSARIO', $ficha('407')->observacion_general);
        $this->assertStringContainsString('Localidad en el indice viejo: AVELLANEDA', $ficha('407')->observacion_general);

        $caba = 'Ciudad Autónoma de Buenos Aires';
        $this->assertSame($caba, $ficha('408')->provincia->nombre, 'CAPITAL FEDERAL es la ciudad, no la provincia');
        $this->assertSame('Córdoba', $ficha('409')->provincia->nombre, 'el "-" no le gana a la provincia del pais');
        $this->assertSame('Córdoba', $ficha('410')->provincia->nombre);
        $this->assertSame('X5000ABC', $ficha('410')->codigo_postal);
        $this->assertSame('Tucumán', $ficha('411')->provincia->nombre);
        $this->assertStringContainsString('Provincia en el indice viejo: S.M. DE TUCUMAN', $ficha('411')->observacion_general);
        $this->assertSame('Córdoba', $ficha('412')->provincia->nombre, '"CAPITAL" a secas no le gana a Cordoba');
        $this->assertSame($caba, $ficha('413')->provincia->nombre);
        $this->assertStringContainsString('Provincia en el indice viejo: BUENOS AIRES CAPITAL FEDERAL', $ficha('413')->observacion_general);
        $this->assertNull($ficha('414')->provincia_id, 'Santa Cruz de la Sierra es Bolivia');
        $this->assertStringContainsString('SANTA CRUZ DE LA SIERRA', $ficha('414')->observacion_general);
        $this->assertSame('Córdoba', $ficha('415')->provincia->nombre);
        $this->assertSame('SANTA ROSA', $ficha('415')->localidad->nombre);
        $this->assertSame($ficha('415')->provincia_id, $ficha('415')->localidad->provincia_id);
        $this->assertSame('Buenos Aires', $ficha('416')->provincia->nombre, 'una letra cambiada');
        $this->assertStringContainsString('Provincia en el indice viejo: BUENSO AIRES', $ficha('416')->observacion_general);
        $this->assertSame('Chile', $ficha('417')->pais->nombre, 'el pais escrito en la provincia');
        $this->assertNotSame('Argentina', $ficha('417')->pais->nombre);
        $this->assertNull($ficha('418')->provincia_id);
        $this->assertNull($ficha('418')->observacion_general, '"ARGENTINA" en la provincia no es una provincia ni un error');

        $this->assertSame(0, Pais::whereIn('nombre', ['Oscar', 'OSCAR', 'B7608v', 'Cordoba'])->count());
        $this->assertSame(0, Provincia::whereIn('nombre', ['GRAN ROSARIO', 'SANTA CRUZ DE LA SIERRA', 'S.M. DE TUCUMAN', 'PCIA. DE CORDOBA'])->count());
        $this->assertSame(1, Provincia::where('nombre', $caba)->count());
    }

    /**
     * Las fechas de ocho cifras del sistema viejo se leen, y lo que ya entro
     * en septiembre con la fecha bien escrita se reconoce igual.
     */
    public function test_sumar_lee_las_fechas_de_ocho_cifras(): void
    {
        $this->sistemaEnUso();
        $this->guardarOriginales('migracion');
        Consulta::query()->update(['origen_clave' => null]);
        $antes = Consulta::count();

        $carpeta = $this->indice(['ALFA,,,,401'], []);

        // Una de septiembre (07/24/26) que ahora viene como 20260724.
        $nitinol = 'FERRUM S.A.,Juan Perez,1000,NITINOL #5 ALAMBRE 2MM EN ROLLO RECOCIDO,RECTO COLOR OXIDO TEMP AF 85C,PEDIDO MINIMO 130 METROS X M,110 DIAS,,0,0,41.7,0,0,DOLAR BILLETE,20260724,Pedido por mail,0,101';
        file_put_contents("{$carpeta}/cotizaciones.csv",
            "EMPRESA,CRESP,DOLAR,PE1,PE2,PE3,PE4,PE5,PR1,PR2,PR3,PR4,PR5,MON,FECHA,NOTA,NROFACT,ID\n"
            ."ALFA,,0,BARRA OCHO CIFRAS,,,,,10,0,0,0,0,PESOS,20240315,,0,401\n"
            ."ALFA,,0,CHAPA OCHO CIFRAS,,,,,20,0,0,0,0,PESOS,15032024,,0,401\n"
            ."ALFA,,0,TUBO SEIS CIFRAS,,,,,30,0,0,0,0,PESOS,999999,,0,401\n"
            ."ALFA,,0,FUTURO,,,,,40,0,0,0,0,PESOS,20991231,,0,401\n"
            .$nitinol."\n");

        $this->artisan('importar:sistema-viejo', ['--carpeta' => $carpeta, '--sumar' => true, '--aplicar' => true])
            ->assertSuccessful();

        $this->assertSame($antes + 4, Consulta::count(), 'el NITINOL de septiembre no vuelve a entrar');
        $this->assertSame('2024-03-15', substr((string) Consulta::where('texto', 'like', 'BARRA OCHO%')->value('fecha'), 0, 10));
        $this->assertSame('2024-03-15', substr((string) Consulta::where('texto', 'like', 'CHAPA OCHO%')->value('fecha'), 0, 10));
        $tubo = Consulta::where('texto', 'like', 'TUBO SEIS%')->first();
        $this->assertSame('1900-01-01', substr((string) $tubo->fecha, 0, 10));
        $this->assertStringContainsString('figura 999999, que no se puede leer', $tubo->nota, 'lo que no se lee no se pierde');

        // La huella lleva la fecha escrita y no la guardada (1900-01-01):
        // no cambia segun el dia en que se corra la suma.
        $futuro = Consulta::where('texto', 'like', 'FUTURO%')->first();
        $this->assertSame('1900-01-01', substr((string) $futuro->fecha, 0, 10));
        $this->assertSame(md5('401|2099-12-31|'.md5('FUTURO   40')), $futuro->origen_clave);
    }

    /**
     * La cotizacion de septiembre escrita distinto no se reconoce, y el
     * informe dice en que caracteres se diferencia, sin mostrar el texto.
     */
    public function test_el_informe_dice_que_caracteres_cambiaron(): void
    {
        $this->sistemaEnUso();
        $this->guardarOriginales('migracion');
        Consulta::query()->update(['origen_clave' => null]);

        $carpeta = $this->indice(['FERRUM S.A.,,,,101'], []);
        file_put_contents("{$carpeta}/cotizaciones.csv",
            "EMPRESA,CRESP,DOLAR,PE1,PE2,PE3,PE4,PE5,PR1,PR2,PR3,PR4,PR5,MON,FECHA,NOTA,NROFACT,ID\n"
            .'FERRUM S.A.,Juan Perez,1000,NITINOL Nº5 ALAMBRE 2MM EN ROLLO RECOCIDO,RECTO COLOR OXIDO TEMP AF 85C,PEDIDO MINIMO 130 METROS X M,110 DIAS,,0,0,41.7,0,0,DOLAR BILLETE,20260724,Pedido por mail,0,101'."\n");

        $this->artisan('importar:sistema-viejo', ['--carpeta' => $carpeta, '--sumar' => true])
            ->expectsOutputToContain('(mirar)')
            ->expectsOutputToContain('º U+00BA (1)')
            ->expectsOutputToContain('# U+0023 (1)')
            ->doesntExpectOutputToContain('ALAMBRE')
            ->assertSuccessful();
    }

    /**
     * El Access completo trae el texto de DOS leido como Windows.
     *
     * "BAR í 1.6" es la "BAR Ý 1.6" de septiembre (la Ø del sistema viejo) y
     * "PE¥A" es PEÑA. Con --desde-dos se traduce: la de septiembre se
     * reconoce y no entra dos veces, y la ficha nueva queda bien escrita.
     */
    public function test_el_texto_de_dos_leido_como_windows_se_traduce(): void
    {
        $this->sistemaEnUso();
        $this->guardarOriginales('migracion');
        Consulta::query()->update(['origen_clave' => null]);

        $carpeta = $this->indice(['PE¥A HNOS,,,,420'], []);
        file_put_contents("{$carpeta}/cotizaciones.csv",
            "EMPRESA,CRESP,DOLAR,PE1,PE2,PE3,PE4,PE5,PR1,PR2,PR3,PR4,PR5,MON,FECHA,NOTA,NROFACT,ID\n"
            ."FERRUM S.A.,,0,BAR í 1.6 X 915MM,,,,,250,0,0,0,0,PESOS,19301204,,0,101\n");

        // Sin traducir no se reconoce: entraria otra vez.
        $this->artisan('importar:sistema-viejo', ['--carpeta' => $carpeta, '--sumar' => true])
            ->expectsOutputToContain('(mirar)')
            ->assertSuccessful();

        $antes = Consulta::count();

        $this->artisan('importar:sistema-viejo', ['--carpeta' => $carpeta, '--sumar' => true, '--desde-dos' => true, '--aplicar' => true])
            ->doesntExpectOutputToContain('(mirar)')
            ->assertSuccessful();

        $this->assertSame($antes, Consulta::count(), 'la BAR Ø de septiembre no vuelve a entrar');
        $this->assertSame('PEÑA HNOS', Empresa::where('codigo_indice', '420')->value('nombre'));
    }

    /**
     * El ID que el sistema viejo uso tambien para otra empresa.
     *
     * En septiembre YPF S.A. llego solo en una cotizacion con el ID 999, y se
     * creo su ficha con ese ID. En el indice completo el 999 es ABRASIVOS DEL
     * PLATA. No es otra numeracion: entra ABRASIVOS, y cada cotizacion va a
     * la empresa que nombra.
     */
    public function test_el_id_que_el_sistema_viejo_uso_para_dos_empresas_se_separa(): void
    {
        $this->sistemaEnUso();
        $this->guardarOriginales('migracion');
        Consulta::query()->update(['origen_clave' => null]);
        $ypf = Empresa::where('nombre', 'YPF S.A.')->first();
        $deYpf = $ypf->consultas()->count();

        $carpeta = $this->indice(['FERRUM S.A.,,,,101', 'ABRASIVOS DEL PLATA,,,,999'], []);
        file_put_contents("{$carpeta}/cotizaciones.csv",
            "EMPRESA,CRESP,DOLAR,PE1,PE2,PE3,PE4,PE5,PR1,PR2,PR3,PR4,PR5,MON,FECHA,NOTA,NROFACT,ID\n"
            ."YPF S.A.,,0,CAÑO SCH 40,,,,,100,0,0,0,0,PESOS,20240315,,1234,999\n"
            ."YPF S.A.,,0,BRIDA SO 4 PULG,,,,,300,0,0,0,0,PESOS,20240916,,0,999\n"
            ."ABRASIVOS DEL PLATA,,0,DISCO DE CORTE,,,,,10,0,0,0,0,PESOS,20240901,,0,999\n");

        $this->artisan('importar:sistema-viejo', ['--carpeta' => $carpeta, '--sumar' => true, '--aplicar' => true])
            ->doesntExpectOutputToContain('Mismo ID y otro nombre')
            ->assertSuccessful();

        $abrasivos = Empresa::where('nombre', 'ABRASIVOS DEL PLATA')->first();
        $this->assertNotNull($abrasivos, 'la empresa del Access entra');
        $this->assertSame('999', $abrasivos->codigo_indice);
        $this->assertSame(['DISCO DE CORTE   10'], $abrasivos->consultas()->pluck('texto')->all());
        $this->assertSame($deYpf + 1, $ypf->consultas()->count(), 'la brida es de YPF; el caño ya estaba');

        // Y otra vez lo mismo no cambia nada.
        $despues = $this->conteos();
        $this->artisan('importar:sistema-viejo', ['--carpeta' => $carpeta, '--sumar' => true, '--aplicar' => true])->assertSuccessful();
        $this->assertSame($despues, $this->conteos());
    }

    /** La fila sin nombre que trae el ID de su empresa se cuelga por el ID; la vacia no entra. */
    public function test_la_cotizacion_sin_nombre_se_cuelga_por_el_id(): void
    {
        $this->sistemaEnUso();
        $ferrum = Empresa::where('codigo_indice', '101')->first();
        $deFerrum = $ferrum->consultas()->count();
        $antes = Consulta::count();

        $carpeta = $this->indice(['FERRUM S.A.,,,,101'], []);
        file_put_contents("{$carpeta}/cotizaciones.csv",
            "EMPRESA,CRESP,DOLAR,PE1,PE2,PE3,PE4,PE5,PR1,PR2,PR3,PR4,PR5,MON,FECHA,NOTA,NROFACT,ID\n"
            .",,0,TUBO SIN NOMBRE,,,,,45,0,0,0,0,PESOS,20240920,,0,101\n"
            .",,,,,,,,,,,,,,,,,\n"
            .",,0,DE UNA QUE NO ESTA,,,,,5,0,0,0,0,PESOS,20240920,,0,55555\n");

        $this->artisan('importar:sistema-viejo', ['--carpeta' => $carpeta, '--sumar' => true, '--aplicar' => true])
            ->expectsOutputToContain('filas vacias del Access (no entran)')
            ->expectsOutputToContain('sin nombre y con un ID que no esta (no entran)')
            ->assertSuccessful();

        $this->assertSame($deFerrum + 1, $ferrum->consultas()->count());
        $this->assertSame($antes + 1, Consulta::count());
        $this->assertSame(0, Empresa::whereNull('nombre')->orWhere('nombre', '')->count());
    }
}

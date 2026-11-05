<?php

namespace Tests\Feature;

use App\Models\CanoEstandar;
use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Contacto;
use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Moneda;
use App\Models\Permiso;
use App\Models\Unidad;
use App\Models\User;
use App\Services\Buscador\Catalogo;
use App\Services\Cordes\Cordes;
use App\Services\Cordes\Entiende;
use App\Services\Cordes\Medidas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El motor de Cordes: entiende y contesta con el código, SIN IA. Cada prueba apaga la IA (y verifica que no se la llame):
 * lo que se prueba es que el sistema, por sí solo, contesta bien y no inventa. Nombres ficticios: el repo es público.
 */
class CordesMotorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->admin = User::factory()->create(['role' => 'Administrador']);
        Sanctum::actingAs($this->admin);
        config(['services.openai.key' => '']);
        Http::fake();
    }

    /* ----------------------------------------------------------------- datos */

    private function forma(string $nombre, array $campos, string $habituales, ?string $expresion, bool $cano = false): Forma
    {
        return Forma::updateOrCreate(['nombre' => $nombre], [
            'campos' => array_map(fn ($c) => ['clave' => $c[0], 'label' => $c[1]], $campos), 'medidas_habituales' => $habituales,
            'expresion' => $expresion, 'activo' => true, 'usa_cano' => $cano,
        ]);
    }

    private function formas(): void
    {
        $this->forma('BARRA REDONDA', [['diameter', 'Diametro'], ['length', 'Largo']], 'Diametro x largo', '(pi * pow(diameter, 2) / 4 * length) / 1000');
        $this->forma('CHAPA', [['width', 'Ancho'], ['height', 'Espesor'], ['length', 'Largo']], 'Espesor x ancho x largo', '(width * height * length) / 1000');
        $this->forma('CAÑO', [['outer', 'Diametro exterior'], ['wall', 'Espesor de pared'], ['length', 'Largo']], 'Diametro nominal + SCH + largo', '(pi * wall * (outer - wall) * length) / 1000', true);
        $this->forma('BRIDA', [], 'Diametro nominal + norma', null);
    }

    private function material(string $nombre, ?float $densidad, array $alias = [], string $familia = 'Níquel y sus Aleaciones'): Material
    {
        $m = Material::create(['nombre' => $nombre, 'familia' => $familia, 'densidad' => $densidad, 'activo' => true, 'uns' => 'N04400']);

        foreach ($alias as $a) {
            MaterialAlias::create(['material_id' => $m->id, 'alias' => $a]);
        }

        return $m;
    }

    private function canos(): void
    {
        foreach ([['2"', '40', 60.3, 3.91], ['2"', '80', 60.3, 5.54], ['2"', 'STD', 60.3, 3.91], ['3"', '40', 88.9, 5.49]] as $i => [$n, $s, $od, $p]) {
            CanoEstandar::create(['nombre' => $n, 'schedule' => $s, 'diametro_mm' => $od, 'pared_mm' => $p, 'orden' => $i, 'activo' => true]);
        }
    }

    private function moneda(string $nombre = 'DOLAR BILLETE BNA VENDEDOR'): Moneda
    {
        return Moneda::firstOrCreate(['nombre' => $nombre], ['moneda_base' => false, 'activo' => true]);
    }

    private function cotizar(Empresa $e, ?Material $m, ?Forma $f, float $precio, string $fecha = '2026-05-01', string $estado = 'Confirmada'): ConsultaLinea
    {
        static $orden = 0;
        $unidad = Unidad::firstOrCreate(['codigo' => 'UN'], ['nombre' => 'Unidad', 'activo' => true]);
        $c = Consulta::create(['empresa_id' => $e->id, 'tipo' => 'Cotizacion', 'fecha' => $fecha, 'estado' => $estado, 'usuario_id' => $this->admin->id, 'moneda_id' => $this->moneda()->id]);

        return ConsultaLinea::create(['consulta_id' => $c->id, 'orden' => ++$orden, 'material_id' => $m?->id, 'forma_id' => $f?->id, 'descripcion' => 'RENGLON',
            'cantidad' => 2, 'unidad_venta_id' => $unidad->id, 'precio_unitario' => $precio, 'importe' => $precio * 2]);
    }

    /** @return array<string, mixed> */
    private function preguntar(string $texto, ?User $u = null): array
    {
        return (new Cordes($u ?? $this->admin))->responder($texto);
    }

    private function tablaDe(array $r, string $titulo): array
    {
        return collect($r['tablas'])->firstWhere('titulo', $titulo) ?? $this->fail("No hay una tabla «{$titulo}»: ".json_encode(array_column($r['tablas'], 'titulo')));
    }

    /* ---------------------------------------------------------------- Medidas */

    public function test_las_medidas_se_leen_en_el_orden_en_que_se_escriben_no_en_el_del_formulario(): void
    {
        $this->formas();
        $chapa = Forma::where('nombre', 'CHAPA')->first();

        // El formulario de la chapa pide ancho, espesor, largo; escrita es «espesor x ancho x largo».
        $r = Medidas::leer(Medidas::limpiar('chapa de 2 x 1000 x 2000'), $chapa);

        $this->assertSame(2.0, $r['medidas']['height']['valor']);
        $this->assertSame(1000.0, $r['medidas']['width']['valor']);
        $this->assertSame(2000.0, $r['medidas']['length']['valor']);
    }

    public function test_las_medidas_entienden_las_formas_de_escribirlas(): void
    {
        $this->formas();
        $barra = Forma::where('nombre', 'BARRA REDONDA')->first();
        $casos = [
            'barra de 38,1x145' => [38.1, 145.0, 'mm'], 'barra Ø20 de diámetro y 1 metro de largo' => [20.0, 1.0, 'm'],
            'barra diametro 25.4 largo 3 m' => [25.4, 3.0, 'm'], 'barra de 1 1/2 pulgadas x 6 metros' => [1.5, 6.0, 'm'],
            'barra de 1.500 mm de largo y 20 de diametro' => [20.0, 1500.0, 'mm'],
        ];

        foreach ($casos as $texto => [$diametro, $largo, $unidadDelLargo]) {
            $r = Medidas::leer(Medidas::limpiar($texto), $barra);

            $this->assertSame($diametro, $r['medidas']['diameter']['valor'], $texto);
            $this->assertSame($largo, $r['medidas']['length']['valor'], $texto);
            $this->assertSame($unidadDelLargo, $r['medidas']['length']['unidad'], $texto);
        }
    }

    public function test_los_largos_aleatorios_se_leen_como_un_rango_con_su_promedio(): void
    {
        $this->formas();
        $barra = Forma::where('nombre', 'BARRA REDONDA')->first();

        $r = Medidas::leer(Medidas::limpiar('barra de 38,1 mm con largos aleatorios de 3 a 6 metros'), $barra);
        $this->assertSame(['min' => 3.0, 'max' => 6.0, 'unidad' => 'm', 'promedio' => 4.5], $r['largo_variable']);
        $this->assertSame(4.5, $r['medidas']['length']['valor']);

        $r = Medidas::leer(Medidas::limpiar('barra de 20 mm largo 3200-3500 mm'), $barra);
        $this->assertSame(3350.0, $r['medidas']['length']['valor']);
    }

    public function test_un_numero_seguido_de_barra_es_cantidad_sólo_si_no_es_parte_de_las_medidas(): void
    {
        $this->formas();
        $barra = Forma::where('nombre', 'BARRA REDONDA')->first();

        $this->assertSame(5.0, Medidas::leer(Medidas::limpiar('5 barras de 20 x 1000 mm'), $barra)['piezas']);

        // «145 barra» es el largo, no 145 piezas.
        $r = Medidas::leer(Medidas::limpiar('38,1 x 145 barra monel'), $barra);
        $this->assertNull($r['piezas']);
        $this->assertSame(145.0, $r['medidas']['length']['valor']);
    }

    public function test_un_largo_chico_sin_unidad_se_toma_en_metros_y_se_avisa(): void
    {
        $this->formas();
        $r = Medidas::leer(Medidas::limpiar('barra 20 de diametro por 3 de largo'), Forma::where('nombre', 'BARRA REDONDA')->first());

        $this->assertSame(['valor' => 3.0, 'unidad' => 'm'], $r['medidas']['length']);
        $this->assertSame(['length' => 'm'], $r['asumidas']);
    }

    public function test_el_cano_se_lee_por_pulgadas_dn_y_schedule(): void
    {
        $casos = [
            'caño de 2 pulgadas schedule 40 de 6 metros' => ['2"', '40', 6.0], 'cano de 2 1/2" sch 80' => ['2 1/2"', '80', null],
            'tubo DN50 sch 40s' => ['2"', '40S', null], 'caño de 3/4 pulgada STD de 2 m' => ['3/4"', 'STD', 2.0], 'caño 2.5" xs' => ['2 1/2"', 'XS', null],
        ];

        foreach ($casos as $texto => [$nominal, $schedule, $metros]) {
            $c = Medidas::cano(Medidas::limpiar($texto));
            $this->assertSame([$nominal, $schedule, $metros], [$c['nominal'], $c['schedule'], $c['metros']], $texto);
        }
    }

    /* ---------------------------------------------------------------- Entiende */

    public function test_entiende_que_se_pregunta(): void
    {
        $this->formas();
        $this->material('Monel 400', 8.8, ['MONEL']);
        $e = new Entiende(new Catalogo);
        $casos = [
            'hola' => 'ayuda', 'cuánto pesa una barra de monel de 38,1 x 145 mm' => 'peso', 'peso barra monel 38.1x145' => 'peso', 'cuánto pesa un caño dn50 sch 80 de monel' => 'peso',
            'tabla de schedule 40' => 'cano', 'schedules del caño de 2 1/2 pulgadas' => 'cano', 'fórmula de la barra redonda' => 'formula', 'cómo se calcula el peso de una chapa' => 'formula',
            'medidas de la barra redonda' => 'formula', 'a cuánto cotizamos monel en barra' => 'precio', 'densidad del monel' => 'material', 'cuántos mm son 1 1/2 pulgadas' => 'convertir',
            'pasar 12 kg a libras' => 'convertir', 'cuánto es 1059,84 + 365,52' => 'calcular', '20% de 450' => 'calcular', 'contame de acme' => 'empresa', 'teléfono de acme' => 'empresa',
            'cotización 2026-0741' => 'cotizacion', 'qué empresas más compran monel' => 'ranking', 'cuántas cotizaciones hicimos por mes' => 'agrupar', 'qué formas hay' => 'catalogo',
            'qué materiales hay de titanio' => 'catalogo',
        ];

        foreach ($casos as $texto => $intencion) {
            $this->assertSame($intencion, $e->plan($texto)['intencion'] ?? null, $texto);
        }

        // Lo que no sabe, no lo adivina: va a las reglas o a la IA.
        $this->assertNull($e->plan('cotizaciones sin respuesta de este mes'));
        $this->assertNull($e->plan('qué opinás del monel para agua de mar'));
    }

    public function test_los_numeros_no_se_confunden_con_codigos_de_material(): void
    {
        $this->formas();
        $this->material('Monel 400', 8.8, ['MONEL']);
        $this->material('Acero Inoxidable', 7.8, ['ACERO INOXIDABLE']);
        $this->material('Acero AISI 304', 8.0, ['AISI 304']);

        $p = (new Entiende(new Catalogo))->plan('cuánto pesa una barra de monel de 38,1 x 145 mm');
        $this->assertSame('Monel 400', $p['datos']['material']);
        $this->assertSame(145.0, $p['datos']['medidas']['length']['valor']);

        // «acero inoxidable 304» es el 304, no el acero inoxidable en general.
        $p = (new Entiende(new Catalogo))->plan('cuánto pesa una chapa de acero inoxidable 304 de 2 x 1000 x 2000');
        $this->assertSame('Acero AISI 304', $p['datos']['material']);
        $this->assertSame(2.0, $p['datos']['medidas']['height']['valor']);
    }

    /* ----------------------------------------------------------------- Peso */

    public function test_el_peso_lo_contesta_el_codigo_con_la_formula_del_sistema(): void
    {
        $this->formas();
        $this->material('Monel 400', 8.8, ['MONEL']);

        $r = $this->preguntar('cuánto pesa una barra de monel de 38,1 x 145 mm');

        // pi * 38,1² / 4 * 145 / 1000 = 165,31 cm³ × 8,8 / 1000 = 1,4548 kg.
        $this->assertSame('codigo', $r['fuente']);
        $this->assertStringContainsString('**1,4548 kg**', $r['mensaje']);
        $this->assertStringContainsString('38,1 × 145 mm', $r['mensaje']);
        $this->assertSame('8,8000 g/cm³', collect($this->tablaDe($r, 'Cómo se calculó')['filas'])->firstWhere(0, 'Densidad')[1]);
        Http::assertNothingSent();
    }

    public function test_la_chapa_se_lee_en_su_orden_y_da_el_peso_correcto(): void
    {
        $this->formas();
        $this->material('Monel 400', 8.8, ['MONEL']);

        // 2 × 1000 × 2000 mm = 4000 cm³ × 8,8 / 1000 = 35,2 kg. (Con el orden del formulario daría otra cosa.)
        $this->assertStringContainsString('**35,2000 kg**', $this->preguntar('cuánto pesa una chapa de monel de 2 x 1000 x 2000')['mensaje']);
    }

    public function test_piezas_y_largo_variable(): void
    {
        $this->formas();
        $this->material('Monel 400', 8.8, ['MONEL']);

        $r = $this->preguntar('cuánto pesan 5 barras de monel de 20 x 1000');
        $this->assertStringContainsString('las 5 pesan **13,8230 kg**', $r['mensaje']);

        $r = $this->preguntar('cuánto pesa una barra de monel de 38,1 mm con largos aleatorios de 3 a 6 metros');
        $this->assertStringContainsString('largo promedio (4,5 m)', $r['mensaje']);
        $this->assertCount(3, $this->tablaDe($r, 'Según el largo')['filas']);
    }

    public function test_un_largo_sin_unidad_se_toma_en_metros_y_lo_dice(): void
    {
        $this->formas();
        $this->material('Monel 400', 8.8, ['MONEL']);

        $r = $this->preguntar('cuánto pesa una barra de monel 20 de diámetro por 3 de largo');

        $this->assertStringContainsString('20 mm × 3 m', $r['mensaje']);
        $this->assertStringContainsString('tomé el largo como **3 m**', $r['mensaje']);
    }

    public function test_si_falta_el_material_se_pide_con_opciones_de_los_mas_cotizados(): void
    {
        $this->formas();
        $monel = $this->material('Monel 400', 8.8, ['MONEL']);
        $sinDensidad = $this->material('Raro 1', null);
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizar($e, $monel, null, 10);
        $this->cotizar($e, $sinDensidad, null, 10);

        $r = $this->preguntar('cuánto pesa una barra de 20 x 1000');

        $this->assertStringContainsString('de qué material', $r['mensaje']);
        $this->assertSame(['Monel 400'], array_column($r['opciones']['opciones'], 'etiqueta'));
        $this->assertSame('cuánto pesa una barra de 20 x 1000 de Monel 400', $r['opciones']['opciones'][0]['enviar']);
    }

    public function test_si_falta_una_medida_dice_cuales_y_si_solo_falta_el_largo_da_el_peso_por_metro(): void
    {
        $this->formas();
        $this->material('Monel 400', 8.8, ['MONEL']);

        $r = $this->preguntar('cuánto pesa una chapa de monel de 2 x 1000');
        $this->assertStringContainsString('**Espesor × Ancho × Largo**', $r['mensaje']);

        $r = $this->preguntar('cuánto pesa una barra de monel de 20 mm');
        $this->assertStringContainsString('**2,7646 kg por metro**', $r['mensaje']);
    }

    public function test_sin_densidad_no_se_inventa_y_se_ofrecen_materiales_parecidos(): void
    {
        $this->formas();
        $this->material('Monel 400', 8.8, ['MONEL']);
        $this->material('Inconel Raro', null, ['INCONEL RARO']);

        $r = $this->preguntar('cuánto pesa una barra de inconel raro de 20 x 1000');

        $this->assertStringContainsString('no tengo cargada la densidad', $r['mensaje']);
        $this->assertStringNotContainsString(' kg**', $r['mensaje']);
        $this->assertSame(['Monel 400'], array_column($r['opciones']['opciones'], 'etiqueta'));
    }

    public function test_una_forma_sin_formula_no_calcula(): void
    {
        $this->formas();
        $this->material('Monel 400', 8.8, ['MONEL']);

        $r = $this->preguntar('cuánto pesa una brida de monel');

        $this->assertStringContainsString('no tiene una fórmula de peso cargada', $r['mensaje']);
    }

    /* ----------------------------------------------------------------- Caños */

    public function test_el_peso_de_un_cano_sale_de_la_tabla_y_la_formula(): void
    {
        $this->formas();
        $this->canos();
        $this->material('Titanio Grado 2', 4.51, ['TITANIO GRADO 2']);

        $r = $this->preguntar('cuánto pesa un caño de 2 pulgadas schedule 40 de titanio grado 2 de 6 metros');

        // Sección = pi/4 (60,3² - 52,48²) = 693,6 mm² × 4,51 = 3,128 kg/m; × 6 m = 18,74 kg con la fórmula del sistema.
        $this->assertStringContainsString('**3,124 kg por metro**', $r['mensaje']);
        $this->assertStringContainsString('**18,7438 kg**', $r['mensaje']);
        $this->assertStringContainsString('Ø exterior 60,3 mm, pared 3,91 mm', $r['mensaje']);
    }

    public function test_sin_schedule_se_ofrecen_los_que_existen_para_ese_diametro(): void
    {
        $this->formas();
        $this->canos();
        $this->material('Monel 400', 8.8, ['MONEL']);

        $r = $this->preguntar('cuánto pesa un caño de 2 pulgadas de monel');

        $this->assertSame(['SCH 40', 'SCH 80', 'SCH STD'], collect($r['opciones']['opciones'])->pluck('etiqueta')->sort()->values()->all());
        $this->assertCount(3, $this->tablaDe($r, 'Caños de la tabla · 2"')['filas']);
    }

    public function test_las_tablas_de_schedule_por_diametro_o_por_schedule(): void
    {
        $this->canos();

        $r = $this->preguntar('tabla de schedule 40');
        $this->assertStringContainsString('En **SCH 40** hay **2** diámetros', $r['mensaje']);

        $r = $this->preguntar('diámetro exterior y pared del caño de 3 pulgadas sch 40');
        $this->assertStringContainsString('Ø exterior **88,9 mm**, pared **5,49 mm**', $r['mensaje']);

        $r = $this->preguntar('qué schedules hay para dn50');
        $this->assertStringContainsString('**3 schedules**', $r['mensaje']);
    }

    /* --------------------------------------------------------- Fórmulas y catálogo */

    public function test_la_formula_se_explica_con_las_medidas_en_el_orden_en_que_se_escriben(): void
    {
        $this->formas();

        $r = $this->preguntar('cómo se calcula el peso de una chapa');

        $this->assertStringContainsString('se miden: **Espesor × Ancho × Largo**', $r['mensaje']);
        $this->assertStringContainsString('(Ancho × Espesor × Largo) ÷ 1000', $r['mensaje']);
    }

    public function test_el_material_se_explica_y_si_no_existe_sugiere(): void
    {
        $this->material('Monel 400', 8.8, ['MONEL']);
        $this->material('Titanio Grado 2', 4.51, []);

        $this->assertStringContainsString('densidad del **Monel 400** es **8,8000 g/cm³**', $this->preguntar('densidad del monel')['mensaje']);

        $r = $this->preguntar('densidad del titanyo');
        $this->assertStringContainsString('No hay un material', $r['mensaje']);
        $this->assertContains('Titanio Grado 2', array_column($r['opciones']['opciones'], 'etiqueta'));
    }

    public function test_el_catalogo_de_formas_y_de_materiales(): void
    {
        $this->formas();
        $this->material('Titanio Grado 2', 4.51, ['TITANIO']);
        $this->material('Titanio Grado 5', null, []);

        $r = $this->preguntar('qué formas hay');
        $this->assertStringContainsString('en **3** se puede calcular el peso', $r['mensaje']);

        $r = $this->preguntar('qué materiales hay de titanio');
        $this->assertStringContainsString('Hay **2** materiales', $r['mensaje']);
        $this->assertSame('Titanio Grado 2', $this->tablaDe($r, 'Materiales: titanio')['filas'][0][0]);
    }

    /* ---------------------------------------------------------------- Cuentas */

    public function test_conversiones_y_cuentas(): void
    {
        $this->assertSame('**1 1/2 in = 38,1 mm**', $this->preguntar('cuántos mm son 1 1/2 pulgadas')['mensaje']);
        $this->assertSame('**12 kg = 26,4555 lb**', $this->preguntar('pasar 12 kg a libras')['mensaje']);
        $this->assertSame('**1059,84 + 365,52 = 1.425,36**', $this->preguntar('cuánto es 1059,84 + 365,52')['mensaje']);
        $this->assertSame('**20% de 450 = 90**', $this->preguntar('20% de 450')['mensaje']);
    }

    /* ---------------------------------------------------------------- Precios */

    public function test_los_precios_son_exactos_y_separados_por_moneda(): void
    {
        $this->formas();
        $monel = $this->material('Monel 400', 8.8, ['MONEL']);
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        foreach ([10.0, 20.0, 30.0] as $i => $p) {
            $this->cotizar($e, $monel, Forma::where('nombre', 'BARRA REDONDA')->first(), $p, '2026-0'.($i + 1).'-10');
        }

        $r = $this->preguntar('a cuánto cotizamos monel en barra');

        $this->assertStringContainsString('en **3** renglones', $r['mensaje']);
        $this->assertStringContainsString('entre **10,00** y **30,00**; promedio **20,00**', $r['mensaje']);
        $this->assertStringContainsString('el último fue **30,00** (10/03/2026)', $r['mensaje']);
        $this->assertSame('renglones', $r['pedido']['grano']);
        $this->assertNotNull($r['analisis']);
    }

    public function test_sin_permiso_de_importes_no_hay_precios(): void
    {
        $monel = $this->material('Monel 400', 8.8, ['MONEL']);
        $this->cotizar(Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]), $monel, null, 10);
        $vendedor = User::factory()->create(['role' => 'Vendedor']);
        Permiso::create(['user_id' => $vendedor->id, 've_importes' => false]);

        $r = $this->preguntar('a cuánto cotizamos monel', $vendedor);

        $this->assertStringContainsString('No tenés permiso para ver precios', $r['mensaje']);
        $this->assertStringNotContainsString('10,00', json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    /* --------------------------------------------------------------- Empresas */

    public function test_una_empresa_se_encuentra_por_nombre_y_las_parecidas_piden_elegir(): void
    {
        $a = Empresa::create(['nombre' => 'ALFA METALES SA', 'activa' => true]);
        Empresa::create(['nombre' => 'ALFA MECANICA SRL', 'activa' => true]);
        Contacto::create(['empresa_id' => $a->id, 'nombre' => 'Ana Prueba', 'cargo' => 'Compras', 'principal' => true, 'activo' => true]);

        $r = $this->preguntar('contame de alfa');
        $this->assertStringContainsString('Hay **2** empresas', $r['mensaje']);
        $this->assertCount(2, $r['opciones']['opciones']);

        // Elegir una de las opciones es otra pregunta: se contesta sola.
        $enviar = collect($r['opciones']['opciones'])->firstWhere('etiqueta', 'ALFA METALES SA')['enviar'];
        $r = $this->preguntar($enviar);
        $this->assertStringContainsString('ALFA METALES SA', $r['mensaje']);
        $this->assertStringContainsString("/empresas/{$a->id}", json_encode($r['atajos'], JSON_UNESCAPED_SLASHES));
    }

    public function test_el_contacto_de_una_empresa_y_la_que_no_existe(): void
    {
        $a = Empresa::create(['nombre' => 'ALFA METALES SA', 'activa' => true]);
        Contacto::create(['empresa_id' => $a->id, 'nombre' => 'Ana Prueba', 'cargo' => 'Compras', 'principal' => true, 'activo' => true]);

        $this->assertStringContainsString('**Ana Prueba**', $this->preguntar('teléfono de alfa metales')['mensaje']);
        $this->assertStringContainsString('No encontré una empresa', $this->preguntar('contame de zzzxx')['mensaje']);
    }

    public function test_una_cotizacion_por_numero(): void
    {
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $c = Consulta::create(['empresa_id' => $e->id, 'tipo' => 'Cotizacion', 'fecha' => '2026-05-01', 'estado' => 'Confirmada', 'usuario_id' => $this->admin->id, 'numero' => '2026-0741', 'revision' => 0]);

        $this->assertStringContainsString('de **ALFA SA**', $this->preguntar('cotización 2026-0741')['mensaje']);
        $this->assertStringContainsString('No encontré la cotización', $this->preguntar('cotización 2026-9999')['mensaje']);
        $this->assertSame("/consultas/{$c->id}", $this->preguntar('la 2026-0741')['atajos'][0]['accion']['ruta']);
    }

    /* ---------------------------------------------------------------- Rankings */

    public function test_el_ranking_no_cuenta_lo_que_no_se_pudo_reconocer_y_explica_las_ventas_vacias(): void
    {
        $monel = $this->material('Monel 400', 8.8, ['MONEL']);
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizar($e, $monel, null, 10);
        $this->cotizar($e, null, null, 10);
        $this->cotizar($e, null, null, 10);

        $r = $this->preguntar('ranking de materiales');
        $this->assertStringContainsString('1. **Monel 400** (1)', $r['mensaje']);
        $this->assertStringContainsString('No cuento 2 renglones', $r['mensaje']);

        $r = $this->preguntar('qué empresas más compran monel');
        $this->assertStringContainsString('el sistema viejo no guardó qué se vendió', $r['mensaje']);
    }

    /* ----------------------------------------------------------------- Límites */

    public function test_lo_que_el_codigo_no_entiende_y_sin_ia_se_dice_y_se_ensena(): void
    {
        $r = $this->preguntar('qué opinás del monel para agua de mar');

        $this->assertSame('reglas', $r['fuente']);
        $this->assertStringContainsString('Busqué «qué opinás del monel para agua de mar» en todo el sistema', $r['mensaje']);
        $this->assertNotEmpty($r['seguir']);
    }

    public function test_el_seguimiento_sin_ia_conserva_lo_que_se_estaba_viendo(): void
    {
        $r = (new Cordes($this->admin))->responder('y de este año', [], ['vista' => 'consultas', 'filtros' => ['estado' => 'Vendida']]);

        $this->assertSame('Vendida', $r['pedido']['estado']);
        $this->assertArrayHasKey('desde', $r['pedido']);
    }

    /* ------------------------------------------- medidas dichas de otra manera */

    public function test_una_fraccion_de_pulgada_dicha_con_de_o_con_palabras_se_lee_como_pulgadas(): void
    {
        $this->formas();
        $this->material('Monel 400', 8.8, ['MONEL']);

        // 3/4" de diámetro y 3 m: π/4 × 19,05² × 3000 mm³ = 855 cm³ × 8,8 = 7,5246 kg. Las cinco maneras de decirlo dan lo mismo.
        foreach ([
            'cuánto pesa una barra de monel de 3/4 de pulgada y 3 metros',
            'cuánto pesa una barra de monel de 3/4 pulgada x 3 m',
            'cuánto pesa una barra de monel de tres cuartos de pulgada x 3 m',
            'cuánto pesa una barra de monel de 3/4" x 3 m',
            'cuánto pesa una barra de monel de 0,75 pulgadas por 3 metros',
        ] as $pregunta) {
            $this->assertStringContainsString('7,5246 kg', (string) $this->preguntar($pregunta)['mensaje'], $pregunta);
        }

        // «media pulgada», «una pulgada y media»: 0,5 in y 1,5 in.
        $barra = Forma::where('nombre', 'BARRA REDONDA')->first();
        $this->assertSame(['valor' => 0.5, 'unidad' => 'in'], Medidas::leer(Medidas::limpiar('barra de media pulgada x 1 m'), $barra)['medidas']['diameter']);
        $this->assertSame(['valor' => 1.5, 'unidad' => 'in'], Medidas::leer(Medidas::limpiar('barra de una pulgada y media x 1 m'), $barra)['medidas']['diameter']);
    }

    public function test_kg_de_una_chapa_con_pulgadas_es_una_chapa_no_un_cano(): void
    {
        $this->formas();
        $this->material('INCONEL 625', 8.44, ['INCONEL 625']);

        // 1/4" = 6,35 mm: 6,35 × 1220 × 2440 mm³ = 18.902,68 cm³ × 8,44 = 159,54 kg.
        $r = $this->preguntar('kg de una chapa de inconel 625 de 1/4" x 1,22 x 2,44 m');

        $this->assertStringContainsString('**chapa**', $r['mensaje']);
        $this->assertStringContainsString('159,5', $r['mensaje']);
    }

    public function test_la_calidad_abreviada_gr_2_es_grado_2_pero_500_gr_siguen_siendo_gramos(): void
    {
        $this->formas();
        $this->material('Titanio Grado 2', 4.51, ['TITANIO GRADO 2', 'TITANIO']);

        $r = $this->preguntar('peso chapa titanio gr 2 5mm 1000x2000');

        $this->assertStringContainsString('Titanio Grado 2', $r['mensaje']);
        $this->assertStringContainsString('45,1', $r['mensaje']);
        $this->assertSame('grado 2', Medidas::limpiar('gr 2'), 'sola, «gr 2» es la calidad');
        $this->assertStringContainsString('500 gr', Medidas::limpiar('500 gr 2 piezas'), 'después de un número son gramos');
    }

    public function test_un_cano_se_entiende_con_el_nominal_pegado_al_schedule(): void
    {
        $this->formas();
        $this->canos();
        $this->material('Monel 400', 8.8, ['MONEL']);

        foreach (['caño 2 sch 40 monel cuánto pesa por metro', 'cuánto pesa un caño de 2 sch 40 de monel por metro', 'peso caño 2 pulgadas sch 40 monel por metro'] as $pregunta) {
            $r = $this->preguntar($pregunta);

            $this->assertStringContainsString('**2"**', $r['mensaje'], $pregunta);
            $this->assertStringContainsString('SCH **40**', $r['mensaje'], $pregunta);
        }
    }

    public function test_cuantas_pulgadas_son_150_mm_se_entiende_en_femenino(): void
    {
        $r = $this->preguntar('cuántas pulgadas son 150 mm');

        $this->assertStringContainsString('150 mm = 5,9055 in', $r['mensaje']);
    }

    public function test_platina_y_tuberia_son_planchuela_y_cano(): void
    {
        $this->forma('PLANCHUELA', [['width', 'Ancho'], ['height', 'Espesor'], ['length', 'Largo']], 'Espesor x ancho x largo', '(width * height * length) / 1000');
        $this->formas();
        $this->material('Monel 400', 8.8, ['MONEL']);

        $r = $this->preguntar('peso de una platina de monel 10 x 50 x 1000');

        // 50 × 10 × 1000 mm³ = 500 cm³ × 8,8 = 4,4 kg.
        $this->assertStringContainsString('4,4000 kg', $r['mensaje']);
    }

    public function test_el_genero_de_la_forma_se_respeta_una_barra_un_cano(): void
    {
        $this->formas();
        $this->material('Monel 400', 8.8, ['MONEL']);

        $this->assertStringContainsString('Para una **BARRA REDONDA** necesito', $this->preguntar('cuánto pesa una barra de monel')['mensaje']);
        $this->assertStringContainsString('Para un **CAÑO** se miden', $this->preguntar('fórmula del caño')['mensaje']);
    }
}

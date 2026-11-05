<?php

namespace Tests\Feature;

use App\Models\CanoEstandar;
use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Moneda;
use App\Models\Pais;
use App\Models\Permiso;
use App\Models\Provincia;
use App\Models\Unidad;
use App\Models\User;
use App\Services\Cordes\Cordes;
use App\Services\Cordes\Herramientas;
use App\Services\Cordes\Medidas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cordes IA resuelve CON EL CÓDIGO todo lo que junta varias cosas (comparar, sumar, dos preguntas en una, un peso por una cantidad,
 * cuántas piezas salen de una chapa), igual que lo haría la IA pero sin ella: con las mismas fórmulas, las mismas densidades y las mismas
 * cuentas. Y nunca se queda sin respuesta, ni dice «necesito la IA». Nombres ficticios: el repo es público.
 */
class CordesDescomponeTest extends TestCase
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
    }

    /* ------------------------------------------------------------------ datos */

    private function forma(string $nombre, array $campos, string $habituales, ?string $expresion, bool $cano = false): Forma
    {
        return Forma::updateOrCreate(['nombre' => $nombre], [
            'campos' => array_map(fn ($c) => ['clave' => $c[0], 'label' => $c[1]], $campos), 'medidas_habituales' => $habituales,
            'expresion' => $expresion, 'activo' => true, 'usa_cano' => $cano,
        ]);
    }

    private function material(string $nombre, float $densidad, array $alias, string $familia = 'Níquel y sus Aleaciones', ?string $uns = null): Material
    {
        $m = Material::create(['nombre' => $nombre, 'familia' => $familia, 'densidad' => $densidad, 'activo' => true, 'uns' => $uns]);

        foreach ($alias as $a) {
            MaterialAlias::create(['material_id' => $m->id, 'alias' => $a]);
        }

        return $m;
    }

    /** El mundo de las pruebas: tres materiales, cinco formas y la tabla de caños. */
    private function mundo(): void
    {
        $this->material('Monel 400', 8.8, ['MONEL', 'MONEL 400'], uns: 'N04400');
        $this->material('Inconel 625', 8.44, ['INCONEL 625'], uns: 'N06625');
        $this->material('Titanio Grado 2', 4.51, ['TITANIO GRADO 2', 'TITANIO'], 'Titanio');
        $this->material('Titanio Grado 5', 4.43, ['TITANIO GRADO 5'], 'Titanio');
        $this->forma('BARRA REDONDA', [['diameter', 'Diametro'], ['length', 'Largo']], 'Diametro x largo', '(pi * pow(diameter, 2) / 4 * length) / 1000');
        $this->forma('BARRA CUADRADA', [['side', 'Lado'], ['length', 'Largo']], 'Lado x largo', '(pow(side, 2) * length) / 1000');
        $this->forma('CHAPA', [['width', 'Ancho'], ['height', 'Espesor'], ['length', 'Largo']], 'Espesor x ancho x largo', '(width * height * length) / 1000');
        $this->forma('CAÑO', [['outer', 'Diametro exterior'], ['wall', 'Espesor de pared'], ['length', 'Largo']], 'Diametro nominal + SCH + largo', '(pi * wall * (outer - wall) * length) / 1000', true);

        foreach ([['1/2"', '40', 21.3, 2.77], ['3/4"', '40', 26.7, 2.87], ['2"', '40', 60.3, 3.91], ['2"', '80', 60.3, 5.54], ['3"', '40', 88.9, 5.49], ['3"', '80', 88.9, 7.62]] as $i => [$n, $s, $od, $p]) {
            CanoEstandar::create(['nombre' => $n, 'schedule' => $s, 'diametro_mm' => $od, 'pared_mm' => $p, 'orden' => $i, 'activo' => true]);
        }
    }

    private function preguntar(string $texto, ?User $u = null): array
    {
        return (new Cordes($u ?? $this->admin))->responder($texto);
    }

    private function sinImportes(): User
    {
        $u = User::factory()->create(['role' => 'Vendedor']);
        Permiso::create(['user_id' => $u->id, 've_importes' => false]);

        return $u;
    }

    private function cotizar(Empresa $e, string $material, string $fecha, float $precio, string $moneda = 'DOLAR BILLETE BNA VENDEDOR', string $unidad = 'KG', ?string $forma = 'BARRA REDONDA'): void
    {
        $c = Consulta::create([
            'empresa_id' => $e->id, 'tipo' => 'Cotizacion', 'fecha' => $fecha, 'estado' => 'Confirmada', 'usuario_id' => $this->admin->id,
            'moneda_id' => Moneda::firstOrCreate(['nombre' => $moneda], ['moneda_base' => false, 'activo' => true])->id,
        ]);
        ConsultaLinea::create(['consulta_id' => $c->id, 'orden' => 1, 'descripcion' => strtoupper($material).' PIEZA', 'cantidad' => 1, 'material_id' => Material::where('nombre', $material)->value('id'),
            'forma_id' => $forma ? Forma::where('nombre', $forma)->value('id') : null, 'unidad_venta_id' => Unidad::firstOrCreate(['codigo' => $unidad], ['nombre' => $unidad, 'activo' => true])->id,
            'precio_unitario' => $precio, 'importe' => $precio]);
    }

    /* ============================================================ la forma de la respuesta */

    /** Lo que la pantalla espera, siempre, venga de donde venga la respuesta. */
    private function assertForma(array $r, string $contexto = ''): void
    {
        $this->assertSame(['fuente', 'mensaje', 'verificada', 'aviso', 'pedido', 'borrador', 'tablas', 'atajos', 'opciones', 'seguir', 'no_entendi', 'pasos', 'analisis'], array_values(array_intersect(
            ['fuente', 'mensaje', 'verificada', 'aviso', 'pedido', 'borrador', 'tablas', 'atajos', 'opciones', 'seguir', 'no_entendi', 'pasos', 'analisis'], array_keys($r),
        )), "faltan claves. {$contexto}");
        $this->assertContains($r['fuente'], ['ia', 'reglas', 'codigo'], $contexto);
        $this->assertTrue($r['mensaje'] === null || is_string($r['mensaje']), $contexto);
        $this->assertIsBool($r['verificada']);
        $this->assertIsArray($r['tablas']);

        foreach ($r['tablas'] as $t) {
            $this->assertIsString($t['titulo']);
            $this->assertNotEmpty($t['columnas']);
            $this->assertCount(count($t['filas']), $t['acciones'], "una lista de botones por fila. {$contexto}");

            foreach ($t['filas'] as $fila) {
                $this->assertLessThanOrEqual(count($t['columnas']), count($fila), $contexto);

                foreach ($fila as $celda) {
                    $this->assertIsString($celda, "toda celda es texto. {$contexto}");
                }
            }
        }

        foreach ($r['atajos'] as $a) {
            $this->assertIsString($a['etiqueta']);
            $this->assertContains($a['icono'], ['lista', 'empresa', 'mas', 'documento', 'grafico', 'excel', 'pregunta']);
            $this->assertContains($a['accion']['tipo'], ['pedido', 'ruta', 'excel', 'preguntar', 'bajar']);
        }

        if ($r['opciones'] !== null) {
            $this->assertNotEmpty($r['opciones']['opciones']);
        }

        $this->assertLessThanOrEqual(3, count($r['seguir']));
        $this->assertTrue(filled($r['mensaje']) || $r['tablas'] !== [] || $r['pedido'] !== null || $r['opciones'] !== null || $r['analisis'] !== null, "una respuesta vacía no sale nunca. {$contexto}");
        $this->assertStringNotContainsString('inteligencia artificial', mb_strtolower((string) $r['mensaje']), "nunca «necesito la IA». {$contexto}");
        $this->assertStringNotContainsString('no la quiero contestar', (string) $r['mensaje'], $contexto);
    }

    /* ============================================================ comparar y sumar piezas */

    public function test_se_comparan_dos_piezas_y_la_segunda_toma_lo_que_no_dice_de_la_primera(): void
    {
        $this->mundo();

        $r = $this->preguntar('comparame lo que pesa una barra de monel de 40 contra una de 20 mm con un largo de un metro');

        $this->assertStringContainsString('barra redonda de Monel 400 de 40 mm × 1 m: **11,0584 kg**', $r['mensaje']);
        $this->assertStringContainsString('barra redonda de Monel 400 de 20 mm × 1 m: **2,7646 kg**', $r['mensaje']);
        $this->assertStringContainsString('El ítem **1** pesa **4 veces** lo que el ítem **2**: **8,2938 kg** más', $r['mensaje']);
        $this->assertStringContainsString('El ítem 1 toma el largo (1 m) del otro', $r['mensaje']);
        $this->assertStringContainsString('El ítem 2 toma la forma (barra redonda) y el material (Monel 400) del otro', $r['mensaje']);
        $this->assertForma($r);
    }

    public function test_igual_copia_las_medidas_y_cambia_el_material(): void
    {
        $this->mundo();

        $r = $this->preguntar('cuánto más pesa una barra de monel de 50,8 x 200 que una de titanio grado 2 igual');

        $this->assertStringContainsString('barra redonda de Monel 400 de 50,8 × 200 mm: **3,5672 kg**', $r['mensaje']);
        $this->assertStringContainsString('barra redonda de Titanio Grado 2 de 50,8 × 200 mm: **1,8282 kg**', $r['mensaje']);
        $this->assertStringContainsString('pesa **1,95 veces**', $r['mensaje']);
    }

    public function test_dos_piezas_con_distinta_forma_comparten_el_largo(): void
    {
        $this->mundo();

        $r = $this->preguntar('cuánto pesa más: una barra redonda de 50 mm de monel o una cuadrada de 50 mm de monel, 1 metro');

        $this->assertStringContainsString('barra redonda de Monel 400 de 50 mm × 1 m: **17,2788 kg**', $r['mensaje']);
        $this->assertStringContainsString('barra cuadrada de Monel 400 de 50 mm × 1 m: **22,0000 kg**', $r['mensaje']);
        $this->assertStringContainsString('El ítem **2** pesa **1,27 veces**', $r['mensaje']);
    }

    public function test_una_chapa_de_3_y_una_de_5_comparten_el_ancho_y_el_largo(): void
    {
        $this->mundo();

        $r = $this->preguntar('cuánto pesa más una chapa de 3 mm o una de 5 mm de monel de 1000 x 2000');

        $this->assertStringContainsString('chapa de Monel 400 de 3 × 1.000 × 2.000 mm: **52,8000 kg**', $r['mensaje']);
        $this->assertStringContainsString('chapa de Monel 400 de 5 × 1.000 × 2.000 mm: **88,0000 kg**', $r['mensaje']);
        $this->assertStringContainsString('el ancho (1.000 mm) y el largo (2.000 mm) del otro', $r['mensaje']);
    }

    public function test_se_suman_piezas_distintas_con_sus_cantidades(): void
    {
        $this->mundo();

        $r = $this->preguntar('cuánto pesan 4 barras de monel de 38,1 x 145 y 2 chapas de titanio grado 2 de 3 x 1000 x 2000');

        $this->assertStringContainsString('Sumo estos ítems', $r['mensaje']);
        $this->assertStringContainsString('4 barras redondas de Monel 400 de 38,1 × 145 mm: **5,8190 kg** (4 piezas, cada una 1,4548 kg)', $r['mensaje']);
        $this->assertStringContainsString('2 chapas de Titanio Grado 2 de 3 × 1.000 × 2.000 mm: **54,1200 kg**', $r['mensaje']);
        // 5,81903… + 54,12 = 59,939: la suma es de los valores sin redondear, no de los ya redondeados.
        $this->assertStringContainsString('En total pesan **59,9390 kg**', $r['mensaje']);
        $this->assertSame('Total', $r['tablas'][0]['filas'][2][1]);
    }

    public function test_sin_decir_en_total_cada_pieza_va_por_separado(): void
    {
        $this->mundo();

        $r = $this->preguntar('cuánto pesa una barra de monel de 20 x 1000 y de 30 x 1000');

        $this->assertStringContainsString('Calculo cada ítem', $r['mensaje']);
        $this->assertStringNotContainsString('En total', $r['mensaje']);
        $this->assertStringContainsString('2,7646 kg', $r['mensaje']);
        $this->assertStringContainsString('6,2204 kg', $r['mensaje']);
    }

    public function test_una_cantidad_sola_cuenta_como_cantidad_de_piezas(): void
    {
        $this->mundo();

        $r = $this->preguntar('quiero saber el peso de 3 barras de inconel 625 de 50 x 1000 y de 2 de monel 30 x 1000');

        $this->assertStringContainsString('3 barras redondas de Inconel 625 de 50 × 1.000 mm: **49,7157 kg**', $r['mensaje']);
        $this->assertStringContainsString('2 barras redondas de Monel 400 de 30 × 1.000 mm: **12,4407 kg**', $r['mensaje']);
    }

    public function test_dos_materiales_sin_medidas_se_comparan_por_densidad(): void
    {
        $this->mundo();

        foreach (['monel o inconel 625 cual es mas pesado', 'diferencia entre el monel y el inconel 625', 'comparame monel y titanio grado 2'] as $pregunta) {
            $r = $this->preguntar($pregunta);

            $this->assertStringContainsString('con los datos del catálogo', $r['mensaje'], $pregunta);
            $this->assertStringContainsString('El más denso es **Monel 400**', $r['mensaje'], $pregunta);
            $this->assertSame('Dato', $r['tablas'][0]['columnas'][0]);
            $this->assertForma($r, $pregunta);
        }
    }

    public function test_una_familia_sin_numero_se_pregunta_con_botones(): void
    {
        $this->mundo();
        $this->material('Inconel 600', 8.47, ['INCONEL 600']);

        $r = $this->preguntar('monel vs inconel');

        $this->assertStringContainsString('«inconel» no es un solo material', $r['mensaje']);
        $this->assertEqualsCanonicalizing(['Inconel 625', 'Inconel 600'], array_column($r['opciones']['opciones'], 'etiqueta'));
        // Cada botón re-pregunta lo mismo con el material elegido, y eso ya se contesta.
        $enviar = collect($r['opciones']['opciones'])->firstWhere('etiqueta', 'Inconel 625')['enviar'];
        $this->assertStringContainsString('El más denso', $this->preguntar($enviar)['mensaje']);
    }

    public function test_se_comparan_dos_formas_por_sus_medidas_y_su_formula(): void
    {
        $this->mundo();

        $r = $this->preguntar('diferencia entre barra redonda y barra cuadrada');

        $this->assertStringContainsString('BARRA REDONDA', $r['mensaje']);
        $this->assertStringContainsString('BARRA CUADRADA', $r['mensaje']);
        $this->assertCount(2, $r['tablas'][0]['filas']);
        $this->assertSame('Lado × Largo', collect($r['tablas'][0]['filas'])->firstWhere(0, 'BARRA CUADRADA')[1]);
    }

    /* ======================================================================== caños */

    public function test_varios_canos_cada_uno_con_su_diametro_y_lo_demas_compartido(): void
    {
        $this->mundo();

        $r = $this->preguntar('cuánto pesan 3 caños de 2 pulgadas sch 40 de monel de 6 metros y 2 de 3 pulgadas sch 40');

        $this->assertStringContainsString('3 caños de 2" SCH 40 de Monel 400 de 6 m: **109,7195 kg**', $r['mensaje']);
        $this->assertStringContainsString('2 caños de 3" SCH 40 de Monel 400 de 6 m: **151,9163 kg**', $r['mensaje']);
        $this->assertStringContainsString('En total pesan **261,6358 kg**', $r['mensaje']);
    }

    public function test_la_cantidad_de_canos_se_respeta_aunque_sea_uno_solo(): void
    {
        $this->mundo();

        $r = $this->preguntar('cuánto pesan 3 caños de 2 pulgadas sch 40 de monel de 6 metros');

        $this->assertStringContainsString('Cada caño de **6 m** pesa **36,5732 kg** y los **3 caños** pesan **109,7195 kg**', $r['mensaje']);
    }

    public function test_dos_schedules_en_una_misma_pregunta_son_dos_respuestas(): void
    {
        $this->mundo();

        $r = $this->preguntar('peso de caño 1/2 sch 40 y 3/4 sch 40 de monel de 6 m');

        $this->assertStringContainsString('**1/2"** SCH **40**', $r['mensaje']);
        $this->assertStringContainsString('**3/4"** SCH **40**', $r['mensaje']);
        $this->assertSame(2, substr_count($r['mensaje'], 'De **6 m** pesa'));
    }

    public function test_uno_de_otro_diametro_es_otro_cano_del_mismo(): void
    {
        $this->mundo();

        $r = $this->preguntar('cuanto mide un caño de 2 sch 40 y uno de 3 sch 80');

        $this->assertStringContainsString('El caño de **2"** SCH **40** tiene Ø exterior **60,3 mm**', $r['mensaje']);
        $this->assertStringContainsString('El caño de **3"** SCH **80** tiene Ø exterior **88,9 mm**, pared **7,62 mm**', $r['mensaje']);
    }

    public function test_la_misma_tabla_para_dos_schedules_o_dos_diametros(): void
    {
        $this->mundo();

        $a = $this->preguntar('tabla de schedule 40 y de schedule 80');
        $b = $this->preguntar('tabla de caños de 2 pulgadas y de 3 pulgadas');

        $this->assertStringContainsString('En **SCH 40** hay', $a['mensaje']);
        $this->assertStringContainsString('En **SCH 80** hay', $a['mensaje']);
        $this->assertStringContainsString('Para **2"** hay', $b['mensaje']);
        $this->assertStringContainsString('Para **3"** hay', $b['mensaje']);
    }

    /* ================================================================ cuentas de ingeniería */

    public function test_cuantas_piezas_salen_de_una_chapa_y_de_la_que_se_venia_hablando(): void
    {
        $this->mundo();

        $r = $this->preguntar('cuánto pesa una chapa de 3 x 1000 x 2000 de monel y cuántas piezas de 200 x 200 salen');

        $this->assertStringContainsString('pesa **52,8000 kg**', $r['mensaje']);
        $this->assertStringContainsString('entran **50 piezas** de **200 × 200 mm**', $r['mensaje']);

        $r = $this->preguntar('cuántas piezas de 50 x 50 salen de una chapa de 1000 x 1000 y cuánto pesa esa chapa de monel de 3 mm');
        $this->assertStringContainsString('entran **400 piezas**', $r['mensaje']);
        $this->assertStringContainsString('pesa **26,4000 kg**', $r['mensaje']);
    }

    public function test_el_peso_ya_conocido_por_una_cantidad_y_su_conversion(): void
    {
        $this->mundo();

        $this->assertStringContainsString('**138,0000 kg** en total', $this->preguntar('si una barra de monel de 20 x 1000 pesa 2,76 kg y compro 50 cuanto es')['mensaje']);

        $r = $this->preguntar('cuántos kilos pesan 20 barras de 38,1 x 145 de monel y cuánto es eso en libras');
        $this->assertStringContainsString('las 20 pesan **29,0951 kg**', $r['mensaje']);
        $this->assertStringContainsString('**29,0951 kg** son **64,1437 lb**', $r['mensaje']);

        $r = $this->preguntar('cuántos kilos son 5 barras de monel de 25,4 x 6000 y cuántas libras');
        $this->assertStringContainsString('**133,7708 kg** son **294,9141 lb**', $r['mensaje']);
    }

    public function test_dos_conversiones_en_una_pregunta(): void
    {
        $this->mundo();

        $a = $this->preguntar('cuántos mm son 2 pulgadas y cuántas pulgadas son 150 mm');
        $b = $this->preguntar('convertí 10 kg a libras y a gramos');
        $c = $this->preguntar('cuántos mm son 2 pulgadas y 3 pulgadas');

        $this->assertStringContainsString('**2 in = 50,8 mm**', $a['mensaje']);
        $this->assertStringContainsString('**150 mm = 5,9055 in**', $a['mensaje']);
        $this->assertStringContainsString('**10 kg = 22,0462 lb**', $b['mensaje']);
        $this->assertStringContainsString('**10 kg = 10.000 g**', $b['mensaje']);
        $this->assertStringContainsString('**3 in = 76,2 mm**', $c['mensaje']);
    }

    /* ======================================================================== preguntas juntas */

    public function test_peso_y_precio_en_una_sola_pregunta_son_dos_respuestas_de_lo_mismo(): void
    {
        $this->mundo();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizar($e, 'Monel 400', '2026-03-01', 410);

        $r = $this->preguntar('cuánto pesa una barra de monel de 20 mm de diámetro y 1 metro y cuánto cuesta');

        $this->assertStringContainsString('pesa **2,7646 kg**', $r['mensaje']);
        $this->assertStringContainsString('**410,00**', $r['mensaje']);
        $this->assertStringContainsString('*Entendí: «cuanto cuesta una barra de monel de 20 mm de diametro y 1 metro»*', $r['mensaje']);

        // «Cuánto pesa y cuánto cuesta una chapa…»: el asunto está en la segunda y vale para las dos.
        $r = $this->preguntar('cuánto pesa y cuánto cuesta una chapa de titanio grado 2 de 3 x 1000 x 2000');
        $this->assertStringContainsString('pesa **27,0600 kg**', $r['mensaje']);
    }

    public function test_que_es_y_cuanto_pesa_comparten_el_material(): void
    {
        $this->mundo();

        $r = $this->preguntar('qué es el monel y cuánto pesa una barra de 20 x 1000');

        $this->assertStringContainsString('El **Monel 400** es de la familia', $r['mensaje']);
        $this->assertStringContainsString('de **Monel 400** de 20 × 1.000 mm pesa **2,7646 kg**', $r['mensaje']);
    }

    public function test_lo_mismo_pedido_de_varios_materiales_o_formas(): void
    {
        $this->mundo();

        $a = $this->preguntar('densidad del monel y del inconel 625');
        $b = $this->preguntar('densidad de monel, inconel 625 y titanio grado 2');
        $c = $this->preguntar('dame la fórmula de la barra redonda y de la chapa');

        $this->assertStringContainsString('Comparo **Monel 400** y **Inconel 625**', $a['mensaje']);
        $this->assertSame(4, count($a['tablas'][0]['columnas']) + 1, 'dato + dos materiales + el cierre de la comparación');
        $this->assertStringContainsString('**Titanio Grado 2**', $b['mensaje']);
        $this->assertStringContainsString('Para una **BARRA REDONDA** se miden', $c['mensaje']);
        $this->assertStringContainsString('Para una **CHAPA** se miden', $c['mensaje']);
    }

    public function test_dos_conteos_de_listas_en_una_pregunta(): void
    {
        $this->mundo();
        $pais = Pais::firstOrCreate(['nombre' => 'Argentina']);
        $cba = Provincia::create(['nombre' => 'Córdoba', 'activo' => true, 'pais_id' => $pais->id]);
        $sf = Provincia::create(['nombre' => 'Santa Fe', 'activo' => true, 'pais_id' => $pais->id]);
        Empresa::create(['nombre' => 'ALFA SA', 'activa' => true, 'provincia_id' => $cba->id]);
        Empresa::create(['nombre' => 'BETA SRL', 'activa' => true, 'provincia_id' => $cba->id]);
        Empresa::create(['nombre' => 'GAMMA SA', 'activa' => true, 'provincia_id' => $sf->id]);

        $r = $this->preguntar('cuántas empresas hay en Córdoba y cuántas en Santa Fe');

        $this->assertStringContainsString('**Córdoba**: **2** empresas', $r['mensaje']);
        $this->assertStringContainsString('**Santa Fe**: **1** empresas', $r['mensaje']);

        $r = $this->preguntar('empresas de Córdoba vs empresas de Santa Fe');
        $this->assertStringContainsString('**Córdoba**: **2**', $r['mensaje']);
        $this->assertStringContainsString('**Córdoba** tiene **1** más', $r['mensaje']);
        $this->assertCount(2, array_filter($r['atajos'], fn ($a) => $a['accion']['tipo'] === 'pedido'));
    }

    public function test_se_comparan_dos_periodos_de_cotizaciones(): void
    {
        $this->mundo();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);

        foreach (['2025-02-01', '2025-05-01', '2026-01-10', '2026-02-10', '2026-03-10'] as $f) {
            Consulta::create(['empresa_id' => $e->id, 'tipo' => 'Cotizacion', 'fecha' => $f, 'estado' => 'Confirmada', 'usuario_id' => $this->admin->id]);
        }

        $r = $this->preguntar('comparar las cotizaciones de 2025 con las de 2026');

        $this->assertStringContainsString('- **2025**: **2**', $r['mensaje']);
        $this->assertStringContainsString('- **2026**: **3**', $r['mensaje']);
        $this->assertStringContainsString('**2026** tiene **1** más', $r['mensaje']);
        $this->assertSame('consultas', $r['pedido']['grano']);
        $this->assertForma($r);
    }

    /* ========================================================================= precios */

    public function test_se_comparan_precios_cuando_estan_en_la_misma_moneda_y_unidad(): void
    {
        $this->mundo();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizar($e, 'Monel 400', '2026-03-01', 400);
        $this->cotizar($e, 'Inconel 625', '2026-04-01', 500);

        $r = $this->preguntar('cuánto más caro es el inconel 625 que el monel');
        $this->assertStringContainsString('El más caro es **Inconel 625**: cuesta **25 %** más que **Monel 400**', $r['mensaje']);

        $r = $this->preguntar('cuál es más barato el monel o el inconel 625');
        $this->assertStringContainsString('El más barato es **Monel 400**: **25 %** menos que **Inconel 625**', $r['mensaje']);

        $r = $this->preguntar('cuánto cuesta el monel y el inconel 625');
        $this->assertStringContainsString('promedio **400,00**', $r['mensaje']);
        $this->assertStringContainsString('promedio **500,00**', $r['mensaje']);
        $this->assertForma($r);
    }

    public function test_los_dolares_y_los_pesos_no_se_comparan_con_una_cuenta(): void
    {
        $this->mundo();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizar($e, 'Monel 400', '2026-03-01', 400);
        $this->cotizar($e, 'Inconel 625', '2026-04-01', 90000, 'PESOS');

        $r = $this->preguntar('cuál es más caro el monel o el inconel 625');

        $this->assertStringContainsString('monedas o unidades distintas', $r['mensaje']);
        $this->assertStringNotContainsString('cuesta **', $r['mensaje'], 'no hay un «% más» entre dólares y pesos');
        $this->assertCount(2, $r['tablas'][0]['filas']);
    }

    public function test_la_calidad_sola_es_la_misma_aleacion_con_otra_calidad(): void
    {
        $this->mundo();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizar($e, 'Titanio Grado 5', '2026-03-01', 300);
        $this->cotizar($e, 'Titanio Grado 2', '2026-03-02', 200);

        $r = $this->preguntar('cuánto más caro es el titanio grado 5 que el grado 2');

        $this->assertStringContainsString('El más caro es **Titanio Grado 5**: cuesta **50 %** más que **Titanio Grado 2**', $r['mensaje']);
    }

    public function test_la_forma_sola_hereda_el_material(): void
    {
        $this->mundo();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizar($e, 'Monel 400', '2026-03-01', 100, forma: 'BARRA REDONDA');
        $this->cotizar($e, 'Monel 400', '2026-03-02', 150, forma: 'CHAPA');

        $r = $this->preguntar('precio de monel en barra versus chapa');

        $this->assertStringContainsString('**Monel 400 en barra**', $r['mensaje']);
        $this->assertStringContainsString('**Monel 400 en chapa**', $r['mensaje']);
        $this->assertStringContainsString('El más caro es **Monel 400 en chapa**: cuesta **50 %** más', $r['mensaje']);
    }

    public function test_sin_permiso_de_importes_ninguna_comparacion_muestra_plata(): void
    {
        $this->mundo();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizar($e, 'Monel 400', '2026-03-01', 31337);
        $u = $this->sinImportes();

        foreach (['cuánto más caro es el inconel 625 que el monel', 'cuánto cuesta el monel y el inconel 625', 'cuánto pesa una barra de monel de 20 x 1000 y cuánto cuesta'] as $pregunta) {
            $r = $this->preguntar($pregunta, $u);

            $this->assertStringNotContainsString('31337', json_encode($r, JSON_UNESCAPED_UNICODE), $pregunta);
            $this->assertStringNotContainsString('31.337', json_encode($r, JSON_UNESCAPED_UNICODE), $pregunta);
        }
    }

    /* ======================================================================= el lector de medidas */

    public function test_las_medidas_se_asignan_en_el_orden_en_que_se_escriben(): void
    {
        $this->mundo();
        $chapa = Forma::where('nombre', 'CHAPA')->first();

        foreach (['chapa de monel de 3 mm 1000 x 2000', 'chapa de 3 mm de espesor 1000 x 2000', 'chapa de 5 mm de monel de 1000 x 2000' => 5.0] as $texto => $espesor) {
            $texto = is_int($texto) ? $espesor : $texto;
            $espesor = is_float($espesor) ? $espesor : 3.0;
            $l = Medidas::leer(Medidas::limpiar($texto), $chapa);

            $this->assertSame($espesor, $l['medidas']['height']['valor'], $texto);
            $this->assertSame(1000.0, $l['medidas']['width']['valor'], $texto);
            $this->assertSame(2000.0, $l['medidas']['length']['valor'], $texto);
        }
    }

    public function test_los_numeros_dichos_con_palabras_junto_a_una_unidad(): void
    {
        $this->mundo();

        $this->assertSame('barra de 20 milimetros por 1 metro largo', Medidas::limpiar('barra de veinte milímetros por un metro largo'));
        $this->assertSame('35 mm de diametro', Medidas::limpiar('treinta y cinco mm de diámetro'));
        $this->assertSame('0,5 metro', Medidas::limpiar('medio metro'));
        $this->assertSame('dos barras', Medidas::limpiar('dos barras'), 'sin unidad al lado, la palabra no se toca');
        $this->assertStringContainsString('2,7646 kg', $this->preguntar('cuánto pesa una barra de monel de veinte milímetros por un metro')['mensaje']);
    }

    /* ======================================== sin IA, con IA que falla, con IA que contesta mal */

    /** @return list<string> */
    private function preguntasCompuestas(): array
    {
        return [
            'comparame lo que pesa una barra de monel de 40 contra una de 20 mm con un largo de un metro',
            'cuánto pesan 4 barras de monel de 38,1 x 145 y 2 chapas de titanio grado 2 de 3 x 1000 x 2000',
            'monel o inconel 625 cual es mas pesado',
            'cuántas piezas de 150 x 150 salen de una chapa de 1000 x 2000',
            'cuánto pesa una barra de monel de 20 mm de diámetro y 1 metro y cuánto cuesta',
            'cuántos mm son 2 pulgadas y cuántas pulgadas son 150 mm',
            'si pesa 2,7646 kg y son 12 piezas cuanto pesan en total',
        ];
    }

    public function test_si_la_ia_no_responde_de_ninguna_manera_el_codigo_contesta_igual(): void
    {
        $this->mundo();
        config(['services.openai.key' => 'clave']);

        $modos = [
            'error 500' => fn () => Http::response(['error' => 'x'], 500),
            'sin crédito' => fn () => Http::response(['error' => 'cuota'], 429),
            'sin permiso' => fn () => Http::response(['error' => 'clave'], 401),
            'sin conexión' => fn () => throw new ConnectionException('se cortó'),
            'sin contenido' => fn () => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => '']]], 'usage' => ['total_tokens' => 5]]),
            'basura' => fn () => Http::response('esto no es json', 200),
            'respuesta rara' => fn () => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => '{"mensaje": 42, "tablas": "x", "atajos": 7}']]], 'usage' => ['total_tokens' => 5]]),
        ];

        foreach ($modos as $modo => $respuesta) {
            Http::fake(['*/chat/completions' => $respuesta]);

            foreach ($this->preguntasCompuestas() as $pregunta) {
                $r = $this->preguntar($pregunta);

                $this->assertForma($r, "{$modo}: {$pregunta}");
                $this->assertNull($r['aviso'], "{$modo}: no se le muestra ningún error de la IA");
                $this->assertContains($r['fuente'], ['codigo', 'reglas'], "{$modo}: {$pregunta}");
                // Lo que el código resuelve trae sus números, no un pedido de disculpas.
                $this->assertMatchesRegularExpression('/\d/', (string) $r['mensaje'], "{$modo}: {$pregunta}");
            }
        }
    }

    public function test_si_la_ia_contesta_con_una_cifra_inventada_manda_el_codigo(): void
    {
        $this->mundo();
        config(['services.openai.key' => 'clave']);
        // La IA «contesta» siempre lo mismo: una cifra que ninguna herramienta devolvió. No se puede comprobar: se descarta.
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(['mensaje' => 'Pesa **777,77 kg**.'])]]], 'usage' => ['total_tokens' => 5]])]);

        $r = $this->preguntar('comparame lo que pesa una barra de monel de 40 contra una de 20 mm con un largo de un metro');

        $this->assertStringNotContainsString('777', json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('11,0584 kg', $r['mensaje']);
        $this->assertSame('codigo', $r['fuente']);
    }

    public function test_con_la_ia_activa_lo_complejo_va_a_la_ia_y_lo_simple_no_la_toca(): void
    {
        $this->mundo();
        config(['services.openai.key' => 'clave']);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(['mensaje' => 'Te lo separo en dos.'])]]], 'usage' => ['total_tokens' => 5]])]);

        $simple = $this->preguntar('cuánto pesa una barra de monel de 38,1 x 145 mm');
        Http::assertNothingSent();
        $this->assertSame('codigo', $simple['fuente']);

        $complejo = $this->preguntar('comparame lo que pesa una barra de monel de 40 contra una de 20 mm con un largo de un metro');
        Http::assertSentCount(1);
        $this->assertSame('ia', $complejo['fuente']);
    }

    public function test_la_ia_puede_delegar_una_comparacion_al_motor_y_recibe_la_cuenta_hecha(): void
    {
        $this->mundo();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizar($e, 'Monel 400', '2026-03-01', 400);
        $this->cotizar($e, 'Inconel 625', '2026-04-01', 90000, 'PESOS');

        $r = (new Herramientas($this->admin))->ejecutar('motor', ['pregunta' => 'cuál es más caro el monel o el inconel 625']);

        $this->assertTrue($r['entendio']);
        $this->assertSame('varias partes', $r['intencion']);
        // La IA recibe el resultado correcto: dólares y pesos no se comparan.
        $this->assertStringContainsString('monedas o unidades distintas', $r['respuesta']);

        $r = (new Herramientas($this->admin))->ejecutar('motor', ['pregunta' => 'cuánto pesan 4 barras de monel de 38,1 x 145 y 2 chapas de titanio grado 2 de 3 x 1000 x 2000']);
        $this->assertStringContainsString('En total pesan **59,9390 kg**', $r['respuesta'], 'la suma exacta, no la de los números ya redondeados');
        $this->assertArrayNotHasKey('_calculo', $r);
    }

    /* =========================================== nunca queda una pregunta sin respuesta */

    public function test_ninguna_pregunta_se_queda_sin_respuesta(): void
    {
        $this->mundo();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizar($e, 'Monel 400', '2026-03-01', 400);

        $raras = [
            'hola', 'ayuda', 'asdfghjkl', '???', '123456', '1+1', '0/0', '%%%', "'; DROP TABLE empresas; --", '<script>alert(1)</script>', 'ñandú', '😀😀😀', 'a b',
            'una barra', 'monel', 'monel y', 'y monel', 'monel y y inconel', 'contra', 'vs', 'comparame', 'comparame monel', 'cuánto pesa', 'cuánto cuesta', 'cuántas',
            'cuánto pesa una barra de unobtanium de 20 x 1000', 'cuánto pesa una barra de monel de -5 x 0', 'cuánto pesa una barra de monel de 999999999 x 999999999',
            'cuántas piezas de 0 x 0 salen de una chapa de 0 x 0', 'cuántas piezas de 5000 x 5000 salen de una chapa de 100 x 100',
            'si pesa 0 kg y son 0 piezas cuánto pesan', 'monel contra monel', 'una barra de monel o una barra de monel', 'comparame el monel con el monel',
            'cuánto más caro es el unobtanium que el monel', 'cuántas cotizaciones de 1900 contra 2999', 'empresas de Narnia vs empresas de Mordor',
            str_repeat('monel ', 80), str_repeat('y cuánto pesa ', 40), 'qué es '.str_repeat('x', 300),
            'caño de 99 pulgadas sch 999 y de 77 sch 1', 'cuánto pesa un caño de 2 sch 40 y otro de 3 sch 40 y otro de 4 sch 40 y otro de 6 sch 40 y otro de 8 sch 40 y otro de 10 sch 40',
            'diferencia entre nada y nadie', 'inconel contra monel contra titanio contra acero contra bronce contra cobre contra níquel',
            'cuánto pesa una barra de monel de 20 x 1000 y de 30 x 1000 y de 40 x 1000 y de 50 x 1000 y de 60 x 1000 y de 70 x 1000 y de 80 x 1000',
        ];

        foreach ([false, true] as $conIa) {
            config(['services.openai.key' => $conIa ? 'clave' : '']);
            Http::fake(['*/chat/completions' => Http::response(['error' => 'x'], 500)]);

            foreach ($raras as $pregunta) {
                $r = $this->preguntar($pregunta);

                $this->assertForma($r, ($conIa ? 'IA caída: ' : 'sin IA: ').$pregunta);
            }
        }
    }

    public function test_lo_que_el_codigo_no_entiende_se_busca_como_texto_en_todo_el_sistema(): void
    {
        $this->mundo();
        Empresa::create(['nombre' => 'ZETA ESPECIAL SA', 'activa' => true]);

        $r = $this->preguntar('zeta especial');

        $this->assertForma($r);
        $this->assertNotNull($r['pedido']);
        $this->assertNotNull($r['analisis'], 'con cifras por vista y botones para cambiar de vista');
    }

    /* ========================================================= de punta a punta, por HTTP */

    public function test_por_la_api_la_respuesta_llega_completa_y_con_su_forma(): void
    {
        $this->mundo();

        foreach ($this->preguntasCompuestas() as $pregunta) {
            $r = $this->postJson('/api/cordes/preguntar', ['texto' => $pregunta])->assertOk();

            $this->assertForma($r->json(), $pregunta);
        }
    }

    public function test_en_vivo_la_ultima_linea_es_la_respuesta_y_antes_van_los_pasos(): void
    {
        $this->mundo();

        $r = $this->post('/api/cordes/preguntar', ['texto' => 'comparame lo que pesa una barra de monel de 40 contra una de 20 mm con un largo de un metro', 'stream' => true])->assertOk();
        $lineas = array_values(array_filter(explode("\n", $r->streamedContent())));
        $eventos = array_map(fn ($l) => json_decode($l, true), $lineas);

        $this->assertSame('fin', end($eventos)['tipo']);
        $this->assertContains('paso', array_column($eventos, 'tipo'), 'los pasos se van contando');
        $this->assertForma(end($eventos)['respuesta']);
    }

    public function test_una_pregunta_cualquiera_nunca_termina_en_error_en_vivo(): void
    {
        $this->mundo();

        foreach (['asdfghjkl', 'cuánto pesa', "'; DROP TABLE empresas; --", str_repeat('monel ', 60)] as $pregunta) {
            $r = $this->post('/api/cordes/preguntar', ['texto' => $pregunta, 'stream' => true])->assertOk();
            $eventos = array_map(fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", $r->streamedContent()))));

            $this->assertSame('fin', end($eventos)['tipo'], $pregunta);
        }
    }
}

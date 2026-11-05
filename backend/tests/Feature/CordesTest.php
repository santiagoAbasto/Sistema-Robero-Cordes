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
use App\Models\Pais;
use App\Models\Permiso;
use App\Models\Provincia;
use App\Models\Unidad;
use App\Models\User;
use App\Services\Cordes\Agente;
use App\Services\Cordes\Analista;
use App\Services\Cordes\Cordes;
use App\Services\Cordes\Herramientas;
use App\Services\Cordes\Respuesta;
use App\Services\Cordes\Verificador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cordes IA: un asistente que consulta el sistema (herramientas), razona con la IA y NO inventa
 * (verificador). La IA se simula con Http::fake. Nombres ficticios: el repo es público.
 */
class CordesTest extends TestCase
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

    /* ------------------------------------------------------------- datos de prueba */

    private function empresa(string $nombre, array $extra = []): Empresa
    {
        return Empresa::create(['nombre' => $nombre, 'activa' => true] + $extra);
    }

    private function moneda(string $nombre = 'DOLAR BILLETE BNA VENDEDOR'): Moneda
    {
        return Moneda::firstOrCreate(['nombre' => $nombre], ['moneda_base' => false, 'activo' => true]);
    }

    private function provincia(string $nombre): Provincia
    {
        return Provincia::create(['nombre' => $nombre, 'activo' => true, 'pais_id' => Pais::firstOrCreate(['nombre' => 'Argentina'])->id]);
    }

    private function unidad(string $codigo = 'KG'): Unidad
    {
        return Unidad::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo, 'activo' => true]);
    }

    private function cotizacion(Empresa $e, array $extra = []): Consulta
    {
        return Consulta::create($extra + [
            'empresa_id' => $e->id, 'tipo' => 'Cotizacion', 'fecha' => '2026-10-01', 'estado' => 'Confirmada',
            'usuario_id' => $this->admin->id, 'moneda_id' => $this->moneda()->id,
        ]);
    }

    private function linea(Consulta $c, array $extra = []): ConsultaLinea
    {
        static $orden = 0;

        return ConsultaLinea::create($extra + ['consulta_id' => $c->id, 'orden' => ++$orden, 'descripcion' => 'BARRA 20MM', 'cantidad' => 1, 'unidad_venta_id' => $this->unidad()->id]);
    }

    private function monel(): Material
    {
        $m = Material::create(['nombre' => 'Monel 400', 'familia' => 'Níquel y sus Aleaciones', 'densidad' => 8.8, 'uns' => 'N04400', 'w_nr' => '2.4360', 'activo' => true]);
        MaterialAlias::create(['material_id' => $m->id, 'alias' => 'MONEL']);

        return $m;
    }

    private function barra(): Forma
    {
        return Forma::updateOrCreate(['nombre' => 'BARRA REDONDA'], [
            'campos' => [['clave' => 'diameter', 'label' => 'Diametro'], ['clave' => 'length', 'label' => 'Largo']],
            'expresion' => '(pi * pow(diameter, 2) / 4 * length) / 1000', 'activo' => true, 'usa_cano' => false,
        ]);
    }

    private function herramientas(?User $u = null): Herramientas
    {
        return new Herramientas($u ?? $this->admin);
    }

    private function sinImportes(): User
    {
        $u = User::factory()->create(['role' => 'Vendedor']);
        Permiso::create(['user_id' => $u->id, 've_importes' => false]);

        return $u;
    }

    /* ----------------------------------------------------------------- Verificador */

    public function test_el_verificador_acepta_cifras_en_cualquier_formato_si_estan_en_los_datos(): void
    {
        $v = new Verificador;
        $v->agregar(['resultado' => 1486.28, 'nota' => 'Total US$ 1.486,28']);

        $this->assertSame([], $v->sinComprobar('Son **US$ 1.486,28** en total, o 1486,28 sin separador, o 1486.28.'));
    }

    public function test_el_verificador_frena_una_cifra_inventada(): void
    {
        $v = new Verificador;
        $v->agregar(['peso_total_kg' => 4.4983]);

        $this->assertSame(['5,2'], $v->sinComprobar('Pesa 5,2 kg.'));
    }

    public function test_un_redondeo_correcto_no_es_inventar(): void
    {
        $v = new Verificador;
        $v->agregar(['peso_total_kg' => 4.4983]);

        $this->assertSame([], $v->sinComprobar('Pesa 4,5 kg (4,50 con dos decimales).'));
        $this->assertSame(['4,6'], $v->sinComprobar('Pesa 4,6 kg.'));
    }

    public function test_las_fechas_y_los_codigos_se_buscan_tal_cual(): void
    {
        $v = new Verificador;
        $v->agregar(['fecha' => '2026-10-28', 'uns' => 'N04400', 'numero' => '2026-0741 R1']);

        $this->assertSame([], $v->sinComprobar('Del 28/10/2026, UNS N04400, cotización 2026-0741 R1.'));
        $this->assertSame(['29/10/2026', 'N06600'], $v->sinComprobar('Del 29/10/2026, UNS N06600.'));
    }

    public function test_lo_que_escribio_la_persona_y_cuantos_son_valen_como_dato(): void
    {
        $v = new Verificador;
        $v->agregar('barra de 38,1 por 145 mm');
        $v->agregar(['empresas' => [['id' => 1], ['id' => 2], ['id' => 3]]]);

        $this->assertSame([], $v->sinComprobar('Para 38,1 x 145 mm, son 3 empresas.'));
    }

    public function test_una_fecha_escrita_en_palabras_vale_si_esa_fecha_esta_en_los_datos(): void
    {
        $v = new Verificador;
        $v->agregar(['ultima' => '2026-09-20', 'otra' => '12/03/2024']);

        $this->assertSame([], $v->sinComprobar('Fue el 20 de septiembre de 2026, y antes el 12 de marzo de 2024.'));
        // Los números están por separado en los datos, pero ESA fecha no existe.
        $this->assertSame(['20 de marzo de 2026'], $v->sinComprobar('Fue el 20 de marzo de 2026.'));
        $this->assertSame([], $v->sinComprobar('El 20 de septiembre.'));
        $this->assertSame(['21 de septiembre'], $v->sinComprobar('El 21 de septiembre.'));
    }

    public function test_un_nombre_en_negrita_tiene_que_salir_de_los_datos(): void
    {
        $v = new Verificador;
        $v->agregar(['empresas' => [['nombre' => 'ACME SA']]]);

        $this->assertSame([], $v->sinComprobar('Se la cotizaste a **ACME SA**.'));
        $this->assertSame(['ZETA SRL'], $v->sinComprobar('Se la cotizaste a **ZETA SRL**.'));
    }

    public function test_la_numeracion_de_una_lista_no_cuenta_como_cifra(): void
    {
        $v = new Verificador;
        $v->agregar(['a' => 'x']);

        $this->assertSame([], $v->sinComprobar("1. Primero\n2. Segundo"));
    }

    /* ---------------------------------------------------------------- Herramientas */

    public function test_sin_permiso_de_importes_la_ia_ni_siquiera_conoce_la_herramienta_de_precios(): void
    {
        $con = collect($this->herramientas()->definiciones())->pluck('function.name');
        $sin = collect($this->herramientas($this->sinImportes())->definiciones())->pluck('function.name');

        $this->assertTrue($con->contains('precios'));
        $this->assertFalse($sin->contains('precios'));
        $this->assertSame('Esta persona no tiene permiso para ver precios.', $this->herramientas($this->sinImportes())->ejecutar('precios', ['filtros' => []])['error']);
    }

    public function test_material_trae_sus_datos_y_sugiere_si_hay_un_error_de_tipeo(): void
    {
        $this->monel();
        Material::create(['nombre' => 'Titanio Grado 2', 'activo' => true]);

        $r = $this->herramientas()->ejecutar('material', ['texto' => 'monel']);
        $this->assertSame('Monel 400', $r['materiales'][0]['nombre']);
        $this->assertSame(8.8, $r['materiales'][0]['densidad_g_cm3']);
        $this->assertSame('N04400', $r['materiales'][0]['uns']);

        $r = $this->herramientas()->ejecutar('material', ['texto' => 'titanyo']);
        $this->assertArrayHasKey('error', $r);
        $this->assertContains('Titanio Grado 2', $r['parecidos']);
    }

    public function test_el_peso_lo_calcula_la_formula_del_sistema(): void
    {
        $this->monel();
        $this->barra();

        $r = $this->herramientas()->ejecutar('calcular_peso', [
            'material' => 'monel', 'forma' => 'barra redonda', 'piezas' => 2,
            'medidas' => [['clave' => 'diameter', 'valor' => 20], ['clave' => 'length', 'valor' => 1000]],
        ]);

        // Volumen = pi * 20² / 4 * 1000 / 1000 = 314,159 cm³; × 8,8 g/cm³ = 2,7646 kg; × 2 piezas.
        $this->assertTrue($r['ok']);
        $this->assertEqualsWithDelta(2.7646, $r['peso_por_pieza_kg'], 0.001);
        $this->assertEqualsWithDelta(5.5292, $r['peso_total_kg'], 0.001);
        $this->assertSame(8.8, $r['densidad_g_cm3']);
    }

    public function test_sin_densidad_no_se_inventa_el_peso_y_se_ofrecen_parecidos_que_si_la_tienen(): void
    {
        $this->monel();
        $this->barra();
        Material::create(['nombre' => 'Inconel Raro', 'familia' => 'Níquel y sus Aleaciones', 'activo' => true]);

        $r = $this->herramientas()->ejecutar('calcular_peso', [
            'material' => 'inconel raro', 'forma' => 'barra redonda',
            'medidas' => [['clave' => 'diameter', 'valor' => 20], ['clave' => 'length', 'valor' => 1000]],
        ]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('densidad', $r['motivo']);
        $this->assertArrayNotHasKey('peso_total_kg', $r);
        $this->assertSame(['Monel 400 (8.8 g/cm³)'], $r['materiales_parecidos_con_densidad']);
    }

    public function test_la_tabla_de_canos_da_los_kilos_por_metro_de_un_material(): void
    {
        $this->monel();
        CanoEstandar::create(['nombre' => '2"', 'schedule' => '40', 'diametro_mm' => 60.3, 'pared_mm' => 3.91, 'orden' => 1, 'activo' => true]);

        $r = $this->herramientas()->ejecutar('canos', ['nominal' => '2', 'schedule' => '40', 'material' => 'monel']);

        // Sección = pi/4 * (60,3² - 52,48²) = 693,5 mm²; × 8,8 g/cm³ = 6,10 kg/m.
        $this->assertEqualsWithDelta(52.48, $r['canos'][0]['diametro_interior_mm'], 0.01);
        $this->assertEqualsWithDelta(6.103, $r['canos'][0]['kg_por_metro'], 0.01);
    }

    public function test_convertir_y_calcular_las_hace_el_codigo(): void
    {
        $h = $this->herramientas();

        $this->assertSame(38.1, $h->ejecutar('convertir', ['valor' => '1 1/2', 'de' => 'in', 'a' => 'mm'])['resultado']);
        $this->assertEqualsWithDelta(2.2046, $h->ejecutar('convertir', ['valor' => '1', 'de' => 'kg', 'a' => 'lb'])['resultado'], 0.0001);
        $this->assertArrayHasKey('error', $h->ejecutar('convertir', ['valor' => '1', 'de' => 'kg', 'a' => 'mm']));
        $this->assertSame(1486.28, $h->ejecutar('calcular', ['expresion' => '1059,84 + 365,52 + 60,92'])['resultado']);
        $this->assertArrayHasKey('error', $h->ejecutar('calcular', ['expresion' => 'borrar todo']));
    }

    public function test_buscar_y_agrupar_usan_el_mismo_buscador_que_la_pantalla(): void
    {
        $cba = $this->provincia('Córdoba');
        $sf = $this->provincia('Santa Fe');
        $monel = $this->monel();
        foreach ([['ALFA SA', $cba], ['BETA SA', $cba], ['GAMMA SA', $sf]] as [$n, $p]) {
            $e = $this->empresa($n, ['provincia_id' => $p->id]);
            $this->linea($this->cotizacion($e), ['material_id' => $monel->id]);
        }
        $this->empresa('SIN MONEL SA', ['provincia_id' => $sf->id]);

        $h = $this->herramientas();
        $r = $h->ejecutar('buscar', ['vista' => 'empresas', 'filtros' => ['material' => 'monel']]);

        $this->assertSame(3, $r['total']);
        $this->assertEqualsCanonicalizing(['ALFA SA', 'BETA SA', 'GAMMA SA'], array_column($r['filas'], 'nombre'));
        $this->assertSame('Empresas', $r['tablas'][0]['titulo']);

        $g = $h->ejecutar('agrupar', ['vista' => 'empresas', 'filtros' => ['material' => 'monel'], 'por' => 'provincia']);
        $this->assertSame(['Córdoba' => 2, 'Santa Fe' => 1], array_column($g['grupos'], 'cantidad', 'etiqueta'));
        $this->assertSame(['provincia_id' => $cba->id], collect($g['grupos'])->firstWhere('etiqueta', 'Córdoba')['filtro']);

        $this->assertArrayHasKey('error', $h->ejecutar('agrupar', ['vista' => 'empresas', 'por' => 'planeta']));
    }

    public function test_precios_separa_por_moneda_y_unidad_y_trae_las_ultimas(): void
    {
        $monel = $this->monel();
        $e = $this->empresa('ALFA SA');
        $usd = $this->moneda();
        $ars = $this->moneda('PESOS');
        foreach ([[$usd, 10.0, '2026-01-10'], [$usd, 20.0, '2026-03-10'], [$usd, 30.0, '2026-05-10'], [$ars, 9000.0, '2026-06-10']] as [$m, $precio, $fecha]) {
            $c = $this->cotizacion($e, ['moneda_id' => $m->id, 'fecha' => $fecha]);
            $this->linea($c, ['material_id' => $monel->id, 'precio_unitario' => $precio, 'importe' => $precio]);
        }

        $r = $this->herramientas()->ejecutar('precios', ['filtros' => ['material' => 'monel']]);
        $porMoneda = collect($r['estadisticas'])->keyBy('moneda');

        $this->assertSame(10.0, $porMoneda['DOLAR BILLETE BNA VENDEDOR']['minimo']);
        $this->assertSame(30.0, $porMoneda['DOLAR BILLETE BNA VENDEDOR']['maximo']);
        $this->assertSame(20.0, $porMoneda['DOLAR BILLETE BNA VENDEDOR']['promedio']);
        $this->assertSame(20.0, $porMoneda['DOLAR BILLETE BNA VENDEDOR']['mediana']);
        $this->assertSame(30.0, $porMoneda['DOLAR BILLETE BNA VENDEDOR']['ultimo_precio']);
        $this->assertSame(9000.0, $porMoneda['PESOS']['promedio']);
        $this->assertSame('2026-06-10', $r['ultimos'][0]['fecha']);
        $this->assertCount(2, $r['tablas']);
    }

    public function test_dos_empresas_con_el_mismo_nombre_no_se_adivinan_se_piden_opciones(): void
    {
        $this->empresa('ALFA SA', ['direccion' => 'x']);
        $this->empresa('ALFA SRL');

        $r = $this->herramientas()->ejecutar('empresa', ['nombre' => 'alfa']);

        $this->assertTrue($r['ambigua']);
        $this->assertCount(2, $r['candidatas']);
    }

    public function test_la_ficha_de_una_empresa_trae_contactos_actividad_y_materiales(): void
    {
        $monel = $this->monel();
        $e = $this->empresa('ALFA SA', ['cuit' => '30-12345678-9']);
        Contacto::create(['empresa_id' => $e->id, 'nombre' => 'Ana Prueba', 'cargo' => 'Compras', 'principal' => true, 'activo' => true]);
        $c = $this->cotizacion($e);
        $this->linea($c, ['material_id' => $monel->id, 'importe' => 100, 'precio_unitario' => 100]);

        $r = $this->herramientas()->ejecutar('empresa', ['nombre' => 'ALFA SA']);

        $this->assertSame('30-12345678-9', $r['cuit']);
        $this->assertSame('Ana Prueba', $r['contactos'][0]['nombre']);
        $this->assertSame(1, $r['cotizaciones_firmes']);
        $this->assertSame(['Monel 400: 1 renglones'], $r['materiales_mas_cotizados']);
        $this->assertSame(100.0, $r['cotizado_por_moneda'][0]['importe']);

        // Sin permiso de importes, ni la suma.
        $sin = $this->herramientas($this->sinImportes())->ejecutar('empresa', ['id' => $e->id]);
        $this->assertArrayNotHasKey('cotizado_por_moneda', $sin);
    }

    public function test_una_cotizacion_sin_permiso_no_muestra_precios(): void
    {
        $e = $this->empresa('ALFA SA');
        $c = $this->cotizacion($e);
        $this->linea($c, ['precio_unitario' => 55, 'importe' => 55]);

        $con = $this->herramientas()->ejecutar('cotizacion', ['id' => $c->id]);
        $sin = $this->herramientas($this->sinImportes())->ejecutar('cotizacion', ['id' => $c->id]);

        $this->assertSame(55.0, $con['renglones'][0]['precio_unitario']);
        $this->assertArrayNotHasKey('precio_unitario', $sin['renglones'][0]);
        $this->assertNull($sin['total']);
    }

    /* ---------------------------------------------------------------------- Agente */

    private function ia(array ...$mensajes): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);
        $secuencia = Http::sequence();

        foreach ($mensajes as $m) {
            $secuencia->push(['choices' => [['message' => ['role' => 'assistant'] + $m]], 'usage' => ['total_tokens' => 100]]);
        }

        Http::fake(['*/chat/completions' => $secuencia]);
    }

    private function llamada(string $nombre, array $args, string $id = 'c1'): array
    {
        return ['content' => null, 'tool_calls' => [['id' => $id, 'type' => 'function', 'function' => ['name' => $nombre, 'arguments' => json_encode($args)]]]];
    }

    private function final(array $datos): array
    {
        return ['content' => json_encode($datos, JSON_UNESCAPED_UNICODE)];
    }

    public function test_el_agente_consulta_una_herramienta_y_contesta_con_lo_que_trajo(): void
    {
        $this->monel();
        $this->ia(
            $this->llamada('material', ['texto' => 'monel']),
            $this->final(['mensaje' => 'El **Monel 400** tiene densidad 8,8 g/cm³ y UNS N04400.', 'seguir' => ['¿Cuánto pesa una barra?']]),
        );

        $pasos = [];
        $r = (new Cordes($this->admin))->responder('comparame el monel con otros materiales', alPaso: function ($p) use (&$pasos) {
            $pasos[] = $p;
        });

        $this->assertSame('ia', $r['fuente']);
        $this->assertTrue($r['verificada']);
        $this->assertSame('El **Monel 400** tiene densidad 8,8 g/cm³ y UNS N04400.', $r['mensaje']);
        $this->assertSame(['Mirando el catálogo: monel…'], $pasos);
        $this->assertSame(['¿Cuánto pesa una barra?'], $r['seguir']);
        Http::assertSentCount(2);
    }

    public function test_si_la_ia_inventa_una_cifra_se_la_hace_corregir(): void
    {
        $this->monel();
        $this->ia(
            $this->llamada('material', ['texto' => 'monel']),
            $this->final(['mensaje' => 'La densidad del Monel es 9,2 g/cm³.']),
            $this->final(['mensaje' => 'La densidad del **Monel 400** es 8,8 g/cm³.']),
        );

        $r = (new Cordes($this->admin))->responder('comparame el monel con otros materiales');

        $this->assertTrue($r['verificada']);
        $this->assertSame('La densidad del **Monel 400** es 8,8 g/cm³.', $r['mensaje']);
        // La corrección viajó a la IA con la cifra que no se pudo comprobar.
        Http::assertSent(fn ($req) => str_contains(json_encode($req->data(), JSON_UNESCAPED_UNICODE), 'VERIFICACIÓN DEL SISTEMA') && str_contains(json_encode($req->data(), JSON_UNESCAPED_UNICODE), '9,2'));
    }

    public function test_si_la_ia_insiste_en_inventar_no_se_muestra_su_texto_sino_los_datos(): void
    {
        $this->monel();
        $this->ia(
            $this->llamada('material', ['texto' => 'monel']),
            $this->final(['mensaje' => 'Pesa 9,2.']),
            $this->final(['mensaje' => 'Pesa 9,2 de verdad.']),
            $this->final(['mensaje' => 'Pesa 9,2, te lo juro.', 'atajos' => [['tipo' => 'excel', 'etiqueta' => 'Bajar']]]),
        );

        $r = (new Cordes($this->admin))->responder('qué opinás del monel para agua de mar');

        $this->assertFalse($r['verificada']);
        $this->assertStringNotContainsString('9,2', $r['mensaje']);
        $this->assertStringContainsString('No pude comprobar', $r['mensaje']);
        $this->assertSame([], $r['atajos']);
        $this->assertNull($r['pedido']);
    }

    public function test_si_consulto_una_busqueda_se_muestra_aunque_la_ia_no_lo_pida_y_el_analista_la_comenta(): void
    {
        $monel = $this->monel();
        $e = $this->empresa('ALFA SA');
        $this->linea($this->cotizacion($e), ['material_id' => $monel->id]);
        $this->ia(
            $this->llamada('buscar', ['vista' => 'empresas', 'filtros' => ['material' => 'monel']]),
            $this->final(['mensaje' => 'Se la cotizaste a **ALFA SA**.']),
        );

        $r = (new Cordes($this->admin))->responder('a quién le cotizamos monel y cuándo');

        $this->assertSame('Monel 400', $r['pedido']['material']);
        $this->assertSame(1, $r['analisis']['total']);
        $this->assertSame('Encontré 1 empresa', $r['analisis']['titulo']);
    }

    public function test_las_tablas_salen_de_la_herramienta_no_de_lo_que_escriba_la_ia(): void
    {
        $this->empresa('ALFA SA');
        $this->ia(
            $this->llamada('buscar_empresas', ['texto' => 'alfa']),
            $this->final(['mensaje' => 'Encontré la empresa **ALFA SA**.', 'tablas' => ['t1', 't99']]),
        );

        $r = (new Cordes($this->admin))->responder('buscame la empresa alfa');

        $this->assertCount(1, $r['tablas']);
        $this->assertSame('ALFA SA', $r['tablas'][0]['filas'][0][0]);
    }

    public function test_si_hay_dos_empresas_parecidas_las_opciones_las_arma_el_codigo(): void
    {
        $a = $this->empresa('ALFA METALES SA');
        $b = $this->empresa('ALFA MECANICA SRL');
        $this->ia(
            $this->llamada('empresa', ['nombre' => 'alfa']),
            // La IA se olvidó de ofrecer opciones.
            $this->final(['mensaje' => 'Hay **ALFA METALES SA** y **ALFA MECANICA SRL**. ¿Cuál?']),
        );

        $r = (new Cordes($this->admin))->responder('contame de alfa');

        $this->assertSame(['ALFA MECANICA SRL', 'ALFA METALES SA'], collect($r['opciones']['opciones'])->pluck('etiqueta')->sort()->values()->all());
        $this->assertStringContainsString("(id {$a->id})", collect($r['opciones']['opciones'])->firstWhere('etiqueta', 'ALFA METALES SA')['enviar']);
    }

    public function test_el_historial_y_la_busqueda_anterior_viajan_a_la_ia(): void
    {
        $this->ia($this->final(['mensaje' => 'Listo.']));

        (new Cordes($this->admin))->responder(
            'y de Córdoba',
            [['rol' => 'usuario', 'texto' => 'empresas que compraron monel'], ['rol' => 'cordes', 'texto' => 'Encontré empresas.']],
            ['vista' => 'empresas', 'filtros' => ['material' => 'monel']],
        );

        Http::assertSent(function ($req) {
            $cuerpo = json_encode($req->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($cuerpo, 'empresas que compraron monel') && str_contains($cuerpo, '\"material\":\"monel\"') && str_contains($cuerpo, 'y de Córdoba');
        });
    }

    public function test_si_la_ia_se_cae_se_contesta_con_las_reglas_y_se_avisa(): void
    {
        config(['services.openai.key' => 'clave']);
        Http::fake(['*/chat/completions' => Http::response(['error' => 'x'], 500)]);
        $this->empresa('ALFA SA');

        $r = (new Cordes($this->admin))->responder('qué opinás del monel para agua de mar');

        // La IA no respondió: no se le muestra ningún error ni «necesito la IA»; el código busca y contesta con lo que tiene.
        $this->assertSame('reglas', $r['fuente']);
        $this->assertNull($r['aviso']);
        $this->assertNotNull($r['pedido']);
        $this->assertNotSame('', trim((string) $r['mensaje']));
    }

    public function test_una_lista_que_las_reglas_entienden_no_gasta_ia(): void
    {
        config(['services.openai.key' => 'clave']);
        Http::fake();
        $this->cotizacion($this->empresa('ALFA SA'), ['estado' => 'Confirmada']);

        $r = (new Cordes($this->admin))->responder('cotizaciones sin respuesta de este mes');

        Http::assertNothingSent();
        $this->assertSame('reglas', $r['fuente']);
        $this->assertSame('consultas', $r['analisis']['vista']);
    }

    /* -------------------------------------------------------------------- Respuesta */

    private function crudo(array $modelo, array $extra = []): array
    {
        return $extra + ['modelo' => $modelo, 'tablas' => [], 'verificacion' => ['ok' => true, 'sin_comprobar' => []], 'pasos' => [], 'ultimas_tablas' => []];
    }

    public function test_los_atajos_se_limitan_a_acciones_conocidas_e_ids_que_existen(): void
    {
        $e = $this->empresa('ALFA SA');
        $baja = Empresa::create(['nombre' => 'BAJA SA', 'activa' => false]);
        $c = $this->cotizacion($e);

        $r = (new Respuesta)->desdeLaIA($this->crudo(['mensaje' => 'ok', 'atajos' => [
            ['tipo' => 'empresa', 'etiqueta' => 'Abrir ALFA', 'id' => $e->id, 'ruta' => 'http://malo.example'],
            ['tipo' => 'empresa', 'etiqueta' => 'Empresa fantasma', 'id' => 99999],
            ['tipo' => 'nueva_cotizacion', 'etiqueta' => 'Cotizar a ALFA', 'empresa_id' => $e->id],
            ['tipo' => 'nueva_cotizacion', 'etiqueta' => 'Cotizar a la de baja', 'empresa_id' => $baja->id],
            ['tipo' => 'cotizacion', 'etiqueta' => 'Abrir la cotización', 'id' => $c->id],
            ['tipo' => 'reporte', 'etiqueta' => 'Reporte', 'reporte' => 'material'],
            ['tipo' => 'reporte', 'etiqueta' => 'Reporte raro', 'reporte' => '../../admin'],
            ['tipo' => 'borrar_todo', 'etiqueta' => 'Borrar'],
            ['tipo' => 'ver', 'etiqueta' => 'Ver cotizaciones', 'vista' => 'consultas', 'filtros' => ['estado' => 'vendidas', 'inventado' => 'x']],
        ]]));

        $rutas = collect($r['atajos'])->pluck('accion.ruta')->filter()->values()->all();

        $this->assertSame(["/empresas/{$e->id}", "/empresas/{$e->id}/agregar", "/consultas/{$c->id}", '/reportes?r=material'], $rutas);
        $this->assertCount(5, $r['atajos']);
        $ver = collect($r['atajos'])->firstWhere('etiqueta', 'Ver cotizaciones');
        $this->assertEquals(['grano' => 'consultas', 'estado' => 'Vendida'], $ver['accion']['pedido']);
    }

    public function test_el_texto_no_lleva_html_y_lo_que_se_muestra_es_lo_que_se_consulto(): void
    {
        // La IA intenta mostrar otra cosa (los filtros de otra búsqueda): se ignora. Manda lo que ejecutó la herramienta.
        $r = (new Respuesta)->desdeLaIA($this->crudo(
            ['mensaje' => 'Hola <script>alert(1)</script> **Monel**', 'vista' => 'consultas', 'filtros' => ['estado' => 'vendidas', 'sql' => '1; drop table']],
            ['pedido_de_herramientas' => ['grano' => 'renglones', 'material' => 'Monel 400']],
        ));

        $this->assertSame('Hola alert(1) **Monel**', $r['mensaje']);
        $this->assertSame(['grano' => 'renglones', 'material' => 'Monel 400'], $r['pedido']);

        // Si no consultó ninguna búsqueda, no hay nada para mostrar abajo.
        $this->assertNull((new Respuesta)->desdeLaIA($this->crudo(['mensaje' => 'x', 'vista' => 'consultas', 'filtros' => ['estado' => 'vendidas']]))['pedido']);
    }

    public function test_las_opciones_necesitan_al_menos_dos_y_se_recortan(): void
    {
        $una = (new Respuesta)->desdeLaIA($this->crudo(['mensaje' => 'x', 'opciones' => ['pregunta' => '¿Cuál?', 'opciones' => [['etiqueta' => 'A']]]]));
        $this->assertNull($una['opciones']);

        $dos = (new Respuesta)->desdeLaIA($this->crudo(['mensaje' => 'x', 'opciones' => ['pregunta' => '¿Cuál?', 'opciones' => [['etiqueta' => 'ALFA SA', 'enviar' => 'ficha de ALFA SA'], ['etiqueta' => 'ALFA SRL']]]]));
        $this->assertSame([['etiqueta' => 'ALFA SA', 'enviar' => 'ficha de ALFA SA'], ['etiqueta' => 'ALFA SRL', 'enviar' => 'ALFA SRL']], $dos['opciones']['opciones']);
    }

    /* --------------------------------------------------------------------- Analista */

    public function test_el_analista_cuenta_reparte_cambia_de_vista_y_dice_que_filtro_sobra(): void
    {
        $cba = $this->provincia('Córdoba');
        $sf = $this->provincia('Santa Fe');
        $monel = $this->monel();
        foreach ([['ALFA SA', $cba], ['BETA SA', $cba], ['GAMMA SA', $sf]] as [$n, $p]) {
            $e = $this->empresa($n, ['provincia_id' => $p->id]);
            $this->linea($this->cotizacion($e), ['material_id' => $monel->id, 'precio_unitario' => 10, 'importe' => 10]);
        }

        $a = (new Analista($this->admin))->analizar(['material' => 'monel']);

        $this->assertSame('empresas', $a['vista']);
        $this->assertSame(3, $a['total']);
        $this->assertSame('Encontré 3 empresas', $a['titulo']);
        $this->assertSame('Dónde están', $a['destacados'][0]['titulo']);
        $this->assertSame(['Córdoba' => 2, 'Santa Fe' => 1], array_column($a['destacados'][0]['items'], 'cantidad', 'etiqueta'));
        $this->assertSame(['provincia_id' => (string) $cba->id, 'material' => 'monel'], $a['destacados'][0]['items'][0]['pedido']);
        $this->assertSame(['consultas', 'renglones'], array_column($a['vistas'], 'vista'));

        // Nada con esa provincia y ese material... pero sin la provincia hay 3.
        $vacio = (new Analista($this->admin))->analizar(['material' => 'monel', 'provincia_id' => (string) $this->provincia('Salta')->id]);
        $this->assertSame('No encontré nada con eso', $vacio['titulo']);
        $this->assertSame('Sin el lugar hay 3', $vacio['relajar'][0]['etiqueta']);
        $this->assertSame(['material' => 'monel'], $vacio['relajar'][0]['pedido']);
    }

    /* ------------------------------------------------------------------ Controlador */

    public function test_hay_que_estar_logueado(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/cordes/preguntar', ['texto' => 'hola'])->assertUnauthorized();
    }

    public function test_el_pedido_se_valida(): void
    {
        $this->postJson('/api/cordes/preguntar', ['texto' => 'a'])->assertUnprocessable();
        $this->postJson('/api/cordes/preguntar', ['texto' => 'hola', 'historial' => [['rol' => 'hacker', 'texto' => 'x']]])->assertUnprocessable();
        $this->postJson('/api/cordes/preguntar', ['texto' => str_repeat('a', 401)])->assertUnprocessable();
    }

    public function test_sin_ia_el_codigo_contesta_el_peso_por_su_cuenta(): void
    {
        $this->monel();
        $this->barra();

        $r = $this->postJson('/api/cordes/preguntar', ['texto' => 'cuánto pesa una barra de monel de 20 x 1000 mm'])->assertOk();

        $this->assertSame('codigo', $r->json('fuente'));
        $this->assertStringContainsString('**2,7646 kg**', $r->json('mensaje'));
    }

    public function test_sin_ia_lo_que_el_codigo_no_entiende_se_dice_con_honestidad(): void
    {
        $r = $this->postJson('/api/cordes/preguntar', ['texto' => 'qué opinás del monel para agua de mar'])->assertOk();

        $this->assertSame('reglas', $r->json('fuente'));
        $this->assertStringContainsString('Busqué «qué opinás del monel para agua de mar» en todo el sistema', $r->json('mensaje'));
        $this->assertNotEmpty($r->json('seguir'));
        $this->assertNotNull($r->json('pedido'));
    }

    public function test_la_respuesta_en_vivo_manda_los_pasos_y_al_final_la_respuesta(): void
    {
        $this->monel();
        $this->ia(
            $this->llamada('material', ['texto' => 'monel']),
            $this->final(['mensaje' => 'El **Monel 400** tiene densidad 8,8 g/cm³.']),
        );

        $r = $this->post('/api/cordes/preguntar', ['texto' => 'comparame el monel con otros materiales', 'stream' => true], ['Accept' => 'application/x-ndjson']);
        $r->assertOk();
        $lineas = array_values(array_filter(explode("\n", $r->streamedContent())));
        $eventos = array_map(fn ($l) => json_decode($l, true), $lineas);

        $this->assertSame('paso', $eventos[0]['tipo']);
        $this->assertSame('Mirando el catálogo: monel…', $eventos[0]['texto']);
        $this->assertSame('fin', end($eventos)['tipo']);
        $this->assertSame('El **Monel 400** tiene densidad 8,8 g/cm³.', end($eventos)['respuesta']['mensaje']);
        $this->assertStringContainsString('ndjson', $r->headers->get('Content-Type'));
    }

    public function test_analizar_descarta_lo_que_el_buscador_no_entiende(): void
    {
        $this->cotizacion($this->empresa('ALFA SA'), ['estado' => 'Vendida']);

        $r = $this->postJson('/api/cordes/analizar', ['pedido' => ['grano' => 'consultas', 'estado' => 'Vendida', 'inventado' => 'x', 'por_pagina' => '9999']])->assertOk();

        $this->assertSame(1, $r->json('analisis.total'));
        $this->assertSame('consultas', $r->json('analisis.vista'));
    }

    public function test_el_agente_esta_configurado_solo_con_credencial(): void
    {
        config(['services.openai.key' => '']);
        $this->assertFalse(Agente::estaConfigurada());
        config(['services.openai.key' => 'x']);
        $this->assertTrue(Agente::estaConfigurada());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Localidad;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Moneda;
use App\Models\Pais;
use App\Models\Provincia;
use App\Models\Rubro;
use App\Models\User;
use App\Services\Buscador\Catalogo;
use App\Services\Buscador\Filtros;
use App\Services\Buscador\Interprete;
use App\Services\Buscador\Periodo;
use App\Services\Buscador\ResolverDePedido;
use App\Services\Buscador\Texto;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cordes IA: una pregunta escrita con las palabras de cada uno, traducida a un pedido
 * del Buscador. Hoy, en estas pruebas, es miércoles 4 de noviembre de 2026.
 * Nombres ficticios: el repo es público.
 */
class CordesIATest extends TestCase
{
    use RefreshDatabase;

    private Provincia $cordoba;

    private Provincia $santaFe;

    private Localidad $rosario;

    private Forma $redonda;

    private Forma $cano;

    private Moneda $dolar;

    private Moneda $pesos;

    private User $ric;

    private Rubro $rubro;

    protected function setUp(): void
    {
        parent::setUp();

        // Sin credencial de IA: lo que se prueba acá es el modo básico. La IA, aparte.
        config(['services.openai.key' => null]);

        $argentina = Pais::create(['nombre' => 'Argentina']);
        $this->cordoba = Provincia::create(['nombre' => 'Córdoba', 'pais_id' => $argentina->id]);
        $this->santaFe = Provincia::create(['nombre' => 'Santa Fe', 'pais_id' => $argentina->id]);
        Provincia::create(['nombre' => 'Buenos Aires', 'pais_id' => $argentina->id]);
        $this->rosario = Localidad::create(['nombre' => 'Rosario', 'provincia_id' => $this->santaFe->id]);
        $this->rubro = Rubro::create(['nombre' => 'CALDERERIA']);

        Material::create(['nombre' => 'Monel']);
        $monel400 = Material::create(['nombre' => 'Monel 400']);
        MaterialAlias::create(['material_id' => $monel400->id, 'alias' => 'MONEL400']);
        Material::create(['nombre' => 'Titanio']);
        Material::create(['nombre' => 'Titanio Grado 2']);
        Material::create(['nombre' => 'Acero AISI 316L']);
        Material::create(['nombre' => 'Inconel 625']);

        $this->redonda = Forma::create(['nombre' => 'BARRA REDONDA', 'clave' => 'barra_redonda']);
        Forma::create(['nombre' => 'BARRA CUADRADA', 'clave' => 'barra_cuadrada']);
        $this->cano = Forma::create(['nombre' => 'CAÑO', 'clave' => 'cano']);
        Forma::create(['nombre' => 'CHAPA', 'clave' => 'chapa']);

        $this->dolar = Moneda::create(['nombre' => 'DOLAR BILLETE BNA VENDEDOR', 'moneda_base' => 'USD']);
        $this->pesos = Moneda::create(['nombre' => 'PESOS', 'moneda_base' => 'ARS']);

        $this->ric = User::factory()->create(['name' => 'Ricardo Prueba', 'iniciales' => 'RIC', 'activo' => true]);
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-04', 'America/Argentina/Buenos_Aires');
    }

    /** @return array{pedido: array<string, string>, fuente: string, mensaje: ?string, no_entendi: list<string>, aviso: ?string} */
    private function pregunta(string $texto): array
    {
        return (new Interprete($this->hoy()))->interpretar($texto);
    }

    /** @param  array<string, string>  $esperado */
    private function assertPedido(array $esperado, string $texto): void
    {
        $r = $this->pregunta($texto);

        ksort($esperado);
        $obtenido = $r['pedido'];
        ksort($obtenido);

        $this->assertSame($esperado, $obtenido, "«{$texto}» se entendió distinto. No entendió: ".implode(', ', $r['no_entendi']));
    }

    /* ------------------------------------------------------------- empresas */

    public function test_a_que_empresas_les_cotice_tal_material(): void
    {
        $this->assertPedido(
            ['material' => 'Monel', 'desde' => '2026-01-01', 'hasta' => '2026-12-31'],
            'a qué empresas les cotice monel este año',
        );
    }

    public function test_hace_mas_de_un_tiempo_que_no_les_cotizo(): void
    {
        $this->assertPedido(['sin_cotizar_meses' => '12'], 'empresas que no les cotizo hace más de un año');
        $this->assertPedido(['sin_cotizar_meses' => '6'], 'hace más de 6 meses que no les cotizo');
        $this->assertPedido(['relacion' => 'Cliente', 'sin_cotizar_meses' => '3'], 'clientes sin cotizar hace tres meses');
    }

    public function test_los_clientes_de_una_provincia_que_hace_tiempo_no_cotizan(): void
    {
        $this->assertPedido(
            ['relacion' => 'Cliente', 'provincia_id' => (string) $this->cordoba->id, 'sin_cotizar_meses' => '6'],
            'clientes de Córdoba sin cotizar hace 6 meses',
        );
    }

    public function test_una_localidad_se_reconoce_cuando_se_la_nombra_con_en_o_de(): void
    {
        $this->assertPedido(['localidad_id' => (string) $this->rosario->id], 'empresas de Rosario');
        $this->assertPedido(['localidad_id' => (string) $this->rosario->id], 'las que están en Rosario');
    }

    public function test_a_quien_le_cotizamos_es_una_pregunta_por_empresas(): void
    {
        // "a quién" es la forma más natural de preguntarlo: tiene que dar la lista de empresas, no la de renglones.
        $this->assertPedido(['material' => 'Titanio Grado 2'], 'a quién le cotizamos titanio grado 2');
        // "Compraron" es una compra, no que se les cotizó: se pide lo vendido.
        $this->assertPedido(['material' => 'Monel', 'compra' => 'vendida'], 'quiénes compraron monel');
        $this->assertPedido(['material' => 'Monel'], 'a quienes les cotizamos monel');
        $this->assertPedido(['material' => 'Monel', 'provincia_id' => (string) $this->cordoba->id], 'empresas que compran monel y son de Córdoba');
    }

    public function test_un_codigo_de_aleacion_suelto_es_el_material(): void
    {
        // "Cotizaciones de" es de cotizaciones; "barras de" o "cuánto", de lo cotizado.
        $this->assertPedido(['grano' => 'consultas', 'material' => '316l'], 'cotizaciones de 316L');
        $this->assertPedido(['grano' => 'renglones', 'material' => '316l'], 'cuánto cotizamos de 316L');
        $this->assertPedido(['grano' => 'renglones', 'forma_id' => (string) $this->redonda->id, 'material' => '316l'], 'barras de acero inoxidable 316L');
    }

    public function test_un_trimestre_son_tres_meses(): void
    {
        $this->assertPedido(['grano' => 'consultas', 'compra' => 'parcial', 'desde' => '2026-08-04', 'hasta' => '2026-11-04'], 'ventas con compra parcial del último trimestre');
    }

    public function test_sin_decir_que_se_quiere_ver_un_periodo_es_lo_que_se_movio(): void
    {
        $this->assertPedido(['grano' => 'consultas', 'desde' => '2026-03-01', 'hasta' => '2026-03-31'], 'lo que se cotizó en marzo');
    }

    public function test_whatsapp_y_mail_juntos_y_borradores_sin_emitir(): void
    {
        $this->assertPedido(['relacion' => 'Cliente', 'con_mail' => '1', 'con_whatsapp' => '1'], 'los clientes que tienen mail y whatsapp');
        $this->assertPedido(['grano' => 'consultas', 'estado' => 'Borrador'], 'borradores sin emitir');
    }

    public function test_un_numero_suelto_no_es_una_empresa(): void
    {
        $this->assertPedido(['grano' => 'consultas', 'estado' => 'Vendida', 'q' => '4471'], 'vendidas 4471');
        // Un 2 suelto tampoco es una empresa: sin nada que entender, se busca como texto.
        $r = $this->pregunta('cotizaciones de 2 pulgadas');
        $this->assertArrayNotHasKey('empresa', $r['pedido']);
        $this->assertSame('cotizaciones de 2 pulgadas', $r['pedido']['q']);
    }

    public function test_un_texto_raro_no_rompe_nada(): void
    {
        foreach (['', '   ', '¿¿??!!', '😀😀', str_repeat('palabra ', 200), "línea uno\nlínea dos\ttab", '<script>alert(1)</script>', "'; drop table empresas; --", '%%%', '0'] as $texto) {
            $r = $this->pregunta($texto);

            $this->assertIsArray($r['pedido']);
            $this->assertSame('reglas', $r['fuente']);
            // Lo que sea que haya salido, el Buscador lo acepta.
            $this->assertNotNull(Filtros::deArray($r['pedido']));
        }
    }

    public function test_el_rubro_hay_que_decirlo_con_la_palabra_rubro(): void
    {
        $this->assertPedido(['rubro_id' => (string) $this->rubro->id], 'empresas del rubro caldereria');
    }

    public function test_con_whatsapp_con_mail_inactivas_y_activas(): void
    {
        $this->assertPedido(['con_whatsapp' => '1'], 'empresas con whatsapp');
        $this->assertPedido(['con_mail' => '1'], 'empresas que tienen mail');
        $this->assertPedido(['activa' => 'inactivas'], 'las empresas inactivas');
        $this->assertPedido(['activa' => 'inactivas'], 'empresas dadas de baja');
        $this->assertPedido(['activa' => 'activas'], 'sólo las activas');
    }

    /* ---------------------------------------------------------- cotizaciones */

    public function test_cotizaciones_sin_respuesta_y_por_vencer(): void
    {
        $this->assertPedido(['grano' => 'consultas', 'sin_respuesta' => '1'], 'cotizaciones sin respuesta');
        $this->assertPedido(['grano' => 'consultas', 'por_vencer' => '7'], 'cotizaciones que vencen esta semana');
        $this->assertPedido(['grano' => 'consultas', 'por_vencer' => '15'], 'las que vencen en los próximos 15 días');
    }

    public function test_ventas_con_compra_parcial_o_total(): void
    {
        $this->assertPedido(['grano' => 'consultas', 'compra' => 'parcial'], 'ventas con compra parcial');
        $this->assertPedido(['grano' => 'consultas', 'compra' => 'total'], 'las vendidas con compra total');
    }

    public function test_estados_tipos_y_moneda(): void
    {
        $this->assertPedido(['grano' => 'consultas', 'estado' => 'Borrador'], 'borradores');
        $this->assertPedido(['grano' => 'consultas', 'estado' => 'Vendida', 'moneda_id' => (string) $this->pesos->id], 'cotizaciones vendidas en pesos');
        $this->assertPedido(['grano' => 'consultas', 'tipo' => 'Pedido', 'desde' => '2026-10-26', 'hasta' => '2026-11-01'], 'pedidos de la semana pasada');
    }

    public function test_quien_hizo_la_cotizacion(): void
    {
        $this->assertPedido(['grano' => 'consultas', 'usuario_id' => (string) $this->ric->id], 'cotizaciones hechas por RIC');
        $this->assertPedido(['grano' => 'consultas', 'usuario_id' => (string) $this->ric->id], 'las que hizo Ricardo');
    }

    public function test_la_orden_de_compra_y_los_numeros(): void
    {
        $this->assertPedido(['grano' => 'consultas', 'orden_de_compra' => '4471'], 'orden de compra 4471');
        $this->assertPedido(['grano' => 'consultas', 'orden_de_compra' => 'OC-88'], 'cotizaciones con OC OC-88');
        $this->assertPedido(['grano' => 'consultas', 'q' => '2026-0011'], 'la cotización 2026-0011');
        $this->assertPedido(['q' => '30-12345678-9'], 'empresa con CUIT 30123456789');
    }

    public function test_un_nombre_propio_es_una_empresa(): void
    {
        $this->assertPedido(
            ['grano' => 'consultas', 'empresa' => 'ACME', 'desde' => '2025-11-04', 'hasta' => '2026-11-04'],
            'cotizaciones de ACME del último año',
        );
        $this->assertPedido(['q' => 'Acme Metales'], 'Mostrame Acme Metales');
    }

    /* ------------------------------------------------------------- material */

    public function test_la_condicion_frente_al_iva_es_la_de_la_ficha_de_la_empresa(): void
    {
        $this->assertPedido(['condicion_iva' => 'Responsable Inscripto'], 'empresas responsables inscriptos');
        $this->assertPedido(['relacion' => 'Cliente', 'condicion_iva' => 'Monotributo'], 'clientes monotributistas');
    }

    public function test_cuanto_le_cotizamos_un_material_en_dolares(): void
    {
        $this->assertPedido(
            ['grano' => 'renglones', 'material' => 'Monel', 'moneda_id' => (string) $this->dolar->id],
            'cuánto le cotizamos monel en dólares',
        );
    }

    public function test_barras_de_titanio_de_20_a_30_mm(): void
    {
        $this->assertPedido(
            ['grano' => 'renglones', 'material' => 'Titanio', 'forma_id' => (string) $this->redonda->id, 'diametro_desde' => '20', 'diametro_hasta' => '30'],
            'barras de titanio de 20 a 30 mm',
        );
        $this->assertPedido(
            ['grano' => 'renglones', 'forma_id' => (string) $this->cano->id, 'diametro_desde' => '50'],
            'caños mayores a 50 mm',
        );
    }

    public function test_un_material_dicho_con_su_alias_se_pide_con_su_nombre(): void
    {
        $this->assertPedido(['grano' => 'renglones', 'material' => 'Monel 400'], 'monel400');
    }

    public function test_una_palabra_suelta_es_un_material_o_un_texto(): void
    {
        $this->assertPedido(['grano' => 'renglones', 'material' => 'Titanio'], 'titanio');
        $this->assertPedido(['q' => 'acme'], 'acme');
    }

    /* ----------------------------------------------------------- comprobantes */

    public function test_facturas_y_remitos(): void
    {
        $this->assertPedido(['grano' => 'comprobantes', 'tipo_comprobante' => 'Factura', 'desde' => '2026-10-01', 'hasta' => '2026-10-31'], 'facturas de octubre');
        $this->assertPedido(['grano' => 'comprobantes', 'tipo_comprobante' => 'Remito', 'desde' => '2025-01-01', 'hasta' => '2025-12-31'], 'remitos del año pasado');
        $this->assertPedido(['grano' => 'comprobantes', 'comprobante' => '0001-00001234'], 'factura 0001-00001234');
    }

    /* ---------------------------------------------------------------- fechas */

    public function test_los_periodos_se_calculan_con_la_fecha_de_hoy(): void
    {
        $dia = fn (array $o) => $this->pregunta('cotizaciones '.$o[0])['pedido'];

        foreach ([
            ['hoy', '2026-11-04', '2026-11-04'],
            ['ayer', '2026-11-03', '2026-11-03'],
            ['esta semana', '2026-11-02', '2026-11-08'],
            ['este mes', '2026-11-01', '2026-11-30'],
            ['el mes pasado', '2026-10-01', '2026-10-31'],
            ['el año pasado', '2025-01-01', '2025-12-31'],
            ['en 2025', '2025-01-01', '2025-12-31'],
            ['en marzo', '2026-03-01', '2026-03-31'],
            ['en diciembre', '2025-12-01', '2025-12-31'],
            ['en marzo de 2024', '2024-03-01', '2024-03-31'],
            ['de los últimos 3 meses', '2026-08-04', '2026-11-04'],
            ['del 1 al 15 de octubre', '2026-10-01', '2026-10-15'],
            ['desde el 1/10 hasta el 15/10', '2026-10-01', '2026-10-15'],
            ['entre el 5/9/2025 y el 20/9/2025', '2025-09-05', '2025-09-20'],
            ['desde el 1 de octubre', '2026-10-01', null],
        ] as [$frase, $desde, $hasta]) {
            $p = $dia([$frase]);
            $this->assertSame($desde, $p['desde'] ?? null, "desde de «{$frase}»");
            $this->assertSame($hasta, $p['hasta'] ?? null, "hasta de «{$frase}»");
        }
    }

    public function test_un_periodo_calculado_por_el_codigo_y_no_por_la_ia(): void
    {
        $hoy = $this->hoy();

        $this->assertSame(['desde' => '2026-11-02', 'hasta' => '2026-11-08'], Periodo::de('esta_semana', $hoy));
        $this->assertSame(['desde' => '2025-11-04', 'hasta' => '2026-11-04'], Periodo::de('ultimo_anio', $hoy));
        $this->assertSame(['desde' => '2026-05-04', 'hasta' => '2026-11-04'], Periodo::de('ultimos_6_meses', $hoy));
        $this->assertNull(Periodo::de('cuando_sea', $hoy));
        // Enero: el mes pasado es diciembre del año anterior.
        $enero = CarbonImmutable::parse('2026-01-15');
        $this->assertSame(['desde' => '2025-12-01', 'hasta' => '2025-12-31'], Periodo::de('mes_pasado', $enero));
    }

    public function test_las_palabras_se_leen_sin_tildes_ni_plurales(): void
    {
        $this->assertSame('cotizacion de cano 0001-00001234', Texto::normalizar('¡Cotización de CAÑO, 0001-00001234!'));
        $this->assertTrue(Texto::mismaFrase('barra redonda', 'barras redondas'));
        $this->assertTrue(Texto::mismaFrase('cliente', 'clientes'));
        $this->assertTrue(Texto::mismaFrase('alambre', 'alambres'));
        $this->assertFalse(Texto::mismaFrase('barra', 'barra redonda'));
        $this->assertSame(6, Texto::numero('6'));
        $this->assertSame(1, Texto::numero('un'));
        $this->assertNull(Texto::fecha('31/02/2026', $this->hoy()));
    }

    /* --------------------------------------------------------------- resolver */

    public function test_el_resolvedor_descarta_lo_que_no_existe_y_lo_dice(): void
    {
        $r = (new ResolverDePedido(new Catalogo($this->hoy())))->resolver([
            'vista' => 'comprobantes',
            'rubro' => 'Algo que no existe',
            'provincia' => 'Atlántida',
            'quien' => 'Fulano',
            'tipo_comprobante' => 'Pagaré',
            'estado' => 'Soñada',
            'compra' => 'a medias',
            'activa' => 'tal vez',
            'por_vencer_dias' => 9999,
            'sin_cotizar_meses' => -3,
            'desde' => '2026-02-31',
            'periodo' => 'cuando_sea',
            'filtro_inventado' => 'x',
            'pagina' => 7,
        ]);

        $this->assertSame(['grano' => 'comprobantes'], $r['pedido']);
        $this->assertSame(
            ['rubro «Algo que no existe»', 'provincia «Atlántida»', 'estado «Soñada»', 'quién la hizo «Fulano»', 'tipo de comprobante «Pagaré»'],
            $r['no_entendi'],
        );
    }

    public function test_el_resolvedor_acepta_lo_valido_y_pone_en_orden_lo_demas(): void
    {
        $r = (new ResolverDePedido(new Catalogo($this->hoy())))->resolver([
            'vista' => 'renglones',
            'material' => 'monel400',
            'diametro_desde' => 40,
            'diametro_hasta' => 10,
            'desde' => '2026-10-20',
            'hasta' => '2026-10-01',
            'con_whatsapp' => 'true',
            'moneda' => 'U$S',
            'forma' => 'barras',
            'texto' => str_repeat('x', 500),
        ]);

        $this->assertSame('renglones', $r['pedido']['grano']);
        $this->assertSame('Monel 400', $r['pedido']['material']);
        $this->assertSame(['10', '40'], [$r['pedido']['diametro_desde'], $r['pedido']['diametro_hasta']]);
        $this->assertSame(['2026-10-01', '2026-10-20'], [$r['pedido']['desde'], $r['pedido']['hasta']]);
        $this->assertSame('1', $r['pedido']['con_whatsapp']);
        $this->assertSame((string) $this->dolar->id, $r['pedido']['moneda_id']);
        $this->assertSame((string) $this->redonda->id, $r['pedido']['forma_id']);
        $this->assertSame(200, mb_strlen($r['pedido']['q']));
        $this->assertSame([], $r['no_entendi']);
    }

    public function test_sin_decir_la_vista_la_dicen_los_filtros(): void
    {
        $vista = fn (array $b) => (new ResolverDePedido(new Catalogo($this->hoy())))->resolver($b)['pedido']['grano'] ?? 'empresas';

        $this->assertSame('empresas', $vista(['provincia' => 'Córdoba']));
        $this->assertSame('consultas', $vista(['sin_respuesta' => true]));
        $this->assertSame('renglones', $vista(['material' => 'Titanio']));
        $this->assertSame('comprobantes', $vista(['comprobante' => '0001-1']));
    }

    /* ------------------------------------------------------------------- IA */

    private function conIA(): void
    {
        config(['services.openai.key' => 'clave-de-prueba', 'services.openai.url' => 'https://ia.test/v1']);
    }

    private function laIADice(array $borrador, int $estado = 200): void
    {
        Http::fake(['ia.test/*' => Http::response(
            $estado === 200 ? ['choices' => [['message' => ['content' => json_encode($borrador)]]]] : ['error' => 'x'],
            $estado,
        )]);
    }

    public function test_si_las_reglas_entendieron_todo_no_se_gasta_la_ia(): void
    {
        $this->conIA();
        Http::fake();

        $r = $this->pregunta('cotizaciones sin respuesta del último año');

        $this->assertSame('reglas', $r['fuente']);
        Http::assertNothingSent();
    }

    public function test_con_palabras_que_las_reglas_no_entienden_pregunta_la_ia(): void
    {
        $this->conIA();
        $this->laIADice([
            'vista' => 'empresas', 'material' => 'Titanio', 'provincia' => 'Santa Fe',
            'periodo' => 'anio_pasado', 'mensaje' => 'Busco las empresas de Santa Fe a las que les cotizaste titanio el año pasado.',
        ]);

        $r = $this->pregunta('qué firmas del litoral santafesino pidieron precio de titanio durante el ejercicio anterior');

        $this->assertSame('ia', $r['fuente']);
        $this->assertEquals([
            'material' => 'Titanio', 'provincia_id' => (string) $this->santaFe->id, 'desde' => '2025-01-01', 'hasta' => '2025-12-31',
        ], $r['pedido']);
        $this->assertStringContainsString('Santa Fe', $r['mensaje']);

        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer clave-de-prueba')
            && str_contains($req['messages'][0]['content'], 'Hoy es miércoles 2026-11-04')
            && $req['messages'][1]['content'] === 'qué firmas del litoral santafesino pidieron precio de titanio durante el ejercicio anterior'
            && $req['response_format'] === ['type' => 'json_object']
            && $req['temperature'] === 0);
    }

    public function test_lo_que_inventa_la_ia_no_llega_al_buscador(): void
    {
        $this->conIA();
        $this->laIADice([
            'vista' => 'todo', 'material' => 'Titanio', 'borrar_todo' => true, 'sql' => 'drop table empresas',
            'provincia' => 'Narnia', 'periodo' => 'el_ano_que_viene', 'por_vencer_dias' => 'muchos',
            'mensaje' => '<script>alert(1)</script>Busco titanio.',
        ]);

        $r = $this->pregunta('asdf qwer zxcv poiu lkjh');

        $this->assertSame('ia', $r['fuente']);
        $this->assertEquals(['grano' => 'renglones', 'material' => 'Titanio'], $r['pedido']);
        $this->assertSame(['provincia «Narnia»'], $r['no_entendi']);
        $this->assertSame('alert(1)Busco titanio.', $r['mensaje']);
    }

    public function test_lo_que_una_ia_suele_devolver_de_mas_se_limpia(): void
    {
        $this->conIA();
        $this->laIADice([
            'vista' => 'comprobantes', 'empresa' => 'Acme', 'fecha_de' => 'factura', 'periodo' => 'anio_pasado',
            // Ceros por "no dijo", el material con la forma adentro y el texto repetido: así contestan los modelos chicos.
            'diametro_desde' => 0, 'diametro_hasta' => 0, 'material' => 'barras de monel', 'forma' => 'barras', 'texto' => 'barras monel',
        ]);

        $r = $this->pregunta('qué le facturamos a los de la sigla esa el ejercicio pasado en barras de monel');

        $this->assertSame('ia', $r['fuente']);
        $this->assertEquals([
            'grano' => 'comprobantes', 'empresa' => 'Acme', 'desde' => '2025-01-01', 'hasta' => '2025-12-31',
            'material' => 'Monel', 'forma_id' => (string) $this->redonda->id,
        ], $r['pedido']);
    }

    public function test_lo_que_la_ia_pasa_por_alto_y_las_reglas_reconocen_se_completa(): void
    {
        $this->conIA();
        // La IA se olvida de Rosario y encima dice que no pudo con eso.
        $this->laIADice([
            'vista' => 'consultas', 'material' => 'monel', 'sin_respuesta' => true,
            'no_entendi' => ['grandes', 'de Rosario'], 'mensaje' => 'No pude filtrar por tamaño ni por región.',
        ]);

        $r = $this->pregunta('decime a cuáles de las empresas grandes de Rosario les mandamos presupuesto de barras de monel y todavía no contestaron');

        $this->assertSame('ia', $r['fuente']);
        $this->assertSame((string) $this->rosario->id, $r['pedido']['localidad_id']);
        $this->assertSame('Monel', $r['pedido']['material']);
        // Lo que ya no es cierto se saca; lo que sigue sin entenderse, se queda.
        $this->assertSame(['grandes'], $r['no_entendi']);
        $this->assertNull($r['mensaje']);
    }

    public function test_si_la_ia_dijo_algo_las_reglas_no_la_corrigen(): void
    {
        $this->conIA();
        $this->laIADice(['vista' => 'consultas', 'provincia' => 'Santa Fe', 'sin_respuesta' => true, 'desde' => '2026-01-01', 'hasta' => '2026-03-31']);

        $r = $this->pregunta('las que no contestaron en Córdoba durante el verano que pasó');

        // La IA eligió Santa Fe y sus fechas: las reglas no las pisan (sólo llenan huecos).
        $this->assertSame((string) $this->santaFe->id, $r['pedido']['provincia_id']);
        $this->assertSame('2026-01-01', $r['pedido']['desde']);
    }

    public function test_una_ia_que_solo_acierta_la_vista_no_pisa_a_las_reglas(): void
    {
        $this->conIA();
        $this->laIADice(['vista' => 'renglones', 'diametro_desde' => 0]);

        $r = $this->pregunta('cotizaciones sin respuesta de las firmas grandotas');

        $this->assertSame('reglas', $r['fuente']);
        $this->assertEquals(['grano' => 'consultas', 'sin_respuesta' => '1'], $r['pedido']);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function fallasDeLaIA(): array
    {
        return ['credencial vencida' => [401], 'sin crédito' => [429], 'servicio caído' => [503]];
    }

    #[DataProvider('fallasDeLaIA')]
    public function test_si_la_ia_falla_se_sigue_con_lo_que_entendieron_las_reglas(int $estado): void
    {
        $this->conIA();
        $this->laIADice([], $estado);

        $r = $this->pregunta('cotizaciones sin respuesta de las firmas grandotas');

        $this->assertSame('reglas', $r['fuente']);
        $this->assertEquals(['grano' => 'consultas', 'sin_respuesta' => '1'], $r['pedido']);
        $this->assertNotNull($r['aviso']);
        $this->assertStringContainsString('modo básico', $r['aviso']);
        $this->assertContains('grandotas', $r['no_entendi']);
    }

    public function test_si_la_ia_no_entiende_nada_no_pisa_lo_que_entendieron_las_reglas(): void
    {
        $this->conIA();
        $this->laIADice(['no_entendi' => ['todo']]);

        $r = $this->pregunta('cotizaciones sin respuesta de las firmas grandotas');

        $this->assertSame('reglas', $r['fuente']);
        $this->assertEquals(['grano' => 'consultas', 'sin_respuesta' => '1'], $r['pedido']);
    }

    public function test_sin_ia_lo_que_no_se_entiende_como_pregunta_se_busca_como_texto(): void
    {
        $r = $this->pregunta('acme sa buenos aires');

        // Buenos Aires es una provincia; "acme sa" queda como nombre.
        $this->assertSame('reglas', $r['fuente']);
        $this->assertArrayHasKey('provincia_id', $r['pedido']);

        $r = $this->pregunta('zzz yyy');
        $this->assertSame(['q' => 'zzz yyy'], $r['pedido']);
        $this->assertStringContainsString('como texto', $r['mensaje']);
    }

    /* -------------------------------------------------------------- endpoint */

    public function test_el_endpoint_pide_sesion_y_texto(): void
    {
        $this->postJson('/api/buscar/interpretar', ['texto' => 'monel'])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));

        $this->postJson('/api/buscar/interpretar', [])->assertUnprocessable();
        $this->postJson('/api/buscar/interpretar', ['texto' => str_repeat('a', 401)])->assertUnprocessable();
        $this->postJson('/api/buscar/interpretar', ['texto' => 'monel'])
            ->assertOk()
            ->assertJsonStructure(['pedido', 'fuente', 'mensaje', 'no_entendi', 'aviso'])
            ->assertJsonPath('pedido.material', 'Monel')
            ->assertJsonPath('fuente', 'reglas');
    }

    public function test_lo_que_interpreta_siempre_lo_acepta_el_buscador(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));
        Empresa::create(['nombre' => 'ALFA SA']);

        foreach ([
            'a qué empresas les cotice monel este año', 'clientes de Córdoba sin cotizar hace 6 meses', 'cotizaciones sin respuesta',
            'ventas con compra parcial', 'facturas de octubre', 'barras de titanio de 20 a 30 mm', 'orden de compra 4471',
            'cuánto le cotizamos monel en dólares', 'pedidos de la semana pasada', 'empresas inactivas', 'zzz yyy',
            'cotizaciones hechas por RIC del 1 al 15 de octubre',
        ] as $frase) {
            $pedido = $this->postJson('/api/buscar/interpretar', ['texto' => $frase])->assertOk()->json('pedido');

            $this->getJson('/api/buscar?'.http_build_query($pedido))->assertOk();
        }
    }
}

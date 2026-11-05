<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaComprobante;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Material;
use App\Models\Moneda;
use App\Models\Permiso;
use App\Models\User;
use App\Services\Reportes\FiltrosDeReporte;
use App\Services\Reportes\ReporteDeMateriales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los reportes: por material, por empresa (o por año), compras y comprobantes, ranking y vencimientos.
 * Un escenario chico, armado a mano, para poder verificar cada número. Nombres ficticios.
 *
 *   ALFA (activa)   C1 2025 confirmada USD: Monel 100, Titanio 50
 *                   C2 2026 vendida USD, compra parcial (se marcó Monel): Monel 200, Titanio 100
 *   BETA (activa)   C3 2026 vendida PESOS, compra total (no se marcó nada): Monel 1000, Titanio 500
 *                   C4 2026 confirmada USD, vence en 3 días: Monel 300 (+ una alternativa de 999 que no suma)
 *   GAMMA (inactiva) C5 2026 confirmada USD: un renglón sin material enlazado, 40
 */
class ReportesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ric;

    private Moneda $dolar;

    private Moneda $pesos;

    private Material $monel;

    private Material $titanio;

    private Empresa $alfa;

    private Empresa $beta;

    private Empresa $gamma;

    /** @var array<string, Consulta> */
    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'Administrador', 'iniciales' => 'ADM']);
        $this->ric = User::factory()->create(['role' => 'Administrador', 'iniciales' => 'RIC']);
        Sanctum::actingAs($this->admin);

        $this->dolar = Moneda::create(['nombre' => 'DOLAR BILLETE BNA VENDEDOR', 'moneda_base' => 'USD']);
        $this->pesos = Moneda::create(['nombre' => 'PESOS', 'moneda_base' => 'ARS']);
        $this->monel = Material::create(['nombre' => 'Monel']);
        $this->titanio = Material::create(['nombre' => 'Titanio']);
        $this->alfa = Empresa::create(['nombre' => 'ALFA SA']);
        $this->beta = Empresa::create(['nombre' => 'BETA SA']);
        $this->gamma = Empresa::create(['nombre' => 'GAMMA SA', 'activa' => false]);

        $this->c['C1'] = $this->cotizacion($this->alfa, '2025-03-10', 'Confirmada', $this->dolar, [[$this->monel, 100], [$this->titanio, 50]]);
        $this->c['C2'] = $this->cotizacion($this->alfa, '2026-02-05', 'Vendida', $this->dolar, [[$this->monel, 200, ['comprada' => true]], [$this->titanio, 100]], $this->ric);
        $this->c['C3'] = $this->cotizacion($this->beta, '2026-03-12', 'Vendida', $this->pesos, [[$this->monel, 1000], [$this->titanio, 500]]);
        $this->c['C4'] = $this->cotizacion($this->beta, '2026-04-01', 'Confirmada', $this->dolar, [[$this->monel, 300]], null, ['vence_el' => now()->addDays(3)->toDateString()]);
        $this->c['C5'] = $this->cotizacion($this->gamma, '2026-05-01', 'Confirmada', $this->dolar, [[null, 40]]);

        // Una alternativa del renglón de Monel de C4: se ofrece, no suma.
        $madre = $this->c['C4']->lineas()->first();
        ConsultaLinea::create(['consulta_id' => $this->c['C4']->id, 'orden' => 9, 'descripcion' => 'ALTERNATIVA', 'material_id' => $this->monel->id, 'importe' => 999, 'alternativa_de_id' => $madre->id]);

        // Lo que NO tiene que contarse: un borrador, un pedido, una descartada y renglones quitados o sin cotizar.
        $this->cotizacion($this->alfa, '2026-06-01', 'Borrador', $this->dolar, [[$this->monel, 7777]]);
        $pedido = $this->cotizacion($this->alfa, '2026-06-02', 'Confirmada', $this->dolar, [[$this->monel, 8888]]);
        $pedido->update(['tipo' => 'Pedido']);
        $this->cotizacion($this->alfa, '2026-06-03', 'Confirmada', $this->dolar, [[$this->monel, 9999]])->delete();
        ConsultaLinea::create(['consulta_id' => $this->c['C2']->id, 'orden' => 8, 'descripcion' => 'Q', 'material_id' => $this->monel->id, 'importe' => 5555, 'quitada' => true]);
        ConsultaLinea::create(['consulta_id' => $this->c['C2']->id, 'orden' => 9, 'descripcion' => 'N', 'material_id' => $this->monel->id, 'importe' => 4444, 'no_cotizada' => true]);

        // Comprobantes.
        ConsultaComprobante::create(['consulta_id' => $this->c['C2']->id, 'tipo' => 'Factura A', 'numero' => '1', 'fecha' => '2026-02-20', 'importe' => 500]);
        ConsultaComprobante::create(['consulta_id' => $this->c['C2']->id, 'tipo' => 'Remito', 'numero' => '2', 'fecha' => '2026-02-18']);
        ConsultaComprobante::create(['consulta_id' => $this->c['C3']->id, 'tipo' => 'Factura', 'numero' => '3', 'fecha' => '2026-03-30', 'importe' => 1500]);
        ConsultaComprobante::create(['consulta_id' => $this->c['C3']->id, 'tipo' => 'Nota de crédito', 'numero' => '4', 'fecha' => '2026-03-31', 'importe' => 100]);
    }

    /** @param  list<array{0: ?Material, 1: float, 2?: array<string, mixed>}>  $lineas */
    private function cotizacion(Empresa $e, string $fecha, string $estado, Moneda $m, array $lineas, ?User $quien = null, array $extra = []): Consulta
    {
        static $n = 0;
        $c = Consulta::create($extra + [
            'empresa_id' => $e->id, 'tipo' => 'Cotizacion', 'fecha' => $fecha, 'estado' => $estado,
            'usuario_id' => ($quien ?? $this->admin)->id, 'moneda_id' => $m->id,
        ]);

        foreach ($lineas as $l) {
            ConsultaLinea::create(($l[2] ?? []) + [
                'consulta_id' => $c->id, 'orden' => ++$n, 'descripcion' => 'RENGLON '.$n,
                'material_id' => $l[0]?->id, 'pedido_material' => $l[0] ? null : 'algo del sistema viejo', 'importe' => $l[1],
            ]);
        }

        return $c;
    }

    private function reporte(string $nombre, array $q = []): TestResponse
    {
        return $this->getJson("/api/reportes/{$nombre}?".http_build_query($q))->assertOk();
    }

    /** La fila de un reporte cuya primera columna vale $valor. */
    private function fila(TestResponse $r, string $columna, string $valor): array
    {
        return collect($r->json('filas'))->firstWhere($columna, $valor) ?? $this->fail("No hay fila con {$columna} = {$valor}");
    }

    private function claveDeImporte(TestResponse $r, string $titulo): string
    {
        return collect($r->json('columnas'))->firstWhere('titulo', $titulo)['clave'] ?? $this->fail("No hay columna «{$titulo}»");
    }

    /* ---------------------------------------------------------------- material */

    public function test_por_material_cuenta_renglones_empresas_y_vendidos(): void
    {
        $r = $this->reporte('material');
        $monel = $this->fila($r, 'material', 'Monel');

        // C1, C2, C3 y C4: la alternativa, el renglón quitado y el sin cotizar no cuentan.
        $this->assertSame(4, $monel['renglones']);
        $this->assertSame(4, $monel['cotizaciones']);
        $this->assertSame(2, $monel['empresas']);
        // Vendidos: el marcado de C2 y el de C3 (compra total: no se marcó nada).
        $this->assertSame(2, $monel['vendidos']);
        $this->assertEquals(50, $monel['vendidos_pct']);
        $this->assertSame('2026-04-01', $monel['ultima']);

        $titanio = $this->fila($r, 'material', 'Titanio');
        $this->assertSame([3, 1], [$titanio['renglones'], $titanio['vendidos']]);
    }

    public function test_los_importes_van_separados_por_moneda_y_sin_la_alternativa(): void
    {
        $r = $this->reporte('material');
        $monel = $this->fila($r, 'material', 'Monel');
        $usd = $this->claveDeImporte($r, 'Cotizado · DOLAR BILLETE');
        $ars = $this->claveDeImporte($r, 'Cotizado · PESOS');

        $this->assertEquals(600, $monel[$usd]);   // 100 + 200 + 300 (la alternativa de 999 no suma)
        $this->assertEquals(1000, $monel[$ars]);
        $this->assertEquals(200, $monel[$this->claveDeImporte($r, 'Vendido · DOLAR BILLETE')]);
        $this->assertEquals(1000, $monel[$this->claveDeImporte($r, 'Vendido · PESOS')]);
    }

    public function test_lo_que_no_tiene_material_enlazado_va_aparte_al_final_con_un_aviso(): void
    {
        $r = $this->reporte('material');
        $filas = $r->json('filas');

        $this->assertSame('(material sin reconocer)', end($filas)['material']);
        $this->assertSame(1, end($filas)['renglones']);
        $this->assertNull(end($filas)['enlace']);
        $this->assertStringContainsString('1 renglones no tienen el material reconocido', $r->json('avisos.0'));
    }

    public function test_el_total_no_suma_lo_que_se_contaria_dos_veces(): void
    {
        $t = $this->reporte('material')->json('totales');

        $this->assertSame(8, $t['renglones']);            // 4 de Monel + 3 de Titanio + 1 sin enlazar
        $this->assertArrayNotHasKey('cotizaciones', $t);   // una cotización con dos materiales cuenta en los dos
        $this->assertArrayNotHasKey('empresas', $t);
        $this->assertSame(3, $t['vendidos']);
    }

    public function test_por_material_se_puede_acotar_por_periodo_material_empresa_y_quien_la_hizo(): void
    {
        $en2026 = $this->reporte('material', ['desde' => '2026-01-01', 'hasta' => '2026-12-31']);
        $this->assertSame(3, $this->fila($en2026, 'material', 'Monel')['renglones']);   // sin C1 (2025)

        $soloTitanio = $this->reporte('material', ['material' => 'titan']);
        $this->assertSame(['Titanio'], array_column($soloTitanio->json('filas'), 'material'));

        $deAlfa = $this->reporte('material', ['empresa_id' => $this->alfa->id]);
        $this->assertSame(2, $this->fila($deAlfa, 'material', 'Monel')['renglones']);

        $deRic = $this->reporte('material', ['usuario_id' => $this->ric->id]);
        $this->assertSame(1, $this->fila($deRic, 'material', 'Monel')['renglones']);
    }

    public function test_cada_material_lleva_a_sus_renglones_en_el_buscador(): void
    {
        $fila = $this->fila($this->reporte('material', ['desde' => '2026-01-01']), 'material', 'Monel');

        $this->assertStringStartsWith('/buscador?', $fila['enlace']);
        $this->assertStringContainsString("material_id={$this->monel->id}", $fila['enlace']);
        $this->assertStringContainsString('grano=renglones', $fila['enlace']);
        $this->assertStringContainsString('desde=2026-01-01', $fila['enlace']);
    }

    public function test_si_se_compro_la_alternativa_cuenta_su_importe(): void
    {
        $madre = $this->c['C4']->lineas()->whereNull('alternativa_de_id')->first();
        $this->c['C4']->update(['estado' => 'Vendida']);
        $this->c['C4']->lineas()->where('alternativa_de_id', $madre->id)->update(['comprada' => true]);

        $monel = $this->fila($this->reporte('material'), 'material', 'Monel');

        // El renglón de C4 está vendido (por su alternativa) y se cuenta lo que se compró: 999, no 300.
        $this->assertSame(3, $monel['vendidos']);
        $this->assertEquals(200 + 999, $monel[$this->claveDeImporte($this->reporte('material'), 'Vendido · DOLAR BILLETE')]);
    }

    /* ----------------------------------------------------------------- empresa */

    public function test_por_empresa_cotizado_contra_vendido(): void
    {
        $r = $this->reporte('empresa');
        $alfa = $this->fila($r, 'empresa', 'ALFA SA');
        $beta = $this->fila($r, 'empresa', 'BETA SA');

        $this->assertEquals([2, 1, 50], [$alfa['cotizaciones'], $alfa['vendidas'], $alfa['vendidas_pct']]);
        $this->assertEquals(300 - 0 + 150, $alfa[$this->claveDeImporte($r, 'Cotizado · DOLAR BILLETE')]);   // C1 150 + C2 300
        $this->assertEquals(200, $alfa[$this->claveDeImporte($r, 'Vendido · DOLAR BILLETE')]);              // lo marcado de C2

        $this->assertSame([2, 1], [$beta['cotizaciones'], $beta['vendidas']]);
        $this->assertEquals(1500, $beta[$this->claveDeImporte($r, 'Cotizado · PESOS')]);
        $this->assertEquals(1500, $beta[$this->claveDeImporte($r, 'Vendido · PESOS')]);   // compra total: todo
        $this->assertEquals(300, $beta[$this->claveDeImporte($r, 'Cotizado · DOLAR BILLETE')]);
    }

    public function test_las_empresas_que_cerraron_se_ven_pero_apagadas_y_cada_una_lleva_a_su_ficha(): void
    {
        $r = $this->reporte('empresa');
        $gamma = $this->fila($r, 'empresa', 'GAMMA SA');

        $this->assertTrue($gamma['apagada']);
        $this->assertSame("/empresas/{$this->gamma->id}", $gamma['enlace']);
        $this->assertFalse($this->fila($r, 'empresa', 'ALFA SA')['apagada']);
        // Las que más cotizan, primero; a igual cantidad, por nombre.
        $this->assertSame(['ALFA SA', 'BETA SA', 'GAMMA SA'], array_column($r->json('filas'), 'empresa'));
    }

    public function test_por_anio_junta_todas_las_empresas_y_va_del_mas_nuevo_al_mas_viejo(): void
    {
        $r = $this->reporte('empresa', ['agrupar' => 'anio']);

        $this->assertSame(['2026', '2025'], array_column($r->json('filas'), 'anio'));
        $y26 = $this->fila($r, 'anio', '2026');
        $this->assertSame([4, 2, 3], [$y26['cotizaciones'], $y26['vendidas'], $y26['empresas']]);
        $this->assertSame([1, 0], [$this->fila($r, 'anio', '2025')['cotizaciones'], $this->fila($r, 'anio', '2025')['vendidas']]);
        $this->assertStringContainsString('desde=2026-01-01', $y26['enlace']);
        $this->assertStringContainsString('Año', $r->json('columnas.0.titulo'));
    }

    public function test_el_porcentaje_del_total_se_recalcula_no_se_promedia(): void
    {
        $t = $this->reporte('empresa')->json('totales');

        $this->assertSame([5, 2], [$t['cotizaciones'], $t['vendidas']]);
        $this->assertEquals(40, $t['vendidas_pct']);   // 2 de 5, no el promedio de los porcentajes de cada empresa
    }

    public function test_por_empresa_se_filtra_por_nombre_y_periodo(): void
    {
        $this->assertSame(['BETA SA'], array_column($this->reporte('empresa', ['empresa' => 'beta'])->json('filas'), 'empresa'));
        $this->assertSame(['ALFA SA'], array_column($this->reporte('empresa', ['hasta' => '2025-12-31'])->json('filas'), 'empresa'));
    }

    /* ------------------------------------------------------------ comprobantes */

    public function test_compras_y_comprobantes_mes_por_mes(): void
    {
        $r = $this->reporte('comprobantes');

        $feb = $this->fila($r, 'mes', 'Febrero 2026');
        $this->assertSame([1, 1, 1, 1, 0], [$feb['ventas'], $feb['parciales'], $feb['facturas'], $feb['remitos'], $feb['notas']]);

        $mar = $this->fila($r, 'mes', 'Marzo 2026');
        $this->assertSame([1, 0, 1, 0, 1], [$mar['ventas'], $mar['parciales'], $mar['facturas'], $mar['remitos'], $mar['notas']]);

        // Del más nuevo al más viejo.
        $this->assertSame(['Marzo 2026', 'Febrero 2026'], array_column($r->json('filas'), 'mes'));
    }

    public function test_lo_facturado_es_solo_de_las_facturas_y_por_moneda(): void
    {
        $r = $this->reporte('comprobantes');

        $this->assertEquals(500, $this->fila($r, 'mes', 'Febrero 2026')[$this->claveDeImporte($r, 'Facturado · DOLAR BILLETE')]);
        // En marzo la nota de crédito de 100 no se suma a lo facturado.
        $this->assertEquals(1500, $this->fila($r, 'mes', 'Marzo 2026')[$this->claveDeImporte($r, 'Facturado · PESOS')]);
        $this->assertSame([2, 1, 2], [$r->json('totales.facturas'), $r->json('totales.parciales'), $r->json('totales.ventas')]);
    }

    public function test_los_comprobantes_se_acotan_por_su_fecha(): void
    {
        $r = $this->reporte('comprobantes', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']);

        $this->assertSame(['Marzo 2026'], array_column($r->json('filas'), 'mes'));
        $this->assertStringContainsString('grano=comprobantes', $r->json('filas.0.enlace'));
        $this->assertStringContainsString('hasta=2026-03-31', $r->json('filas.0.enlace'));
    }

    /* ------------------------------------------------------------------ ranking */

    public function test_ranking_de_empresas_por_cantidad(): void
    {
        $r = $this->reporte('ranking', ['por' => 'empresas', 'medida' => 'cotizaciones', 'top' => 2]);

        $this->assertSame(['ALFA SA', 'BETA SA'], array_column($r->json('filas'), 'nombre'));
        $this->assertSame([1, 2], array_column($r->json('filas'), 'puesto'));
        $this->assertNull($r->json('totales'));
    }

    public function test_ranking_por_plata_se_ordena_en_la_moneda_con_mas_movimiento_y_lo_dice(): void
    {
        // En pesos BETA cotizó 1500; en dólares ALFA 450 + BETA 300 + GAMMA 40. Los pesos mueven más cifra.
        $r = $this->reporte('ranking', ['por' => 'empresas', 'medida' => 'cotizado']);

        $this->assertSame('BETA SA', $r->json('filas.0.nombre'));
        $this->assertStringContainsString('PESOS', $r->json('avisos.0'));

        // Si se pide el dólar, manda el dólar.
        $r = $this->reporte('ranking', ['por' => 'empresas', 'medida' => 'cotizado', 'moneda_id' => $this->dolar->id]);
        $this->assertSame('ALFA SA', $r->json('filas.0.nombre'));
    }

    public function test_ranking_por_vendidas_y_de_quien_la_hizo(): void
    {
        $r = $this->reporte('ranking', ['por' => 'usuarios', 'medida' => 'vendidas']);

        // RIC hizo C2 (vendida); ADM, el resto (C3 también vendida, y 3 más).
        $nombres = array_column($r->json('filas'), 'nombre');
        $this->assertEqualsCanonicalizing(['ADM', 'RIC'], $nombres);
        $this->assertSame('Quién la hizo', $r->json('columnas.1.titulo'));
    }

    public function test_ranking_de_materiales_deja_afuera_lo_que_no_esta_enlazado(): void
    {
        $r = $this->reporte('ranking', ['por' => 'materiales', 'medida' => 'cotizaciones']);

        $this->assertSame(['Monel', 'Titanio'], array_column($r->json('filas'), 'material'));
        $this->assertSame([1, 2], array_column($r->json('filas'), 'puesto'));
    }

    public function test_el_ranking_no_pide_mas_de_500_filas(): void
    {
        $this->getJson('/api/reportes/ranking?top=501')->assertUnprocessable();
    }

    /* ------------------------------------------------------------- vencimientos */

    public function test_vencimientos_reparte_las_abiertas_en_tramos(): void
    {
        $this->cotizacion($this->alfa, '2026-01-01', 'Confirmada', $this->dolar, [[$this->monel, 70]], null, ['vence_el' => now()->subDays(40)->toDateString()]);
        $this->cotizacion($this->alfa, '2026-01-02', 'Confirmada', $this->dolar, [[$this->monel, 30]], null, ['vence_el' => now()->subDays(2)->toDateString()]);
        $this->cotizacion($this->beta, '2026-01-03', 'Confirmada', $this->dolar, [[$this->monel, 5]], null, ['vence_el' => now()->toDateString()]);
        // Vendida y cerrada no son abiertas.
        $this->cotizacion($this->beta, '2026-01-04', 'Vendida', $this->dolar, [[$this->monel, 11]], null, ['vence_el' => now()->subDays(5)->toDateString()]);

        $r = $this->reporte('vencimientos');
        $tramo = fn (string $nombre) => $this->fila($r, 'tramo', $nombre);

        $this->assertSame(1, $tramo('Vencidas hace 31 a 90 días')['cotizaciones']);
        $this->assertSame(1, $tramo('Vencidas hace 1 a 7 días')['cotizaciones']);
        $this->assertSame(1, $tramo('Vencen hoy')['cotizaciones']);
        $this->assertSame(1, $tramo('Vencen en 1 a 7 días')['cotizaciones']);   // C4, que vence en 3 días
        $this->assertSame(0, $tramo('Vencen en más de 30 días')['cotizaciones']);

        $usd = $this->claveDeImporte($r, 'Cotizado · DOLAR BILLETE');
        $this->assertEquals(70, $tramo('Vencidas hace 31 a 90 días')[$usd]);
        $this->assertEquals(300, $tramo('Vencen en 1 a 7 días')[$usd]);

        // Las demás confirmadas (C1, C3 no: C3 está vendida) no tienen fecha: son las del sistema anterior.
        $this->assertSame(2, $tramo('Sin fecha de vencimiento')['cotizaciones']);   // C1 y C5
        $this->assertStringContainsString('sistema anterior', $r->json('avisos.0'));
    }

    public function test_los_tramos_llevan_a_la_lista_correspondiente(): void
    {
        $r = $this->reporte('vencimientos');

        $this->assertSame('/buscador?grano=consultas&sin_respuesta=1', $this->fila($r, 'tramo', 'Vencidas hace 1 a 7 días')['enlace']);
        $this->assertSame('/buscador?grano=consultas&por_vencer=7', $this->fila($r, 'tramo', 'Vencen en 1 a 7 días')['enlace']);
        $this->assertNull($this->fila($r, 'tramo', 'Sin fecha de vencimiento')['enlace']);
    }

    /* ----------------------------------------------------------------- permisos */

    public function test_quien_no_ve_importes_recibe_los_reportes_sin_una_sola_columna_de_plata(): void
    {
        $vendedor = User::factory()->create(['role' => 'Vendedor']);
        Permiso::create(['user_id' => $vendedor->id, 've_importes' => false]);
        Sanctum::actingAs($vendedor);

        foreach (['material', 'empresa', 'comprobantes', 'ranking', 'vencimientos'] as $nombre) {
            $r = $this->reporte($nombre);

            $this->assertFalse($r->json('permisos.ve_importes'), $nombre);
            $this->assertSame([], array_values(array_filter($r->json('columnas'), fn ($c) => $c['tipo'] === 'importe')), "{$nombre}: columnas de plata");
            $this->assertStringNotContainsString('importe', strtolower(implode(',', array_keys($r->json('filas.0') ?? []))), $nombre);
            $this->assertNotEmpty($r->json('filas'), $nombre);
        }

        // Pedir el ranking por plata cae a la cantidad: no se filtra el orden de los importes.
        $r = $this->reporte('ranking', ['por' => 'empresas', 'medida' => 'cotizado']);
        $this->assertSame(['ALFA SA', 'BETA SA', 'GAMMA SA'], array_column($r->json('filas'), 'nombre'));
        $this->assertSame([], $r->json('avisos'));

        // Ni el Excel.
        $this->get('/api/reportes/material/exportar')->assertOk();
    }

    /* -------------------------------------------------------------------- Excel */

    public function test_cada_reporte_se_baja_a_excel_con_las_mismas_columnas(): void
    {
        foreach (['material', 'empresa', 'comprobantes', 'ranking', 'vencimientos'] as $nombre) {
            $r = $this->get("/api/reportes/{$nombre}/exportar?desde=2026-01-01&hasta=2026-12-31")->assertOk();

            $this->assertStringContainsString('spreadsheetml.sheet', $r->headers->get('Content-Type'));
            $this->assertMatchesRegularExpression("/reporte-{$nombre}-2026-01-01_2026-12-31\\.xlsx/", $r->headers->get('Content-Disposition'));
            $this->assertStringStartsWith('PK', $r->getContent());
        }
    }

    public function test_el_excel_trae_titulos_filas_y_totales_con_las_fechas_como_se_escriben(): void
    {
        $reporte = new ReporteDeMateriales($this->admin, FiltrosDeReporte::deArray([]));
        $x = $reporte->paraExcel();

        $this->assertSame(['Material', 'Renglones cotizados', 'Cotizaciones', 'Empresas', 'Vendidos', '% vendido', 'Última cotización'], array_slice($x['titulos'], 0, 7));
        $this->assertSame('Monel', $x['filas'][0][0]);
        $this->assertSame('01/04/2026', $x['filas'][0][6]);
        $this->assertSame('Total', end($x['filas'])[0]);
    }

    /* --------------------------------------------------------------- validación */

    public function test_los_pedidos_mal_hechos_se_rechazan(): void
    {
        $this->getJson('/api/reportes/inventado')->assertNotFound();
        $this->getJson('/api/reportes/material?desde=ayer')->assertUnprocessable();
        $this->getJson('/api/reportes/material?desde=2026-05-01&hasta=2026-01-01')->assertUnprocessable()
            ->assertJsonPath('errors.hasta.0', 'La fecha "hasta" no puede ser anterior a la fecha "desde".');
        $this->getJson('/api/reportes/empresa?agrupar=planeta')->assertUnprocessable();
    }

    public function test_hay_que_iniciar_sesion(): void
    {
        $this->app['auth']->forgetGuards();
        $this->refreshApplication();

        $this->getJson('/api/reportes/material')->assertUnauthorized();
    }

    public function test_el_reporte_dice_de_cuando_es(): void
    {
        $r = $this->reporte('material', ['desde' => '2026-01-01', 'hasta' => '2026-06-30']);

        $this->assertNotNull($r->json('generado_el'));
        $this->assertSame(['desde' => '2026-01-01', 'hasta' => '2026-06-30'], $r->json('periodo'));
        $this->assertSame('Por material', $r->json('titulo'));
    }
}

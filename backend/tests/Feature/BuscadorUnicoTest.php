<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaComprobante;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Moneda;
use App\Models\Permiso;
use App\Models\TipoDeCondicion;
use App\Models\User;
use App\Services\Buscador\Buscador;
use App\Services\Buscador\Filtros;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * El buscador único (GET /api/buscar): cuatro vistas del mismo dato con los
 * mismos filtros. Nombres ficticios: el repo es público.
 */
class BuscadorUnicoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'Administrador']);
        Sanctum::actingAs($this->admin);
    }

    private function empresa(string $nombre, bool $activa = true, array $extra = []): Empresa
    {
        return Empresa::create(['nombre' => $nombre, 'activa' => $activa] + $extra);
    }

    private function cotizacion(Empresa $e, array $extra = []): Consulta
    {
        return Consulta::create($extra + [
            'empresa_id' => $e->id, 'tipo' => 'Cotizacion', 'fecha' => '2026-10-01',
            'estado' => 'Confirmada', 'usuario_id' => $this->admin->id,
        ]);
    }

    private function linea(Consulta $c, array $extra = []): ConsultaLinea
    {
        static $orden = 0;

        return ConsultaLinea::create($extra + ['consulta_id' => $c->id, 'orden' => ++$orden, 'descripcion' => 'BARRA 20MM']);
    }

    private function buscar(array $q): TestResponse
    {
        return $this->getJson('/api/buscar?'.http_build_query($q));
    }

    /** @return list<string> los nombres de las empresas, en el orden en que salen */
    private function nombres(TestResponse $r): array
    {
        return collect($r->json('data'))->map(fn ($f) => $f['nombre'] ?? $f['empresa']['nombre'])->all();
    }

    // ---------------------------------------------------------------- empresas

    public function test_las_inactivas_se_ven_pero_al_final_aunque_coincidan_mejor(): void
    {
        $this->empresa('ALFA SA', activa: false);
        $this->empresa('ZETA ALFA SRL');

        $r = $this->buscar(['q' => 'alfa'])->assertOk();

        // La inactiva empieza con lo buscado, y aun así va última.
        $this->assertSame(['ZETA ALFA SRL', 'ALFA SA'], $this->nombres($r));
        $this->assertSame([true, false], collect($r->json('data'))->pluck('activa')->all());
        $this->assertSame(['activas' => 1, 'inactivas' => 1], $r->json('resumen'));
    }

    public function test_las_empresas_sin_texto_salen_en_orden_alfabetico_y_las_inactivas_al_final(): void
    {
        $this->empresa('ZETA SA');
        $this->empresa('ALFA SA', activa: false);
        $this->empresa('MEDIA SA');
        // La que se cotizó hace poco no sube: sin texto, el orden es el de una guía telefónica.
        $this->cotizacion(Empresa::where('nombre', 'ZETA SA')->first(), ['fecha' => '2026-10-30']);

        $this->assertSame(['MEDIA SA', 'ZETA SA', 'ALFA SA'], $this->nombres($this->buscar([])));
    }

    public function test_se_pueden_esconder_las_inactivas_o_ver_solo_ellas(): void
    {
        $this->empresa('ALFA SA', activa: false);
        $this->empresa('BETA SA');

        $this->assertSame(['BETA SA'], $this->nombres($this->buscar(['activa' => 'activas'])));
        $this->assertSame(['ALFA SA'], $this->nombres($this->buscar(['activa' => 'inactivas'])));
        $this->assertCount(2, $this->buscar([])->json('data'));
    }

    public function test_un_cuit_sin_guiones_encuentra_al_que_esta_con_guiones(): void
    {
        $this->empresa('ALFA SA', extra: ['cuit' => '30-12345678-9']);
        $this->empresa('BETA SA', extra: ['cuit' => '30-99999999-1']);

        $this->assertSame(['ALFA SA'], $this->nombres($this->buscar(['q' => '30123456789'])));
    }

    public function test_cada_palabra_tiene_que_aparecer_en_cualquier_orden(): void
    {
        $this->empresa('ALFA METALES SA');
        $this->empresa('ALFA PLASTICOS SA');

        $this->assertSame(['ALFA METALES SA'], $this->nombres($this->buscar(['q' => 'metales alfa'])));
    }

    public function test_a_que_empresa_le_cotice_tal_material(): void
    {
        $monel = Material::create(['nombre' => 'Monel 400']);
        MaterialAlias::create(['material_id' => $monel->id, 'alias' => 'MONEL400']);
        $otro = Material::create(['nombre' => 'Inconel 625']);

        // Enlazada al catálogo.
        $enlazada = $this->empresa('ALFA SA');
        $this->linea($this->cotizacion($enlazada), ['material_id' => $monel->id]);

        // Del sistema viejo: sin enlazar, el material está escrito en el texto.
        $sinEnlazar = $this->empresa('BETA SA');
        $this->linea($this->cotizacion($sinEnlazar), ['pedido_material' => 'monel400', 'descripcion' => 'BARRA']);

        // Otro material.
        $this->linea($this->cotizacion($this->empresa('GAMMA SA')), ['material_id' => $otro->id]);

        // Era de monel pero la línea se quitó: ya no se cotizó.
        $this->linea($this->cotizacion($this->empresa('DELTA SA')), ['material_id' => $monel->id, 'quitada' => true]);

        // Era de monel pero en un borrador: todavía no figura.
        $this->linea($this->cotizacion($this->empresa('EPSILON SA'), ['estado' => 'Borrador']), ['material_id' => $monel->id]);

        $r = $this->buscar(['material_id' => $monel->id])->assertOk();

        $this->assertEqualsCanonicalizing(['ALFA SA', 'BETA SA'], $this->nombres($r));
    }

    public function test_se_busca_por_texto_dentro_de_la_observacion_de_la_ficha(): void
    {
        $this->empresa('ALFA SA', extra: ['observacion_general' => 'Compra recortes de titanio']);
        $this->empresa('BETA SA', extra: ['observacion_general' => 'Sólo barras']);

        $this->assertSame(['ALFA SA'], $this->nombres($this->buscar(['observacion' => 'recortes'])));

        // Vale desde las otras vistas: las cotizaciones de las empresas que cumplen.
        $this->cotizacion(Empresa::where('nombre', 'ALFA SA')->first(), ['numero' => 'C-1']);
        $this->cotizacion(Empresa::where('nombre', 'BETA SA')->first(), ['numero' => 'C-2']);
        $r = $this->buscar(['grano' => 'consultas', 'observacion' => 'recortes']);
        $this->assertSame(['ALFA SA'], $this->nombres($r));
    }

    public function test_se_filtra_por_la_condicion_frente_al_iva_con_los_valores_de_la_ficha(): void
    {
        $this->empresa('ALFA SA', extra: ['condicion_iva' => 'Responsable Inscripto']);
        $this->empresa('BETA SA', extra: ['condicion_iva' => 'Monotributo']);

        // Los mismos valores que ofrece el catálogo para la ficha de la empresa.
        $iva = $this->getJson('/api/catalogos')->json('condiciones_iva_empresa');
        $this->assertContains('Responsable Inscripto', $iva);

        $this->assertSame(['ALFA SA'], $this->nombres($this->buscar(['condicion_iva' => 'Responsable Inscripto'])));
    }

    public function test_se_busca_por_una_condicion_de_la_ficha_y_su_dato(): void
    {
        $flete = TipoDeCondicion::firstOrCreate(['nombre' => 'Flete']);
        $cliente = $flete->opciones()->create(['texto' => 'A cargo del cliente']);
        $incluido = $flete->opciones()->create(['texto' => 'Incluido']);
        $pago = TipoDeCondicion::firstOrCreate(['nombre' => 'Tipo de pago']);

        $alfa = $this->empresa('ALFA SA');
        $alfa->campos()->create(['titulo' => 'Flete', 'valor' => $cliente->texto, 'tipo_de_condicion_id' => $flete->id, 'opcion_de_condicion_id' => $cliente->id]);
        $beta = $this->empresa('BETA SA');
        $beta->campos()->create(['titulo' => 'Flete', 'valor' => $incluido->texto, 'tipo_de_condicion_id' => $flete->id, 'opcion_de_condicion_id' => $incluido->id]);
        $this->empresa('GAMMA SA');

        // Todas las que tienen cargado el flete, sea cual sea.
        $this->assertEqualsCanonicalizing(['ALFA SA', 'BETA SA'], $this->nombres($this->buscar(['condicion_id' => $flete->id])));
        // Las que lo tienen con ese dato.
        $this->assertSame(['ALFA SA'], $this->nombres($this->buscar(['condicion_id' => $flete->id, 'opcion_id' => $cliente->id])));
        $this->assertSame([], $this->nombres($this->buscar(['condicion_id' => $pago->id])));

        // Y vale desde las otras vistas.
        $this->cotizacion($alfa, ['numero' => 'C-1']);
        $this->cotizacion($beta, ['numero' => 'C-2']);
        $this->assertSame(['ALFA SA'], $this->nombres($this->buscar(['grano' => 'consultas', 'opcion_id' => $cliente->id])));
    }

    public function test_hace_mas_de_n_meses_que_no_se_les_cotiza(): void
    {
        $this->cotizacion($this->empresa('ALFA SA'), ['fecha' => now()->subMonths(2)->toDateString()]);
        $this->cotizacion($this->empresa('BETA SA'), ['fecha' => now()->subMonths(10)->toDateString()]);
        $this->empresa('GAMMA SA');

        $this->assertEqualsCanonicalizing(['BETA SA', 'GAMMA SA'], $this->nombres($this->buscar(['sin_cotizar_meses' => 6])));
    }

    public function test_cada_empresa_trae_su_ultima_cotizacion_sin_contar_borradores(): void
    {
        $e = $this->empresa('ALFA SA');
        $this->cotizacion($e, ['fecha' => '2026-08-10']);
        $this->cotizacion($e, ['fecha' => '2026-09-20']);
        $this->cotizacion($e, ['fecha' => '2026-10-30', 'estado' => 'Borrador']);

        $fila = $this->buscar([])->json('data.0');

        $this->assertSame('2026-09-20', substr($fila['ultima_cotizacion'], 0, 10));
        $this->assertSame(2, $fila['cotizaciones']);
    }

    // ------------------------------------------------------------ cotizaciones

    public function test_las_cotizaciones_se_filtran_por_tipo_estado_y_fechas(): void
    {
        $e = $this->empresa('ALFA SA');
        $this->cotizacion($e, ['fecha' => '2026-09-05', 'estado' => 'Vendida']);
        $this->cotizacion($e, ['fecha' => '2026-10-05', 'estado' => 'Confirmada']);
        $this->cotizacion($e, ['fecha' => '2026-10-06', 'tipo' => 'Pedido']);

        $r = $this->buscar(['grano' => 'consultas', 'tipo' => 'Cotizacion', 'desde' => '2026-10-01', 'hasta' => '2026-10-31'])->assertOk();
        $this->assertSame(['2026-10-05'], collect($r->json('data'))->pluck('fecha')->all());
        $this->assertSame(['Confirmada' => 1], $r->json('resumen.por_estado'));

        $this->assertCount(1, $this->buscar(['grano' => 'consultas', 'estado' => 'Vendida'])->json('data'));
    }

    public function test_los_borradores_y_los_descartados_no_aparecen_salvo_que_se_pidan(): void
    {
        $e = $this->empresa('ALFA SA');
        $this->cotizacion($e);
        $this->cotizacion($e, ['estado' => 'Borrador']);
        $this->cotizacion($e)->delete();

        $this->assertCount(1, $this->buscar(['grano' => 'consultas'])->json('data'));
        $this->assertCount(2, $this->buscar(['grano' => 'consultas', 'incluir_borradores' => 1])->json('data'));
        $this->assertCount(1, $this->buscar(['grano' => 'consultas', 'estado' => 'Borrador'])->json('data'));
    }

    public function test_la_fecha_que_se_mira_puede_ser_la_de_la_factura(): void
    {
        $e = $this->empresa('ALFA SA');
        $this->cotizacion($e, ['fecha' => '2026-01-10', 'estado' => 'Vendida', 'fecha_factura' => '2026-10-20']);
        $this->cotizacion($e, ['fecha' => '2026-10-15', 'estado' => 'Vendida', 'fecha_factura' => '2026-12-01']);

        $r = $this->buscar(['grano' => 'consultas', 'fecha_de' => 'factura', 'desde' => '2026-10-01', 'hasta' => '2026-10-31']);

        $this->assertSame(['2026-01-10'], collect($r->json('data'))->pluck('fecha')->all());
    }

    public function test_compra_total_y_parcial(): void
    {
        $e = $this->empresa('ALFA SA');

        // Dos ítems, se compró uno: parcial.
        $parcial = $this->cotizacion($e, ['estado' => 'Vendida', 'numero' => 'C-1']);
        $this->linea($parcial, ['comprada' => true, 'importe' => 100]);
        $this->linea($parcial, ['importe' => 50]);

        // Dos ítems, se compraron los dos: total.
        $total = $this->cotizacion($e, ['estado' => 'Vendida', 'numero' => 'C-2']);
        $this->linea($total, ['comprada' => true]);
        $this->linea($total, ['comprada' => true]);

        // Se compró la alternativa de un ítem: ese ítem cuenta como comprado.
        $conAlternativa = $this->cotizacion($e, ['estado' => 'Vendida', 'numero' => 'C-3']);
        $madre = $this->linea($conAlternativa);
        $this->linea($conAlternativa, ['alternativa_de_id' => $madre->id, 'comprada' => true]);

        // Vendida sin marcar nada: se tomó todo.
        $this->cotizacion($e, ['estado' => 'Vendida', 'numero' => 'C-4'])->lineas()->create(['orden' => 1, 'descripcion' => 'X']);

        // No se vendió: no tiene compra.
        $this->cotizacion($e, ['numero' => 'C-5']);

        $numeros = fn (string $compra) => collect($this->buscar(['grano' => 'consultas', 'compra' => $compra])->json('data'))
            ->pluck('numero')->map(fn ($n) => substr($n, 0, 3))->sort()->values()->all();

        $this->assertSame(['C-1'], $numeros('parcial'));
        $this->assertSame(['C-2', 'C-3', 'C-4'], $numeros('total'));
        $this->assertSame(['C-1', 'C-2', 'C-3', 'C-4'], $numeros('vendida'));

        // Lo que dice la lista es lo mismo que decide el filtro.
        $fila = collect($this->buscar(['grano' => 'consultas', 'compra' => 'parcial'])->json('data'))->first();
        $this->assertTrue($fila['compra']['parcial']);
        $this->assertSame([1, 2], [$fila['compra']['compradas'], $fila['compra']['total']]);
    }

    public function test_la_orden_de_compra_se_encuentra_por_la_solicitud_el_pedido_o_un_comprobante(): void
    {
        $e = $this->empresa('ALFA SA');
        $this->cotizacion($e, ['numero' => 'C-1', 'solicitud_nro' => 'OC-4471']);
        $this->cotizacion($e, ['numero' => 'C-2', 'nro_pedido_interno' => 'OC-4471-B']);
        $conComprobante = $this->cotizacion($e, ['numero' => 'C-3']);
        ConsultaComprobante::create(['consulta_id' => $conComprobante->id, 'tipo' => 'Remito', 'numero' => 'OC-4471/9']);
        $this->cotizacion($e, ['numero' => 'C-4', 'solicitud_nro' => 'OC-9999']);

        $r = $this->buscar(['grano' => 'consultas', 'orden_de_compra' => 'OC-4471']);

        $this->assertEqualsCanonicalizing(['C-1', 'C-2', 'C-3'], collect($r->json('data'))->pluck('numero')->map(fn ($n) => substr($n, 0, 3))->all());
    }

    public function test_sin_respuesta_y_por_vencer(): void
    {
        $e = $this->empresa('ALFA SA');
        $this->cotizacion($e, ['numero' => 'C-1', 'vence_el' => now()->subDays(3)->toDateString()]);
        $this->cotizacion($e, ['numero' => 'C-2', 'vence_el' => now()->addDays(2)->toDateString()]);
        $this->cotizacion($e, ['numero' => 'C-3', 'vence_el' => now()->addDays(30)->toDateString()]);

        $sin = $this->buscar(['grano' => 'consultas', 'sin_respuesta' => 1])->json('data');
        $this->assertSame(['C-1'], collect($sin)->pluck('numero')->map(fn ($n) => substr($n, 0, 3))->all());

        $por = $this->buscar(['grano' => 'consultas', 'por_vencer' => 7])->json('data');
        $this->assertSame(['C-2'], collect($por)->pluck('numero')->map(fn ($n) => substr($n, 0, 3))->all());
    }

    public function test_las_inactivas_tambien_van_al_final_entre_las_cotizaciones(): void
    {
        $this->cotizacion($this->empresa('ALFA SA', activa: false), ['fecha' => '2026-10-30']);
        $this->cotizacion($this->empresa('BETA SA'), ['fecha' => '2026-01-01']);

        $r = $this->buscar(['grano' => 'consultas']);

        $this->assertSame(['BETA SA', 'ALFA SA'], $this->nombres($r));
        $this->assertFalse($r->json('data.1.empresa.activa'));
    }

    public function test_el_texto_busca_en_el_numero_la_empresa_los_renglones_y_los_comprobantes(): void
    {
        $e = $this->empresa('ALFA SA');
        $a = $this->cotizacion($e, ['numero' => 'C-1']);
        $this->linea($a, ['descripcion' => 'TUBO ESPECIAL']);
        $b = $this->cotizacion($this->empresa('BETA SA'), ['numero' => 'C-2']);
        ConsultaComprobante::create(['consulta_id' => $b->id, 'tipo' => 'Factura A', 'numero' => '0001-77']);

        $ids = fn (string $q) => collect($this->buscar(['grano' => 'consultas', 'q' => $q])->json('data'))->pluck('id')->all();

        $this->assertSame([$a->id], $ids('especial'));
        $this->assertSame([$b->id], $ids('0001-77'));
        $this->assertSame([$a->id], $ids('C-1'));
        $this->assertSame([$b->id], $ids('beta'));
    }

    // ---------------------------------------------------------------- renglones

    public function test_los_renglones_traen_la_empresa_y_se_filtran_por_material_y_medidas(): void
    {
        $monel = Material::create(['nombre' => 'Monel 400']);
        $inconel = Material::create(['nombre' => 'Inconel 625']);
        $uno = $this->cotizacion($this->empresa('ALFA SA'), ['numero' => 'C-1']);
        $this->linea($uno, ['material_id' => $monel->id, 'diametro_mm' => 20, 'descripcion' => 'BARRA 20']);
        $this->linea($uno, ['material_id' => $monel->id, 'diametro_mm' => 50, 'descripcion' => 'BARRA 50']);
        $this->linea($uno, ['material_id' => $inconel->id, 'diametro_mm' => 20, 'descripcion' => 'BARRA INCONEL']);

        $r = $this->buscar(['grano' => 'renglones', 'material_id' => $monel->id, 'diametro_desde' => 10, 'diametro_hasta' => 30])->assertOk();

        $this->assertSame(['BARRA 20'], collect($r->json('data'))->pluck('descripcion')->all());
        $this->assertSame('ALFA SA', $r->json('data.0.empresa.nombre'));
        $this->assertSame('Monel 400', $r->json('data.0.material'));
        $this->assertStringStartsWith('C-1 R', $r->json('data.0.consulta.numero'));
    }

    public function test_un_renglon_quitado_o_sin_cotizar_o_de_una_cotizacion_descartada_no_figura(): void
    {
        $e = $this->empresa('ALFA SA');
        $c = $this->cotizacion($e);
        $this->linea($c, ['descripcion' => 'BUENA']);
        $this->linea($c, ['descripcion' => 'QUITADA', 'quitada' => true]);
        $this->linea($c, ['descripcion' => 'SIN COTIZAR', 'no_cotizada' => true]);
        $descartada = $this->cotizacion($e);
        $this->linea($descartada, ['descripcion' => 'DESCARTADA']);
        $descartada->delete();

        $this->assertSame(['BUENA'], collect($this->buscar(['grano' => 'renglones'])->json('data'))->pluck('descripcion')->all());
        $this->assertCount(2, $this->buscar(['grano' => 'renglones', 'incluir_no_cotizadas' => 1])->json('data'));
    }

    public function test_el_resumen_de_renglones_suma_por_moneda_sin_las_alternativas(): void
    {
        $dolar = Moneda::create(['nombre' => 'Dolar', 'moneda_base' => 'USD']);
        $c = $this->cotizacion($this->empresa('ALFA SA'), ['moneda_id' => $dolar->id]);
        $madre = $this->linea($c, ['importe' => 100]);
        $this->linea($c, ['importe' => 40]);
        $this->linea($c, ['alternativa_de_id' => $madre->id, 'importe' => 999]);

        $r = $this->buscar(['grano' => 'renglones']);

        $this->assertSame(3, $r->json('meta.total'));
        $this->assertEquals([['moneda' => 'Dolar', 'importe' => 140, 'renglones' => 2]], $r->json('resumen.importe_por_moneda'));
    }

    public function test_el_resumen_de_cotizaciones_suma_lo_cotizado_de_todo_el_resultado_no_de_la_pagina(): void
    {
        $dolar = Moneda::create(['nombre' => 'Dolar', 'moneda_base' => 'USD']);
        $e = $this->empresa('ALFA SA');

        foreach (range(1, 3) as $i) {
            $this->linea($this->cotizacion($e, ['moneda_id' => $dolar->id]), ['importe' => 100]);
        }

        $r = $this->buscar(['grano' => 'consultas', 'por_pagina' => 2]);

        $this->assertCount(2, $r->json('data'));
        $this->assertSame(3, $r->json('meta.total'));
        $this->assertEquals([['moneda' => 'Dolar', 'importe' => 300, 'cotizaciones' => 3]], $r->json('resumen.importe_por_moneda'));
    }

    // ------------------------------------------------------------- comprobantes

    public function test_los_comprobantes_se_buscan_por_tipo_numero_y_fecha(): void
    {
        $dolar = Moneda::create(['nombre' => 'Dolar', 'moneda_base' => 'USD']);
        $c = $this->cotizacion($this->empresa('ALFA SA'), ['numero' => 'C-1', 'estado' => 'Vendida', 'moneda_id' => $dolar->id]);
        ConsultaComprobante::create(['consulta_id' => $c->id, 'tipo' => 'Factura A', 'numero' => '0001-10', 'fecha' => '2026-10-05', 'importe' => 1200.5]);
        ConsultaComprobante::create(['consulta_id' => $c->id, 'tipo' => 'Remito', 'numero' => '0002-20', 'fecha' => '2026-10-04']);
        ConsultaComprobante::create(['consulta_id' => $c->id, 'tipo' => 'Factura A', 'numero' => '0001-11', 'fecha' => '2026-12-05', 'importe' => 10]);

        $r = $this->buscar(['grano' => 'comprobantes', 'tipo_comprobante' => 'Factura A', 'desde' => '2026-10-01', 'hasta' => '2026-10-31'])->assertOk();

        $this->assertSame(['0001-10'], collect($r->json('data'))->pluck('numero')->all());
        $this->assertEquals(1200.5, $r->json('data.0.importe'));
        $this->assertSame('ALFA SA', $r->json('data.0.empresa.nombre'));
        $this->assertSame('Dolar', $r->json('data.0.moneda'));
        $this->assertEquals([['moneda' => 'Dolar', 'importe' => 1200.5, 'comprobantes' => 1]], $r->json('resumen.importe_por_moneda'));

        $this->assertSame(['Factura A' => 1], $r->json('resumen.por_tipo'));
        $this->assertCount(1, $this->buscar(['grano' => 'comprobantes', 'comprobante' => '0002'])->json('data'));
    }

    public function test_las_cotizaciones_con_un_comprobante_de_esa_fecha_se_encuentran_desde_las_empresas(): void
    {
        $con = $this->cotizacion($this->empresa('ALFA SA'));
        ConsultaComprobante::create(['consulta_id' => $con->id, 'tipo' => 'Factura A', 'numero' => '1', 'fecha' => '2026-10-05']);
        $sin = $this->cotizacion($this->empresa('BETA SA'));
        ConsultaComprobante::create(['consulta_id' => $sin->id, 'tipo' => 'Factura A', 'numero' => '2', 'fecha' => '2026-02-05']);

        $r = $this->buscar(['fecha_de' => 'comprobante', 'desde' => '2026-10-01', 'hasta' => '2026-10-31']);

        $this->assertSame(['ALFA SA'], $this->nombres($r));
    }

    // ---------------------------------------------------------------- permisos

    public function test_quien_no_ve_importes_no_recibe_ni_un_precio_ni_una_suma(): void
    {
        $dolar = Moneda::create(['nombre' => 'Dolar', 'moneda_base' => 'USD']);
        $c = $this->cotizacion($this->empresa('ALFA SA'), ['moneda_id' => $dolar->id, 'estado' => 'Vendida']);
        $this->linea($c, ['importe' => 100, 'precio_unitario' => 10, 'comprada' => true]);
        ConsultaComprobante::create(['consulta_id' => $c->id, 'tipo' => 'Factura A', 'numero' => '1', 'importe' => 500]);

        $vendedor = User::factory()->create(['role' => 'Vendedor']);
        Permiso::create(['user_id' => $vendedor->id, 've_importes' => false]);
        Sanctum::actingAs($vendedor);

        foreach (['consultas', 'renglones', 'comprobantes'] as $grano) {
            $r = $this->buscar(['grano' => $grano])->assertOk();
            $this->assertFalse($r->json('permisos.ve_importes'));
            $this->assertArrayNotHasKey('importe_por_moneda', $r->json('resumen'), $grano);
            $this->assertStringNotContainsString('500', json_encode($r->json('data')), $grano);
        }

        $fila = $this->buscar(['grano' => 'consultas'])->json('data.0');
        $this->assertNull($fila['total']);
        $this->assertArrayNotHasKey('importe', $fila['compra']);

        $renglon = $this->buscar(['grano' => 'renglones'])->json('data.0');
        $this->assertNull($renglon['importe']);
        $this->assertNull($renglon['precio_unitario']);
    }

    public function test_el_administrador_ve_los_importes(): void
    {
        $c = $this->cotizacion($this->empresa('ALFA SA'));
        $this->linea($c, ['importe' => 100, 'precio_unitario' => 10]);

        $r = $this->buscar(['grano' => 'renglones']);

        $this->assertTrue($r->json('permisos.ve_importes'));
        $this->assertEquals(100, $r->json('data.0.importe'));
    }

    public function test_sin_sesion_no_se_puede_buscar(): void
    {
        $this->app['auth']->forgetGuards();
        // Sanctum::actingAs deja al usuario; se prueba con una petición nueva sin él.
        $this->refreshApplication();

        $this->getJson('/api/buscar')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- validación

    public function test_los_pedidos_mal_hechos_se_rechazan_en_castellano(): void
    {
        $this->buscar(['grano' => 'cosas'])->assertUnprocessable()
            ->assertJsonPath('errors.grano.0', 'La vista tiene que ser empresas, consultas, renglones o comprobantes.');

        $this->buscar(['por_pagina' => 500])->assertUnprocessable()
            ->assertJsonPath('errors.por_pagina.0', 'Se pueden pedir hasta 100 resultados por página.');

        $this->buscar(['desde' => 'ayer'])->assertUnprocessable()
            ->assertJsonPath('errors.desde.0', 'La fecha "desde" no es válida.');

        $this->buscar(['compra' => 'mucha'])->assertUnprocessable();
    }

    public function test_la_paginacion_dice_cuantos_hay(): void
    {
        foreach (range(1, 5) as $i) {
            $this->empresa("EMPRESA {$i}");
        }

        $r = $this->buscar(['por_pagina' => 2, 'pagina' => 3])->assertOk();

        $this->assertCount(1, $r->json('data'));
        $this->assertSame(['grano' => 'empresas', 'current_page' => 3, 'last_page' => 3, 'per_page' => 2, 'total' => 5], $r->json('meta'));
    }

    // -------------------------------------------------------------------- Excel

    public function test_el_excel_baja_lo_filtrado_con_su_nombre(): void
    {
        $this->cotizacion($this->empresa('ALFA SA'), ['numero' => 'C-1']);

        $r = $this->get('/api/buscar/exportar?grano=consultas')->assertOk();

        $this->assertStringContainsString('spreadsheetml.sheet', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('buscador-consultas-'.now()->format('Y-m-d').'.xlsx', $r->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $r->getContent());
    }

    public function test_el_excel_no_corta_en_silencio_si_hay_mas_que_el_tope(): void
    {
        foreach (range(1, 3) as $i) {
            $this->empresa("EMPRESA {$i}");
        }

        try {
            (new Buscador($this->admin))->paraExcel(Filtros::deArray([]), 2);
            $this->fail('Tendría que haber avisado que son demasiados.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('Son 3 resultados', $e->getMessage());
            $this->assertStringContainsString('Agregá algún filtro', $e->getMessage());
        }

        $this->assertCount(3, (new Buscador($this->admin))->paraExcel(Filtros::deArray([]))['filas']);
    }

    public function test_el_excel_no_lleva_importes_para_quien_no_los_ve(): void
    {
        $c = $this->cotizacion($this->empresa('ALFA SA'));
        $this->linea($c, ['importe' => 100, 'precio_unitario' => 10]);

        $vendedor = User::factory()->create(['role' => 'Vendedor']);
        Permiso::create(['user_id' => $vendedor->id, 've_importes' => false]);

        $conImportes = (new Buscador($this->admin))->paraExcel(Filtros::deArray(['grano' => 'renglones']));
        $sinImportes = (new Buscador($vendedor))->paraExcel(Filtros::deArray(['grano' => 'renglones']));

        $this->assertContains('Importe', $conImportes['titulos']);
        $this->assertNotContains('Importe', $sinImportes['titulos']);
        $this->assertNotContains('Precio unitario', $sinImportes['titulos']);
        $this->assertCount(count($sinImportes['titulos']), $sinImportes['filas'][0]);
        $this->assertCount(count($conImportes['titulos']), $conImportes['filas'][0]);
    }
}

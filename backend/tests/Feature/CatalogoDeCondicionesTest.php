<?php

namespace Tests\Feature;

use App\Models\CondicionHabitual;
use App\Models\CondicionPago;
use App\Models\Empresa;
use App\Models\EmpresaCampo;
use App\Models\OpcionDeCondicion;
use App\Models\TipoDeCondicion;
use App\Services\CatalogoDeCondiciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * La lista de condiciones de la ficha: se arma con lo que ya está cargado, sin inventar nada,
 * y es la única fuente del título y del dato de cada campo. Nombres ficticios: el repo es público.
 */
class CatalogoDeCondicionesTest extends TestCase
{
    use RefreshDatabase;

    private CatalogoDeCondiciones $catalogo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalogo = new CatalogoDeCondiciones;

        // Otras migraciones siembran las condiciones de pago, los juegos y (la de ésta) la cabecera:
        // cada prueba arranca de cero para contar sólo lo suyo.
        CondicionPago::query()->delete();
        CondicionHabitual::query()->delete();
        OpcionDeCondicion::query()->delete();
        TipoDeCondicion::query()->delete();
    }

    private function campo(string $titulo, ?string $valor): EmpresaCampo
    {
        static $n = 0;

        return Empresa::create(['nombre' => 'EMPRESA '.++$n])->campos()->create(['titulo' => $titulo, 'valor' => $valor]);
    }

    private function tipo(string $nombre): TipoDeCondicion
    {
        return TipoDeCondicion::whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])->firstOrFail();
    }

    /** Las fichas como las dejan los años de tipear a mano: mayúsculas, tildes y espacios de más. */
    private function fichasDeLaVidaReal(): void
    {
        $this->campo('Flete', 'A cargo del cliente');
        $this->campo('flete ', 'a cargo del cliente');
        $this->campo('FLETE', 'Incluido en el precio');
        $this->campo('Cómo lo retira', 'Retira en planta');
        $this->campo('Como lo retira', 'Retira  en  planta');
        $this->campo('Cómo lo retira', 'Lo retira Juan Pérez con su camioneta blanca');
        $this->campo('Tipo de pago', '30 días fecha factura');
        $this->campo('Tipo de pago', '30 dias fecha factura');
        $this->campo('Sin dato', null);
    }

    public function test_la_lista_se_arma_con_los_titulos_que_ya_estan_en_las_fichas(): void
    {
        $this->fichasDeLaVidaReal();

        $r = $this->catalogo->sembrar();

        // "Flete", "flete " y "FLETE" son uno; "Cómo" y "Como" también. Se queda la ortografía más usada.
        $nombres = TipoDeCondicion::pluck('nombre')->all();
        $this->assertContains('Flete', $nombres);
        $this->assertContains('Cómo lo retira', $nombres);
        $this->assertContains('Tipo de pago', $nombres);
        $this->assertContains('Sin dato', $nombres);
        $this->assertSame(1, collect($nombres)->filter(fn ($n) => str_starts_with(mb_strtolower($n), 'flete'))->count());
        $this->assertContains('Flete', $r['tipos_nuevos']);
    }

    public function test_entran_a_la_lista_los_datos_que_se_repiten_y_no_los_que_se_escribieron_una_vez(): void
    {
        $this->fichasDeLaVidaReal();

        $r = $this->catalogo->sembrar();

        $this->assertEqualsCanonicalizing(['A cargo del cliente'], $this->tipo('Flete')->opciones->pluck('texto')->all());
        $this->assertEqualsCanonicalizing(['Retira en planta'], $this->tipo('Cómo lo retira')->opciones->pluck('texto')->all());

        // Las dos escrituras de "30 días" son el mismo dato.
        $this->assertCount(1, $this->tipo('Tipo de pago')->opciones);

        // Lo que se escribió una sola vez queda en la ficha como estaba, fuera de la lista.
        $this->assertSame(2, $r['sueltos']);
        $this->assertSame(
            ['Incluido en el precio', 'Lo retira Juan Pérez con su camioneta blanca'],
            collect($r['muestra_de_sueltos'])->pluck('valor')->sort()->values()->all(),
        );
        $this->assertSame(
            'Lo retira Juan Pérez con su camioneta blanca',
            EmpresaCampo::where('valor', 'like', 'Lo retira Juan%')->first()->valor,
        );
    }

    public function test_cada_ficha_queda_vinculada_a_su_condicion_y_a_su_dato_sin_tocar_lo_escrito(): void
    {
        $this->fichasDeLaVidaReal();
        $escritos = EmpresaCampo::orderBy('id')->get(['titulo', 'valor'])->map->toArray()->all();

        $r = $this->catalogo->sembrar();

        // Lo que decía cada ficha sigue diciéndolo: de ahí sale la cotización.
        $this->assertSame($escritos, EmpresaCampo::orderBy('id')->get(['titulo', 'valor'])->map->toArray()->all());

        $flete = EmpresaCampo::where('titulo', 'FLETE')->first();
        $this->assertSame($this->tipo('Flete')->id, $flete->tipo_de_condicion_id);
        // "Incluido en el precio" está en una sola ficha: con su condición, pero sin dato de la lista.
        $this->assertNull($flete->opcion_de_condicion_id);

        $otra = EmpresaCampo::where('titulo', 'flete ')->first();
        $this->assertSame('A cargo del cliente', $otra->opcion->texto);

        $this->assertSame(6, $r['vinculados']);
    }

    public function test_incluye_los_titulos_de_los_juegos_y_los_de_la_cabecera_de_la_cotizacion(): void
    {
        CondicionHabitual::create(['juego' => 'Stock', 'titulo' => 'Entrega', 'texto' => 'x', 'orden' => 1, 'activo' => true, 'por_defecto' => true]);
        CondicionHabitual::create(['juego' => 'Stock', 'titulo' => 'Condic. de Pago', 'texto' => 'x', 'orden' => 2, 'activo' => true, 'por_defecto' => true]);

        $this->catalogo->sembrar();

        $nombres = TipoDeCondicion::pluck('nombre')->all();
        foreach (['Entrega', 'Condic. de Pago', 'Tipo de pago', 'Lista de precios', 'Moneda habitual'] as $esperado) {
            $this->assertContains($esperado, $nombres);
        }
    }

    public function test_los_datos_de_una_condicion_de_pago_son_las_condiciones_de_pago_de_siempre(): void
    {
        CondicionPago::create(['nombre' => '30 dias fecha factura', 'orden' => 1]);
        CondicionPago::create(['nombre' => 'Contado', 'orden' => 2]);

        $this->catalogo->sembrar();

        $this->assertEqualsCanonicalizing(['30 dias fecha factura', 'Contado'], $this->tipo('Tipo de pago')->opciones->pluck('texto')->all());
    }

    public function test_sin_aplicar_cuenta_lo_que_haria_y_no_guarda_nada(): void
    {
        $this->fichasDeLaVidaReal();

        $r = $this->catalogo->sembrar(aplicar: false);

        $this->assertFalse($r['aplicado']);
        $this->assertGreaterThan(0, count($r['tipos_nuevos']));
        $this->assertSame(0, TipoDeCondicion::count());
        $this->assertSame(0, OpcionDeCondicion::count());
        $this->assertSame(0, EmpresaCampo::whereNotNull('tipo_de_condicion_id')->count());
    }

    public function test_correrlo_otra_vez_no_agrega_ni_cambia_nada(): void
    {
        $this->fichasDeLaVidaReal();
        $this->catalogo->sembrar();
        $antes = [TipoDeCondicion::count(), OpcionDeCondicion::count(), EmpresaCampo::whereNotNull('tipo_de_condicion_id')->count()];

        $r = $this->catalogo->sembrar();

        $this->assertSame([], $r['tipos_nuevos']);
        $this->assertSame(0, $r['opciones_nuevas']);
        $this->assertSame($antes, [TipoDeCondicion::count(), OpcionDeCondicion::count(), EmpresaCampo::whereNotNull('tipo_de_condicion_id')->count()]);
    }

    public function test_con_minimo_uno_entra_todo_lo_que_hay(): void
    {
        $this->fichasDeLaVidaReal();

        $r = $this->catalogo->sembrar(minimo: 1);

        $this->assertSame(0, $r['sueltos']);
        $this->assertContains('Incluido en el precio', $this->tipo('Flete')->opciones->pluck('texto')->all());
    }

    public function test_un_dato_largo_o_vacio_no_entra_a_la_lista(): void
    {
        $largo = str_repeat('muy largo ', 40);
        $this->campo('Notas', $largo);
        $this->campo('Notas', $largo);
        $this->campo('Notas', '   ');
        $this->campo('Notas', null);

        $r = $this->catalogo->sembrar(minimo: 1);

        $this->assertCount(0, $this->tipo('Notas')->opciones);
        // El largo queda en la ficha, tal cual, fuera de la lista.
        $this->assertSame(2, $r['sueltos']);
    }

    /* ------------------------------------------------------------ lo que se cambia */

    public function test_un_dato_nuevo_no_se_repite_aunque_se_escriba_distinto(): void
    {
        $tipo = $this->catalogo->tipo('Flete');
        $uno = $this->catalogo->opcion($tipo, 'A cargo del cliente');

        $this->assertSame($uno->id, $this->catalogo->opcion($tipo, '  a  CARGO del cliente ')->id);
        $this->assertSame($tipo->id, $this->catalogo->tipo('FLETE')->id);
        $this->assertCount(1, $tipo->opciones()->get());
    }

    public function test_un_dato_desactivado_vuelve_a_estar_disponible_si_se_lo_pide(): void
    {
        $tipo = $this->catalogo->tipo('Flete');
        $opcion = $this->catalogo->opcion($tipo, 'Incluido');
        $opcion->update(['activo' => false]);

        $this->assertTrue($this->catalogo->opcion($tipo, 'incluido')->activo);
    }

    public function test_renombrar_un_dato_cambia_el_texto_en_todas_las_fichas(): void
    {
        $this->fichasDeLaVidaReal();
        $this->catalogo->sembrar();
        $opcion = $this->tipo('Flete')->opciones->first();

        $this->catalogo->renombrarOpcion($opcion, 'Lo paga el cliente');

        $this->assertSame(['Lo paga el cliente', 'Lo paga el cliente'], EmpresaCampo::whereIn('titulo', ['Flete', 'flete '])->pluck('valor')->all());
    }

    public function test_renombrar_una_condicion_cambia_el_titulo_en_todas_las_fichas(): void
    {
        $this->fichasDeLaVidaReal();
        $this->catalogo->sembrar();

        $this->catalogo->renombrarTipo($this->tipo('Flete'), 'Transporte');

        $this->assertSame(3, EmpresaCampo::where('titulo', 'Transporte')->count());
        $this->assertSame(0, EmpresaCampo::whereIn('titulo', ['Flete', 'flete ', 'FLETE'])->count());
    }

    public function test_no_se_puede_renombrar_a_algo_que_ya_esta(): void
    {
        $tipo = $this->catalogo->tipo('Flete');
        $this->catalogo->tipo('Transporte');
        $uno = $this->catalogo->opcion($tipo, 'Incluido');
        $this->catalogo->opcion($tipo, 'A cargo del cliente');

        $this->expectException(ValidationException::class);
        $this->catalogo->renombrarOpcion($uno, 'a cargo del CLIENTE');
    }

    public function test_unir_dos_datos_pasa_las_fichas_al_que_queda(): void
    {
        $this->campo('Flete', 'Incluido');
        $this->campo('Flete', 'Incluído en el precio');
        $this->catalogo->sembrar(minimo: 1);
        $tipo = $this->tipo('Flete');
        [$origen, $destino] = [$tipo->opciones()->where('texto', 'Incluído en el precio')->first(), $tipo->opciones()->where('texto', 'Incluido')->first()];

        $fichas = $this->catalogo->unirOpciones($origen, $destino);

        $this->assertSame(1, $fichas);
        $this->assertNull(OpcionDeCondicion::find($origen->id));
        $this->assertSame(['Incluido', 'Incluido'], EmpresaCampo::where('titulo', 'Flete')->pluck('valor')->all());
        $this->assertSame(2, $destino->campos()->count());
    }

    public function test_unir_dos_condiciones_junta_sus_datos_y_sus_fichas(): void
    {
        $this->campo('Flete', 'Incluido');
        $this->campo('Fletes', 'Incluido');
        $this->campo('Fletes', 'A cargo del cliente');
        $this->catalogo->sembrar(minimo: 1);
        $queda = $this->tipo('Flete');
        $sobra = $this->tipo('Fletes');

        $this->catalogo->unirTipos($sobra, $queda);

        $this->assertNull(TipoDeCondicion::find($sobra->id));
        $this->assertEqualsCanonicalizing(['Incluido', 'A cargo del cliente'], $queda->opciones()->pluck('texto')->all());
        $this->assertSame(3, EmpresaCampo::where('titulo', 'Flete')->count());
        $this->assertSame(0, EmpresaCampo::where('titulo', 'Fletes')->count());
        $this->assertSame(2, $queda->opciones()->where('texto', 'Incluido')->first()->campos()->count());
    }

    public function test_adoptar_un_dato_suelto_lo_suma_a_la_lista_y_vincula_las_fichas_que_lo_tenian(): void
    {
        $this->campo('Flete', 'Lo trae el chofer');
        $this->campo('flete', 'lo trae el  chofer');
        // Con tres como mínimo, ninguno entra solo: quedan sueltos.
        $this->catalogo->sembrar(minimo: 3);
        $this->assertSame(0, $this->tipo('Flete')->opciones()->count());

        $r = $this->catalogo->adoptar('Flete', 'Lo trae el chofer');

        $this->assertSame(2, $r['fichas']);
        $this->assertSame('Lo trae el chofer', $r['opcion']->texto);
        $this->assertSame(2, $r['opcion']->campos()->count());
        $this->assertSame([], $this->catalogo->sueltos());
    }

    public function test_los_sueltos_se_listan_del_mas_repetido_al_menos(): void
    {
        $this->campo('Flete', 'Uno solo');
        $this->campo('Flete', 'Dos veces');
        $this->campo('Flete', 'Dos veces');
        $this->campo('Flete', null);

        $sueltos = $this->catalogo->sueltos();

        $this->assertSame(['Dos veces', 'Uno solo'], array_column($sueltos, 'valor'));
        $this->assertSame([2, 1], array_column($sueltos, 'n'));
    }
}

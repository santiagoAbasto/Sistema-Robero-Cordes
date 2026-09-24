<?php

namespace Tests\Feature;

use App\Models\CondicionHabitual;
use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\CondicionesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Las condiciones que van en cada cotizacion.
 *
 * Son mas de 2000 caracteres de terminos que la empresa repite en todas sus
 * ofertas. Antes habia que tipearlos o pegarlos, y dos de los bloques ni
 * siquiera entraban en la columna: "Forma de Pago" tiene 734 caracteres y
 * "Validez de Oferta" 674, contra un limite de 200. O sea que el texto que
 * CORDES manda todos los dias no se podia guardar.
 *
 * Ahora una cotizacion nueva arranca con el bloque puesto segun el juego que
 * se elija —importacion o stock, que difieren en plazo y forma de pago— y
 * desde ahi se edita. Estas pruebas cuidan tres cosas que no pueden fallar:
 * que el texto entre completo, que la fecha de validez sea la de ESTA
 * cotizacion y no la de la plantilla, y que no se mezclen los dos juegos.
 */
class CondicionesDeLaCotizacionTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CondicionesSeeder::class);
        $this->usuario = User::factory()->create();
        Sanctum::actingAs($this->usuario);
        $this->empresa = Empresa::create(['nombre' => 'PROFERTIL S.A.']);
    }

    private function crearCotizacion(array $extra = [])
    {
        return $this->postJson("/api/empresas/{$this->empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'validez_dias' => 9,
            'juego_condiciones' => 'Importacion',
            ...$extra,
        ]);
    }

    /**
     * Sin juego elegido no entra ninguna condicion.
     *
     * Importacion y stock difieren en el plazo de entrega —120 dias corridos
     * contra 2 a 4 habiles— y en la forma de pago. Elegir uno por la persona
     * mandaria la mitad de las ofertas con las condiciones del otro caso.
     */
    public function test_sin_juego_elegido_no_entra_ninguna_condicion(): void
    {
        $r = $this->crearCotizacion(['juego_condiciones' => null])->assertCreated();

        $conTitulo = collect($r->json('data.condiciones'))->whereNotNull('titulo');

        $this->assertCount(0, $conTitulo);
    }

    public function test_el_juego_de_stock_trae_su_propio_plazo_y_forma_de_pago(): void
    {
        $r = $this->crearCotizacion(['juego_condiciones' => 'Stock'])->assertCreated();

        $condiciones = collect($r->json('data.condiciones'));

        $this->assertStringContainsString(
            '2-4 días hábiles',
            $condiciones->firstWhere('titulo', 'Entrega')['texto'],
        );
        $this->assertStringContainsString(
            'Contado contra entrega',
            $condiciones->firstWhere('titulo', 'Condic. de Pago')['texto'],
        );
        // El parrafo de Reglamentos Tecnicos es solo de importacion.
        $this->assertStringNotContainsString(
            'Reglamentos Técnicos',
            $condiciones->firstWhere('titulo', 'Entrega')['texto'],
        );
    }

    public function test_el_juego_de_importacion_trae_los_120_dias_y_el_anticipo(): void
    {
        $r = $this->crearCotizacion(['juego_condiciones' => 'Importacion'])->assertCreated();

        $condiciones = collect($r->json('data.condiciones'));

        $entrega = $condiciones->firstWhere('titulo', 'Entrega')['texto'];

        $this->assertStringContainsString('120 días corridos', $entrega);
        $this->assertStringContainsString('Reglamentos Técnicos', $entrega);
        $this->assertStringContainsString(
            '50% de anticipo',
            $condiciones->firstWhere('titulo', 'Condic. de Pago')['texto'],
        );
    }

    /** Los dos juegos avisan lo mismo sobre largos aleatorios y venta al peso. */
    public function test_los_dos_juegos_avisan_por_los_largos_aleatorios(): void
    {
        foreach (['Importacion', 'Stock'] as $juego) {
            $r = $this->crearCotizacion(['juego_condiciones' => $juego])->assertCreated();

            $this->assertStringContainsString(
                'se facturará la cantidad efectivamente entregada',
                collect($r->json('data.condiciones'))->firstWhere('titulo', 'Cantidades')['texto'],
                "El juego {$juego} perdio el aviso de largos aleatorios.",
            );
        }
    }

    public function test_una_cotizacion_nueva_arranca_con_las_condiciones_de_siempre(): void
    {
        $r = $this->crearCotizacion()->assertCreated();

        $titulos = collect($r->json('data.condiciones'))->pluck('titulo')->filter()->all();

        $this->assertSame([
            'Cantidades',
            'Precios',
            'Entrega',
            'Certificación',
            'Condic. de Pago',
            'Forma de Pago',
            'Validez de Oferta',
            'IMPORTANTE',
        ], $titulos);
    }

    /** El bloque largo tiene que entrar entero, no recortado. */
    public function test_el_bloque_de_forma_de_pago_entra_completo(): void
    {
        $r = $this->crearCotizacion()->assertCreated();

        $formaDePago = collect($r->json('data.condiciones'))
            ->firstWhere('titulo', 'Forma de Pago');

        $this->assertGreaterThan(700, mb_strlen($formaDePago['texto']));
        // Las dos puntas: si se hubiera truncado, la ultima frase no estaria.
        $this->assertStringContainsString('DOLARES ESTADOUNIDENSES', $formaDePago['texto']);
        $this->assertStringContainsString('N.C / N.D.', $formaDePago['texto']);
        // Y el detalle que importa: contra que tipo de cambio se convierte.
        $this->assertStringContainsString('BNA Billete venta', $formaDePago['texto']);
    }

    /**
     * La fecha de validez sale de la cotizacion, no de la plantilla.
     *
     * Es el error caro: que una oferta salga diciendo que vence en la fecha de
     * otra cotizacion, o peor, en una fecha ya pasada.
     */
    public function test_la_validez_lleva_la_fecha_de_esta_cotizacion(): void
    {
        $r = $this->crearCotizacion()->assertCreated();

        $consulta = Consulta::findOrFail($r->json('data.id'));
        $esperada = $consulta->vence_el->format('d-m-Y');

        $validez = collect($r->json('data.condiciones'))
            ->firstWhere('titulo', 'Validez de Oferta');

        $this->assertStringStartsWith($esperada.', salvo venta previa', $validez['texto']);
        $this->assertStringNotContainsString('{vence}', $validez['texto']);
    }

    public function test_dos_cotizaciones_con_distinta_validez_no_comparten_la_fecha(): void
    {
        $nueve = $this->crearCotizacion(['validez_dias' => 9])->assertCreated();
        $treinta = $this->crearCotizacion(['validez_dias' => 30])->assertCreated();

        $fecha = fn ($r) => collect($r->json('data.condiciones'))
            ->firstWhere('titulo', 'Validez de Oferta')['texto'];

        $this->assertNotSame($fecha($nueve), $fecha($treinta));
    }

    /** Sin vencimiento no queda el hueco a la vista. */
    public function test_sin_fecha_de_vencimiento_no_sale_el_marcador(): void
    {
        $consulta = new Consulta(['validez_dias' => 0]);
        $consulta->vence_el = null;

        $texto = CondicionHabitual::where('titulo', 'Validez de Oferta')
            ->firstOrFail()->textoPara($consulta);

        $this->assertStringNotContainsString('{vence}', $texto);
        $this->assertStringStartsWith('Salvo venta previa', $texto);
    }

    /**
     * Vaciar las condiciones a proposito no es lo mismo que no mandarlas.
     *
     * Si alguien las saca todas, tienen que quedar sacadas: que el sistema las
     * vuelva a poner solo seria pelearle a la persona.
     */
    public function test_mandar_la_lista_vacia_deja_la_cotizacion_sin_condiciones(): void
    {
        $r = $this->crearCotizacion(['condiciones' => []])->assertCreated();

        $condiciones = collect($r->json('data.condiciones'));

        // Solo queda la linea automatica de validez, que es la de siempre.
        $this->assertCount(1, $condiciones);
        $this->assertSame('Automatica', $condiciones->first()['origen']);
        $this->assertNull($condiciones->first()['titulo']);
    }

    /** Las condiciones propias mandan sobre las de siempre. */
    public function test_las_condiciones_que_mandan_reemplazan_a_las_de_siempre(): void
    {
        $r = $this->crearCotizacion([
            'condiciones' => [
                ['titulo' => 'Entrega', 'texto' => '120 dias corridos.'],
            ],
        ])->assertCreated();

        $condiciones = collect($r->json('data.condiciones'));

        $this->assertSame('120 dias corridos.', $condiciones->firstWhere('titulo', 'Entrega')['texto']);
        $this->assertNull($condiciones->firstWhere('titulo', 'Forma de Pago'));
    }

    /**
     * El bloque de validez y la linea suelta no pueden convivir.
     *
     * Serian dos textos distintos diciendo hasta cuando vale la oferta.
     */
    public function test_con_el_bloque_de_validez_no_se_agrega_la_linea_suelta(): void
    {
        $r = $this->crearCotizacion()->assertCreated();

        $sueltas = collect($r->json('data.condiciones'))
            ->filter(fn ($c) => $c['titulo'] === null);

        $this->assertCount(0, $sueltas);
    }

    /** Cambiar la plantilla no puede cambiar una cotizacion ya guardada. */
    public function test_cambiar_la_plantilla_no_toca_lo_ya_cotizado(): void
    {
        $r = $this->crearCotizacion()->assertCreated();
        $id = $r->json('data.id');

        $antes = collect($r->json('data.condiciones'))->firstWhere('titulo', 'Entrega')['texto'];

        CondicionHabitual::where('titulo', 'Entrega')->update(['texto' => 'OTRA COSA']);

        $ahora = collect($this->getJson("/api/consultas/{$id}")->json('data.condiciones'))
            ->firstWhere('titulo', 'Entrega')['texto'];

        $this->assertSame($antes, $ahora);
        $this->assertStringContainsString('120 días corridos', $ahora);
    }

    /**
     * Los datos con los que el cliente reconoce cada item.
     *
     * Profertil mando "Plano SUO1413884/1 posición 21 y 22": antes eso
     * terminaba metido dentro de la descripcion.
     */
    public function test_la_linea_guarda_el_codigo_y_el_item_del_cliente(): void
    {
        $r = $this->postJson("/api/empresas/{$this->empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'juego_condiciones' => 'Importacion',
            'lineas' => [[
                'descripcion' => 'Arandela Plana Øe 21mm x Øi 10,5mm x 3mm',
                'codigo_cliente' => 'SUO1413884-22',
                'item_cliente' => '22',
                'nota' => "Plano SUO1413884/1 posición 22.\nNo cumple UNI 6592.",
                'cantidad' => 100,
                'precio_unitario' => 3.85,
            ]],
        ])->assertCreated();

        $linea = $r->json('data.lineas.0');

        $this->assertSame('SUO1413884-22', $linea['codigo_cliente']);
        $this->assertSame('22', $linea['item_cliente']);
        $this->assertStringContainsString('posición 22', $linea['nota']);
        // La nota del articulo es del articulo, no de la cotizacion.
        $this->assertNull($r->json('data.nota'));
    }

    /** Copiar una cotizacion se lleva los titulos, no solo el texto. */
    public function test_copiar_una_cotizacion_conserva_los_titulos(): void
    {
        $r = $this->crearCotizacion()->assertCreated();
        $destino = Empresa::create(['nombre' => 'OTRO CLIENTE']);

        $copia = $this->postJson("/api/consultas/{$r->json('data.id')}/copiar", [
            'empresas' => [$destino->id],
        ])->assertCreated();

        $titulos = collect($copia->json('borradores.0.condiciones'))
            ->pluck('titulo')->filter()->all();

        $this->assertContains('Forma de Pago', $titulos);
        $this->assertContains('Validez de Oferta', $titulos);
    }
}

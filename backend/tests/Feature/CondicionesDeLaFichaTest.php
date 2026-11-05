<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\EmpresaCampo;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\CondicionesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La ficha de la empresa manda sobre las condiciones de Configuración.
 *
 * Pedido del cliente (04-11-2026): lo que cargan en las condiciones de la
 * ficha lo pisaba el juego de Configuración al cotizar. El pago de la ficha ni
 * siquiera salía en la hoja. Ahora, cuando la ficha y el juego hablan de lo
 * mismo, gana la ficha. (Nombres ficticios: el repo es público.)
 */
class CondicionesDeLaFichaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CondicionesSeeder::class);
        Sanctum::actingAs(User::factory()->create());
        $this->empresa = Empresa::create(['nombre' => 'Empresa Demo SA']);
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

    private function bloques($respuesta)
    {
        return collect($respuesta->json('data.condiciones'));
    }

    private function campo(string $titulo, ?string $valor, bool $usar = true): void
    {
        EmpresaCampo::create([
            'empresa_id' => $this->empresa->id,
            'titulo' => $titulo,
            'valor' => $valor,
            'orden' => 1,
            'usar_al_cotizar' => $usar,
        ]);
    }

    public function test_la_condicion_de_pago_de_la_ficha_reemplaza_la_generica_y_sale_en_la_hoja(): void
    {
        $this->empresa->update(['condicion_pago' => '30 días fecha factura']);

        $bloques = $this->bloques($this->crearCotizacion()->assertCreated());

        $pago = $bloques->firstWhere('titulo', 'Condic. de Pago');

        $this->assertSame('30 días fecha factura', $pago['texto']);
        $this->assertSame('De la ficha', $pago['origen']);
        $this->assertTrue($pago['imprime'], 'si no se imprime, la ficha sigue sin verse en la hoja');
        // Un solo bloque de pago: el genérico no queda al lado.
        $this->assertCount(1, $bloques->where('titulo', 'Condic. de Pago'));
        $this->assertStringNotContainsString('50% de anticipo', $bloques->pluck('texto')->implode(' '));
    }

    public function test_forma_de_pago_no_se_toca_es_la_clausula_de_moneda_de_la_factura(): void
    {
        $this->empresa->update(['condicion_pago' => 'Contado']);

        $bloques = $this->bloques($this->crearCotizacion()->assertCreated());

        $this->assertStringContainsString(
            'DOLARES ESTADOUNIDENSES',
            $bloques->firstWhere('titulo', 'Forma de Pago')['texto'],
        );
        $this->assertSame('Automatica', $bloques->firstWhere('titulo', 'Forma de Pago')['origen']);
    }

    public function test_un_campo_de_la_ficha_con_el_mismo_titulo_reemplaza_al_del_juego_en_su_lugar(): void
    {
        $this->campo('Entrega', '15 días hábiles');

        $bloques = $this->bloques($this->crearCotizacion()->assertCreated());
        $titulos = $bloques->pluck('titulo')->all();

        $entrega = $bloques->firstWhere('titulo', 'Entrega');

        $this->assertSame('15 días hábiles', $entrega['texto']);
        $this->assertSame('De la ficha', $entrega['origen']);
        $this->assertCount(1, $bloques->where('titulo', 'Entrega'));
        // Sigue en su misma posición: entre Precios y Certificación.
        $this->assertSame(
            ['Precios', 'Entrega', 'Certificación'],
            array_slice($titulos, array_search('Precios', $titulos, true), 3),
        );
    }

    public function test_el_titulo_se_compara_sin_tildes_ni_mayusculas(): void
    {
        $this->campo('CERTIFICACION', 'Con certificado 3.1');

        $bloques = $this->bloques($this->crearCotizacion()->assertCreated());

        $this->assertCount(1, $bloques->filter(fn ($b) => str_contains(mb_strtolower($b['titulo'] ?? ''), 'certific')));
        $this->assertSame('Con certificado 3.1', $bloques->firstWhere('origen', 'De la ficha')['texto']);
    }

    public function test_un_campo_con_titulo_nuevo_se_agrega_antes_de_la_validez(): void
    {
        $this->campo('Flete', 'A cargo del cliente');

        $titulos = $this->bloques($this->crearCotizacion()->assertCreated())->pluck('titulo')->filter()->values()->all();

        $flete = array_search('Flete', $titulos, true);
        $validez = array_search('Validez de Oferta', $titulos, true);

        $this->assertNotFalse($flete);
        $this->assertSame($validez - 1, $flete, 'el de validez tiene que seguir siendo el último');
    }

    public function test_un_campo_que_no_se_usa_al_cotizar_o_esta_vacio_no_entra(): void
    {
        $this->campo('Flete', 'A cargo del cliente', usar: false);
        $this->campo('Seguro', '   ');

        $bloques = $this->bloques($this->crearCotizacion()->assertCreated());

        $this->assertNull($bloques->firstWhere('titulo', 'Flete'));
        $this->assertNull($bloques->firstWhere('titulo', 'Seguro'));
    }

    public function test_los_campos_que_alimentan_la_cabecera_no_se_duplican_como_bloques(): void
    {
        $this->campo('Lista de precios', 'Lista 3');
        $this->campo('Moneda habitual', 'DOLAR');

        $bloques = $this->bloques($this->crearCotizacion()->assertCreated());

        $this->assertNull($bloques->firstWhere('titulo', 'Lista de precios'));
        $this->assertNull($bloques->firstWhere('titulo', 'Moneda habitual'));
    }

    public function test_el_pago_puede_venir_del_campo_tipo_de_pago_de_la_ficha(): void
    {
        $this->campo('Tipo de pago', '60 días');

        $pago = $this->bloques($this->crearCotizacion()->assertCreated())->firstWhere('titulo', 'Condic. de Pago');

        $this->assertSame('60 días', $pago['texto']);
        $this->assertSame('De la ficha', $pago['origen']);
    }

    public function test_si_la_cotizacion_trae_su_propio_pago_manda_ese(): void
    {
        $this->empresa->update(['condicion_pago' => '30 días']);

        $pago = $this->bloques($this->crearCotizacion(['condicion_pago' => 'Contado anticipado'])->assertCreated())
            ->firstWhere('titulo', 'Condic. de Pago');

        $this->assertSame('Contado anticipado', $pago['texto']);
    }

    public function test_un_campo_de_pago_propio_de_la_ficha_gana_sobre_condicion_pago(): void
    {
        $this->empresa->update(['condicion_pago' => '30 días']);
        $this->campo('Condic. de Pago', 'Anticipo del 100%');

        $bloques = $this->bloques($this->crearCotizacion()->assertCreated());

        $this->assertCount(1, $bloques->where('titulo', 'Condic. de Pago'));
        $this->assertSame('Anticipo del 100%', $bloques->firstWhere('titulo', 'Condic. de Pago')['texto']);
    }

    public function test_sin_nada_en_la_ficha_queda_exactamente_igual_que_antes(): void
    {
        $conFicha = $this->bloques($this->crearCotizacion()->assertCreated());

        $this->assertNull($conFicha->firstWhere('origen', 'De la ficha'));
        $this->assertSame('Automatica', $conFicha->firstWhere('titulo', 'Entrega')['origen']);
        $this->assertStringContainsString('50% de anticipo', $conFicha->firstWhere('titulo', 'Condic. de Pago')['texto']);
    }

    public function test_sin_juego_elegido_sigue_sin_entrar_ninguna_condicion_del_juego(): void
    {
        $this->empresa->update(['condicion_pago' => '30 días']);

        $bloques = $this->bloques($this->crearCotizacion(['juego_condiciones' => null])->assertCreated());

        // Solo la línea automática de validez, como siempre.
        $this->assertCount(0, $bloques->whereNotNull('titulo'));
    }

    public function test_el_origen_que_manda_el_navegador_se_guarda(): void
    {
        $r = $this->crearCotizacion([
            'condiciones' => [
                ['titulo' => 'Flete', 'texto' => 'A cargo del cliente', 'imprime' => true, 'origen' => 'De la ficha'],
                ['titulo' => 'Extra', 'texto' => 'Escrito a mano', 'imprime' => true],
            ],
        ])->assertCreated();

        $bloques = $this->bloques($r);

        $this->assertSame('De la ficha', $bloques->firstWhere('titulo', 'Flete')['origen']);
        $this->assertSame('Manual', $bloques->firstWhere('titulo', 'Extra')['origen']);
    }

    public function test_cambiar_la_ficha_despues_no_cambia_una_cotizacion_ya_hecha(): void
    {
        $this->empresa->update(['condicion_pago' => '30 días']);
        $id = $this->crearCotizacion()->assertCreated()->json('data.id');

        $this->empresa->update(['condicion_pago' => '90 días']);

        $pago = $this->bloques($this->getJson("/api/consultas/{$id}")->assertOk())->firstWhere('titulo', 'Condic. de Pago');

        $this->assertSame('30 días', $pago['texto']);
    }
}

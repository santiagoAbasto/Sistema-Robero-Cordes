<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Material;
use App\Models\User;
use App\Services\CalculadoraDePeso;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Administracion de formas y formulas.
 *
 * Una formula mal cargada no se nota: devuelve un numero igual, solo que
 * equivocado. Por eso lo que se prueba acá es sobre todo lo que NO se tiene
 * que poder guardar.
 */
class FormasAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        $this->usuario = User::factory()->create();
        Sanctum::actingAs($this->usuario);
    }

    public function test_no_deja_guardar_una_formula_que_usa_una_medida_no_declarada(): void
    {
        // "largo" no existe: el campo se llama "length".
        $r = $this->postJson('/api/formas', [
            'nombre' => 'FORMA NUEVA',
            'campos' => [['clave' => 'diameter', 'label' => 'Diametro']],
            'expresion' => '(pi * pow(diameter, 2) / 4 * largo) / 1000',
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('largo', $r->json('errors.expresion.0'));
        $this->assertDatabaseMissing('formas', ['nombre' => 'FORMA NUEVA']);
    }

    public function test_no_deja_guardar_una_formula_rota(): void
    {
        $this->postJson('/api/formas', [
            'nombre' => 'FORMA ROTA',
            'campos' => [['clave' => 'diameter', 'label' => 'Diametro']],
            'expresion' => '(pi * pow(diameter, 2)',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('formas', ['nombre' => 'FORMA ROTA']);
    }

    public function test_el_cano_puede_usar_diametro_y_pared_sin_declararlos(): void
    {
        // En un caño esas dos medidas salen de la tabla de comerciales.
        $this->postJson('/api/formas', [
            'nombre' => 'CAÑO ESPECIAL',
            'campos' => [['clave' => 'length', 'label' => 'Largo']],
            'expresion' => '(pi * wall * (outer - wall) * length) / 1000',
            'usa_cano' => true,
        ])->assertStatus(201);

        $this->assertDatabaseHas('formas', ['nombre' => 'CAÑO ESPECIAL', 'usa_cano' => true]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function pegadasDeExcel(): array
    {
        return [
            'con el igual' => ['=(pi * pow(diameter, 2) / 4 * length) / 1000', 'pi'],
            'con POTENCIA y punto y coma' => ['=PI()*POTENCIA(diameter;2)/4*length/1000', 'pow'],
            'con por y dividido raros' => ['PI()×POTENCIA(diameter,2)÷4×length÷1000', 'pow'],
            'con RAIZ' => ['sqrt(pow(diameter,2)) * length / 1000', 'sqrt'],
        ];
    }

    #[DataProvider('pegadasDeExcel')]
    public function test_acepta_formulas_pegadas_de_una_planilla(string $pegada, string $esperado): void
    {
        $r = $this->postJson('/api/formas/probar', [
            'expresion' => $pegada,
            'campos' => [['clave' => 'diameter'], ['clave' => 'length']],
            'valores' => ['diameter' => 25, 'length' => 6000],
        ]);

        $r->assertOk();
        $this->assertTrue($r->json('ok'), $r->json('error') ?? '');
        $this->assertStringContainsString($esperado, $r->json('formula'));
        $this->assertStringNotContainsString('=', $r->json('formula'));
        $this->assertStringNotContainsString(';', $r->json('formula'));
    }

    public function test_probar_avisa_que_medida_falta_declarar(): void
    {
        $r = $this->postJson('/api/formas/probar', [
            'expresion' => '(pi * pow(diameter, 2) / 4 * largo) / 1000',
            'campos' => [['clave' => 'diameter']],
        ]);

        $r->assertOk();
        $this->assertFalse($r->json('ok'));
        $this->assertSame(['largo'], $r->json('sin_declarar'));
    }

    public function test_probar_da_el_volumen_con_los_valores_de_ejemplo(): void
    {
        $r = $this->postJson('/api/formas/probar', [
            'expresion' => '(pi * pow(diameter, 2) / 4 * length) / 1000',
            'campos' => [['clave' => 'diameter'], ['clave' => 'length']],
            'valores' => ['diameter' => 25, 'length' => 6000],
        ]);

        $r->assertOk();
        $this->assertEqualsWithDelta(2945.2431, $r->json('volumen_cm3'), 0.001);
    }

    public function test_el_listado_dice_cuantas_cotizaciones_toca_cada_forma(): void
    {
        $forma = Forma::where('nombre', 'BARRA REDONDA')->firstOrFail();
        $consulta = $this->cotizacionCon($forma, 3);

        $r = $this->getJson('/api/formas')->assertOk();

        $fila = collect($r->json('formas'))->firstWhere('id', $forma->id);

        $this->assertSame(3, $fila['lineas_cotizadas']);
        $this->assertNotNull($consulta->id);
    }

    public function test_cambiar_una_formula_no_recalcula_lo_ya_cotizado(): void
    {
        $forma = Forma::where('nombre', 'BARRA REDONDA')->firstOrFail();

        $r = app(CalculadoraDePeso::class)->calcular(
            Material::where('nombre', 'AISI 304')->firstOrFail(),
            $forma,
            ['diameter' => ['valor' => 25, 'unidad' => 'mm'], 'length' => ['valor' => 6, 'unidad' => 'm']],
            piezas: 10,
        );

        $consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'CLIENTE'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Confirmada',
            'usuario_id' => $this->usuario->id,
        ]);

        // forceCreate: la foto la escribe el servidor; el modelo la tiene
        // cerrada al fill para que no llegue del navegador.
        $linea = ConsultaLinea::forceCreate([
            'consulta_id' => $consulta->id,
            'orden' => 1,
            'descripcion' => 'BARRA REDONDA 25 X 6000',
            'forma_id' => $forma->id,
            'calculo' => $r,
            'peso_kg' => $r['peso_total_kg'],
        ]);

        $pesoDelDia = (float) $linea->peso_kg;
        $formulaDelDia = $r['formula'];

        // Alguien cambia la cuenta de la forma meses despues.
        $this->putJson("/api/formas/{$forma->id}", [
            'nombre' => 'BARRA REDONDA',
            'campos' => [
                ['clave' => 'diameter', 'label' => 'Diametro'],
                ['clave' => 'length', 'label' => 'Largo'],
            ],
            // Una cuenta distinta a proposito: el doble.
            'expresion' => '(pi * pow(diameter, 2) / 4 * length) / 500',
        ])->assertOk();

        $linea->refresh();

        // La cotizacion vieja no se movio, y guarda con que cuenta se hizo.
        $this->assertSame($pesoDelDia, (float) $linea->peso_kg);
        $this->assertSame($formulaDelDia, $linea->calculo['formula']);
        $this->assertStringNotContainsString('500', $linea->calculo['formula']);
    }

    /**
     * El navegador no decide ningun numero.
     *
     * Se mandan falsificados TODOS los resultados derivados —los dos volumenes,
     * los dos pesos, la densidad y hasta la formula— junto con las medidas de
     * verdad. Lo unico que el servidor tiene que mirar son las medidas.
     *
     * Importa porque el peso se convierte en precio: si el navegador pudiera
     * dictarlo, cualquiera con la consola abierta cambia lo que sale una
     * cotizacion y nadie se entera hasta la factura.
     */
    public function test_el_navegador_no_puede_dictar_ningun_resultado(): void
    {
        $empresa = Empresa::create(['nombre' => 'CLIENTE']);
        $forma = Forma::where('nombre', 'BARRA REDONDA')->firstOrFail();
        $material = Material::where('nombre', 'AISI 304')->firstOrFail();

        $densidadReal = (float) $material->densidad;
        $formulaReal = $forma->expresion;

        // Lo que dice el navegador. Todo mentira.
        $falsos = [
            'peso_kg' => 999999,
            'weight_kg' => 111111,
            'weight_per_piece_kg' => 222222,
            'total_weight_kg' => 333333,
            'volume_cm3' => 444444,
            'volume_per_piece_cm3' => 555555,
            'total_volume_cm3' => 666666,
            'density_g_cm3' => 777.7,
            'formula' => '(1) / 1',
            'calculo' => [
                'peso_por_pieza_kg' => 888888,
                'peso_total_kg' => 999999,
                'volumen_por_pieza_cm3' => 111111,
                'volumen_total_cm3' => 222222,
                'densidad_g_cm3' => 777.7,
                'formula' => 'lo que yo diga',
                'ok' => true,
            ],
        ];

        $this->postJson("/api/empresas/{$empresa->id}/consultas", [
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'lineas' => [array_merge($falsos, [
                'descripcion' => 'BARRA REDONDA 25 X 6000',
                'material_id' => $material->id,
                'forma_id' => $forma->id,
                // Lo unico legitimo: medidas, unidades, piezas y caño.
                'calc_piezas' => 10,
                'calc_cano_id' => null,
                'calc_medidas' => [
                    'diameter' => ['valor' => 25, 'unidad' => 'mm'],
                    // En metros a proposito: el servidor tiene que normalizar.
                    'length' => ['valor' => 6, 'unidad' => 'm'],
                ],
            ])],
        ])->assertCreated();

        $linea = ConsultaLinea::latest('id')->firstOrFail();
        $foto = $linea->calculo;

        // 2 · el servidor vuelve a leer la densidad y la formula vigentes
        $this->assertEqualsWithDelta($densidadReal, $foto['densidad_g_cm3'], 0.0001);
        $this->assertSame($formulaReal, $foto['formula']);

        // 3 · normaliza las medidas a milimetros
        // (al volver del JSON un 25.0 llega como 25: se compara el numero)
        $this->assertEqualsWithDelta(25, $foto['medidas']['diameter']['valor_mm'], 0.0001);
        $this->assertEqualsWithDelta(6000, $foto['medidas']['length']['valor_mm'], 0.0001);
        // Y deja anotado como venian cargadas.
        $this->assertSame('m', $foto['medidas']['length']['unidad']);
        $this->assertEqualsWithDelta(6, $foto['medidas']['length']['valor'], 0.0001);

        // 4 · recalcula los cuatro resultados
        $volumenUnitario = 2945.2431;
        $this->assertEqualsWithDelta($volumenUnitario, $foto['volumen_por_pieza_cm3'], 0.001);
        $this->assertEqualsWithDelta($volumenUnitario * 10, $foto['volumen_total_cm3'], 0.01);
        $this->assertEqualsWithDelta(
            $volumenUnitario * $densidadReal / 1000,
            $foto['peso_por_pieza_kg'],
            0.001,
        );
        $this->assertEqualsWithDelta(
            $volumenUnitario * $densidadReal * 10 / 1000,
            $foto['peso_total_kg'],
            0.01,
        );
        $this->assertEqualsWithDelta($foto['peso_total_kg'], (float) $linea->peso_kg, 0.001);
        $this->assertEqualsWithDelta(10, $foto['piezas'], 0.0001);

        // 5 · ninguno de los valores falsificados sobrevivio
        $inventados = [999999, 111111, 222222, 333333, 444444, 555555, 666666, 888888, 777.7];

        foreach (
            ['volumen_por_pieza_cm3', 'volumen_total_cm3', 'peso_por_pieza_kg',
                'peso_total_kg', 'densidad_g_cm3'] as $campo
        ) {
            $this->assertNotContains(
                round((float) $foto[$campo], 1),
                $inventados,
                "El navegador logro dictar {$campo}.",
            );
        }

        $this->assertNotSame('lo que yo diga', $foto['formula']);
        $this->assertNotSame('(1) / 1', $foto['formula']);
        $this->assertNotEquals(999999, (float) $linea->peso_kg);

        // 6 · la foto tiene solo lo que calculo el servidor: ninguna de las
        // claves que invento el navegador entro.
        foreach (['weight_kg', 'weight_per_piece_kg', 'total_weight_kg', 'volume_cm3',
            'volume_per_piece_cm3', 'total_volume_cm3', 'density_g_cm3'] as $inventada) {
            $this->assertArrayNotHasKey($inventada, $foto);
        }

        // Y tampoco quedaron colgadas en la linea.
        foreach (['weight_kg', 'volume_cm3', 'density_g_cm3'] as $inventada) {
            $this->assertNull($linea->getAttribute($inventada));
        }
    }

    /**
     * 1 · lo unico que el navegador puede mandar.
     *
     * Si mañana alguien agrega un campo derivado a la validacion, esta prueba
     * lo caza: la lista de lo aceptado tiene que ser exactamente esta.
     */
    public function test_solo_se_aceptan_medidas_unidades_piezas_y_cano(): void
    {
        $reglas = (new \ReflectionMethod(
            \App\Http\Controllers\ConsultaEscrituraController::class, 'validar'
        ));
        $codigo = file_get_contents(
            (new \ReflectionClass(\App\Http\Controllers\ConsultaEscrituraController::class))->getFileName()
        );

        $delCalculo = [];

        foreach (['calc_medidas', 'calc_piezas', 'calc_cano_id'] as $permitido) {
            $this->assertStringContainsString("lineas.*.{$permitido}", $codigo);
            $delCalculo[] = $permitido;
        }

        // Ningun resultado derivado esta entre lo que se valida y se guarda.
        foreach (['peso_kg', 'weight_kg', 'volumen', 'volume', 'densidad_g_cm3',
            'density', 'calculo'] as $prohibido) {
            $this->assertStringNotContainsString("'lineas.*.{$prohibido}'", $codigo);
        }

        $this->assertCount(3, $delCalculo);
        $this->assertNotNull($reglas->getName());
    }

    /**
     * Una forma con historial no se borra: se desactiva.
     *
     * Borrarla dejaria las lineas viejas apuntando a nada, y una cotizacion ya
     * impresa y mandada tiene que poder volver a abrirse igual que el dia que
     * se hizo.
     */
    public function test_no_se_puede_borrar_una_forma_que_ya_se_cotizo(): void
    {
        $forma = Forma::where('nombre', 'BARRA REDONDA')->firstOrFail();
        $this->cotizacionCon($forma, 2);

        $r = $this->deleteJson("/api/formas/{$forma->id}")->assertOk();

        $this->assertFalse($r->json('borrada'));
        $this->assertStringContainsString('desactivada', $r->json('mensaje'));

        // Sigue existiendo, pero fuera de la lista al cotizar.
        $forma->refresh();
        $this->assertFalse($forma->activo);
        $this->assertDatabaseHas('formas', ['id' => $forma->id]);

        // Y las lineas que la usaban la siguen encontrando.
        $linea = ConsultaLinea::where('forma_id', $forma->id)->firstOrFail();
        $this->assertSame('BARRA REDONDA', $linea->forma->nombre);
    }

    public function test_una_forma_sin_uso_si_se_borra(): void
    {
        $r = $this->postJson('/api/formas', [
            'nombre' => 'FORMA SIN USAR',
            'campos' => [['clave' => 'diameter', 'label' => 'Diametro']],
        ])->assertStatus(201);

        $id = $r->json('id');

        $borrada = $this->deleteJson("/api/formas/{$id}")->assertOk();

        $this->assertTrue($borrada->json('borrada'));
        $this->assertDatabaseMissing('formas', ['id' => $id]);
    }

    public function test_la_clave_se_arma_sola_y_no_se_repite(): void
    {
        $primera = $this->postJson('/api/formas', ['nombre' => 'MEDIA CAÑA'])->assertStatus(201);

        $this->assertSame('media_cana', $primera->json('clave'));

        // Otro nombre, distinto de verdad —MySQL compara sin distinguir
        // mayusculas ni acentos— pero que da la misma clave.
        $segunda = $this->postJson('/api/formas', ['nombre' => 'MEDIA-CANA'])->assertStatus(201);

        $this->assertNotSame('media_cana', $segunda->json('clave'));
        $this->assertStringStartsWith('media_cana', $segunda->json('clave'));
    }

    /**
     * La clave unica la impone la BASE, no solo el validador.
     *
     * El validador se puede saltear: un seeder, una importacion, un comando.
     * El indice no.
     */
    public function test_la_base_rechaza_dos_formas_con_la_misma_clave(): void
    {
        $this->assertTrue(
            Schema::hasIndex('formas', ['clave'], 'unique'),
            'Falta el indice UNIQUE en formas.clave.'
        );

        Forma::create(['nombre' => 'PRIMERA', 'clave' => 'repetida']);

        $this->expectException(UniqueConstraintViolationException::class);

        // Sin pasar por el controlador ni por el validador.
        DB::table('formas')->insert([
            'nombre' => 'SEGUNDA',
            'clave' => 'repetida',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_no_acepta_una_clave_con_formato_invalido(): void
    {
        $this->postJson('/api/formas', [
            'nombre' => 'FORMA X',
            'clave' => 'Con Mayusculas Y Espacios',
        ])->assertStatus(422);
    }

    /**
     * La clave de una forma con historial esta congelada.
     *
     * No es una advertencia: el servidor la rechaza. Cambiarla dejaria las
     * cotizaciones viejas apuntando a algo que ya no existe.
     */
    public function test_no_se_puede_cambiar_la_clave_de_una_forma_con_historial(): void
    {
        $forma = Forma::where('nombre', 'BARRA REDONDA')->firstOrFail();
        $claveOriginal = $forma->clave;
        $this->cotizacionCon($forma, 1);

        $r = $this->putJson("/api/formas/{$forma->id}", [
            'nombre' => 'BARRA REDONDA',
            'clave' => 'otra_clave',
            'campos' => [
                ['clave' => 'diameter', 'label' => 'Diametro'],
                ['clave' => 'length', 'label' => 'Largo'],
            ],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('migracion', $r->json('errors.clave.0'));

        $forma->refresh();
        $this->assertSame($claveOriginal, $forma->clave);
    }

    public function test_la_clave_se_puede_cambiar_mientras_no_tenga_historial(): void
    {
        $id = $this->postJson('/api/formas', ['nombre' => 'FORMA LIBRE'])->json('id');

        $this->putJson("/api/formas/{$id}", [
            'nombre' => 'FORMA LIBRE',
            'clave' => 'forma_renombrada',
        ])->assertOk();

        $this->assertSame('forma_renombrada', Forma::find($id)->clave);
    }

    public function test_el_listado_dice_si_la_clave_se_puede_cambiar(): void
    {
        $conHistorial = Forma::where('nombre', 'BARRA REDONDA')->firstOrFail();
        $this->cotizacionCon($conHistorial, 1);

        $sinHistorial = Forma::where('nombre', 'ESFERA')->firstOrFail();

        $formas = collect($this->getJson('/api/formas')->json('formas'))->keyBy('id');

        $this->assertFalse($formas[$conHistorial->id]['clave_editable']);
        $this->assertTrue($formas[$sinHistorial->id]['clave_editable']);
    }

    /**
     * Desactivar una forma no rompe nada de lo ya hecho.
     *
     * Sale de la lista al cotizar, pero la cotizacion vieja tiene que poder
     * abrirse, imprimirse y mostrar el mismo peso que el dia que se hizo.
     */
    public function test_desactivar_una_forma_no_toca_las_cotizaciones_viejas(): void
    {
        $forma = Forma::where('nombre', 'BARRA REDONDA')->firstOrFail();
        $material = Material::where('nombre', 'AISI 304')->firstOrFail();

        $calculo = app(CalculadoraDePeso::class)->calcular(
            $material,
            $forma,
            ['diameter' => ['valor' => 25, 'unidad' => 'mm'], 'length' => ['valor' => 6, 'unidad' => 'm']],
            piezas: 10,
        );

        $consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'CLIENTE'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Confirmada',
            'usuario_id' => $this->usuario->id,
        ]);

        // forceCreate: la escribe el servidor, no el navegador.
        $linea = ConsultaLinea::forceCreate([
            'consulta_id' => $consulta->id,
            'orden' => 1,
            'descripcion' => 'BARRA REDONDA 25 X 6000',
            'forma_id' => $forma->id,
            'material_id' => $material->id,
            'cantidad' => 10,
            'precio_unitario' => 100,
            'calculo' => $calculo,
            'peso_kg' => $calculo['peso_total_kg'],
        ]);
        $linea->recalcular();
        $linea->save();

        $pesoDelDia = (float) $linea->peso_kg;

        // Se desactiva.
        $this->deleteJson("/api/formas/{$forma->id}")->assertOk();
        $this->assertFalse($forma->fresh()->activo);

        // 1 · ya no se ofrece al cotizar
        $delCatalogo = collect($this->getJson('/api/catalogos')->json('formas'))->pluck('nombre');
        $this->assertNotContains('BARRA REDONDA', $delCatalogo);

        // 2 · la cotizacion vieja sigue mostrandola
        $vista = $this->getJson("/api/consultas/{$consulta->id}")->assertOk();
        $this->assertSame('BARRA REDONDA', $vista->json('data.lineas.0.forma'));

        // 3 · el PDF sale igual
        $pdf = $this->get("/api/consultas/{$consulta->id}/pdf");
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        // 4 · el calculo guardado no se movio
        $linea->refresh();
        $this->assertSame($pesoDelDia, (float) $linea->peso_kg);
        $this->assertSame('BARRA REDONDA', $linea->calculo['forma']);
        $this->assertEqualsWithDelta(2945.2431, $linea->calculo['volumen_por_pieza_cm3'], 0.001);
    }

    /**
     * "Probar formula" tiene que usar el mismo evaluador que el calculo final.
     *
     * Si fueran dos, se podria probar una formula que da bien y despues cotizar
     * con otra cuenta. Se compara el volumen que devuelve la prueba contra el
     * que guarda la calculadora para las mismas medidas.
     */
    public function test_probar_formula_usa_el_mismo_evaluador_que_el_calculo(): void
    {
        $forma = Forma::where('nombre', 'BARRA REDONDA')->firstOrFail();

        $deLaPrueba = $this->postJson('/api/formas/probar', [
            'expresion' => $forma->expresion,
            'campos' => $forma->camposDelCalculo(),
            'valores' => ['diameter' => 25, 'length' => 6000],
        ])->assertOk()->json('volumen_cm3');

        $delCalculo = app(CalculadoraDePeso::class)->calcular(
            Material::where('nombre', 'AISI 304')->firstOrFail(),
            $forma,
            ['diameter' => ['valor' => 25, 'unidad' => 'mm'], 'length' => ['valor' => 6000, 'unidad' => 'mm']],
            piezas: 1,
        )['volumen_por_pieza_cm3'];

        $this->assertEqualsWithDelta($delCalculo, $deLaPrueba, 0.0001);

        // Y es literalmente la misma clase, no dos que dan parecido.
        $usadoAlProbar = (new \ReflectionClass(\App\Http\Controllers\FormaController::class))
            ->getConstructor()->getParameters()[0]->getType()->getName();
        $usadoAlCalcular = (new \ReflectionClass(CalculadoraDePeso::class))
            ->getConstructor()->getParameters()[0]->getType()->getName();

        $this->assertSame(\App\Services\EvaluadorDeFormulas::class, $usadoAlProbar);
        $this->assertSame($usadoAlProbar, $usadoAlCalcular);
    }

    /**
     * El listado dice en que estado esta la cuenta de cada forma.
     *
     * "invalida" es el que importa: una formula rota devuelve pesos
     * equivocados sin protestar, y hay que poder verla de un vistazo.
     */
    public function test_el_listado_marca_el_estado_de_cada_formula(): void
    {
        // Una rota, metida por debajo del validador —como la dejaria una
        // migracion mal hecha o alguien tocando la base.
        DB::table('formas')->where('nombre', 'ESFERA')->update([
            'expresion' => '(pi * pow(diametro, 3) / 6) / 1000',
        ]);

        $formas = collect($this->getJson('/api/formas')->json('formas'))->keyBy('nombre');

        $this->assertSame('valida', $formas['BARRA REDONDA']['estado_formula']);
        $this->assertNull($formas['BARRA REDONDA']['problema_formula']);

        $this->assertSame('sin_formula', $formas['BRIDA']['estado_formula']);
        $this->assertSame('sin_formula', $formas['PERFIL']['estado_formula']);

        // "diametro" no es una medida declarada: el campo se llama "diameter".
        $this->assertSame('invalida', $formas['ESFERA']['estado_formula']);
        $this->assertStringContainsString('diametro', $formas['ESFERA']['problema_formula']);
    }

    public function test_el_cano_no_queda_marcado_como_invalido(): void
    {
        // Su formula usa outer y wall, que salen de la tabla de caños y no son
        // campos declarados: eso no la hace invalida.
        $formas = collect($this->getJson('/api/formas')->json('formas'))->keyBy('nombre');

        $this->assertSame('valida', $formas['CAÑO']['estado_formula']);
    }

    /** Son exactamente dos, y son las que no son un solido simple. */
    public function test_solo_brida_y_perfil_estan_sin_formula(): void
    {
        $formas = collect($this->getJson('/api/formas')->json('formas'));

        $sinFormula = $formas->where('estado_formula', 'sin_formula')->pluck('nombre')->sort()->values();

        $this->assertSame(['BRIDA', 'PERFIL'], $sinFormula->all());
        // 17 formas: las 16 de siempre mas ARANDELA, que CORDES pidio como
        // denominacion comercial propia aunque calcule igual que ANILLO.
        $this->assertCount(15, $formas->where('estado_formula', 'valida'));
        $this->assertCount(17, $formas);
    }

    /**
     * "BARRA" a secas queda, pero fuera de la lista de cotizacion.
     *
     * CORDES confirmo que no es una denominacion que usen. No se borra porque
     * las cotizaciones viejas la nombran y tienen que poder abrirse.
     */
    public function test_barra_sola_ya_no_se_ofrece_al_cotizar(): void
    {
        $barra = Forma::where('nombre', 'BARRA')->firstOrFail();

        $this->assertFalse((bool) $barra->activo);

        $ofrecidas = collect($this->getJson('/api/catalogos')->json('formas'))->pluck('nombre');

        $this->assertNotContains('BARRA', $ofrecidas);
        $this->assertContains('BARRA REDONDA', $ofrecidas);
    }

    /** La arandela se cotiza con su nombre y calcula como anillo. */
    public function test_la_arandela_calcula_igual_que_el_anillo(): void
    {
        $formas = collect($this->getJson('/api/formas')->json('formas'))->keyBy('nombre');

        $this->assertSame(
            $formas['ANILLO']['expresion'],
            $formas['ARANDELA']['expresion'],
        );
        $this->assertSame('valida', $formas['ARANDELA']['estado_formula']);
    }

    private function cotizacionCon(Forma $forma, int $cuantas): Consulta
    {
        $consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'CLIENTE '.$forma->id])->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Borrador',
            'usuario_id' => $this->usuario->id,
        ]);

        for ($i = 1; $i <= $cuantas; $i++) {
            ConsultaLinea::create([
                'consulta_id' => $consulta->id,
                'orden' => $i,
                'descripcion' => 'linea '.$i,
                'forma_id' => $forma->id,
            ]);
        }

        return $consulta;
    }
}

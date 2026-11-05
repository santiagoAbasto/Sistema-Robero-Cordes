<?php

namespace Tests\Feature;

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
use App\Services\Buscador\Catalogo;
use App\Services\Cordes\Conocimiento;
use App\Services\Cordes\Cordes;
use App\Services\Cordes\Entiende;
use App\Services\Cordes\Herramientas;
use App\Services\Cordes\Respuesta;
use App\Services\EvaluadorDeFormulas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cordes IA como ORQUESTADOR: la IA divide una pregunta compleja en pasos y le pasa cada uno al motor del sistema
 * (el mismo que contesta sin IA), hace cuentas de ingeniería con `calcular` y compara. Nada se inventa: todo lo que
 * dice sigue pasando por el Verificador. La IA se simula con Http::fake. Nombres ficticios: el repo es público.
 */
class CordesOrquestadorTest extends TestCase
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

    /** Una cotización con un renglón de monel a $precio (USD o pesos), en una fecha. */
    private function cotizado(Empresa $e, string $fecha, float $precio, string $moneda = 'DOLAR BILLETE BNA VENDEDOR'): void
    {
        $c = Consulta::create([
            'empresa_id' => $e->id, 'tipo' => 'Cotizacion', 'fecha' => $fecha, 'estado' => 'Confirmada', 'usuario_id' => $this->admin->id,
            'moneda_id' => Moneda::firstOrCreate(['nombre' => $moneda], ['moneda_base' => false, 'activo' => true])->id,
        ]);
        ConsultaLinea::create(['consulta_id' => $c->id, 'orden' => 1, 'descripcion' => 'MONEL BARRA 20MM', 'cantidad' => 1, 'material_id' => Material::where('nombre', 'Monel 400')->value('id'), 'forma_id' => Forma::where('nombre', 'BARRA REDONDA')->value('id'),
            'unidad_venta_id' => Unidad::firstOrCreate(['codigo' => 'KG'], ['nombre' => 'KG', 'activo' => true])->id, 'precio_unitario' => $precio, 'importe' => $precio]);
    }

    /* ----------------------------------------------------------------------- IA */

    private function ia(array ...$mensajes): void
    {
        config(['services.openai.key' => 'clave-de-prueba']);
        $secuencia = Http::sequence();

        foreach ($mensajes as $m) {
            $secuencia->push(['choices' => [['message' => ['role' => 'assistant'] + $m]], 'usage' => ['total_tokens' => 100]]);
        }

        Http::fake(['*/chat/completions' => $secuencia]);
    }

    /** Varias consultas pedidas a la vez, en un mismo paso. @param list<array{0: string, 1: array<string, mixed>}> $llamadas */
    private function juntas(array $llamadas): array
    {
        return ['content' => null, 'tool_calls' => array_map(fn ($l, $i) => ['id' => 'c'.($i + 1), 'type' => 'function', 'function' => ['name' => $l[0], 'arguments' => json_encode($l[1])]], $llamadas, array_keys($llamadas))];
    }

    private function final(array $datos): array
    {
        return ['content' => json_encode($datos, JSON_UNESCAPED_UNICODE)];
    }

    /* ------------------------------------------------- cuentas de ingeniería */

    public function test_calcular_entiende_redondeos_y_funciones_para_cuentas_de_ingenieria(): void
    {
        $h = $this->herramientas();

        // Cuántas piezas de 150 x 150 salen de una chapa de 1000 x 2000.
        $this->assertSame(78.0, $h->ejecutar('calcular', ['expresion' => 'floor(1000 / 150) * floor(2000 / 150)'])['resultado']);
        $this->assertSame(7.0, $h->ejecutar('calcular', ['expresion' => 'ceil(6,2)'])['resultado']);
        $this->assertSame(2.57, $h->ejecutar('calcular', ['expresion' => 'round(2,5678; 2)'])['resultado']);
        $this->assertSame(4.0, $h->ejecutar('calcular', ['expresion' => 'max(3; 4)'])['resultado']);
        $this->assertSame(5.0, $h->ejecutar('calcular', ['expresion' => 'sqrt(25)'])['resultado']);
        $this->assertSame(8.0, $h->ejecutar('calcular', ['expresion' => 'pow(2; 3)'])['resultado']);
        $this->assertSame(1.5, $h->ejecutar('calcular', ['expresion' => '3 / 2'])['resultado']);
    }

    public function test_calcular_sigue_rechazando_palabras_y_dividir_por_cero(): void
    {
        $h = $this->herramientas();

        $this->assertArrayHasKey('error', $h->ejecutar('calcular', ['expresion' => 'system(1)']));
        $this->assertArrayHasKey('error', $h->ejecutar('calcular', ['expresion' => 'floor(diametro)']));
        $this->assertArrayHasKey('error', $h->ejecutar('calcular', ['expresion' => '1 / 0']));
        $this->assertArrayHasKey('error', $h->ejecutar('calcular', ['expresion' => str_repeat('1+', 150).'1']));
    }

    public function test_los_redondeos_no_se_cuelan_en_las_formulas_de_las_formas(): void
    {
        // Las fórmulas guardadas siguen con su lista de siempre: floor() sólo existe para las cuentas sueltas de Cordes IA.
        $this->expectException(\InvalidArgumentException::class);
        app(EvaluadorDeFormulas::class)->evaluar('floor(diameter)', ['diameter' => 20.0]);
    }

    /* ------------------------------------------------------------------ motor */

    public function test_el_motor_contesta_un_peso_con_exactitud_y_sin_tocar_nada(): void
    {
        $this->monel();
        $this->barra();

        $r = $this->herramientas()->ejecutar('motor', ['pregunta' => 'cuánto pesa una barra de monel de 20 mm x 1000 mm']);

        $this->assertTrue($r['entendio']);
        $this->assertSame('peso', $r['intencion']);
        // Volumen = pi * 20² / 4 * 1000 / 1000 = 314,159 cm³ × 8,8 = 2,7646 kg.
        $this->assertStringContainsString('2,76', $r['respuesta']);
    }

    public function test_el_motor_dice_con_honestidad_cuando_no_entiende(): void
    {
        $r = $this->herramientas()->ejecutar('motor', ['pregunta' => 'qwerty asdf zxcv']);

        $this->assertFalse($r['entendio']);
        $this->assertStringContainsString('herramientas finas', $r['nota']);
        $this->assertArrayHasKey('error', $this->herramientas()->ejecutar('motor', ['pregunta' => 'a']));
    }

    public function test_el_motor_pide_el_dato_que_falta_con_opciones_en_vez_de_adivinar(): void
    {
        $this->monel();
        $this->barra();
        // Los materiales que más se cotizaron (con densidad conocida) son las opciones que ofrece.
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizado($e, '2026-03-01', 410);
        $this->cotizado($e, '2026-04-01', 420);

        // Sin material no se puede calcular un peso: el motor lo pide.
        $r = $this->herramientas()->ejecutar('motor', ['pregunta' => 'cuánto pesa una barra de 20 x 1000']);

        $this->assertTrue($r['entendio']);
        $this->assertNotEmpty($r['faltan']['opciones'] ?? []);
    }

    public function test_el_motor_respeta_quien_ve_importes(): void
    {
        $this->monel();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizado($e, '2026-03-01', 410);

        $con = $this->herramientas()->ejecutar('motor', ['pregunta' => 'a cuánto cotizamos monel']);
        $sin = $this->herramientas($this->sinImportes())->ejecutar('motor', ['pregunta' => 'a cuánto cotizamos monel']);

        $this->assertStringContainsString('410', json_encode($con, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('410', json_encode($sin, JSON_UNESCAPED_UNICODE));
    }

    public function test_los_botones_de_una_tabla_del_motor_se_vuelven_a_validar(): void
    {
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $armadas = [
            ['etiqueta' => 'Abrir ficha', 'icono' => 'empresa', 'accion' => ['tipo' => 'ruta', 'ruta' => "/empresas/{$e->id}"]],
            ['etiqueta' => 'Cotizar', 'icono' => 'mas', 'accion' => ['tipo' => 'ruta', 'ruta' => "/empresas/{$e->id}/agregar"]],
            ['etiqueta' => 'Ver estas', 'icono' => 'lista', 'accion' => ['tipo' => 'pedido', 'pedido' => ['grano' => 'consultas', 'estado' => 'Vendida']]],
        ];
        $malas = [
            ['etiqueta' => 'Sitio', 'icono' => 'lista', 'accion' => ['tipo' => 'ruta', 'ruta' => 'https://malo.example/robar']],
            ['etiqueta' => 'Admin', 'icono' => 'lista', 'accion' => ['tipo' => 'ruta', 'ruta' => '/configuracion/usuarios']],
            ['etiqueta' => 'Truco', 'icono' => 'lista', 'accion' => ['tipo' => 'ruta', 'ruta' => '/empresas/1/../../configuracion']],
            ['etiqueta' => 'Barra', 'icono' => 'lista', 'accion' => ['tipo' => 'ruta', 'ruta' => '//malo.example']],
            ['etiqueta' => 'Inventado', 'icono' => 'lista', 'accion' => ['tipo' => 'pedido', 'pedido' => ['inventado' => 'x']]],
            ['etiqueta' => 'Otra cosa', 'icono' => 'lista', 'accion' => ['tipo' => 'borrar', 'id' => 1]],
            ['etiqueta' => '', 'icono' => 'lista', 'accion' => ['tipo' => 'ruta', 'ruta' => '/empresas/1']],
        ];

        $r = new Respuesta;

        $this->assertCount(3, $r->accionesDeFila($armadas));
        $this->assertSame('/empresas/'.$e->id, $r->accionesDeFila($armadas)[0]['accion']['ruta']);
        $this->assertSame([], $r->accionesDeFila($malas));
    }

    /* ------------------------------------------------- evolución de precios */

    public function test_la_evolucion_de_precios_separa_por_ano_moneda_y_unidad(): void
    {
        $this->monel();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizado($e, '2024-05-10', 300);
        $this->cotizado($e, '2024-09-10', 340);
        $this->cotizado($e, '2025-02-01', 400);
        $this->cotizado($e, '2025-06-01', 90000, 'PESOS');

        $r = $this->herramientas()->ejecutar('evolucion_de_precios', ['filtros' => ['material' => 'monel']]);

        $this->assertSame('anio', $r['por']);
        $por = collect($r['periodos'])->keyBy(fn ($p) => $p['periodo'].'|'.$p['moneda']);
        $dolares2024 = $por['2024|DOLAR BILLETE BNA VENDEDOR'];

        $this->assertSame(2, $dolares2024['renglones']);
        $this->assertSame(300.0, $dolares2024['minimo']);
        $this->assertSame(320.0, $dolares2024['promedio']);
        $this->assertSame(340.0, $dolares2024['maximo']);
        $this->assertSame(400.0, $por['2025|DOLAR BILLETE BNA VENDEDOR']['promedio']);
        $this->assertSame(90000.0, $por['2025|PESOS']['promedio']);
        $this->assertCount(3, $r['periodos'], 'los dólares y los pesos no se mezclan');
        $this->assertNotEmpty($r['tablas']);
    }

    public function test_la_evolucion_de_precios_puede_ser_por_mes(): void
    {
        $this->monel();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizado($e, '2026-03-02', 100);
        $this->cotizado($e, '2026-04-02', 120);

        $r = $this->herramientas()->ejecutar('evolucion_de_precios', ['filtros' => ['material' => 'monel'], 'por' => 'mes']);

        $this->assertSame(['2026-04', '2026-03'], array_column($r['periodos'], 'periodo'));
    }

    public function test_sin_permiso_de_importes_no_hay_evolucion_de_precios(): void
    {
        $nombres = fn (User $u) => array_map(fn ($d) => $d['function']['name'], $this->herramientas($u)->definiciones());

        $this->assertContains('evolucion_de_precios', $nombres($this->admin));
        $this->assertContains('motor', $nombres($this->admin));
        $this->assertNotContains('evolucion_de_precios', $nombres($this->sinImportes()));
        $this->assertContains('motor', $nombres($this->sinImportes()));
        $this->assertArrayHasKey('error', $this->herramientas($this->sinImportes())->ejecutar('evolucion_de_precios', ['filtros' => ['material' => 'monel']]));
    }

    /* ------------------------------------------------------------ orquestación */

    public function test_la_ia_puede_pedir_varias_consultas_juntas_y_todas_se_contestan(): void
    {
        $this->monel();
        $this->barra();
        $this->ia(
            $this->juntas([
                ['motor', ['pregunta' => 'cuánto pesa una barra de monel de 20 mm x 1000 mm']],
                ['motor', ['pregunta' => 'cuánto pesa una barra de monel de 40 mm x 1000 mm']],
            ]),
            $this->juntas([['calcular', ['expresion' => '11,0584 / 2,7646']]]),
            $this->final(['mensaje' => 'La barra de 40 mm pesa **4** veces más que la de 20 mm.']),
        );

        $pasos = [];
        $r = (new Cordes($this->admin))->responder('comparame lo que pesa una barra de monel de 40 contra una de 20 mm con un largo de un metro', alPaso: function ($p) use (&$pasos) {
            $pasos[] = $p;
        });

        $this->assertSame('ia', $r['fuente']);
        $this->assertTrue($r['verificada'], json_encode($r));
        $this->assertCount(3, $pasos);
        Http::assertSentCount(3);

        // Las dos respuestas de las herramientas llegaron a la IA, cada una con su identificador.
        Http::assertSent(function ($req) {
            $tools = collect($req['messages'])->where('role', 'tool');

            return $tools->count() === 3 && $tools->pluck('tool_call_id')->sort()->values()->all() === ['c1', 'c1', 'c2'];
        });
    }

    public function test_la_misma_consulta_no_se_repite_ni_se_aceptan_mas_de_seis_juntas(): void
    {
        $llamadas = array_map(fn ($i) => ['calcular', ['expresion' => "{$i} + 1"]], range(1, 8));
        $this->ia(
            $this->juntas([...$llamadas, ['calcular', ['expresion' => '1 + 1']]]),
            $this->juntas([['calcular', ['expresion' => '2 + 1']]]),
            $this->final(['mensaje' => 'Listo.']),
        );

        $pasos = [];
        (new Cordes($this->admin))->responder('hacé unas cuentas raras con muchos números para probar el tope de consultas', alPaso: function ($p) use (&$pasos) {
            $pasos[] = $p;
        });

        // Seis se ejecutan (la repetida de "1 + 1" ya no): 6 en el primer paso y 0 de nuevo en el segundo ("2 + 1" ya se hizo).
        $this->assertCount(6, $pasos);

        Http::assertSent(function ($req) {
            $tools = collect($req['messages'])->where('role', 'tool')->pluck('content');

            return $tools->count() === 10 && $tools->contains(fn ($c) => str_contains($c, 'Demasiadas consultas juntas'));
        });
    }

    public function test_si_la_ia_inventa_un_numero_despues_de_consultar_al_motor_se_la_corrige(): void
    {
        $this->monel();
        $this->barra();
        $this->ia(
            $this->juntas([['motor', ['pregunta' => 'cuánto pesa una barra de monel de 20 mm x 1000 mm']]]),
            $this->final(['mensaje' => 'La barra pesa **9,99 kg**.']),
            $this->final(['mensaje' => 'La barra pesa **2,76 kg**.']),
        );

        $r = (new Cordes($this->admin))->responder('decime cuánto pesa una barra de monel de un grosor de cuatro dedos por un metro largo');

        $this->assertTrue($r['verificada']);
        $this->assertStringContainsString('2,76', (string) $r['mensaje']);
        $this->assertStringNotContainsString('9,99', (string) $r['mensaje']);
    }

    public function test_lo_que_el_motor_entendio_viaja_a_la_ia_como_pista(): void
    {
        $cordes = new Cordes($this->admin);
        $pista = (fn () => $this->pista(
            ['entendio' => false, 'restos' => [], 'borrador' => []],
            ['intencion' => 'peso', 'datos' => ['material' => 'Monel 400', 'piezas' => 2, 'medidas' => ['x' => 1], 'vacio' => '']],
        ))->call($cordes);

        $this->assertSame(['el_motor_reconocio' => ['tipo_de_pregunta' => 'peso', 'datos' => ['material' => 'Monel 400', 'piezas' => 2]]], $pista);
    }

    public function test_sin_ia_el_codigo_cuenta_como_se_movio_el_precio_con_su_variacion(): void
    {
        $this->monel();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizado($e, '2024-05-10', 300);
        $this->cotizado($e, '2025-02-01', 360);

        $r = (new Cordes($this->admin))->responder('cómo viene el precio del monel por año');

        $this->assertSame('codigo', $r['fuente']);
        $this->assertStringContainsString('**2024**: promedio **300,00**', $r['mensaje']);
        $this->assertStringContainsString('**2025**: promedio **360,00**', $r['mensaje']);
        // De 300 a 360 es un 20 %: la cuenta la hace el código, no la IA.
        $this->assertStringContainsString('subió **20,0 %**', $r['mensaje']);
        $this->assertNotEmpty($r['tablas']);
        $this->assertNotNull($r['pedido']);
    }

    public function test_la_evolucion_distingue_subio_de_bajo_y_no_mezcla_monedas(): void
    {
        $this->monel();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizado($e, '2024-05-10', 400);
        $this->cotizado($e, '2025-02-01', 300);
        $this->cotizado($e, '2025-03-01', 90000, 'PESOS');

        $r = (new Cordes($this->admin))->responder('el precio del monel subió o bajó?');

        $this->assertStringContainsString('bajó **25,0 %**', $r['mensaje']);
        $this->assertStringContainsString('PESOS', $r['mensaje'], 'cada moneda va en su propio bloque');
    }

    public function test_sin_permiso_de_importes_la_evolucion_no_muestra_precios(): void
    {
        $this->monel();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizado($e, '2024-05-10', 300);

        $r = (new Cordes($this->sinImportes()))->responder('cómo viene el precio del monel por año');

        $this->assertStringContainsString('No tenés permiso', $r['mensaje']);
        $this->assertStringNotContainsString('300', json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    public function test_ultimo_precio_arranca_con_lo_ultimo_que_se_cotizo_y_a_quien(): void
    {
        $this->monel();
        $a = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $b = Empresa::create(['nombre' => 'BETA SRL', 'activa' => true]);
        $this->cotizado($a, '2025-02-01', 300);
        $this->cotizado($b, '2026-03-01', 410);

        $r = (new Cordes($this->admin))->responder('último precio del monel');

        $this->assertStringStartsWith('Lo último que se cotizó fue **410,00**', $r['mensaje']);
        $this->assertStringContainsString('**BETA SRL**', $r['mensaje']);
    }

    public function test_cuantas_cotizaciones_por_provincia_cuenta_cotizaciones_no_empresas(): void
    {
        $cba = Provincia::create(['nombre' => 'Córdoba', 'activo' => true, 'pais_id' => Pais::firstOrCreate(['nombre' => 'Argentina'])->id]);
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true, 'provincia_id' => $cba->id]);
        Empresa::create(['nombre' => 'BETA SRL', 'activa' => true, 'provincia_id' => $cba->id]);
        foreach (['2026-01-10', '2026-02-10', '2026-03-10'] as $f) {
            Consulta::create(['empresa_id' => $e->id, 'tipo' => 'Cotizacion', 'fecha' => $f, 'estado' => 'Confirmada', 'usuario_id' => $this->admin->id]);
        }

        $cotizaciones = (new Cordes($this->admin))->responder('cuántas cotizaciones por provincia');
        $empresas = (new Cordes($this->admin))->responder('cuántas empresas por provincia');
        $porRubro = (new Cordes($this->admin))->responder('cuántas cotizaciones por rubro');

        $this->assertStringContainsString('Hay **3** cotizaciones y pedidos', $cotizaciones['mensaje']);
        $this->assertStringContainsString('Hay **2** empresas', $empresas['mensaje']);
        $this->assertStringContainsString('sólo puedo contar empresas, no cotizaciones', $porRubro['mensaje'], 'si no se puede contar lo que pidió, lo dice');
    }

    /* --------------------------------------- preguntas que juntan varias cosas */

    private function titanioEInconel(): void
    {
        $this->monel();
        $ti = Material::create(['nombre' => 'Titanio Grado 2', 'familia' => 'Titanio', 'densidad' => 4.51, 'activo' => true]);
        MaterialAlias::create(['material_id' => $ti->id, 'alias' => 'TITANIO GRADO 2']);
        MaterialAlias::create(['material_id' => $ti->id, 'alias' => 'TITANIO']);
        $in = Material::create(['nombre' => 'Inconel 625', 'familia' => 'Níquel y sus Aleaciones', 'densidad' => 8.44, 'activo' => true]);
        MaterialAlias::create(['material_id' => $in->id, 'alias' => 'INCONEL 625']);
        MaterialAlias::create(['material_id' => $in->id, 'alias' => 'INCONEL']);
        $this->barra();
        Forma::updateOrCreate(['nombre' => 'CHAPA'], [
            'campos' => [['clave' => 'width', 'label' => 'Ancho'], ['clave' => 'height', 'label' => 'Espesor'], ['clave' => 'length', 'label' => 'Largo']],
            'expresion' => '(width * height * length) / 1000', 'activo' => true, 'usa_cano' => false,
        ]);
    }

    public function test_detecta_las_preguntas_que_juntan_varias_cosas(): void
    {
        $this->titanioEInconel();
        $e = new Entiende(new Catalogo);

        foreach ([
            'comparame lo que pesa una barra de monel de 40 contra una de 20 mm' => 'compara',
            'cuánto más pesa una barra de monel de 50,8 x 200 que una de titanio grado 2 igual' => 'compara',
            'diferencia de peso entre una barra de monel de 20 x 1000 y una de 30 x 1000' => 'compara',
            'monel vs inconel 625 qué pesa más en barra de 20 mm' => 'compara',
            'cuánto pesan 4 barras de monel de 38,1 x 145 y 2 chapas de titanio grado 2 de 3 x 1000 x 2000' => 'varias piezas',
            'cuánto pesa una barra de monel o de inconel de 20 mm' => 'varios materiales',
            'cuánto pesa una barra de monel de 20 mm de diámetro y 1 metro y cuánto cuesta' => 'peso y el precio',
        ] as $pregunta => $motivo) {
            $this->assertStringContainsString($motivo, (string) $e->compuesta($pregunta), $pregunta);
        }
    }

    public function test_una_pregunta_simple_nunca_se_toma_por_compuesta(): void
    {
        $this->titanioEInconel();
        $e = new Entiende(new Catalogo);

        foreach ([
            'cuánto pesa una barra de monel de 38,1 x 145 mm',
            'cuánto pesa una chapa de titanio grado 2 de 3 x 1000 x 2000',
            'cuánto pesa una barra de inconel 625 de 20 mm de diámetro y 3 metros de largo',
            'cuánto pesan 5 barras de monel de 20 x 1000',
            'caño de 2 pulgadas schedule 40 de monel',
            'a cuánto cotizamos titanio grado 2 en chapa',
            'cuántas cotizaciones hicimos por mes este año',
            'qué empresas de Córdoba compraron monel',
            'fórmula de la barra redonda',
            'contame de ALFA SA',
            'cuántos mm son 1 1/2 pulgadas',
            'densidad del inconel 625',
        ] as $pregunta) {
            $this->assertNull($e->compuesta($pregunta), $pregunta);
        }
    }

    public function test_sin_ia_el_codigo_resuelve_lo_compuesto_con_las_mismas_cuentas(): void
    {
        $this->titanioEInconel();

        // Comparar: la segunda pieza toma el material y la forma de la primera, y el largo se comparte.
        $r = (new Cordes($this->admin))->responder('comparame lo que pesa una barra de monel de 40 contra una de 20 mm con un largo de un metro');

        $this->assertStringContainsString('barra redonda de Monel 400 de 40 mm × 1 m: **11,0584 kg**', $r['mensaje']);
        $this->assertStringContainsString('barra redonda de Monel 400 de 20 mm × 1 m: **2,7646 kg**', $r['mensaje']);
        $this->assertStringContainsString('pesa **4 veces** lo que el ítem **2**: **8,2938 kg** más', $r['mensaje']);
        $this->assertStringContainsString('toma el largo (1 m) del otro', $r['mensaje'], 'dice cómo entendió cada parte');
        $this->assertSame('codigo', $r['fuente']);
        $this->assertNotEmpty($r['tablas']);

        // Sumar ítems distintos.
        $r = (new Cordes($this->admin))->responder('cuánto pesan 4 barras de monel de 38,1 x 145 y 2 chapas de titanio grado 2 de 3 x 1000 x 2000');
        $this->assertStringContainsString('Sumo estos ítems', $r['mensaje']);
        $this->assertStringContainsString('4 barras redondas de Monel 400', $r['mensaje']);
        $this->assertStringContainsString('2 chapas de Titanio Grado 2', $r['mensaje']);
        $this->assertStringContainsString('En total pesan', $r['mensaje']);

        // Un peso que ya se sabe, por una cantidad.
        $r = (new Cordes($this->admin))->responder('si pesa 2,7646 kg y son 12 piezas cuanto pesan en total');
        $this->assertStringContainsString('**33,1752 kg** en total', $r['mensaje']);
    }

    public function test_sin_ia_peso_y_precio_juntos_son_dos_respuestas_en_una(): void
    {
        $this->titanioEInconel();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizado($e, '2026-03-01', 410);

        $r = (new Cordes($this->admin))->responder('cuánto pesa una barra de monel de 20 mm de diámetro y 1 metro y cuánto cuesta');

        $this->assertStringContainsString('pesa **2,7646 kg**', $r['mensaje']);
        $this->assertStringContainsString('se cotizó en', $r['mensaje']);
        $this->assertStringContainsString('**410,00**', $r['mensaje']);
        $this->assertGreaterThanOrEqual(2, count($r['tablas']));
    }

    public function test_sin_ia_se_comparan_precios_de_dos_materiales_en_la_misma_moneda_y_unidad(): void
    {
        $this->titanioEInconel();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizado($e, '2026-03-01', 400);
        $in = Material::where('nombre', 'Inconel 625')->first();
        $c = Consulta::create(['empresa_id' => $e->id, 'tipo' => 'Cotizacion', 'fecha' => '2026-04-01', 'estado' => 'Confirmada', 'usuario_id' => $this->admin->id,
            'moneda_id' => Moneda::firstOrCreate(['nombre' => 'DOLAR BILLETE BNA VENDEDOR'], ['moneda_base' => false, 'activo' => true])->id]);
        ConsultaLinea::create(['consulta_id' => $c->id, 'orden' => 1, 'descripcion' => 'INCONEL 625 BARRA', 'cantidad' => 1, 'material_id' => $in->id,
            'unidad_venta_id' => Unidad::firstOrCreate(['codigo' => 'KG'], ['nombre' => 'KG', 'activo' => true])->id, 'precio_unitario' => 500, 'importe' => 500]);

        $r = (new Cordes($this->admin))->responder('cuánto más caro es el inconel 625 que el monel');

        $this->assertStringContainsString('promedio **400,00**', $r['mensaje']);
        $this->assertStringContainsString('promedio **500,00**', $r['mensaje']);
        // El inconel sale 500 y el monel 400: de 400 a 500 es un 25 % más, y la cuenta la hace el código.
        $this->assertStringContainsString('El más caro es **Inconel 625**: cuesta **25 %** más que **Monel 400**', $r['mensaje']);

        $barato = (new Cordes($this->admin))->responder('cuál es más barato el monel o el inconel 625');
        $this->assertStringContainsString('El más barato es **Monel 400**: **25 %** menos que **Inconel 625**', $barato['mensaje']);
    }

    public function test_sin_permiso_de_importes_comparar_precios_no_muestra_nada_de_plata(): void
    {
        $this->titanioEInconel();
        $e = Empresa::create(['nombre' => 'ALFA SA', 'activa' => true]);
        $this->cotizado($e, '2026-03-01', 400);

        $r = (new Cordes($this->sinImportes()))->responder('cuánto más caro es el inconel 625 que el monel');

        $this->assertStringContainsString('No tenés permiso', $r['mensaje']);
        $this->assertStringNotContainsString('400', json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    public function test_con_ia_una_pregunta_compuesta_va_a_la_ia_con_la_pista_de_que_hay_que_dividirla(): void
    {
        $this->titanioEInconel();
        $this->ia($this->final(['mensaje' => 'Te lo separo en dos preguntas.']));

        $r = (new Cordes($this->admin))->responder('comparame lo que pesa una barra de monel de 40 contra una de 20 mm con un largo de un metro');

        $this->assertSame('ia', $r['fuente']);
        Http::assertSent(fn ($req) => str_contains(json_encode($req['messages'], JSON_UNESCAPED_UNICODE), 'la_pregunta_junta_varias_cosas'));
    }

    public function test_cuantas_piezas_salen_de_una_chapa_lo_calcula_el_codigo_de_las_dos_maneras(): void
    {
        $cuadrada = (new Cordes($this->admin))->responder('cuántas piezas de 150 x 150 salen de una chapa de 1000 x 2000');
        $this->assertStringContainsString('entran **78 piezas** de **150 × 150 mm**', $cuadrada['mensaje']);
        $this->assertStringContainsString('87,8 %', $cuadrada['mensaje']);

        // 100 x 250 en 1220 x 2440: de una manera 108 (12 × 9) y de la otra 96 (4 × 24); elige la mejor y muestra las dos.
        $r = (new Cordes($this->admin))->responder('cuántas piezas de 100 x 250 salen de una chapa de 1,22 x 2,44 m');
        $this->assertStringContainsString('entran **108 piezas**', $r['mensaje']);
        $this->assertCount(2, $r['tablas'][0]['filas']);
        $this->assertSame(['108', '96'], array_map(fn ($f) => $f[3], $r['tablas'][0]['filas']));

        // Que no entre también es una respuesta.
        $r = (new Cordes($this->admin))->responder('cuántas piezas de 3000 x 3000 salen de una chapa de 1000 x 2000');
        $this->assertStringContainsString('no entra', $r['mensaje']);
    }

    public function test_si_el_codigo_no_lee_las_medidas_la_ia_lo_intenta_y_si_falla_queda_lo_del_codigo(): void
    {
        $this->monel();
        $this->barra();
        config(['services.openai.key' => 'clave']);
        Http::fake(['*/chat/completions' => Http::response(['error' => 'x'], 500)]);

        $r = (new Cordes($this->admin))->responder('decime cuánto pesa una barra de monel de un grosor de cuatro dedos por un metro largo');

        Http::assertSentCount(1);
        $this->assertStringContainsString('necesito', (string) $r['mensaje']);
        $this->assertArrayNotHasKey('necesita_medidas', $r);
    }

    public function test_el_diagnostico_mide_sin_mostrar_datos_de_clientes(): void
    {
        $this->monel();
        $this->barra();
        $e = Empresa::create(['nombre' => 'ZETA RESERVADA SRL', 'activa' => true]);
        $this->cotizado($e, '2026-03-01', 410);

        $this->assertSame(0, Artisan::call('cordes:diagnostico'));
        $salida = Artisan::output();

        $this->assertStringContainsString('TIEMPOS', $salida);
        $this->assertStringContainsString('Pregunta: Peso de una barra', $salida);
        $this->assertStringNotContainsString('ZETA RESERVADA', $salida);
        $this->assertSame('', (string) config('services.openai.key'), 'la medición con el código solo no deja la IA apagada para el resto');
    }

    public function test_el_prompt_le_ensena_a_orquestar(): void
    {
        $this->monel();
        $prompt = Conocimiento::prompt($this->admin);

        $this->assertStringContainsString('ORQUESTADOR', $prompt);
        $this->assertStringContainsString('`motor`', $prompt);
        $this->assertStringContainsString('`evolucion_de_precios`', $prompt);
        $this->assertStringContainsString('floor(', $prompt);
        $this->assertStringContainsString('EN PARALELO', mb_strtoupper($prompt));
    }
}

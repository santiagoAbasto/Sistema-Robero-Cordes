<?php

namespace Tests\Feature;

use App\Models\CondicionHabitual;
use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\User;
use App\Services\FormatoDeTexto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resaltar con asteriscos (*así*) y elegir el color del resaltado.
 *
 * Lo usa gente grande: lo que va entre asteriscos sale en negrita, y el color se
 * elige una vez por texto ({#rojo} al principio, que la pantalla esconde). Lo
 * que no puede fallar: que un texto SIN asteriscos salga igual que siempre, que
 * "5 * 10" no se tome por un resaltado, que nunca se cuele HTML, y que ninguna
 * combinación rara rompa la hoja.
 *
 * Los mismos casos están en frontend/src/lib/formato.ts (la vista previa).
 */
class FormatoDeTextoTest extends TestCase
{
    use RefreshDatabase;

    private const ROJO = '#b91c1c';

    private function trozos(string $texto): array
    {
        return FormatoDeTexto::trozos($texto);
    }

    private function t(string $texto, bool $negrita = false, ?string $color = null): array
    {
        return ['texto' => $texto, 'negrita' => $negrita, 'color' => $color];
    }

    public function test_un_texto_sin_asteriscos_sale_igual_que_siempre(): void
    {
        $this->assertSame('Entrega inmediata', FormatoDeTexto::html('Entrega inmediata'));
        $this->assertSame('Una<br />\nDos', str_replace("\n", '\n', FormatoDeTexto::html("Una\nDos")));
        $this->assertSame([$this->t('Hola')], $this->trozos('Hola'));
    }

    public function test_vacio_y_nulo_no_dan_nada(): void
    {
        $this->assertSame('', FormatoDeTexto::html(null));
        $this->assertSame('', FormatoDeTexto::html(''));
        $this->assertSame([], $this->trozos(''));
        $this->assertSame([], $this->trozos('{#rojo}'));
    }

    public function test_lo_que_va_entre_asteriscos_sale_en_negrita(): void
    {
        $this->assertSame('Es <b>muy</b> claro', FormatoDeTexto::html('Es *muy* claro'));
        $this->assertSame(
            [$this->t('Entrega: '), $this->t('90 días', true), $this->t(' salvo '), $this->t('venta previa', true), $this->t('.')],
            $this->trozos('Entrega: *90 días* salvo *venta previa*.'),
        );
    }

    public function test_sin_color_elegido_el_resaltado_es_negro(): void
    {
        $this->assertSame('<b>x</b>', FormatoDeTexto::html('*x*'));
    }

    public function test_el_color_elegido_vale_para_todo_lo_resaltado_y_solo_para_eso(): void
    {
        $this->assertSame(
            'Plazo <span style="color:'.self::ROJO.'"><b>90 días</b></span> y <span style="color:'.self::ROJO.'"><b>sin cambios</b></span>, el resto común',
            FormatoDeTexto::html('{#rojo}Plazo *90 días* y *sin cambios*, el resto común'),
        );
    }

    public function test_los_cinco_colores_existen_y_se_imprimen(): void
    {
        foreach (FormatoDeTexto::COLORES as $nombre => $hex) {
            $this->assertSame(
                "<span style=\"color:{$hex}\"><b>x</b></span>",
                FormatoDeTexto::html("{#{$nombre}}*x*"),
            );
        }
    }

    public function test_el_encabezado_de_color_no_se_imprime_y_no_distingue_mayusculas(): void
    {
        $html = FormatoDeTexto::html('{#ROJO}Hola *mundo*');

        $this->assertStringNotContainsString('{#', $html);
        $this->assertStringContainsString('color:'.self::ROJO, $html);
    }

    public function test_un_color_inexistente_o_a_mitad_de_texto_es_texto_comun(): void
    {
        // El encabezado solo vale al principio y con uno de los cinco colores:
        // si no, queda escrito tal cual y no pinta nada. El resaltado sigue negro.
        $this->assertSame('{#amarillo}<b>x</b>', FormatoDeTexto::html('{#amarillo}*x*'));
        $this->assertSame('Hola {#rojo}<b>x</b>', FormatoDeTexto::html('Hola {#rojo}*x*'));
        $this->assertStringNotContainsString('<span', FormatoDeTexto::html('Hola {#rojo}*x*'));
    }

    public function test_separar_devuelve_el_color_y_el_texto(): void
    {
        $this->assertSame(['rojo', 'Hola *x*'], FormatoDeTexto::separar('{#rojo}Hola *x*'));
        $this->assertSame([null, 'Hola *x*'], FormatoDeTexto::separar('Hola *x*'));
        $this->assertSame([null, ''], FormatoDeTexto::separar(null));
    }

    // --- Cuándo un asterisco NO es un resaltado -------------------------------

    public function test_un_asterisco_de_multiplicar_o_suelto_no_resalta(): void
    {
        foreach (['5 * 10 = 50', '2*3 y 4*5', 'SA* y otra cosa', 'a * b * c', 'Nota* al pie', 'precio*: 10'] as $texto) {
            $this->assertSame([$this->t($texto)], $this->trozos($texto), "«{$texto}» no debería resaltar nada");
        }
    }

    public function test_los_asteriscos_dobles_quedan_como_estan(): void
    {
        $this->assertSame([$this->t('**x**')], $this->trozos('**x**'));
    }

    public function test_un_resaltado_no_cruza_renglones(): void
    {
        $this->assertSame([$this->t("*uno\ndos*")], $this->trozos("*uno\ndos*"));
    }

    public function test_dos_resaltados_en_renglones_distintos_funcionan(): void
    {
        $this->assertSame(
            [$this->t('uno', true), $this->t("\n"), $this->t('dos', true)],
            $this->trozos("*uno*\n*dos*"),
        );
    }

    public function test_un_espacio_pegado_al_asterisco_lo_anula(): void
    {
        $this->assertSame([$this->t('* uno*')], $this->trozos('* uno*'));
        $this->assertSame([$this->t('*uno *')], $this->trozos('*uno *'));
    }

    public function test_un_espacio_duro_o_un_tabulador_pegado_al_asterisco_tambien_lo_anula(): void
    {
        // Pegar desde Word o una web trae espacios duros: cuentan como espacio, igual que en el navegador.
        foreach (["*uno\u{00A0}*", "*\u{00A0}uno*", "*uno\u{2003}*", "*uno\t*"] as $texto) {
            $this->assertSame([$this->t($texto)], $this->trozos($texto));
        }
    }

    public function test_un_asterisco_sin_pareja_queda_comun(): void
    {
        $this->assertSame([$this->t('Ojo *esto no cierra')], $this->trozos('Ojo *esto no cierra'));
        $this->assertSame([$this->t('Cierra* sin abrir')], $this->trozos('Cierra* sin abrir'));
    }

    public function test_acentos_y_letras_del_castellano_cuentan_como_letras(): void
    {
        // El de cierre pegado a una "ñ" o una vocal con tilde no cierra: sigue la palabra.
        $this->assertSame([$this->t('*año*ñ')], $this->trozos('*año*ñ'));
        $this->assertSame([$this->t('Entrega ', false), $this->t('rápida', true), $this->t('.')], $this->trozos('Entrega *rápida*.'));
    }

    public function test_el_resaltado_va_pegado_a_signos_de_puntuacion(): void
    {
        $this->assertSame(
            [$this->t('('), $this->t('ojo', true), $this->t('), ¡'), $this->t('ya', true), $this->t('!')],
            $this->trozos('(*ojo*), ¡*ya*!'),
        );
    }

    public function test_una_palabra_de_una_sola_letra_se_puede_resaltar(): void
    {
        $this->assertSame([$this->t('x', true)], $this->trozos('*x*'));
    }

    public function test_no_se_cuela_html_ni_script(): void
    {
        $html = FormatoDeTexto::html('{#rojo}*<script>alert(1)</script>* <img src=x onerror=alert(2)> & "comillas"');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringContainsString('<b>', $html);
    }

    public function test_no_se_puede_inyectar_un_estilo_propio(): void
    {
        // Solo existen los cinco colores: no hay {#...} con otra cosa.
        $html = FormatoDeTexto::html('{#red;background:url(x)}*x*');

        $this->assertStringNotContainsString('style=', $html);
    }

    public function test_sin_formato_devuelve_lo_que_leeria_quien_no_ve_el_formato(): void
    {
        $this->assertSame('Entrega en 90 días', FormatoDeTexto::sinFormato('{#azul}Entrega en *90 días*'));
        $this->assertSame('5 * 10', FormatoDeTexto::sinFormato('5 * 10'));
    }

    // --- La hoja de verdad ------------------------------------------------

    private function hoja(Consulta $consulta, $condiciones, $lineas = null): string
    {
        return view('pdf.cotizacion', [
            'consulta' => $consulta->fresh(),
            'lineas' => $lineas ?? collect(),
            'condiciones' => $condiciones,
            'total' => 0.0,
            'titulo' => 'Cotizacion',
            'marca' => null,
            'simbolo' => 'US$',
            'fecha' => '04/11/2026',
            'nombreEnPdf' => 'CLIENTE DEMO',
            'contacto' => null,
            'telefono' => null,
            'mail' => null,
            'incluyeImportes' => true,
            'incluyeNota' => false,
        ])->render();
    }

    private function consulta(): Consulta
    {
        return Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'CLIENTE DEMO'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-11-04',
            'estado' => 'Borrador',
            'usuario_id' => User::factory()->create()->id,
        ]);
    }

    public function test_la_hoja_imprime_las_condiciones_con_su_formato_y_escapadas(): void
    {
        $html = $this->hoja($this->consulta(), collect([
            new CondicionHabitual(['titulo' => 'Entrega', 'texto' => "{#rojo}Plazo: *90 días*\nSalvo *venta previa* <i>x</i>", 'imprime' => true]),
        ]));

        $this->assertStringContainsString('Plazo: <span style="color:'.self::ROJO.'"><b>90 días</b></span><br />', $html);
        $this->assertStringContainsString('<span style="color:'.self::ROJO.'"><b>venta previa</b></span>', $html);
        $this->assertStringContainsString('&lt;i&gt;x&lt;/i&gt;', $html);
        $this->assertStringNotContainsString('{#rojo}', $html);
        $this->assertStringNotContainsString('*90', $html);
    }

    public function test_las_condiciones_sueltas_sin_titulo_tambien_llevan_formato(): void
    {
        $html = $this->hoja($this->consulta(), collect([
            new CondicionHabitual(['titulo' => null, 'texto' => 'Pago *contado*', 'imprime' => true]),
        ]));

        $this->assertStringContainsString('<li>Pago <b>contado</b></li>', $html);
    }

    public function test_la_nota_del_renglon_sale_con_formato(): void
    {
        $consulta = $this->consulta();
        $linea = ConsultaLinea::create([
            'consulta_id' => $consulta->id,
            'orden' => 1,
            'descripcion' => 'BARRA REDONDA 20MM * 6 m',
            'nota' => '{#azul}Con *certificado 3.1*',
        ]);

        $html = $this->hoja($consulta, collect(), collect([$linea]));

        $this->assertStringContainsString('<span style="color:#1d4ed8"><b>certificado 3.1</b></span>', $html);
        // La descripción NO lleva formato: se imprime tal cual, asteriscos incluidos.
        $this->assertStringContainsString('BARRA REDONDA 20MM * 6 m', $html);
    }
}

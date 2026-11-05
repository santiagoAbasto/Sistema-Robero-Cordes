<?php

namespace Tests\Feature;

use App\Models\Localidad;
use App\Models\Pais;
use App\Models\Provincia;
use App\Services\FirmaDeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pegar el pie de un mail y que complete la ficha.
 *
 * El criterio es el mismo que en el lector de pedidos: lo que no se reconoce
 * con seguridad queda vacio. Un teléfono o un código postal mal leídos
 * terminan en una factura, y nadie los vuelve a mirar.
 */
class FirmaDeMailTest extends TestCase
{
    use RefreshDatabase;

    /** El mail que mandó CORDES en su repaso, tal cual. */
    private const MAIL = <<<'TEXTO'
        From: Pablo Ferreyra - Apex Metalurgica <pferreyra@apex.example>
        Sent: Friday, September 25, 2026 10:32 AM
        To: Roberto Cordes <roberto.cordes@cordes.ar>; Lucía Benítez <lbenitez@apex.example>
        Cc: ventas@cordes.ar
        Subject: RE: Nuevo Pedido de Cotización - AISI 310S (22/9)

        Hola Roberto, buenos días.

        Vamos por la opción de 2 chapas 10x1500x3000 Acero 310S chino

        Pablo Ferreyra
        Supervisor de Mantenimiento
        Cel: (2954) 15-412233

        Parque Industrial, Calle 9 esq. 10 | CP 6300
        Santa Rosa, La Pampa, Argentina
        www.apex.example
        TEXTO;

    private function leer(string $texto): array
    {
        return (new FirmaDeMail)->leer($texto);
    }

    public function test_lee_el_mail_que_mandaron(): void
    {
        $d = $this->leer(self::MAIL);

        $this->assertSame('Apex Metalurgica', $d['empresa']);
        $this->assertSame('Pablo Ferreyra', $d['contacto']);
        $this->assertSame('Supervisor de Mantenimiento', $d['cargo']);
        $this->assertSame('pferreyra@apex.example', $d['mail']);
        $this->assertSame('+54 9 295441-2233', $d['telefono']);
        $this->assertSame('www.apex.example', $d['web']);
        $this->assertSame('Parque Industrial, Calle 9 esq. 10', $d['direccion']);
        $this->assertSame('6300', $d['codigo_postal']);
    }

    /**
     * El fijo y el celular: los dos, no solo el primero.
     *
     * "La ia solo toma un numero": una firma con "Tel / Fax" y "Celular"
     * traia uno solo, y encima mezclaba formatos. Ahora salen los dos, cada
     * uno con su tipo y en el mismo formato +54.
     */
    public function test_trae_los_dos_telefonos_normalizados(): void
    {
        $d = $this->leer(
            "Ing. Alejandro Koblecovsky\nFinanzas - Aceros Corona S.R.L.\n"
            ."Tel / Fax:: +54 11 46997003 int 109\nCelular: 115009-3040"
        );

        // El primero queda como el principal.
        $this->assertSame('+54 11 4699-7003 int 109', $d['telefono']);
        $this->assertSame('Telefono', $d['tipo_telefono']);

        $tels = collect($d['telefonos']);
        $this->assertCount(2, $tels);
        $this->assertTrue($tels->contains(fn ($t) => $t['valor'] === '+54 11 4699-7003 int 109' && $t['tipo'] === 'Telefono'));
        $this->assertTrue($tels->contains(fn ($t) => $t['valor'] === '+54 9 11 5009-3040' && $t['tipo'] === 'Celular'));
    }

    /**
     * Localidad, provincia y país salen del catálogo, no del texto.
     *
     * Dar de alta una localidad desde el pie de un mail llenaría la tabla de
     * variantes mal escritas, que es justo lo que se acaba de limpiar.
     */
    public function test_engancha_donde_queda_con_el_catalogo(): void
    {
        $argentina = Pais::create(['nombre' => 'Argentina']);
        $laPampa = Provincia::create(['nombre' => 'La Pampa', 'pais_id' => $argentina->id]);
        $santaRosa = Localidad::create(['nombre' => 'Santa Rosa', 'provincia_id' => $laPampa->id]);

        $d = $this->leer(self::MAIL);

        $this->assertSame($argentina->id, $d['pais_id']);
        $this->assertSame($laPampa->id, $d['provincia_id']);
        $this->assertSame($santaRosa->id, $d['localidad_id']);
    }

    /**
     * Sin "Argentina" en la firma, el pais sale de la provincia.
     *
     * Casi nadie lo escribe y quedaba vacio. Y si solo dice la ciudad, la
     * ciudad dice su provincia.
     */
    public function test_el_pais_sale_de_la_provincia_y_la_provincia_de_la_ciudad(): void
    {
        $argentina = Pais::create(['nombre' => 'Argentina']);
        $santaFe = Provincia::create(['nombre' => 'Santa Fe', 'pais_id' => $argentina->id]);
        Localidad::create(['nombre' => 'Rosario', 'provincia_id' => $santaFe->id]);

        $d = $this->leer("Pedro Gomez\nCompras\nAv. Pellegrini 1234 - Rosario\nTel 0341 456-7890");

        $this->assertSame($santaFe->id, $d['provincia_id']);
        $this->assertSame($argentina->id, $d['pais_id']);
        $this->assertSame('Av. Pellegrini 1234 - Rosario', $d['direccion']);
    }

    /** Una localidad que no está cargada queda vacía: se elige a mano. */
    public function test_lo_que_no_esta_en_el_catalogo_queda_vacio(): void
    {
        $d = $this->leer(self::MAIL);

        $this->assertNull($d['localidad_id']);
        $this->assertNull($d['provincia_id']);
    }

    /**
     * El dominio dice la empresa cuando el encabezado no la nombra.
     *
     * Pero un gmail no dice nada: ahí queda vacío.
     */
    public function test_la_empresa_sale_del_dominio_si_hace_falta(): void
    {
        $this->assertSame('Ferrum', $this->leer("Juan Perez\nCompras\njperez@ferrum.com.ar")['empresa']);
        $this->assertNull($this->leer("Juan Perez\nCompras\njuanperez@gmail.com")['empresa']);
    }

    /**
     * Un número suelto no es un teléfono.
     *
     * En una firma hay códigos postales, alturas de calle y años. Se le exige
     * la etiqueta —Cel, Tel— o el formato con paréntesis.
     */
    public function test_no_confunde_cualquier_numero_con_un_telefono(): void
    {
        $this->assertNull($this->leer("Juan Perez\nCalle Falsa 1234\nCP 1870")['telefono']);
        $this->assertSame('+54 11 4567-8900', $this->leer("Juan Perez\nTel: 11 4567-8900")['telefono']);
    }

    /** Un texto que no es una firma no inventa nada. */
    public function test_sin_firma_no_inventa(): void
    {
        $d = $this->leer('Buen dia, quedamos a la espera de la cotizacion. Gracias.');

        $this->assertNull($d['contacto']);
        $this->assertNull($d['telefono']);
        $this->assertNull($d['direccion']);
        $this->assertNull($d['mail']);
    }

    /*
     * Los tres ejemplos que mando Roberto el 30-09-2026, tal cual. Las fichas
     * de la web traen TABs: son las columnas aplanadas al copiar y pegar.
     */

    private const SULFOQUIMICA = "Pedro A. Quiroga\ndeposito@sulfoquimica.com.ar\n                      \n"
        ."Sulfoquimica S.A.\nPanamá 8051\nMartin Coronado C.P. (1682)\nProv. Buenos Aires - Argentina\nCel   1145672389";

    private const FICHA_WEB = "DATOS DE CONTACTO\nNOMBRE\nDiego Arce\tEMAIL\ndarce@implantestraumatologicos.com\n"
        ."PAÍS\nArgentina\tEMPRESA\nDGS ANTIPINA\nTELÉFONO\n011 4812-3365\tORIGEN\nCONSULTA DESDE LA WEB";

    private const FICHA_WEB_REORDENADA = "EMPRESA DGS ANTIPINA\nDATOS DE CONTACTO\nNOMBRE Diego Arce\t\n"
        ."EMAIL darce@implantestraumatologicos.com\n\nPAÍS Argentina\t\nTELÉFONO 011 4812-3365";

    /**
     * "En este ejemplo NO agrega dirección".
     *
     * Tres cosas que no se leian: el nombre con inicial, la direccion sin
     * "Av" ni "Calle", y el codigo postal con puntos y parentesis.
     */
    public function test_la_firma_de_sulfoquimica_trae_la_direccion(): void
    {
        $d = $this->leer(self::SULFOQUIMICA);

        $this->assertSame('Pedro A. Quiroga', $d['contacto']);
        $this->assertSame('Sulfoquimica S.A.', $d['empresa'], 'el nombre legal, no el del dominio');
        $this->assertSame('Panamá 8051', $d['direccion']);
        $this->assertSame('1682', $d['codigo_postal']);
        $this->assertSame('+54 9 11 4567-2389', $d['telefono']);
    }

    /**
     * La localidad tiene que ser de la provincia que se encontro.
     *
     * Martin Coronado no esta cargada y "Buenos Aires" si, pero como localidad
     * de la Ciudad Autonoma. Salia una localidad de CABA con provincia de
     * Buenos Aires. Vacia se elige a mano; equivocada no se ve.
     */
    public function test_no_pone_una_localidad_de_otra_provincia(): void
    {
        $argentina = Pais::create(['nombre' => 'Argentina']);
        $provincia = Provincia::create(['nombre' => 'Buenos Aires', 'pais_id' => $argentina->id]);
        $caba = Provincia::create(['nombre' => 'Ciudad Autónoma de Buenos Aires', 'pais_id' => $argentina->id]);
        Localidad::create(['nombre' => 'Buenos Aires', 'provincia_id' => $caba->id]);

        $d = $this->leer(self::SULFOQUIMICA);

        $this->assertSame($provincia->id, $d['provincia_id']);
        $this->assertNull($d['localidad_id']);
    }

    /**
     * La ficha de contacto de la web, en los dos ordenes en que la pegaron.
     *
     * Leida como firma, el contacto se llamaba "DATOS DE CONTACTO", tenia el
     * cargo "NOMBRE" y la empresa salia del dominio del mail.
     */
    public function test_lee_la_ficha_de_contacto_de_la_web(): void
    {
        $argentina = Pais::create(['nombre' => 'Argentina']);

        foreach ([self::FICHA_WEB, self::FICHA_WEB_REORDENADA] as $ficha) {
            $d = $this->leer($ficha);

            $this->assertSame('DGS ANTIPINA', $d['empresa']);
            $this->assertSame('Diego Arce', $d['contacto']);
            $this->assertNull($d['cargo'], 'NOMBRE es una etiqueta, no un cargo');
            $this->assertSame('darce@implantestraumatologicos.com', $d['mail']);
            $this->assertSame('+54 11 4812-3365', $d['telefono']);
            $this->assertSame($argentina->id, $d['pais_id']);
        }
    }

    /** Una firma con "Tel:" y "Mail:" sigue siendo una firma: no pierde el nombre. */
    public function test_una_firma_con_etiquetas_no_es_una_ficha(): void
    {
        $d = $this->leer("Juan Perez\nGerente de Compras\nTel: 4555-3700\nMail: jperez@acme.com.ar");

        $this->assertSame('Juan Perez', $d['contacto']);
        $this->assertSame('Gerente de Compras', $d['cargo']);
    }

    /**
     * La firma de FAGISA, 14-10-2026, tal cual: iconos en vez de rotulos.
     *
     * "La IA trae solo el primero, el segundo telefono no esta llegando." Con
     * el icono adelante las reglas no reconocian ningun telefono ni la calle.
     */
    public function test_firma_con_iconos_trae_los_dos_telefonos_y_la_calle(): void
    {
        $d = $this->leer("Analía Nemiña / Lucía Razzetti\n\n"
            ."\u{2666}\u{FE0F} Manuel Quintana 155, B1878 - Quilmes, Buenos Aires\n"
            ."\u{260E}\u{FE0F} +54 9 11 4252 0500\n"
            ."\u{260E}\u{FE0F} +54 9 11 2829 4118\n"
            ."\u{2709}\u{FE0F} compras@fagisa.com.ar\n"
            ."\u{2666}\u{FE0F} www.fagisa.com.ar");

        $this->assertSame(['+54 9 11 4252-0500', '+54 9 11 2829-4118'], array_column($d['telefonos'], 'valor'));
        $this->assertSame('Manuel Quintana 155', $d['direccion']);
        $this->assertSame('B1878', $d['codigo_postal']);
        $this->assertSame('compras@fagisa.com.ar', $d['mail']);
    }

    /** Con el codigo de pais adelante un numero es un telefono, aunque no diga "Tel". */
    public function test_numero_internacional_sin_rotulo(): void
    {
        $this->assertSame('+54 11 4252-0500', $this->leer("Juan Perez\n+54 11 4252 0500")['telefono']);
        // Un codigo postal o una altura siguen sin serlo.
        $this->assertNull($this->leer("Juan Perez\nAv. Mitre 4252\nB1878")['telefono']);
    }

    /*
      El CUIT. Sale impreso en una factura, así que se lee SOLO si el dígito
      verificador cierra y el prefijo existe. 30-12345678-1 es un número de
      prueba que cumple la cuenta oficial; no es de ninguna empresa.
    */

    public function test_lee_el_cuit_con_guiones_y_lo_deja_en_el_formato_que_se_guarda(): void
    {
        $this->assertSame('30-12345678-1', $this->leer("Apex SRL\nCUIT: 30-12345678-1\nTel: (2954) 15-412233")['cuit']);
    }

    public function test_lee_el_cuit_sin_guiones_ni_puntos_y_con_espacios(): void
    {
        $this->assertSame('30-12345678-1', $this->leer("Apex SRL\nCUIT 30123456781")['cuit']);
        $this->assertSame('30-12345678-1', $this->leer("Apex SRL\nC.U.I.T.: 30 12345678 1")['cuit']);
        $this->assertSame('30-12345678-1', $this->leer("Apex SRL\nCUIT 30.12345678.1")['cuit']);
    }

    public function test_un_cuit_con_el_digito_verificador_mal_no_se_toma(): void
    {
        // Cambia solo el último dígito: antes era 1.
        $this->assertNull($this->leer("Apex SRL\nCUIT: 30-12345678-2")['cuit']);
    }

    public function test_un_numero_de_11_digitos_con_prefijo_que_no_existe_no_es_cuit(): void
    {
        $this->assertNull($this->leer("Apex SRL\nPedido 99123456781")['cuit']);
    }

    public function test_un_telefono_largo_no_se_confunde_con_un_cuit(): void
    {
        $d = $this->leer("Pablo Ferreyra\nCel: +54 9 11 5555 0100\nTel: 0800 555 0100");

        $this->assertNull($d['cuit']);
        $this->assertNotNull($d['telefono']);
    }

    public function test_un_numero_mas_largo_que_un_cuit_no_se_corta_para_encontrarlo(): void
    {
        // Trece dígitos seguidos que CONTIENEN un cuit válido: no es un CUIT.
        $this->assertNull($this->leer("Apex SRL\nNro 3012345678100")['cuit']);
    }

    public function test_sin_cuit_el_dato_viene_vacio(): void
    {
        $this->assertNull($this->leer(self::MAIL)['cuit']);
    }

    public function test_si_hay_varios_cuit_validos_toma_el_primero(): void
    {
        $this->assertSame(
            '30-12345678-1',
            $this->leer("Apex SRL CUIT 30-12345678-1\nContador CUIL 20-12345678-6")['cuit'],
        );
    }

    public function test_lee_el_cuit_de_una_ficha_de_la_web(): void
    {
        $d = $this->leer("EMPRESA: Apex SRL\nNOMBRE: Pablo Ferreyra\nCUIT: 30-12345678-1\nMAIL: pferreyra@apex.example");

        $this->assertSame('30-12345678-1', $d['cuit']);
        $this->assertSame('Apex SRL', $d['empresa']);
    }

    public function test_el_digito_verificador_oficial(): void
    {
        $this->assertTrue(FirmaDeMail::cuitValido('30123456781'));
        $this->assertFalse(FirmaDeMail::cuitValido('30123456782'));
        $this->assertFalse(FirmaDeMail::cuitValido('3012345678'));     // 10 dígitos
        $this->assertFalse(FirmaDeMail::cuitValido('30-12345678-1')); // con guiones: se limpia antes
    }
}

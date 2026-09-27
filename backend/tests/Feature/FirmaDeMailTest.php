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
        From: Gonzalo Sack - Apex Metalurgica <gsack@apex.com.ar>
        Sent: Friday, September 25, 2026 10:32 AM
        To: Roberto Cordes <roberto.cordes@cordes.ar>; Marcos Sebastián Fiorucci <mfiorucci@apex.com.ar>
        Cc: ventas@cordes.ar
        Subject: RE: Nuevo Pedido de Cotización - AISI 310S (22/9)

        Hola Roberto, buenos días.

        Vamos por la opción de 2 chapas 10x1500x3000 Acero 310S chino

        Gonzalo Sack
        Supervisor de Mantenimiento
        Cel: (2954) 15-584584

        Parque Industrial, Calle 9 esq. 10 | CP 6300
        Santa Rosa, La Pampa, Argentina
        www.apex.com.ar
        TEXTO;

    private function leer(string $texto): array
    {
        return (new FirmaDeMail)->leer($texto);
    }

    public function test_lee_el_mail_que_mandaron(): void
    {
        $d = $this->leer(self::MAIL);

        $this->assertSame('Apex Metalurgica', $d['empresa']);
        $this->assertSame('Gonzalo Sack', $d['contacto']);
        $this->assertSame('Supervisor de Mantenimiento', $d['cargo']);
        $this->assertSame('gsack@apex.com.ar', $d['mail']);
        $this->assertSame('(2954) 15-584584', $d['telefono']);
        $this->assertSame('www.apex.com.ar', $d['web']);
        $this->assertSame('Parque Industrial, Calle 9 esq. 10', $d['direccion']);
        $this->assertSame('6300', $d['codigo_postal']);
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
        $this->assertSame('11 4567-8900', $this->leer("Juan Perez\nTel: 11 4567-8900")['telefono']);
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
}

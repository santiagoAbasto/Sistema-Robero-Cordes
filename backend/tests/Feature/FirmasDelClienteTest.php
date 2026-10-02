<?php

namespace Tests\Feature;

use App\Models\Localidad;
use App\Models\Pais;
use App\Models\Provincia;
use App\Services\FirmaDeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las fichas y firmas que mando Roberto el 01-10-2026, con la misma forma.
 *
 * Las personas, los mails y los telefonos son inventados: los reales no van
 * al repositorio.
 */
class FirmasDelClienteTest extends TestCase
{
    use RefreshDatabase;

    private Pais $argentina;

    private Provincia $bsas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->argentina = Pais::create(['nombre' => 'Argentina']);
        $this->bsas = Provincia::create(['nombre' => 'Buenos Aires', 'pais_id' => $this->argentina->id]);
        $caba = Provincia::create(['nombre' => 'Ciudad Autónoma de Buenos Aires', 'pais_id' => $this->argentina->id]);
        // La localidad "Buenos Aires" de CABA, mas larga que Sarandi.
        Localidad::create(['nombre' => 'Buenos Aires', 'provincia_id' => $caba->id]);
    }

    private function leer(string $texto): array
    {
        return (new FirmaDeMail)->leer($texto);
    }

    /** La ficha del mail de THORSA: rotulos con barra y la direccion con el lugar pegado. */
    public function test_la_ficha_con_rotulos_dobles(): void
    {
        $d = $this->leer(
            "por material Monel K –500\ndiametro exterior 76 mm largo 970 mm.Nombre: Pablo Ferreyra\n"
            ."Cargo / Área: Compras\nEmpresa: ACME\n"
            ."Dirección: Calle Falsa 1234, Remedios de Escalada, Buenos Aires\n"
            ."Teléfono / WhatsApp: (+54-9) 11-4567-2389\nWeb: www.acme.com.ar"
        );

        $this->assertSame('ACME', $d['empresa']);
        $this->assertSame('Pablo Ferreyra', $d['contacto']);
        $this->assertSame('Compras', $d['cargo']);
        $this->assertSame('(+54-9) 11-4567-2389', $d['telefono']);
        $this->assertSame('Celular', $d['tipo_telefono']);
        $this->assertSame('www.acme.com.ar', $d['web']);
        $this->assertSame('Calle Falsa 1234', $d['direccion']);
        $this->assertSame($this->bsas->id, $d['provincia_id']);
        $this->assertSame($this->argentina->id, $d['pais_id']);
    }

    /** La firma de Tormicron: una leyenda que empieza con "Empresa" no es un rotulo. */
    public function test_una_leyenda_no_se_toma_como_rotulo(): void
    {
        $d = $this->leer(
            "Lucia Benitez\nAdministración y ventas\nAv. Siempreviva 742, San Justo\n"
            ."WhatsApp: 11-4321-9876\ninfo@acme.com.ar\nwww.acme.com.ar\n"
            ."Empresa Habilitada para la fabricación de productos médicos"
        );

        $this->assertSame('Lucia Benitez', $d['contacto']);
        $this->assertSame('Administración y ventas', $d['cargo']);
        $this->assertSame('Av. Siempreviva 742', $d['direccion']);
        $this->assertSame('WhatsApp', $d['tipo_telefono']);
    }

    /** La firma de JMH: "Cargo, Empresa SRL" en un renglon y la calle con el lugar detras. */
    public function test_cargo_y_empresa_en_el_mismo_renglon(): void
    {
        $sarandi = Localidad::create(['nombre' => 'SARANDI', 'provincia_id' => $this->bsas->id]);

        $d = $this->leer(
            "Lucas Ferrari\nGerente Comercial, ACME SRL\nTel: +54 11 4321-9876\n"
            ."lferrari@acme.com.ar\nMadariaga 830, Sarandí, Buenos Aires, Argentina"
        );

        $this->assertSame('ACME SRL', $d['empresa']);
        $this->assertSame('Gerente Comercial', $d['cargo']);
        $this->assertSame('Madariaga 830', $d['direccion']);
        $this->assertSame($sarandi->id, $d['localidad_id']);
        $this->assertSame($this->bsas->id, $d['provincia_id']);
    }

    /** La web y las redes salen como enlaces, con rotulo o escritas como direccion. */
    public function test_la_web_y_las_redes(): void
    {
        $d = $this->leer(
            "Lucas Ferrari\nGerente Comercial, ACME SRL\nlferrari@acme.com.ar\n"
            ."www.acme.com.ar\nInstagram: acme.argentina\nLinkedIn: linkedin.com/company/acme-srl\n"
            ."Web: [www.acme.com.ar](https://www.acme.com.ar)"
        );

        $this->assertSame([
            ['tipo' => 'Web', 'url' => 'www.acme.com.ar'],
            ['tipo' => 'Instagram', 'url' => 'instagram.com/acme.argentina'],
            ['tipo' => 'LinkedIn', 'url' => 'linkedin.com/company/acme-srl'],
        ], $d['enlaces']);
    }
}

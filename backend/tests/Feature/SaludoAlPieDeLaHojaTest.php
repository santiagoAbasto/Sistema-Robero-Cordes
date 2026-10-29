<?php

namespace Tests\Feature;

use App\Models\CondicionHabitual;
use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La hoja cierra como una carta (pedido del cliente, 29-10-2026): debajo de
 * las condiciones, "Saludos cordiales," y el nombre de quien cotizo.
 */
class SaludoAlPieDeLaHojaTest extends TestCase
{
    use RefreshDatabase;

    private function hoja(Consulta $consulta, $condiciones): string
    {
        return view('pdf.cotizacion', [
            'consulta' => $consulta->fresh(),
            'lineas' => collect(),
            'condiciones' => $condiciones,
            'total' => 0.0,
            'titulo' => 'Cotizacion',
            'marca' => null,
            'simbolo' => 'US$',
            'fecha' => '29/10/2026',
            'nombreEnPdf' => 'CLIENTE',
            'contacto' => null,
            'telefono' => null,
            'mail' => null,
            'incluyeImportes' => true,
            'incluyeNota' => false,
        ])->render();
    }

    public function test_despues_de_las_condiciones_va_el_saludo_y_quien_cotizo(): void
    {
        $quien = User::factory()->create(['name' => 'Vendedora De Prueba']);
        $consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'CLIENTE'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-10-29',
            'estado' => 'Borrador',
            'usuario_id' => $quien->id,
        ]);

        $html = $this->hoja($consulta, collect([new CondicionHabitual(['titulo' => 'Entrega', 'texto' => 'Inmediata', 'imprime' => true])]));

        $condiciones = strpos($html, 'Inmediata');
        $saludo = strpos($html, 'Saludos cordiales,');
        $nombre = strpos($html, 'Vendedora De Prueba');

        $this->assertNotFalse($saludo);
        $this->assertNotFalse($nombre);
        $this->assertTrue($condiciones < $saludo && $saludo < $nombre, 'condiciones, saludo y nombre, en ese orden');
    }
}

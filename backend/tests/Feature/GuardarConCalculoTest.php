<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\HistorialCambio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * El Server Error al guardar, que reportaron los duenios.
 *
 * "COTIZACION: roberto quiere GUARDAR E IMPRIMIR cotizacion de ricardo y tira
 * server error."
 *
 * No era de permisos ni de la impresion. En el log del servidor:
 *
 *     production.ERROR: Array to string conversion
 *     at app/Models/Concerns/RegistraCambios.php:99
 *
 * consulta_lineas.calculo es una columna JSON —la foto de como quedo la
 * calculadora de peso—, y el historial la casteaba a texto con (string).
 * Sobre un array eso no devuelve nada: tira. Y como el historial se escribe
 * dentro del guardado, se caia el guardado entero.
 *
 * Se arregla en los dos lados: la conversion aguanta arrays, y la foto del
 * calculo no va mas al historial porque no es una decision comercial.
 */
class GuardarConCalculoTest extends TestCase
{
    use RefreshDatabase;

    private function linea(): ConsultaLinea
    {
        $usuario = User::factory()->create();
        Auth::login($usuario);

        $consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'YPF S.A.'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-09-25',
            'estado' => 'Borrador',
            'usuario_id' => $usuario->id,
        ]);

        return ConsultaLinea::create([
            'consulta_id' => $consulta->id,
            'descripcion' => 'TITANIO GR2 BARRA',
            'cantidad' => 3,
            'calculo' => ['medidas' => ['diameter' => ['valor' => '127', 'unidad' => 'mm']], 'piezas' => '3'],
        ]);
    }

    /**
     * Tocar una medida y guardar no puede voltear el guardado.
     *
     * calculo y peso_kg son guarded: los pone el codigo, no el formulario.
     * Por eso se asignan directo, igual que en el guardado de verdad.
     */
    public function test_cambiar_el_calculo_no_rompe_el_guardado(): void
    {
        $linea = $this->linea();

        $linea->calculo = ['medidas' => ['diameter' => ['valor' => '150', 'unidad' => 'mm']], 'piezas' => '3'];
        $linea->peso_kg = 12.5;
        $linea->save();

        $this->assertSame('150', $linea->fresh()->calculo['medidas']['diameter']['valor']);
    }

    /**
     * La foto del calculo no ensucia el historial; el peso si se anota.
     *
     * Lo que importa de ese calculo es el peso que se factura.
     */
    public function test_el_calculo_no_va_al_historial_pero_el_peso_si(): void
    {
        $linea = $this->linea();

        $linea->calculo = ['piezas' => '9'];
        $linea->peso_kg = 40;
        $linea->save();

        $campos = HistorialCambio::where('tabla', 'consulta_lineas')
            ->whereNotNull('campo')
            ->pluck('campo');

        $this->assertNotContains('calculo', $campos);
        $this->assertContains('peso_kg', $campos);
    }

    /**
     * Y si alguna vez un array llega igual al historial, se guarda legible.
     *
     * Es la red: manianna alguien agrega otra columna JSON y no tiene que
     * acordarse de nada. Antes, cualquier array volteaba el guardado entero.
     */
    public function test_un_array_se_guarda_como_json_y_no_revienta(): void
    {
        $linea = $this->linea();

        $linea->anotarCambio('Modificacion', 'prueba', ['antes' => 1], ['ahora' => 2, 'mm' => 150]);

        $anotado = HistorialCambio::where('campo', 'prueba')->firstOrFail();

        $this->assertStringContainsString('"ahora":2', (string) $anotado->valor_nuevo);
        $this->assertStringContainsString('150', (string) $anotado->valor_nuevo);
        $this->assertStringNotContainsString('Array', (string) $anotado->valor_nuevo);
    }
}

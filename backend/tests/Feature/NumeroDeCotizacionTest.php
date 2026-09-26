<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El numero de la cotizacion.
 *
 * "Imprimi cotizacion y no salio numerada". El numero es por el que pregunta
 * el cliente cuando llama: tiene que ser uno solo, no repetirse nunca y no
 * cambiar despues de impreso.
 */
class NumeroDeCotizacionTest extends TestCase
{
    use RefreshDatabase;

    private function cotizacion(string $fecha): Consulta
    {
        return Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'CLIENTE '.uniqid()])->id,
            'tipo' => 'Cotizacion',
            'fecha' => $fecha,
            'estado' => 'Borrador',
            'usuario_id' => User::factory()->create()->id,
        ]);
    }

    /** Correlativo, de a uno, sin saltos. */
    public function test_numera_correlativo_dentro_del_anio(): void
    {
        $this->assertSame('2026-0001', $this->cotizacion('2026-03-04')->numerar());
        $this->assertSame('2026-0002', $this->cotizacion('2026-07-19')->numerar());
        $this->assertSame('2026-0003', $this->cotizacion('2026-12-30')->numerar());
    }

    /** Cada anio arranca de nuevo. */
    public function test_cada_anio_arranca_en_uno(): void
    {
        $this->cotizacion('2026-05-05')->numerar();

        $this->assertSame('2027-0001', $this->cotizacion('2027-01-02')->numerar());
        $this->assertSame('2026-0002', $this->cotizacion('2026-11-11')->numerar());
    }

    /**
     * El anio sale de la fecha de la cotizacion, no del dia en que se imprime.
     *
     * Una cotizacion de diciembre que se imprime en enero sigue siendo del
     * anio en que se hizo: si no, el numero no dice cuando se cotizo.
     */
    public function test_el_anio_es_el_de_la_cotizacion(): void
    {
        $this->travelTo('2027-01-15');

        $this->assertSame('2026-0001', $this->cotizacion('2026-12-28')->numerar());
    }

    /**
     * Numerar dos veces no cambia el numero.
     *
     * Se imprime, se corrige una cantidad y se vuelve a imprimir: tiene que
     * salir la misma hoja con el mismo numero. El cliente ya tiene la primera.
     */
    public function test_no_cambia_si_ya_tenia_numero(): void
    {
        $consulta = $this->cotizacion('2026-04-01');
        $primero = $consulta->numerar();

        $this->assertSame($primero, $consulta->numerar());
        $this->assertSame($primero, $consulta->fresh()->numerar());
    }

    /**
     * Un borrador no se lleva un numero.
     *
     * Copiar una cotizacion a veinte empresas deja veinte borradores y la
     * mitad se descarta. Si cada uno se llevara su numero, la correlatividad
     * quedaria llena de agujeros que despues alguien tiene que explicar.
     */
    public function test_el_borrador_no_gasta_numero(): void
    {
        $borrador = $this->cotizacion('2026-06-01');

        $this->assertNull($borrador->numero);

        // Al confirmarla si.
        $usuario = User::factory()->create(['role' => 'Administrador']);

        $this->actingAs($usuario)
            ->postJson("/api/consultas/{$borrador->id}/estado", ['estado' => 'Confirmada'])
            ->assertOk();

        $this->assertSame('2026-0001', $borrador->fresh()->numero);
    }
}

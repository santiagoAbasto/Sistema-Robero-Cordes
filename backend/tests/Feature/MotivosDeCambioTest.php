<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\MotivoCambio;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los motivos de cambio los administra la empresa, no el codigo.
 *
 * "Agregar mas opciones de motivos de cambio, solo hay unos cuantos, debe ser
 * mas administrable". Era un desplegable cerrado: agregar una octava opcion
 * era tocar el codigo y volver a desplegar. Ahora se elige o se escribe, y lo
 * escrito queda para la proxima, igual que la condicion de pago.
 *
 * El motivo sale impreso al lado de la diferencia y lo lee el cliente, asi que
 * el sistema no inventa motivos: trae los que ya se usaban y los demas los
 * escribe la empresa.
 */
class MotivosDeCambioTest extends TestCase
{
    use RefreshDatabase;

    private Consulta $consulta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);

        Sanctum::actingAs($usuario = User::factory()->create());

        $this->consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'APEX'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Borrador',
            'usuario_id' => $usuario->id,
        ]);
    }

    private function guardarConMotivo(?string $motivo, bool $igual = false)
    {
        return $this->putJson("/api/consultas/{$this->consulta->id}", [
            'tipo' => 'Cotizacion',
            'fecha' => $this->consulta->fecha->toDateString(),
            'lineas' => [[
                'descripcion' => 'Barra redonda',
                'cantidad' => 1,
                'igual_a_lo_pedido' => $igual,
                'pedido_material' => 'AISI 420',
                'motivo_cambio' => $motivo,
            ]],
        ]);
    }

    private function nombres(): array
    {
        return MotivoCambio::orderBy('orden')->pluck('nombre')->all();
    }

    /** El que pidio Roberto por su nombre. */
    public function test_viene_el_motivo_que_pidieron(): void
    {
        $this->assertContains('No hay esa calidad', $this->nombres());
    }

    /** Un motivo escrito a mano queda en la lista para la proxima. */
    public function test_lo_escrito_queda_en_la_lista(): void
    {
        $this->assertNotContains('El cliente adelanta la entrega', $this->nombres());

        $this->guardarConMotivo('El cliente adelanta la entrega')->assertOk();

        $this->assertContains('El cliente adelanta la entrega', $this->nombres());
    }

    /** Y aparece en el catalogo que arma la pantalla. */
    public function test_aparece_en_la_lista_que_ve_la_pantalla(): void
    {
        $this->guardarConMotivo('El cliente adelanta la entrega')->assertOk();

        $this->getJson('/api/catalogos')
            ->assertOk()
            ->assertJsonPath('motivos_cambio', fn ($m) => in_array('El cliente adelanta la entrega', $m, true));
    }

    /** No se duplica: escribirlo de nuevo, con otras mayusculas, no agrega otro. */
    public function test_no_se_repite_por_mayusculas(): void
    {
        $this->guardarConMotivo('Plazo de fabricacion')->assertOk();
        $this->guardarConMotivo('PLAZO DE FABRICACION')->assertOk();

        $cuantos = MotivoCambio::whereRaw('LOWER(nombre) = ?', ['plazo de fabricacion'])->count();

        $this->assertSame(1, $cuantos);
    }

    /**
     * Cotizando igual a lo pedido no hay motivo que recordar.
     *
     * El motivo se borra junto con el resto de lo pedido; si igual se
     * guardara, la lista se llenaria de motivos de lineas sin diferencia.
     */
    public function test_cotizando_igual_no_se_guarda_ningun_motivo(): void
    {
        $antes = MotivoCambio::count();

        $this->guardarConMotivo('Esto no deberia quedar', igual: true)->assertOk();

        $this->assertSame($antes, MotivoCambio::count());
    }

    /** Un motivo vacio no ensucia la lista. */
    public function test_un_motivo_vacio_no_agrega_nada(): void
    {
        $antes = MotivoCambio::count();

        $this->guardarConMotivo('   ')->assertOk();

        $this->assertSame($antes, MotivoCambio::count());
    }
}

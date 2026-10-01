<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Versiones de una cotizacion: 2026-0001 R0, R1, R2.
 *
 * "La original es la version 0 y despues 1, 2, 3". "Si ya la emitiste no la
 * podes modificar, pero si te deja como base y hace un borrador".
 *
 * Lo que se cuida es que la hoja que tiene el cliente y la que esta en el
 * sistema coincidan siempre. Una cotizacion emitida que se pudiera editar
 * dejaria de coincidir sin que nadie lo note.
 */
class RevisionesDeCotizacionTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Consulta $consulta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);

        $this->usuario = User::factory()->create(['role' => 'Administrador']);
        Sanctum::actingAs($this->usuario);

        $this->consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'APEX'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-09-30',
            'estado' => 'Borrador',
            'usuario_id' => $this->usuario->id,
        ]);

        ConsultaLinea::create([
            'consulta_id' => $this->consulta->id,
            'orden' => 1,
            'descripcion' => 'Barra redonda Ø20',
            'cantidad' => 3,
            'precio_unitario' => 100,
            'importe' => 300,
        ]);
    }

    private function guardar(Consulta $consulta, float $precio = 100)
    {
        return $this->putJson("/api/consultas/{$consulta->id}", [
            'tipo' => 'Cotizacion',
            'fecha' => $consulta->fecha->toDateString(),
            'lineas' => [[
                'id' => $consulta->lineas()->first()?->id,
                'descripcion' => 'Barra redonda Ø20',
                'cantidad' => 3,
                'precio_unitario' => $precio,
            ]],
        ]);
    }

    private function emitir(Consulta $consulta)
    {
        return $this->postJson("/api/consultas/{$consulta->id}/emitir");
    }

    private function revisar(Consulta $consulta)
    {
        return $this->postJson("/api/consultas/{$consulta->id}/revision");
    }

    /** Un borrador se edita todas las veces que haga falta. */
    public function test_un_borrador_se_edita(): void
    {
        $this->guardar($this->consulta, 120)->assertOk();
        $this->guardar($this->consulta, 130)->assertOk();

        $this->assertFalse($this->consulta->fresh()->estaEmitida());
    }

    /** Emitir la numera como R0. */
    public function test_emitir_la_numera_como_r0(): void
    {
        $this->emitir($this->consulta)
            ->assertOk()
            ->assertJsonPath('data.numero_con_revision', '2026-0001 R0')
            ->assertJsonPath('data.emitida', true);

        $emitida = $this->consulta->fresh();
        $this->assertSame(0, $emitida->revision);
        $this->assertSame($this->usuario->id, $emitida->emitida_por);
    }

    /** Lo emitido no se edita: el servidor lo rechaza, no solo la pantalla. */
    public function test_lo_emitido_no_se_puede_modificar(): void
    {
        $this->emitir($this->consulta)->assertOk();

        $this->guardar($this->consulta->fresh(), 999)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Esta cotizacion ya se emitio como 2026-0001 R0 y no se puede modificar. Para cambiarla, hace una revision.']);

        // Y el precio quedo como se emitio.
        $this->assertEquals(100, (float) $this->consulta->lineas()->first()->precio_unitario);
    }

    /** Emitir dos veces no la renumera. */
    public function test_emitir_de_nuevo_no_cambia_nada(): void
    {
        $this->emitir($this->consulta)->assertOk();
        $this->emitir($this->consulta->fresh())->assertOk()
            ->assertJsonPath('data.numero_con_revision', '2026-0001 R0');
    }

    /**
     * La revision nace como borrador, con todo lo de la base.
     *
     * Precios incluidos: es la misma cotizacion corregida, no una nueva para
     * otra empresa. Todavia no tiene numero: se lo gana al emitirse.
     */
    public function test_la_revision_nace_como_borrador_con_todo_lo_de_la_base(): void
    {
        $this->emitir($this->consulta)->assertOk();

        $r = $this->revisar($this->consulta->fresh())->assertCreated();

        $revision = Consulta::find($r->json('data.id'));

        $this->assertFalse($revision->estaEmitida());
        $this->assertSame($this->consulta->id, $revision->revision_de_id);
        $this->assertNull($revision->numero);
        $this->assertEquals(100, (float) $revision->lineas()->first()->precio_unitario);
    }

    /** Emitida, la revision lleva el mismo numero y la revision siguiente. */
    public function test_la_revision_emitida_es_el_mismo_numero_r1(): void
    {
        $this->emitir($this->consulta)->assertOk();
        $r1 = Consulta::find($this->revisar($this->consulta->fresh())->json('data.id'));

        $this->guardar($r1, 90)->assertOk();
        $this->emitir($r1)->assertOk()->assertJsonPath('data.numero_con_revision', '2026-0001 R1');

        // Y la siguiente, R2 — revisando la R1, no la original.
        $r2 = Consulta::find($this->revisar($r1->fresh())->json('data.id'));
        $this->emitir($r2)->assertOk()->assertJsonPath('data.numero_con_revision', '2026-0001 R2');
    }

    /**
     * Una revision no le roba el numero a otra cotizacion.
     *
     * Si al numerar una revision se pidiera un numero nuevo, la misma
     * cotizacion quedaria partida en dos: 2026-0001 y 2026-0002.
     */
    public function test_una_revision_no_se_gana_un_numero_nuevo(): void
    {
        $this->emitir($this->consulta)->assertOk();
        $r1 = Consulta::find($this->revisar($this->consulta->fresh())->json('data.id'));
        $this->emitir($r1)->assertOk();

        $otra = Consulta::create([
            'empresa_id' => $this->consulta->empresa_id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-09-30',
            'estado' => 'Borrador',
            'usuario_id' => $this->usuario->id,
        ]);

        $this->emitir($otra)->assertOk()->assertJsonPath('data.numero_con_revision', '2026-0002 R0');
    }

    /** Un borrador se edita directo: no hace falta revisarlo. */
    public function test_un_borrador_no_se_revisa(): void
    {
        $this->revisar($this->consulta)->assertStatus(422);
    }

    /** Un solo borrador por familia: dos revisiones a medio hacer se pisan. */
    public function test_no_se_abren_dos_revisiones_a_la_vez(): void
    {
        $this->emitir($this->consulta)->assertOk();
        $primera = $this->revisar($this->consulta->fresh())->assertCreated()->json('data.id');

        $this->revisar($this->consulta->fresh())
            ->assertStatus(409)
            ->assertJsonPath('id', $primera);
    }

    /** Todas las versiones, de la R0 a la ultima, desde cualquiera de ellas. */
    public function test_se_ven_todas_las_versiones(): void
    {
        $this->emitir($this->consulta)->assertOk();
        $r1 = Consulta::find($this->revisar($this->consulta->fresh())->json('data.id'));
        $this->emitir($r1)->assertOk();

        foreach ([$this->consulta, $r1] as $desde) {
            $this->getJson("/api/consultas/{$desde->id}/versiones")
                ->assertOk()
                ->assertJsonCount(2)
                ->assertJsonPath('0.numero', '2026-0001 R0')
                ->assertJsonPath('1.numero', '2026-0001 R1');
        }
    }

    /**
     * Imprimir un borrador no lo numera.
     *
     * Antes la hoja se numeraba al imprimirla, y un borrador impreso se
     * llevaba un numero sin quedar congelado. Numera emitir.
     */
    public function test_imprimir_un_borrador_no_lo_numera(): void
    {
        $this->get("/api/consultas/{$this->consulta->id}/pdf")->assertOk();

        $this->assertNull($this->consulta->fresh()->numero);
    }

    /**
     * Mirar la hoja no es mandarla.
     *
     * La pantalla muestra la hoja de una emitida en lugar del formulario, y la
     * pide cada vez que se abre. Si cada vistazo quedara anotado, el historial
     * diria que se le mando al cliente veinte veces.
     */
    public function test_mirar_la_hoja_no_la_anota_como_impresion(): void
    {
        $this->emitir($this->consulta)->assertOk();

        $this->get("/api/consultas/{$this->consulta->id}/pdf?registrar=0")->assertOk();
        $this->assertSame(0, $this->consulta->impresiones()->count());

        // Imprimirla de verdad si queda anotado.
        $this->get("/api/consultas/{$this->consulta->id}/pdf")->assertOk();
        $this->assertSame(1, $this->consulta->impresiones()->count());
    }

    /**
     * La revision emitida aparece en la ficha de la empresa.
     *
     * Nacia en Borrador y asi quedaba al emitirse: la ficha, que esconde los
     * borradores, no la mostraba. Una R1 mandada al cliente no estaba en
     * ningun lado salvo en las solapas de versiones.
     */
    public function test_la_revision_emitida_aparece_en_la_ficha(): void
    {
        $this->emitir($this->consulta)->assertOk();
        $r1 = Consulta::find($this->revisar($this->consulta->fresh())->json('data.id'));
        $this->emitir($r1)->assertOk();

        $numeros = collect($this->getJson("/api/empresas/{$this->consulta->empresa_id}")->json('data.cotizaciones'))
            ->pluck('numero_con_revision');

        $this->assertContains('2026-0001 R0', $numeros);
        $this->assertContains('2026-0001 R1', $numeros);
    }
}

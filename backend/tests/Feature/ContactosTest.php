<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Contacto;
use App\Models\ContactoMedio;
use App\Models\Empresa;
use App\Models\TipoMedio;
use App\Models\User;
use App\Services\FirmaDeMail;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contactos: que se armen solos, que no se repitan, y que no se pierda nada.
 *
 * Lo pidio Roberto el 30-09-2026 con la firma de Sulfoquimica: "despues hay
 * que agregar manualmente el contacto... tendria que poder tomar los datos que
 * pusimos al cargar la empresa", "si la empresa existe pero solo queremos
 * agregar un nuevo contacto, se necesita poder pegar los datos", y "como
 * telefono manda un celular, tendriamos que marcar si es celular".
 */
class ContactosTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));

        $this->empresa = Empresa::create(['nombre' => 'Sulfoquimica S.A.']);
    }

    private function tipo(string $nombre): int
    {
        return TipoMedio::where('nombre', $nombre)->value('id');
    }

    private function alta(array $datos)
    {
        return $this->postJson("/api/empresas/{$this->empresa->id}/contactos", $datos);
    }

    /** Celular es un tipo de medio, como Telefono o Mail. */
    public function test_existe_el_celular(): void
    {
        $this->assertNotNull(TipoMedio::where('nombre', 'Celular')->first());
        $this->assertContains('Celular', collect($this->getJson('/api/catalogos')->json('tipos_medio'))->pluck('nombre'));
    }

    /**
     * La empresa nace con su contacto.
     *
     * El pie del mail trae la empresa y a quien firma: se guardan juntos, y
     * el contacto nace principal, con su celular marcado como celular.
     */
    public function test_la_empresa_nueva_se_guarda_con_su_contacto(): void
    {
        $r = $this->postJson('/api/empresas', [
            'nombre' => 'Apex Metalurgica',
            'contacto' => [
                'nombre' => 'Gonzalo Sack',
                'cargo' => 'Supervisor de Mantenimiento',
                'medios' => [
                    ['tipo_medio_id' => $this->tipo('Celular'), 'valor' => '(2954) 15-584584'],
                    ['tipo_medio_id' => $this->tipo('Mail'), 'valor' => 'gsack@apex.com.ar'],
                ],
            ],
        ])->assertCreated();

        $contacto = Contacto::where('empresa_id', $r->json('data.id'))->with('medios.tipoMedio')->sole();

        $this->assertSame('Gonzalo Sack', $contacto->nombre);
        $this->assertSame('Supervisor de Mantenimiento', $contacto->cargo);
        $this->assertTrue((bool) $contacto->principal);
        $this->assertSame(
            ['Celular', 'Mail'],
            $contacto->medios->pluck('tipoMedio.nombre')->sort()->values()->all(),
        );
    }

    /** Sin contacto, la empresa se crea igual: el contacto es opcional. */
    public function test_la_empresa_se_crea_sin_contacto(): void
    {
        $r = $this->postJson('/api/empresas', ['nombre' => 'Otra S.A.'])->assertCreated();

        $this->assertSame(0, Contacto::where('empresa_id', $r->json('data.id'))->count());
    }

    /**
     * "Solo guardo datos nuevos, no repetidos".
     *
     * Pegar dos veces la firma de la misma persona no deja dos personas: la
     * segunda vez se le suma solo lo que no tenia. El mismo celular escrito
     * distinto no cuenta como nuevo.
     */
    public function test_la_misma_persona_no_se_repite_y_suma_solo_lo_nuevo(): void
    {
        $this->alta([
            'nombre' => 'Juan J. Saccomanno',
            'medios' => [['tipo_medio_id' => $this->tipo('Celular'), 'valor' => '11 3106-1795']],
        ])->assertOk();

        $r = $this->alta([
            'nombre' => 'juan j.  saccomanno',
            'cargo' => 'Pañol',
            'medios' => [
                ['tipo_medio_id' => $this->tipo('Celular'), 'valor' => '+54 9 11 3106 1795'],
                ['tipo_medio_id' => $this->tipo('Mail'), 'valor' => 'panol@sulfoquimica.com.ar'],
            ],
        ])->assertOk();

        $this->assertTrue($r->json('ya_estaba'));
        $this->assertStringContainsString('ya estaba: se le sumaron 2 datos nuevos', $r->json('mensaje'));

        $contacto = Contacto::where('empresa_id', $this->empresa->id)->sole();

        $this->assertSame('Pañol', $contacto->cargo, 'lo vacio se completa');
        $this->assertSame(2, $contacto->medios()->count(), 'el celular no se repite; el mail se suma');
    }

    /** Lo que ya tenia escrito no se pisa. */
    public function test_lo_escrito_no_se_pisa_al_sumar(): void
    {
        $this->alta(['nombre' => 'Juan Saccomanno', 'cargo' => 'Jefe de Pañol', 'medios' => []]);
        $this->alta(['nombre' => 'Juan Saccomanno', 'cargo' => 'Otra cosa', 'medios' => []]);

        $this->assertSame('Jefe de Pañol', Contacto::where('empresa_id', $this->empresa->id)->sole()->cargo);
    }

    /** Dos personas distintas siguen siendo dos. */
    public function test_nombres_distintos_no_se_juntan(): void
    {
        $this->alta(['nombre' => 'Juan Saccomanno', 'medios' => []]);
        $this->alta(['nombre' => 'Juan Perez', 'medios' => []]);

        $this->assertSame(2, Contacto::where('empresa_id', $this->empresa->id)->count());
    }

    /**
     * El fax no se pierde al modificar el contacto.
     *
     * El modal armaba la lista con telefonos, WhatsApp y mails, y al guardar
     * se reemplazan todos: el fax no entraba y se borraba. 128 contactos lo
     * tienen. Ahora la ficha manda la lista entera y el modal la devuelve.
     */
    public function test_modificar_un_contacto_no_le_borra_el_fax(): void
    {
        $fax = TipoMedio::firstOrCreate(['nombre' => 'Fax'])->id;
        $contacto = Contacto::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Juan', 'activo' => true]);
        ContactoMedio::create(['contacto_id' => $contacto->id, 'tipo_medio_id' => $this->tipo('Telefono'), 'valor' => '4555-3700', 'activo' => true]);
        ContactoMedio::create(['contacto_id' => $contacto->id, 'tipo_medio_id' => $fax, 'valor' => '4555-3701', 'activo' => true]);

        // Lo que ve el modal al abrirlo: todos los medios, fax incluido.
        $medios = collect($this->getJson("/api/empresas/{$this->empresa->id}")->json('data.contactos.0.medios'));
        $this->assertContains('4555-3701', $medios->pluck('valor'));

        // Y lo que manda al guardar, sin tocar nada.
        $this->putJson("/api/empresas/{$this->empresa->id}/contactos/{$contacto->id}", [
            'nombre' => 'Juan',
            'medios' => $medios->map(fn ($m) => ['tipo_medio_id' => $m['tipo_medio_id'], 'valor' => $m['valor']])->all(),
        ])->assertOk();

        $this->assertTrue($contacto->medios()->where('tipo_medio_id', $fax)->exists());
    }

    /** Un medio dado de baja no se borra al guardar: no llega al modal. */
    public function test_los_medios_de_baja_sobreviven_al_guardar(): void
    {
        $contacto = Contacto::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Juan', 'activo' => true]);
        ContactoMedio::create(['contacto_id' => $contacto->id, 'tipo_medio_id' => $this->tipo('Telefono'), 'valor' => '4555-0000', 'activo' => false]);

        $this->putJson("/api/empresas/{$this->empresa->id}/contactos/{$contacto->id}", ['nombre' => 'Juan', 'medios' => []])
            ->assertOk();

        $this->assertSame(1, $contacto->medios()->where('activo', false)->count());
    }

    /**
     * El pie del mail dice que tipo de telefono es.
     *
     * El rotulo manda: "Cel" es celular, "Telefono" es fijo. Sin rotulo, el 15
     * despues de la caracteristica delata al celular.
     */
    public function test_la_firma_dice_si_el_telefono_es_celular(): void
    {
        $firma = new FirmaDeMail;

        $this->assertSame('Celular', $firma->leer("Juan J. Saccomanno\nCel   1131061795")['tipo_telefono']);
        $this->assertSame('Celular', $firma->leer("Gonzalo Sack\n(2954) 15-584584")['tipo_telefono']);
        $this->assertSame('Telefono', $firma->leer("EMPRESA DGS ANTIPINA\nNOMBRE Cristian Obon\nTELÉFONO 011 4427-9394")['tipo_telefono']);
    }

    /** Leer una firma no cuenta el tipo de telefono como un dato mas. */
    public function test_el_tipo_de_telefono_no_suma_datos_reconocidos(): void
    {
        $this->postJson('/api/empresas/leer-firma', ['texto' => "Juan Perez\nCel 1131061795"])
            ->assertOk()
            ->assertJsonPath('mensaje', '2 datos reconocidos. Revisalos antes de guardar.');
    }

    /**
     * "Web" en como llego se puede guardar.
     *
     * Estaba en la lista que ve la pantalla pero no en la que acepta el
     * guardado: elegirla daba error. Ahora es una sola lista.
     */
    public function test_como_llego_por_la_web_se_guarda(): void
    {
        $consulta = Consulta::create([
            'empresa_id' => $this->empresa->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Borrador',
            'usuario_id' => User::first()->id,
        ]);

        $this->putJson("/api/consultas/{$consulta->id}", [
            'tipo' => 'Cotizacion',
            'fecha' => $consulta->fecha->toDateString(),
            'solicitud_via' => 'Web',
            'lineas' => [['descripcion' => 'Barra', 'cantidad' => 1]],
        ])->assertOk();

        $this->assertSame('Web', $consulta->fresh()->solicitud_via);
    }
}

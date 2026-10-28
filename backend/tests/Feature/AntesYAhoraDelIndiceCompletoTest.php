<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Migracion\OriginalesDelAccess;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Antes y ahora" con el indice completo sumado encima de septiembre.
 *
 * Las 8.048 de septiembre se emparejan con su fila por posicion: entraron
 * primero y juntas. Las que se suman despues entran detras de todo lo que se
 * cargo en el sistema nuevo, y por posicion quedarian al lado de la fila de
 * otra. Se encuentran por la huella. Esto lo fija, como en el servidor: sin
 * los CSV, el "antes" sale de la base.
 */
class AntesYAhoraDelIndiceCompletoTest extends TestCase
{
    use RefreshDatabase;

    private string $storageFalso;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageFalso = sys_get_temp_dir().'/cordes-indice-completo-'.uniqid();

        foreach (['app/migracion', 'framework/views', 'framework/cache', 'logs'] as $sub) {
            mkdir($this->storageFalso.'/'.$sub, 0775, true);
        }

        $this->app->useStoragePath($this->storageFalso);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->storageFalso));
        parent::tearDown();
    }

    /** Septiembre importado y guardado, y una cotizacion cargada despues a mano. */
    private function servidorConSeptiembre(): Consulta
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        $admin = User::factory()->create(['role' => 'Administrador']);

        $this->artisan('importar:sistema-viejo', ['--carpeta' => base_path('tests/Fixtures/migracion'), '--aplicar' => true])
            ->assertSuccessful();

        foreach (['empresas.csv', 'cotizaciones.csv'] as $archivo) {
            foreach (OriginalesDelAccess::delArchivo(base_path("tests/Fixtures/migracion/{$archivo}")) as $i => $fila) {
                DB::table('migracion_originales')->insert([
                    'archivo' => $archivo, 'fila' => $i, 'datos' => json_encode($fila, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        // Las de septiembre entraron antes de la huella.
        Consulta::query()->update(['origen_clave' => null]);

        $aMano = Consulta::create([
            'empresa_id' => Empresa::where('codigo_indice', '101')->value('id'),
            'tipo' => 'Cotizacion', 'fecha' => '2026-10-26', 'estado' => 'Borrador',
            'usuario_id' => $admin->id, 'texto' => 'Cargada a mano en el sistema nuevo', 'validez_dias' => 7,
        ]);

        $this->artisan('importar:sistema-viejo', ['--carpeta' => base_path('tests/Fixtures/migracion-completa'), '--sumar' => true, '--aplicar' => true])
            ->assertSuccessful();

        Sanctum::actingAs($admin);

        return $aMano;
    }

    public function test_la_suma_guarda_la_fila_original_de_lo_que_entra(): void
    {
        $this->servidorConSeptiembre();

        // ACME, Talleres Sur (la ficha hecha a mano que se reconocio),
        // Metales del Sur y Torneria Norte; no las tres que ya estaban.
        $empresas = DB::table('migracion_originales')->where('archivo', 'empresas.csv')->where('fila', '>=', 3)->orderBy('fila')->get();
        $this->assertSame([3, 4, 5, 6], $empresas->pluck('fila')->all(), 'siguen la numeracion de septiembre');
        $this->assertSame(['104', '105', '106', '107'], $empresas->map(fn ($f) => json_decode($f->datos, true)['ID'])->all());

        // Cada cotizacion nueva tiene su fila, con la misma huella.
        $nuevas = Consulta::whereNotNull('origen_clave')->get();
        $this->assertCount(6, $nuevas);

        foreach ($nuevas as $c) {
            $this->assertSame(1, DB::table('migracion_originales')->where('archivo', 'cotizaciones.csv')->where('clave', $c->origen_clave)->count());
        }
    }

    /** Cada cotizacion al lado de SU fila del Access, la de septiembre y la sumada. */
    public function test_cada_cotizacion_queda_al_lado_de_su_original(): void
    {
        $aMano = $this->servidorConSeptiembre();

        $septiembre = Consulta::where('texto', 'like', 'FLETE AEREO%')->first();
        $r = $this->getJson("/api/migracion/comparacion?que=cotizaciones&cotizacion={$septiembre->id}")->assertOk();
        $r->assertJsonPath('total', 1);
        $this->assertStringContainsString('FLETE AEREO', $r->json('filas.0.antes')['Los cinco renglones']);
        $this->assertStringContainsString('FLETE AEREO', $r->json('filas.0.ahora.Articulos'));

        // Entro despues de la cargada a mano: por posicion caeria en otra.
        $sumada = Consulta::where('texto', 'like', 'BARRA INOX 304%')->first();
        $this->assertGreaterThan($aMano->id, $sumada->id);
        $r = $this->getJson("/api/migracion/comparacion?que=cotizaciones&cotizacion={$sumada->id}")->assertOk();
        $r->assertJsonPath('total', 1);
        $this->assertStringContainsString('BARRA INOX 304', $r->json('filas.0.antes')['Los cinco renglones']);
        $this->assertStringContainsString('BARRA INOX 304', $r->json('filas.0.ahora.Articulos'));

        // La cargada a mano no tiene "antes".
        $this->getJson("/api/migracion/comparacion?que=cotizaciones&cotizacion={$aMano->id}")
            ->assertOk()->assertJsonPath('total', 0);
    }

    /** La lista entera: paginada en la base, con el buscador, y cada fila con su par. */
    public function test_la_lista_y_el_buscador(): void
    {
        $this->servidorConSeptiembre();

        $r = $this->getJson('/api/migracion/comparacion?que=cotizaciones')->assertOk();
        $r->assertJsonPath('total', 5 + 6);

        foreach ($r->json('filas') as $fila) {
            $this->assertNotNull($fila['ahora'], 'fila '.$fila['clave'].' sin su cotizacion');
            $this->assertSame($fila['antes']['Empresa'] === 'NUEVA DESDE COTIZACION' ? 'NUEVA DESDE COTIZACION' : $fila['antes']['Empresa'], $fila['ahora']['Empresa'] === 'TALLERES SUR SA' ? 'Talleres Sur S.A.' : $fila['ahora']['Empresa']);
        }

        // Sin importar mayusculas, y con el Ø escrito como se busca.
        $this->getJson('/api/migracion/comparacion?que=cotizaciones&buscar=barra inox')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/migracion/comparacion?que=cotizaciones&buscar='.urlencode('BAR Ø 1.6'))->assertOk()->assertJsonPath('total', 1);

        $r = $this->getJson('/api/migracion/comparacion?que=empresas&buscar=ACME')->assertOk();
        $r->assertJsonPath('total', 1);
        $r->assertJsonPath('filas.0.ahora.Empresa', 'ACME METALES S.R.L.');
    }

    /**
     * Una revision de una cotizacion del indice no le roba el lugar en "Antes y ahora".
     *
     * La R1 se copiaba con la huella de la importacion y la pantalla mostraba
     * la revision —con la fecha de hoy y las lineas cambiadas— al lado de la
     * fila del Access, como si la importacion las hubiera cambiado.
     */
    public function test_la_revision_no_reemplaza_a_la_original(): void
    {
        $this->servidorConSeptiembre();
        $sumada = Consulta::where('texto', 'like', 'BARRA INOX 304%')->first();

        $this->postJson("/api/consultas/{$sumada->id}/revision")->assertSuccessful();
        $revision = Consulta::where('revision_de_id', $sumada->id)->first();
        $this->assertNull($revision->origen_clave, 'la revision no hereda la huella');

        $r = $this->getJson("/api/migracion/comparacion?que=cotizaciones&cotizacion={$sumada->id}")->assertOk();
        $r->assertJsonPath('total', 1);
        $r->assertJsonPath('filas.0.ahora.Fecha', '15-10-2026');
    }

    /** El buscador mira los valores, no los nombres de las columnas. */
    public function test_el_buscador_no_encuentra_los_nombres_de_las_columnas(): void
    {
        $this->servidorConSeptiembre();

        $this->getJson('/api/migracion/comparacion?que=cotizaciones&buscar=fecha')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/migracion/comparacion?que=empresas&buscar=telef')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/migracion/comparacion?que=cotizaciones&buscar=flete')->assertOk()->assertJsonPath('total', 1);
    }
}

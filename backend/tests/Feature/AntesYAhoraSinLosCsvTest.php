<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\User;
use App\Services\Migracion\OriginalesDelAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La pantalla "Antes y ahora" fuera de la maquina donde se importo.
 *
 * El lado "antes" salia de los CSV del Access en storage/app/migracion/. Esos
 * archivos no se versionan ni se copian al servidor —son datos de clientes—,
 * asi que puesto el sistema en un servidor la pantalla mostraba la mitad
 * derecha y nada a la izquierda: justo la comparacion que CORDES tiene que
 * hacer para dar el visto bueno.
 *
 * Aca se prueba con la carpeta vacia, que es la situacion del servidor.
 */
class AntesYAhoraSinLosCsvTest extends TestCase
{
    use RefreshDatabase;

    private string $storageFalso;

    protected function setUp(): void
    {
        parent::setUp();

        /*
          Una carpeta de storage propia y vacia.

          En la maquina donde se corren estos tests los CSV de verdad estan
          ahi, asi que sin esto el test leeria el archivo y no probaria nada.
        */
        $this->storageFalso = sys_get_temp_dir().'/cordes-antes-y-ahora-'.uniqid();

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

    /** Una fila del Access guardada en la base, como la deja el comando. */
    private function guardar(string $archivo, int $fila, array $datos): void
    {
        DB::table('migracion_originales')->insert([
            'archivo' => $archivo,
            'fila' => $fila,
            'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function comoAdministrador(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));
    }

    /**
     * Sin los CSV, el "antes" sale igual — de la base.
     *
     * Y sale crudo: el telefono con la mascara vacia y el texto tal cual lo
     * escribia el Access. Limpiarlo seria mentir sobre el punto de partida.
     */
    public function test_sin_los_csv_el_antes_sale_de_la_base(): void
    {
        $this->assertSame([], glob($this->storageFalso.'/app/migracion/*.csv'));

        $this->guardar('empresas.csv', 0, [
            'EMPRESA' => 'FERRUM S.A.', 'RESPONS' => 'Juan Perez',
            'DIRECCION' => 'Av. Mitre 750', 'LOCALIDAD' => 'AVELLANEDA',
            'CODPOSTAL' => '1870', 'PROVINCIA' => 'BUENOS AIRES', 'PAIS' => '',
            'TELEF1' => '4201-5000', 'TELEF2' => '-    -2520', 'TELEF3' => '',
            'TELEF4' => '', 'TELEF5' => '', 'FAX' => '', 'TELEX' => '',
            'TELEDISC' => '011', 'OBSERV' => '', 'HORARIOS' => '',
            'RUBRO' => 'SANITARIOS', 'RELAC' => 'C', 'ID' => '101',
        ]);

        Empresa::create(['nombre' => 'FERRUM S.A.', 'codigo_indice' => '101']);

        $this->comoAdministrador();

        $r = $this->getJson('/api/migracion/comparacion?que=empresas');

        $r->assertOk();
        $r->assertJsonPath('total', 1);
        $r->assertJsonPath('filas.0.clave', '101');
        $r->assertJsonPath('filas.0.antes.Empresa', 'FERRUM S.A.');
        // La mascara sin numero viaja tal cual: es el dato de partida.
        $this->assertStringContainsString('-    -2520', $r->json('filas.0.antes.Telefonos'));
    }

    /**
     * El numero de fila se conserva.
     *
     * Es lo unico que empareja cada cotizacion con su original: las del Access
     * no traen clave propia. Si al leer de la base se reindexara desde cero,
     * las comparaciones se correrian una fila y cada cotizacion apareceria al
     * lado del original de otra.
     */
    public function test_el_numero_de_fila_se_conserva(): void
    {
        foreach ([0 => 'PRIMERA', 5 => 'SEXTA', 9 => 'DECIMA'] as $i => $nombre) {
            $this->guardar('cotizaciones.csv', $i, ['EMPRESA' => $nombre, 'ID' => '1']);
        }

        $leidas = OriginalesDelAccess::deLaBase('cotizaciones.csv');

        $this->assertSame([0, 5, 9], array_keys($leidas));
        $this->assertSame('SEXTA', $leidas[5]['EMPRESA']);
    }

    /** Un archivo que nadie guardo no rompe: devuelve vacio, como antes. */
    public function test_sin_archivo_y_sin_filas_no_rompe(): void
    {
        $this->comoAdministrador();

        $r = $this->getJson('/api/migracion/comparacion?que=materiales');

        $r->assertOk();
        $r->assertJsonPath('total', 0);
        $r->assertJsonPath('filas', []);
    }
}

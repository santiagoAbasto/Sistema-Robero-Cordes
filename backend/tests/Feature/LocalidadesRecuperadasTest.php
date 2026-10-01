<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Localidad;
use App\Models\Pais;
use App\Models\Provincia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Las 281 localidades que se perdieron con la limpieza de provincias.
 *
 * Se recuperan del indice viejo guardado en la base: la que coincide con una
 * sola localidad cargada se pone; la que no, queda escrita para elegirla.
 */
class LocalidadesRecuperadasTest extends TestCase
{
    use RefreshDatabase;

    private function fichaVieja(int $id, string $localidad, string $provincia = ''): void
    {
        DB::table('migracion_originales')->insert([
            'archivo' => 'empresas.csv',
            'fila' => $id,
            'datos' => json_encode(['ID' => $id, 'LOCALIDAD' => $localidad, 'PROVINCIA' => $provincia]),
        ]);
    }

    private function recuperar(): void
    {
        (require database_path('migrations/2026_10_01_000200_localidades_recuperadas.php'))->up();
    }

    public function test_recupera_la_localidad_sin_adivinar(): void
    {
        $argentina = Pais::create(['nombre' => 'Argentina']);
        $bsas = Provincia::create(['nombre' => 'Buenos Aires', 'pais_id' => $argentina->id]);
        $cordoba = Provincia::create(['nombre' => 'Córdoba', 'pais_id' => $argentina->id]);
        $lanus = Localidad::create(['nombre' => 'LANUS', 'provincia_id' => $bsas->id]);
        Localidad::create(['nombre' => 'Capital', 'provincia_id' => $bsas->id]);
        Localidad::create(['nombre' => 'Capital', 'provincia_id' => $cordoba->id]);

        $conUna = Empresa::create(['nombre' => 'A', 'codigo_indice' => '10']);
        $conVarias = Empresa::create(['nombre' => 'B', 'codigo_indice' => '11', 'observacion_general' => 'Atiende de mañana']);
        $conNinguna = Empresa::create(['nombre' => 'C', 'codigo_indice' => '12']);
        $yaCargada = Empresa::create(['nombre' => 'D', 'codigo_indice' => '13', 'localidad_id' => $lanus->id]);

        $this->fichaVieja(10, 'Lanús');
        $this->fichaVieja(11, 'CAPITAL');
        $this->fichaVieja(12, 'MORON');
        $this->fichaVieja(13, 'MORON');

        $this->recuperar();
        $this->recuperar(); // dos veces no repite la nota

        $this->assertSame($lanus->id, $conUna->fresh()->localidad_id);
        $this->assertSame($bsas->id, $conUna->fresh()->provincia_id, 'la provincia sale de la localidad');

        $this->assertNull($conVarias->fresh()->localidad_id, 'CAPITAL hay en dos provincias: no se adivina');
        $this->assertSame(
            "Localidad en el indice viejo: CAPITAL (falta elegirla en Modificar).\nAtiende de mañana",
            $conVarias->fresh()->observacion_general,
        );

        $this->assertNull($conNinguna->fresh()->localidad_id);
        $this->assertStringContainsString('MORON', $conNinguna->fresh()->observacion_general);

        $this->assertSame($lanus->id, $yaCargada->fresh()->localidad_id, 'lo cargado a mano no se toca');
        $this->assertNull($yaCargada->fresh()->observacion_general);
    }
}

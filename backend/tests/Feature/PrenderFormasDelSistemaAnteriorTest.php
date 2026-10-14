<?php

namespace Tests\Feature;

use App\Models\Forma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las formas del sistema anterior, prendidas (14-10-2026).
 *
 * Nacieron apagadas al importar el Access y en las pruebas faltaban las de
 * todos los dias, como el CODO 90°. Se prenden todas las que vinieron del
 * Access, menos las que en su propio nombre dicen que no se usan o remiten a
 * otra.
 */
class PrenderFormasDelSistemaAnteriorTest extends TestCase
{
    use RefreshDatabase;

    private function forma(string $nombre, ?string $origen, bool $activo = false): Forma
    {
        return Forma::create([
            'nombre' => $nombre,
            'clave' => Forma::claveDesde($nombre),
            'origen_id' => $origen,
            'activo' => $activo,
        ]);
    }

    private function migrar(): void
    {
        (require database_path('migrations/2026_10_14_000100_prender_las_formas_del_sistema_anterior.php'))->up();
    }

    public function test_prende_las_del_access_menos_las_que_dicen_que_no_se_usan(): void
    {
        $codo = $this->forma('CODO 90°', '41');
        $cupla = $this->forma('1/2 CUPLA', '7');
        $noUsar = $this->forma('CINTA NO USAR', '88');
        $manguito = $this->forma('MANGUITO - USAR COLLAR', '90');
        $tapa = $this->forma('TAPA (VER CASQUETE)', '95');
        // Creada en la pantalla, no viene del Access: no se toca.
        $propia = $this->forma('PIEZA DE PRUEBA', null);

        $this->migrar();

        $this->assertTrue($codo->fresh()->activo);
        $this->assertTrue($cupla->fresh()->activo);
        $this->assertFalse($noUsar->fresh()->activo);
        $this->assertFalse($manguito->fresh()->activo);
        $this->assertFalse($tapa->fresh()->activo);
        $this->assertFalse($propia->fresh()->activo);
    }
}

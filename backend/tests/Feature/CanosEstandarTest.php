<?php

namespace Tests\Feature;

use App\Models\CanoEstandar;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La tabla de caños comerciales.
 *
 * Las paredes salen de una tabla ANSI que CORDES mando escaneada: es una
 * imagen, no tiene texto, asi que los numeros se transcribieron a ojo. Un
 * digito mal en una pared es una cotizacion con el peso equivocado, y nadie lo
 * notaria mirando la pantalla.
 *
 * Por eso cada fila viaja con el kg/m que trae impreso la tabla, y esta prueba
 * lo recalcula desde el diametro y la pared. Si no coinciden, alguien
 * transcribio mal.
 */
class CanosEstandarTest extends TestCase
{
    use RefreshDatabase;

    /** Las columnas de inoxidable de la tabla estan calculadas a otra densidad. */
    private const B36_19 = ['5S', '10S', '40S', '80S'];

    private const ACERO = 7.85;

    private const INOX = 7.97;

    /**
     * Una celda de la tabla original no cierra con las demas.
     *
     * 8" 10S dice pared 3,80 y 20,12 kg/m. Con 3,80 y densidad de inoxidable da
     * 20,48. El 20,12 sale de calcular esa fila a densidad de acero al carbono:
     * es un desprolijo de la tabla impresa, no de la transcripcion — se releyo
     * la imagen ampliada y dice 3,80 y 20,12.
     *
     * Lo que usa el sistema es la pared, que es correcta. El kg/m impreso no se
     * guarda en ningun lado.
     */
    private const DESPROLIJO = ['8"' => ['10S']];

    private function tabla(): array
    {
        return require database_path('seeders/datos/canos_ansi.php');
    }

    public function test_cada_pared_reproduce_el_peso_impreso_en_la_tabla(): void
    {
        $revisadas = 0;

        foreach ($this->tabla() as $medida => [$exterior, $schedules]) {
            foreach ($schedules as $schedule => [$pared, $kgm]) {
                if (in_array($schedule, self::DESPROLIJO[$medida] ?? [], true)) {
                    continue;
                }

                $densidad = in_array($schedule, self::B36_19, true) ? self::INOX : self::ACERO;

                // Un metro de caño: area de la corona por 1000 mm de largo.
                $calculado = M_PI * $pared * ($exterior - $pared) * $densidad / 1000;

                $this->assertEqualsWithDelta(
                    $kgm,
                    $calculado,
                    $kgm * 0.01,
                    "El caño {$medida} SCH {$schedule} no cierra: con pared {$pared} y diametro "
                    ."{$exterior} da ".round($calculado, 2)." kg/m, pero la tabla dice {$kgm}.",
                );

                $revisadas++;
            }
        }

        // Si alguien recorta la tabla sin querer, la prueba seguiria en verde
        // revisando cuatro filas. El numero tiene que estar a la vista.
        $this->assertSame(323, $revisadas);
    }

    public function test_estan_los_schedules_que_pidio_la_empresa(): void
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);

        $schedules = CanoEstandar::where('activo', true)->distinct()->pluck('schedule')->all();

        // Los tres que faltaban y motivaron la consulta.
        foreach (['5S', '10S', '160'] as $pedido) {
            $this->assertContains($pedido, $schedules);
        }

        $this->assertCount(17, $schedules);
    }

    public function test_el_cano_de_dos_pulgadas_tiene_sus_cuatro_inoxidables(): void
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);

        $dosPulgadas = CanoEstandar::where('nombre', '2"')->where('activo', true)
            ->pluck('pared_mm', 'schedule');

        $this->assertEqualsWithDelta(1.65, (float) $dosPulgadas['5S'], 0.001);
        $this->assertEqualsWithDelta(2.77, (float) $dosPulgadas['10S'], 0.001);
        $this->assertEqualsWithDelta(3.91, (float) $dosPulgadas['40S'], 0.001);
        $this->assertEqualsWithDelta(5.54, (float) $dosPulgadas['80S'], 0.001);
        // El diametro exterior no cambia con el schedule.
        $this->assertEqualsWithDelta(
            60.3,
            (float) CanoEstandar::where('nombre', '2"')->first()->diametro_mm,
            0.01,
        );
    }

    /** Los nombres viejos no pueden convivir con los nuevos. */
    public function test_no_quedan_dos_nombres_para_el_mismo_cano(): void
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);

        $conPunto = CanoEstandar::where('activo', true)
            ->where('nombre', 'like', '%.%/%')->count();

        $this->assertSame(0, $conPunto, 'Quedaron caños con el nombre viejo (1.1/4") en la lista.');
    }
}

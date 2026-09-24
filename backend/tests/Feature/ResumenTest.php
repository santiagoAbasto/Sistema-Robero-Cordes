<?php

namespace Tests\Feature;

use App\Http\Controllers\ResumenController;
use App\Models\Consulta;
use App\Models\Empresa;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\IndiceTelefonicoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La pantalla de inicio no puede mostrar numeros inventados.
 *
 * Es lo primero que se ve al entrar: si dice mil empresas y hay diez, en una
 * demostracion nadie lo nota y se toma por cierto.
 */
class ResumenTest extends TestCase
{
    use RefreshDatabase;

    private function resumen(): array
    {
        return app(ResumenController::class)->index()->getData(true);
    }

    public function test_los_totales_son_los_de_la_base(): void
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(IndiceTelefonicoSeeder::class);

        $resumen = $this->resumen();

        $this->assertSame(Empresa::activas()->count(), $resumen['totales']['empresas']);
        $this->assertSame(
            Empresa::activas()->conRelacion('Cliente')->count(),
            $resumen['totales']['clientes']
        );
        $this->assertSame(
            Empresa::activas()->conRelacion('Proveedor')->count(),
            $resumen['totales']['proveedores']
        );
    }

    public function test_el_total_de_cada_cotizacion_no_da_cero(): void
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(IndiceTelefonicoSeeder::class);

        $resumen = $this->resumen();

        $this->assertNotEmpty($resumen['ultimas']);

        // El modelo devuelve cero si no le pidieron las lineas. Que una
        // cotizacion con lineas muestre cero seria ese olvido, no un dato.
        foreach ($resumen['ultimas'] as $u) {
            $conLineas = Consulta::find($u['id'])->lineas()->where('quitada', false)->count();

            if ($conLineas > 0) {
                $this->assertGreaterThan(0, $u['total'], "La cotizacion {$u['id']} da total cero.");
            }
        }
    }

    public function test_sin_datos_no_inventa_nada(): void
    {
        $this->seed(CatalogosSeeder::class);

        $resumen = $this->resumen();

        $this->assertSame(0, $resumen['totales']['empresas']);
        $this->assertSame(0, $resumen['totales']['cotizaciones_mes']);
        $this->assertSame([], $resumen['ultimas']);
        $this->assertSame([], $resumen['materiales']);
    }
}

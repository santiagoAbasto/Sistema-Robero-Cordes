<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El enlace del material y la forma se deduce solo en las lineas historicas.
 *
 * Una linea cargada en el sistema nuevo sin material la dejo asi una persona:
 * correr el enlazado despues de sumar el indice no le tiene que poner nada.
 */
class EnlazarLineasTest extends TestCase
{
    use RefreshDatabase;

    private function linea(string $nota): ConsultaLinea
    {
        $consulta = Consulta::create([
            'empresa_id' => Empresa::firstOrCreate(['nombre' => 'FERRUM'], ['activa' => true])->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-07-24',
            'estado' => 'Confirmada',
            'usuario_id' => User::first()->id,
            'nota' => $nota,
            'validez_dias' => 0,
        ]);

        return ConsultaLinea::create([
            'consulta_id' => $consulta->id,
            'orden' => 1,
            'descripcion' => 'INCONEL 600 BARRA 25 MM',
            'precio_unitario' => 10,
            'igual_a_lo_pedido' => true,
        ]);
    }

    public function test_solo_enlaza_las_lineas_historicas(): void
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        User::factory()->create();
        $inconel = Material::where('nombre', 'INCONEL 600')->firstOrFail();

        $historica = $this->linea('Importada del sistema anterior.');
        $nueva = $this->linea('Cargada en el sistema nuevo');

        $this->artisan('lineas:enlazar', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame($inconel->id, $historica->fresh()->material_id);
        $this->assertNull($nueva->fresh()->material_id);
    }
}

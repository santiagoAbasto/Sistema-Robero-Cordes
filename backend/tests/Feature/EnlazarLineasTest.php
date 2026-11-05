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

    /**
     * Con --solo-sumadas no se tocan las de septiembre ni las copias.
     *
     * Las de septiembre ya las vio el cliente como quedaron, y una copia o una
     * revision hereda la nota pero tiene lineas que escribio una persona.
     */
    public function test_solo_sumadas_no_toca_septiembre_ni_las_copias(): void
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        User::factory()->create();
        $inconel = Material::where('nombre', 'INCONEL 600')->firstOrFail();

        $septiembre = $this->linea('Importada del sistema anterior.');
        $sumada = $this->linea('Importada del sistema anterior.');
        $sumada->consulta->update(['origen_clave' => md5('sumada')]);
        $copia = $this->linea('Importada del sistema anterior.');
        $copia->consulta->update(['origen_clave' => md5('copia'), 'copiada_de_id' => $sumada->consulta_id]);

        $this->artisan('lineas:enlazar', ['--aplicar' => true, '--solo-sumadas' => true])->assertSuccessful();

        $this->assertSame($inconel->id, $sumada->fresh()->material_id);
        $this->assertNull($septiembre->fresh()->material_id);
        $this->assertNull($copia->fresh()->material_id);
    }

    /**
     * La muestra es al azar entre TODO lo que se escribiria, no las primeras.
     *
     * Las primeras son las cotizaciones mas viejas y parecen todas iguales: un error que vive en el
     * medio de la tabla no se ve nunca.
     */
    public function test_la_muestra_es_de_lo_que_se_escribiria_y_dice_cuantas(): void
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        User::factory()->create();

        foreach (range(1, 5) as $_) {
            $this->linea('Importada del sistema anterior.');
        }

        $this->artisan('lineas:enlazar', ['--muestra' => 3])
            ->expectsOutputToContain('Muestra al azar de lo que se escribiria (3 de 5)')
            ->expectsOutputToContain('No se escribio nada.')
            ->assertSuccessful();

        // En seco: no escribio.
        $this->assertSame(0, ConsultaLinea::whereNotNull('material_id')->count());
    }
}

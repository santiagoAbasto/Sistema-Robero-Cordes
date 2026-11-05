<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Forma;
use App\Models\HistorialCambio;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * La muestra dice que medidas se escribirian: es lo que hay que mirar con los datos reales.
     *
     * Las medidas eran donde estaban los errores mas graves, y la muestra no las mostraba.
     */
    public function test_la_muestra_muestra_las_medidas_que_se_escribirian(): void
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        User::factory()->create();

        $this->linea('Importada del sistema anterior.')->update(['descripcion' => 'INCONEL 600 TUBO 25.4 X 1.65 X 1000 MM, C/U']);

        $this->artisan('lineas:enlazar', ['--muestra' => 5])
            // Una sola expectativa: cada una consume una linea de salida.
            ->expectsOutputToContain('material: INCONEL 600 · forma: TUBO · medidas: diam 25.4 · esp 1.65 · largo 1000')
            ->assertSuccessful();

        $this->assertNull(ConsultaLinea::first()->diametro_mm);
    }

    private function lineaConMaterial(string $nota, string $descripcion, Material $material): ConsultaLinea
    {
        $linea = $this->linea($nota);
        $linea->update(['descripcion' => $descripcion]);
        DB::table('consulta_lineas')->where('id', $linea->id)->update(['material_id' => $material->id]);

        return $linea->fresh();
    }

    /**
     * Los enlaces escritos cuando "cobre" y "plata" eran ruido se corrigen, y solo esos.
     *
     * "COBRE TUNGSTENO 20/80" habia quedado en "Tungsteno" y "CROMO PIEDRAS" en "Cobre Cromo". Lo que una persona
     * toco no se pisa, lo que esta bien no se mueve, y un archivo guarda como estaba cada linea.
     */
    public function test_corregir_materiales_vacia_lo_que_las_reglas_de_hoy_no_sostienen(): void
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        User::factory()->create();

        // Las formas que contienen "cobre" y "plata": por ellas eran ruido.
        Forma::firstOrCreate(['nombre' => 'ALAMBRE CON ALMA DE COBRE']);
        Forma::firstOrCreate(['nombre' => 'FUNDENTE SOLD. PLATA']);

        $tungsteno = Material::firstOrCreate(['nombre' => 'Tungsteno'], ['activo' => true]);
        $tungstenoPlata = Material::firstOrCreate(['nombre' => 'Tungsteno Plata 70/30'], ['activo' => true]);
        $cobreCromo = Material::firstOrCreate(['nombre' => 'Cobre Cromo'], ['activo' => true]);
        Material::firstOrCreate(['nombre' => 'Cobre'], ['activo' => true]);
        Material::firstOrCreate(['nombre' => 'Plata'], ['activo' => true]);

        $historica = 'Importada del sistema anterior.';
        $mal = $this->lineaConMaterial($historica, 'COBRE TUNGSTENO 20/80 BARRA 6.5 X 500 MM C/U (+IVA)', $tungsteno);
        $malPlata = $this->lineaConMaterial($historica, 'COBRE TUNGSTENO 70/30 BARRA 30 X 65 MM C/U (+IVA)', $tungstenoPlata);
        $malCromo = $this->lineaConMaterial($historica, 'CROMO PIEDRAS P/KG(+IVA)', $cobreCromo);
        $bien = $this->lineaConMaterial($historica, 'TUNGSTENO PLATA 70/30 BARRA 30 X 65 MM C/U (+IVA)', $tungstenoPlata);
        $deUnaPersona = $this->lineaConMaterial($historica, 'CROMO METALICO P/KG(+IVA)', $cobreCromo);
        HistorialCambio::create([
            'tabla' => 'consulta_lineas', 'registro_id' => $deUnaPersona->id, 'campo' => 'material_id',
            'valor_anterior' => null, 'valor_nuevo' => (string) $cobreCromo->id, 'accion' => 'Modificacion', 'fecha' => now(),
        ]);
        $nueva = $this->lineaConMaterial('Cargada en el sistema nuevo', 'COBRE TUNGSTENO 20/80 BARRA 6.5 X 500 MM C/U (+IVA)', $tungsteno);

        // En seco: dice que cambiaria, y no escribe.
        $this->artisan('lineas:enlazar', ['--corregir-materiales' => true])
            ->expectsOutputToContain('Tungsteno → sin material')
            ->expectsOutputToContain('No se escribio nada.')
            ->assertSuccessful();

        $this->assertSame($tungsteno->id, $mal->fresh()->material_id);
        $this->assertSame($cobreCromo->id, $malCromo->fresh()->material_id);

        $yaEstaban = glob(storage_path('app/materiales-corregidos-*.csv'));

        $this->artisan('lineas:enlazar', ['--corregir-materiales' => true, '--aplicar' => true])->assertSuccessful();

        // Se corrigen las tres mal enlazadas...
        $this->assertNull($mal->fresh()->material_id);
        $this->assertNull($malPlata->fresh()->material_id);
        $this->assertNull($malCromo->fresh()->material_id);
        // ...y no se mueve ni lo que esta bien, ni lo que toco una persona, ni lo del sistema nuevo.
        $this->assertSame($tungstenoPlata->id, $bien->fresh()->material_id);
        $this->assertSame($cobreCromo->id, $deUnaPersona->fresh()->material_id);
        $this->assertSame($tungsteno->id, $nueva->fresh()->material_id);

        // Nada se borra: las lineas siguen, y un archivo guarda como estaba cada una.
        $this->assertSame(6, ConsultaLinea::count());
        // Solo el archivo de esta corrida: nunca uno que ya existiera.
        $archivos = array_values(array_diff(glob(storage_path('app/materiales-corregidos-*.csv')), $yaEstaban));
        $this->assertCount(1, $archivos);
        $csv = (string) file_get_contents($archivos[0]);
        $this->assertStringContainsString('COBRE TUNGSTENO 20/80', $csv);
        $this->assertStringContainsString('CROMO PIEDRAS', $csv);
        $this->assertStringNotContainsString('CROMO METALICO', $csv);
        array_map('unlink', $archivos);
    }

    /**
     * Un material marcado "NO USAR" en el catalogo no recibe enlaces nuevos, y los que ya tenia se sueltan.
     *
     * Quedaron lineas enlazadas a "Acero Duplex 2507 NO USAR" porque el enlazador elegia por las palabras y no
     * leia el aviso del nombre.
     */
    public function test_no_se_enlaza_a_un_material_marcado_no_usar_y_se_sueltan_los_que_ya_tenia(): void
    {
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        User::factory()->create();

        // Como en el catalogo real: hay formas con "no" y "usar", asi que esas palabras son ruido.
        Forma::firstOrCreate(['nombre' => 'NO USAR']);
        $marcado = Material::create(['nombre' => 'Acero Duplex 2507 NO USAR', 'activo' => true]);
        $historica = 'Importada del sistema anterior.';

        $vieja = $this->lineaConMaterial($historica, 'ACERO SUPER DUPLEX 2507 BARRA Ø 101.6 X 600 MM, X C/U', $marcado);
        $vacia = $this->linea($historica);
        $vacia->update(['descripcion' => 'ACERO SUPER DUPLEX 2507 BARRA Ø 127 X 200 MM, X C/U']);

        // Una linea sin material no se enlaza al marcado.
        $this->artisan('lineas:enlazar', ['--aplicar' => true])->assertSuccessful();
        $this->assertNull($vacia->fresh()->material_id);

        // La que ya lo tenia se suelta, y queda dicho.
        $this->artisan('lineas:enlazar', ['--corregir-materiales' => true])
            ->expectsOutputToContain('Acero Duplex 2507 NO USAR → sin material')
            ->assertSuccessful();
        $this->assertSame($marcado->id, $vieja->fresh()->material_id);

        $yaEstaban = glob(storage_path('app/materiales-corregidos-*.csv'));
        $this->artisan('lineas:enlazar', ['--corregir-materiales' => true, '--aplicar' => true])->assertSuccessful();
        $this->assertNull($vieja->fresh()->material_id);
        array_map('unlink', array_diff(glob(storage_path('app/materiales-corregidos-*.csv')), $yaEstaban));
    }
}

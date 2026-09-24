<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\User;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seguridad del guardado de lineas por id.
 *
 * Desde que la linea se actualiza en vez de recrearse, el id que llega del
 * navegador dice a que fila de la base tocar. Un id ajeno o repetido tiene que
 * rebotar: si se ignorara en silencio, la cotizacion terminaria con una linea
 * duplicada o con una menos, y nadie se enteraria hasta que el cliente compare
 * la hoja contra lo que pidio.
 */
class GuardadoDeLineasTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Consulta $propia;

    private Consulta $ajena;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        $this->usuario = User::factory()->create();
        Sanctum::actingAs($this->usuario);

        $this->propia = $this->cotizacionCon('CLIENTE A', ['Barra 1', 'Barra 2']);
        $this->ajena = $this->cotizacionCon('CLIENTE B', ['Tubo de otro cliente']);
    }

    private function cotizacionCon(string $empresa, array $descripciones): Consulta
    {
        $consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => $empresa])->id,
            'tipo' => 'Cotizacion',
            'fecha' => now()->toDateString(),
            'estado' => 'Borrador',
            'usuario_id' => $this->usuario->id,
        ]);

        foreach ($descripciones as $i => $texto) {
            ConsultaLinea::create([
                'consulta_id' => $consulta->id,
                'orden' => $i + 1,
                'descripcion' => $texto,
                'cantidad' => 1,
                'precio_unitario' => 100,
                'importe' => 100,
            ]);
        }

        return $consulta->fresh('lineas');
    }

    private function guardar(Consulta $consulta, array $lineas)
    {
        return $this->putJson("/api/consultas/{$consulta->id}", [
            'tipo' => 'Cotizacion',
            'fecha' => $consulta->fecha->toDateString(),
            'lineas' => $lineas,
        ]);
    }

    /** 1 y 2 · el id tiene que ser de esta cotizacion. */
    public function test_no_se_puede_actualizar_una_linea_de_otra_cotizacion(): void
    {
        $ajena = $this->ajena->lineas->first();
        $textoOriginal = $ajena->descripcion;

        $r = $this->guardar($this->propia, [[
            'id' => $ajena->id,
            'descripcion' => 'ROBADA',
            'cantidad' => 99,
            'precio_unitario' => 1,
        ]]);

        $r->assertStatus(422);
        // La clave dice cual linea rebota, no un error generico de la cotizacion.
        $r->assertJsonValidationErrors(['lineas.0.id']);
        $this->assertSame(
            'Una de las lineas no es de esta cotizacion.',
            $r->json('errors')['lineas.0.id'][0],
        );

        // Ni se movio ni se modifico.
        $ajena->refresh();
        $this->assertSame($textoOriginal, $ajena->descripcion);
        $this->assertSame($this->ajena->id, $ajena->consulta_id);

        // Y la cotizacion propia quedo intacta.
        $this->assertSame(2, $this->propia->lineas()->count());
    }

    /** 2 · tampoco se crea una copia sin avisar. */
    public function test_un_id_ajeno_no_termina_creando_una_linea_nueva(): void
    {
        $antes = ConsultaLinea::count();

        $this->guardar($this->propia, [[
            'id' => $this->ajena->lineas->first()->id,
            'descripcion' => 'ROBADA',
        ]])->assertStatus(422);

        $this->assertSame($antes, ConsultaLinea::count());
    }

    /** 3 · el mismo id dos veces en el mismo pedido. */
    public function test_no_se_aceptan_dos_lineas_con_el_mismo_id(): void
    {
        $linea = $this->propia->lineas->first();

        $r = $this->guardar($this->propia, [
            ['id' => $linea->id, 'descripcion' => 'Primera'],
            ['id' => $linea->id, 'descripcion' => 'Segunda'],
        ]);

        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['lineas']);
        $this->assertSame(
            'Llegaron dos lineas con el mismo numero. Volvé a abrir la cotizacion y guardala de nuevo.',
            $r->json('errors')['lineas'][0],
        );

        // Nada se toco: siguen las dos originales.
        $this->assertSame(2, $this->propia->lineas()->count());
        $this->assertSame('Barra 1', $linea->fresh()->descripcion);
    }

    /** 4 · omitir una linea la borra, pero solo dentro de esta cotizacion. */
    public function test_omitir_una_linea_la_borra_sin_tocar_las_de_otras(): void
    {
        $queda = $this->propia->lineas->first();
        $seVa = $this->propia->lineas->last();
        $deLaOtra = $this->ajena->lineas->first();

        $this->guardar($this->propia, [[
            'id' => $queda->id,
            'descripcion' => 'Barra 1',
            'cantidad' => 1,
            'precio_unitario' => 100,
        ]])->assertOk();

        $this->assertNull(ConsultaLinea::find($seVa->id));
        $this->assertNotNull(ConsultaLinea::find($queda->id));
        // La de la otra cotizacion sigue ahi.
        $this->assertNotNull(ConsultaLinea::find($deLaOtra->id));
    }

    /** 5 y 6 · si algo falla en el medio, no queda nada a medias. */
    public function test_si_falla_una_linea_no_queda_nada_a_medias(): void
    {
        $primera = $this->propia->lineas->first();
        $segunda = $this->propia->lineas->last();

        $r = $this->guardar($this->propia, [
            // Esta se guardaria bien...
            ['id' => $primera->id, 'descripcion' => 'CAMBIADA', 'cantidad' => 5, 'precio_unitario' => 200],
            // ...y esta rompe: la forma no existe.
            ['id' => $segunda->id, 'descripcion' => 'ROMPE', 'forma_id' => 999999],
        ]);

        $r->assertStatus(422);

        // Ni la primera se movio.
        $this->assertSame('Barra 1', $primera->fresh()->descripcion);
        $this->assertSame('Barra 2', $segunda->fresh()->descripcion);
        $this->assertSame(2, $this->propia->lineas()->count());
    }

    /**
     * 6 · rollback tambien cuando el fallo es de la base, no de la validacion.
     *
     * Se rompe adentro de la transaccion, despues de que la primera linea ya
     * se guardo: es el momento en que un guardado a medias haria daño.
     */
    public function test_un_fallo_dentro_de_la_transaccion_deshace_todo(): void
    {
        $primera = $this->propia->lineas->first();
        $cabeceraAntes = $this->propia->nota;

        // Un guardado normal, pero la base falla al llegar a la segunda linea.
        $updates = 0;
        $exploto = false;

        DB::listen(function ($query) use (&$updates, &$exploto) {
            if (! str_contains($query->sql, 'update "consulta_lineas"')
                && ! str_contains($query->sql, 'update `consulta_lineas`')) {
                return;
            }
            if (++$updates === 2) {
                $exploto = true;
                throw new \RuntimeException('se cayo la base');
            }
        });

        try {
            $this->guardar($this->propia, [
                ['id' => $primera->id, 'descripcion' => 'PRIMERA CAMBIADA', 'cantidad' => 5, 'precio_unitario' => 200],
                ['id' => $this->propia->lineas->last()->id, 'descripcion' => 'SEGUNDA CAMBIADA'],
            ]);
        } catch (\Throwable) {
            // Lo que importa es lo que quedo en la base.
        }

        // Sin esto la prueba pasaria sola: si el fallo nunca se disparara, no
        // habria nada que deshacer y las lineas quedarian intactas igual.
        $this->assertTrue($exploto, 'El fallo simulado nunca se disparo: la prueba no probaria nada.');
        $this->assertSame(2, $updates, 'Se corto en la segunda linea, con la primera ya guardada.');

        // Nada cambio: ni las lineas ni la cabecera.
        $this->assertSame('Barra 1', $primera->fresh()->descripcion);
        $this->assertSame('Barra 2', $this->propia->lineas()->orderBy('orden')->get()[1]->descripcion);
        $this->assertSame($cabeceraAntes, $this->propia->fresh()->nota);
        $this->assertSame(2, $this->propia->lineas()->count());
    }

    /** Un guardado normal conserva los ids: no se recrean las lineas. */
    public function test_un_guardado_normal_conserva_los_ids(): void
    {
        $ids = $this->propia->lineas->pluck('id')->all();

        $this->guardar($this->propia, $this->propia->lineas->map(fn ($l) => [
            'id' => $l->id,
            'descripcion' => $l->descripcion,
            'cantidad' => 1,
            'precio_unitario' => 100,
        ])->all())->assertOk();

        $this->assertSame($ids, $this->propia->lineas()->orderBy('orden')->pluck('id')->all());
    }

    /** Una linea nueva convive con las que ya estaban. */
    public function test_se_puede_agregar_una_linea_sin_perder_las_anteriores(): void
    {
        $ids = $this->propia->lineas->pluck('id')->all();

        $this->guardar($this->propia, [
            ...$this->propia->lineas->map(fn ($l) => [
                'id' => $l->id,
                'descripcion' => $l->descripcion,
                'cantidad' => 1,
                'precio_unitario' => 100,
            ])->all(),
            ['descripcion' => 'Barra 3', 'cantidad' => 2, 'precio_unitario' => 50],
        ])->assertOk();

        $ahora = $this->propia->lineas()->orderBy('orden')->get();

        $this->assertCount(3, $ahora);
        $this->assertSame($ids, $ahora->take(2)->pluck('id')->all());
        $this->assertSame('Barra 3', $ahora->last()->descripcion);
    }

    /** 7 · sin permiso para modificar, no se procesa ninguna linea. */
    public function test_sin_permiso_no_se_toca_nada(): void
    {
        $sinPermiso = User::factory()->create(['role' => 'Consulta']);
        Permiso::create(['user_id' => $sinPermiso->id, 'puede_modificar' => false]);
        Sanctum::actingAs($sinPermiso);

        $linea = $this->propia->lineas->first();

        $this->guardar($this->propia, [[
            'id' => $linea->id,
            'descripcion' => 'NO DEBERIA ENTRAR',
        ]])->assertStatus(403);

        $this->assertSame('Barra 1', $linea->fresh()->descripcion);
    }

    public function test_el_administrador_siempre_puede(): void
    {
        $admin = User::factory()->create(['role' => 'Administrador']);
        Permiso::create(['user_id' => $admin->id, 'puede_modificar' => false]);
        Sanctum::actingAs($admin);

        $this->guardar($this->propia, [[
            'id' => $this->propia->lineas->first()->id,
            'descripcion' => 'CAMBIADA POR EL ADMIN',
            'cantidad' => 1,
            'precio_unitario' => 100,
        ]])->assertOk();

        $this->assertSame('CAMBIADA POR EL ADMIN', $this->propia->lineas()->first()->descripcion);
    }

    /**
     * 8 · los campos de auditoria no entran por mass assignment.
     *
     * Se mandan todos juntos: el navegador no puede escribir ninguno.
     */
    public function test_el_navegador_no_puede_escribir_los_campos_de_auditoria(): void
    {
        $otro = User::factory()->create();
        $linea = $this->propia->lineas->first();

        $this->guardar($this->propia, [[
            'id' => $linea->id,
            'descripcion' => 'Barra 1',
            'cantidad' => 1,
            'precio_unitario' => 100,
            // Todo esto lo decide el servidor.
            'peso_kg' => 999999,
            'calculo' => ['peso_total_kg' => 999999],
            'factor_calculado' => true,
            'origen_factor' => 'calculadora',
            'factor_cargado_por' => $otro->id,
            'factor_cargado_el' => '2020-01-01 00:00:00',
            'consulta_id' => $this->ajena->id,
            'importe' => 123456,
        ]])->assertOk();

        $linea->refresh();

        $this->assertNull($linea->peso_kg);
        $this->assertNull($linea->calculo);
        $this->assertNull($linea->origen_factor);
        $this->assertNull($linea->factor_cargado_por);
        $this->assertNull($linea->factor_cargado_el);
        // Y no se la llevaron a la otra cotizacion.
        $this->assertSame($this->propia->id, $linea->consulta_id);
        // El importe lo saca el servidor: 1 x 100.
        $this->assertEqualsWithDelta(100.0, (float) $linea->importe, 0.01);
    }
}

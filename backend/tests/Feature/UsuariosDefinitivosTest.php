<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El paso del usuario de prueba a los definitivos.
 *
 * Lo que se cuida: que nadie pierda sus cotizaciones por cambiar de correo,
 * que la clave de prueba deje de servir —tambien en el navegador que ya estaba
 * logueado— y que una lista con un error no deje la mitad hecha.
 */
class UsuariosDefinitivosTest extends TestCase
{
    use RefreshDatabase;

    private string $lista;

    private string $claves;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lista = tempnam(sys_get_temp_dir(), 'lista');
        $this->claves = tempnam(sys_get_temp_dir(), 'claves');
    }

    protected function tearDown(): void
    {
        @unlink($this->lista);
        @unlink($this->claves);

        parent::tearDown();
    }

    private function lista(string $filas): void
    {
        // Con BOM, como la escribe PowerShell.
        file_put_contents($this->lista, "\xEF\xBB\xBFnombre,correo,rol,antes\n".$filas);
    }

    private function correr(array $bajas = [], bool $aplicar = true)
    {
        return $this->artisan('usuarios:definitivos', array_filter([
            'lista' => $this->lista,
            '--baja' => $bajas,
            '--claves-a' => $this->claves,
            '--aplicar' => $aplicar,
        ]));
    }

    /** @return array<string, string> correo => clave */
    private function clavesEscritas(): array
    {
        $claves = [];

        foreach (file($this->claves, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            [, $correo, $clave] = preg_split('/ {4}/', $linea);
            $claves[$correo] = $clave;
        }

        return $claves;
    }

    /** El de prueba, con la clave que tiene todo el mundo y las cotizaciones de siempre. */
    private function deDemo(): User
    {
        $demo = User::factory()->create([
            'name' => 'Roberto A. Cordes',
            'email' => 'roberto@demo.ar',
            'role' => 'Administrador',
            'activo' => true,
            'password' => Hash::make('la-de-la-demo'),
        ]);

        Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'FERRUM', 'activa' => true])->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-07-24',
            'estado' => 'Confirmada',
            'usuario_id' => $demo->id,
            'validez_dias' => 0,
        ]);

        return $demo;
    }

    public function test_cada_uno_queda_con_su_usuario_y_sus_cotizaciones(): void
    {
        $demo = $this->deDemo();
        $vendedor = User::factory()->create(['email' => 'ricardo@demo.ar', 'role' => 'Vendedor', 'iniciales' => 'RAC']);
        Permiso::create(['user_id' => $vendedor->id, 've_fichas' => 'Solo las suyas', 'puede_modificar' => false]);
        $prueba = User::factory()->create(['email' => 'prueba@demo.ar', 'role' => 'Ventas', 'activo' => true]);

        $this->lista(
            "Roberto Cordes,roberto.cordes@ejemplo.ar,Administrador,roberto@demo.ar\n"
            ."Ricardo Cordes,Ricardo.Cordes@ejemplo.ar,Administrador,ricardo@demo.ar\n"
            ."Lucía Pérez,asistente@ejemplo.ar,Ventas,\n"
        );

        $this->correr(['prueba@demo.ar'])->assertSuccessful();

        // Roberto es el mismo usuario: id y cotizaciones, con el correo nuevo.
        $roberto = $demo->fresh();
        $this->assertSame('roberto.cordes@ejemplo.ar', $roberto->email);
        $this->assertSame('Roberto Cordes', $roberto->name);
        $this->assertSame(1, Consulta::where('usuario_id', $roberto->id)->count());
        $this->assertFalse(Hash::check('la-de-la-demo', $roberto->password), 'la clave de la demo deja de servir');

        // Ricardo conserva sus iniciales y arranca con los permisos de un usuario nuevo.
        $ricardo = $vendedor->fresh();
        $this->assertSame('ricardo.cordes@ejemplo.ar', $ricardo->email);
        $this->assertSame('RAC', $ricardo->iniciales);
        $this->assertSame('Administrador', $ricardo->role);
        $this->assertNull(Permiso::where('user_id', $ricardo->id)->first());

        $lucia = User::where('email', 'asistente@ejemplo.ar')->firstOrFail();
        $this->assertSame('Lucía Pérez', $lucia->name);
        $this->assertTrue($lucia->activo);

        $this->assertFalse($prueba->fresh()->activo, 'el de prueba se da de baja, no se borra');

        // Cada clave escrita es la que entra.
        $claves = $this->clavesEscritas();
        $this->assertCount(3, $claves);

        foreach ($claves as $correo => $clave) {
            $this->assertGreaterThanOrEqual(12, strlen($clave));
            $this->assertTrue(Hash::check($clave, User::where('email', $correo)->value('password')), $correo);
        }
    }

    /** Las claves que tipea el que lo corre: escondidas, repetidas y con el largo minimo. */
    public function test_las_claves_las_pone_quien_lo_corre(): void
    {
        $this->deDemo();
        $this->lista(
            "Roberto Cordes,roberto.cordes@ejemplo.ar,Administrador,roberto@demo.ar\n"
            ."Lucia Perez,asistente@ejemplo.ar,Ventas,\n"
        );

        $this->artisan('usuarios:definitivos', ['lista' => $this->lista, '--pedir-claves' => true, '--aplicar' => true])
            ->expectsQuestion('Clave para Roberto Cordes (roberto.cordes@ejemplo.ar)', 'corta')
            ->expectsOutputToContain('Al menos diez caracteres')
            ->expectsQuestion('Clave para Roberto Cordes (roberto.cordes@ejemplo.ar)', 'la-de-roberto-2026')
            ->expectsQuestion('Repetila', 'la-de-roberto-2026')
            ->expectsQuestion('Clave para Lucia Perez (asistente@ejemplo.ar)', 'la-de-lucia-2026')
            ->expectsQuestion('Repetila', 'otra-distinta-2026')
            ->expectsOutputToContain('Las dos no coinciden')
            ->expectsQuestion('Clave para Lucia Perez (asistente@ejemplo.ar)', 'la-de-lucia-2026')
            ->expectsQuestion('Repetila', 'la-de-lucia-2026')
            ->doesntExpectOutputToContain('la-de-lucia-2026')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('la-de-roberto-2026', User::where('email', 'roberto.cordes@ejemplo.ar')->value('password')));
        $this->assertTrue(Hash::check('la-de-lucia-2026', User::where('email', 'asistente@ejemplo.ar')->value('password')));
    }

    /** El navegador que quedo logueado con la clave de la demo sale. */
    public function test_la_sesion_abierta_con_la_clave_vieja_se_cierra(): void
    {
        $demo = $this->deDemo();
        $demo->createToken('cordes-spa');

        $this->lista("Roberto Cordes,roberto.cordes@ejemplo.ar,Administrador,roberto@demo.ar\n");
        $this->correr()->assertSuccessful();

        $this->assertSame(0, $demo->tokens()->count());
    }

    /**
     * Volver a correr la lista para sumar a alguien no toca a los que ya estaban:
     * ni la clave que ya les mandaron ni lo que se ajusto en "Quien ve que".
     */
    public function test_correrla_otra_vez_no_toca_a_los_que_ya_estaban(): void
    {
        $this->deDemo();
        $this->lista("Roberto Cordes,roberto.cordes@ejemplo.ar,Administrador,roberto@demo.ar\n");
        $this->correr()->assertSuccessful();

        $roberto = User::where('email', 'roberto.cordes@ejemplo.ar')->first();
        $clave = $roberto->password;
        Permiso::create(['user_id' => $roberto->id, 've_fichas' => 'Solo las suyas']);

        $this->lista(
            "Roberto Cordes,roberto.cordes@ejemplo.ar,Administrador,roberto@demo.ar\n"
            ."Lucia Perez,asistente@ejemplo.ar,Ventas,\n"
        );
        $this->correr()->assertSuccessful();

        $this->assertSame(2, User::count());
        $this->assertSame($clave, $roberto->fresh()->password);
        $this->assertNotNull(Permiso::where('user_id', $roberto->id)->first());
        $this->assertSame(['asistente@ejemplo.ar'], array_keys($this->clavesEscritas()));
    }

    /** Un correo "antes" que no existe frena todo: no queda la mitad hecha. */
    public function test_si_algo_no_cierra_no_se_toca_nadie(): void
    {
        $demo = $this->deDemo();

        $this->lista(
            "Roberto Cordes,roberto.cordes@ejemplo.ar,Administrador,roberto@demo.ar\n"
            ."Ricardo Cordes,ricardo.cordes@ejemplo.ar,Administrador,no-existe@demo.ar\n"
        );

        $this->correr()->assertFailed();

        $this->assertSame('roberto@demo.ar', $demo->fresh()->email);
        $this->assertSame(1, User::count());
    }

    /** Dar de baja a alguien que no esta no frena la lista: igual no entra. */
    public function test_la_baja_de_alguien_que_no_esta_no_frena(): void
    {
        $this->deDemo();
        $this->lista("Roberto Cordes,roberto.cordes@ejemplo.ar,Administrador,roberto@demo.ar\n");

        $this->correr(['nadie@demo.ar'])->assertSuccessful();

        $this->assertSame(1, User::where('email', 'roberto.cordes@ejemplo.ar')->count());
    }

    public function test_un_rol_que_no_existe_no_entra(): void
    {
        $this->deDemo();
        $this->lista("Lucia Perez,asistente@ejemplo.ar,Jefa,\n");

        $this->correr()->assertFailed();

        $this->assertNull(User::where('email', 'asistente@ejemplo.ar')->first());
    }

    public function test_no_deja_el_sistema_sin_administrador(): void
    {
        $this->deDemo();
        $this->lista("Lucia Perez,asistente@ejemplo.ar,Ventas,\n");

        $this->correr(['roberto@demo.ar'])->assertFailed();

        $this->assertTrue(User::where('email', 'roberto@demo.ar')->value('activo'));
    }

    public function test_sin_aplicar_no_escribe(): void
    {
        $demo = $this->deDemo();
        $this->lista("Roberto Cordes,roberto.cordes@ejemplo.ar,Administrador,roberto@demo.ar\n");

        $this->correr(aplicar: false)->assertSuccessful();

        $this->assertSame('roberto@demo.ar', $demo->fresh()->email);
        $this->assertTrue(Hash::check('la-de-la-demo', $demo->fresh()->password));
    }
}

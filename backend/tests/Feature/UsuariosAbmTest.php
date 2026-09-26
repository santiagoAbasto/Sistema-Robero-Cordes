<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alta, baja y modificacion de usuarios.
 *
 * Lo que se cuida acá es que nadie se quede afuera por accidente: ni el
 * sistema sin administradores, ni ocho mil cotizaciones sin saber quién las
 * hizo.
 */
class UsuariosAbmTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'Administrador', 'activo' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function nuevo(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Vendedor Nuevo',
            'iniciales' => 'VN',
            'email' => 'nuevo@cordes.com',
            'rol' => 'Vendedor',
            'clave' => 'una-clave-larga',
        ], $cambios);
    }

    public function test_un_administrador_da_de_alta(): void
    {
        $this->admin();

        $this->postJson('/api/usuarios', $this->nuevo())->assertCreated();

        $creado = User::where('email', 'nuevo@cordes.com')->firstOrFail();

        $this->assertSame('Vendedor', $creado->role);
        $this->assertTrue($creado->activo);
        $this->assertTrue(Hash::check('una-clave-larga', $creado->password));
    }

    /** La clave nunca queda corta: el sistema está en una dirección pública. */
    public function test_la_clave_tiene_un_minimo(): void
    {
        $this->admin();

        $this->postJson('/api/usuarios', $this->nuevo(['clave' => 'corta']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('clave');
    }

    public function test_no_se_repite_el_correo(): void
    {
        $this->admin();
        User::factory()->create(['email' => 'nuevo@cordes.com']);

        $this->postJson('/api/usuarios', $this->nuevo())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /** Al modificar, la clave es opcional: cambiarle el rol no la toca. */
    public function test_modificar_sin_clave_no_cambia_la_clave(): void
    {
        $this->admin();
        $otro = User::factory()->create(['role' => 'Vendedor', 'password' => Hash::make('la-de-siempre')]);

        $this->putJson("/api/usuarios/{$otro->id}", [
            'nombre' => $otro->name,
            'email' => $otro->email,
            'rol' => 'Ventas',
        ])->assertOk();

        $otro->refresh();

        $this->assertSame('Ventas', $otro->role);
        $this->assertTrue(Hash::check('la-de-siempre', $otro->password));
    }

    /**
     * La baja desactiva, no borra.
     *
     * Un usuario es el autor de sus cotizaciones y de cada linea del
     * historial: borrarlo dejaria ese trabajo sin duenio.
     */
    public function test_la_baja_desactiva_y_no_borra(): void
    {
        $this->admin();
        $otro = User::factory()->create(['role' => 'Vendedor', 'activo' => true]);

        $this->postJson("/api/usuarios/{$otro->id}/baja")->assertOk();

        $this->assertDatabaseHas('users', ['id' => $otro->id]);
        $this->assertFalse($otro->fresh()->activo);

        // Y se puede volver atrás.
        $this->postJson("/api/usuarios/{$otro->id}/alta")->assertOk();
        $this->assertTrue($otro->fresh()->activo);
    }

    public function test_no_se_puede_dar_de_baja_a_si_mismo(): void
    {
        $admin = $this->admin();

        $this->postJson("/api/usuarios/{$admin->id}/baja")->assertStatus(422);
        $this->assertTrue($admin->fresh()->activo);
    }

    /** Sin administradores activos nadie podria volver a entrar a esta pantalla. */
    public function test_no_se_puede_bajar_al_ultimo_administrador(): void
    {
        $this->admin();
        $otroAdmin = User::factory()->create(['role' => 'Administrador', 'activo' => true]);

        // Con dos, se puede bajar a uno.
        $this->postJson("/api/usuarios/{$otroAdmin->id}/baja")->assertOk();

        // Con uno solo activo —el que está operando— ya no.
        $tercero = User::factory()->create(['role' => 'Administrador', 'activo' => true]);
        Sanctum::actingAs($tercero);

        $quedaUno = User::where('role', 'Administrador')->where('activo', true)->get();
        $this->assertCount(2, $quedaUno);
    }

    public function test_un_vendedor_no_puede_tocar_usuarios(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Vendedor']));

        $this->getJson('/api/usuarios')->assertForbidden();
        $this->postJson('/api/usuarios', $this->nuevo())->assertForbidden();
    }
}

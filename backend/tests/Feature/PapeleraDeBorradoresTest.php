<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\HistorialCambio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La papelera de borradores.
 *
 * "Que puedan sacar los borradores y no hacer basura, pero con la clave del
 * admin, 3 intentos y después una hora, que quede en trazabilidad, y que se
 * puedan restaurar 30 días."
 */
class PapeleraDeBorradoresTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $clave = 'clave-ok'): User
    {
        return User::factory()->create(['role' => 'Administrador', 'password' => $clave]);
    }

    private function borrador(): Consulta
    {
        return Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'CLIENTE '.uniqid()])->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-10-07',
            'estado' => 'Borrador',
            'usuario_id' => User::factory()->create()->id,
        ]);
    }

    public function test_un_admin_con_su_clave_lo_manda_a_la_papelera(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $borrador = $this->borrador();

        $this->deleteJson("/api/consultas/{$borrador->id}", ['password' => 'clave-ok'])->assertOk();

        $this->assertSoftDeleted('consultas', ['id' => $borrador->id, 'eliminada_por' => $admin->id]);
        // Desaparece de las búsquedas normales: el soft-delete lo esconde solo.
        $this->assertNull(Consulta::find($borrador->id));
        // Queda en trazabilidad, con quién lo hizo.
        $this->assertDatabaseHas('historial_cambios', [
            'tabla' => 'consultas', 'registro_id' => $borrador->id,
            'accion' => 'Eliminado', 'usuario_id' => $admin->id,
        ]);
    }

    public function test_la_clave_mal_tres_veces_y_despues_espera(): void
    {
        Sanctum::actingAs($this->admin());
        $borrador = $this->borrador();

        for ($i = 0; $i < 3; $i++) {
            $this->deleteJson("/api/consultas/{$borrador->id}", ['password' => 'mal'])
                ->assertStatus(422);
        }

        // Al cuarto, bloqueado por una hora.
        $this->deleteJson("/api/consultas/{$borrador->id}", ['password' => 'clave-ok'])
            ->assertStatus(429);

        // No se borró nada.
        $this->assertNotNull(Consulta::find($borrador->id));
    }

    public function test_solo_un_administrador_puede_descartar(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Usuario', 'password' => 'clave-ok']));
        $borrador = $this->borrador();

        $this->deleteJson("/api/consultas/{$borrador->id}", ['password' => 'clave-ok'])
            ->assertStatus(403);

        $this->assertNotNull(Consulta::find($borrador->id));
    }

    public function test_una_confirmada_no_se_descarta(): void
    {
        Sanctum::actingAs($this->admin());
        $borrador = $this->borrador();
        $borrador->update(['estado' => 'Confirmada']);

        $this->deleteJson("/api/consultas/{$borrador->id}", ['password' => 'clave-ok'])
            ->assertStatus(422);
    }

    public function test_restaurar_lo_devuelve_y_queda_anotado(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $borrador = $this->borrador();
        $this->deleteJson("/api/consultas/{$borrador->id}", ['password' => 'clave-ok'])->assertOk();

        $this->postJson("/api/consultas/{$borrador->id}/restaurar")->assertOk();

        $this->assertNotNull(Consulta::find($borrador->id));
        $this->assertDatabaseHas('consultas', ['id' => $borrador->id, 'eliminada_por' => null, 'deleted_at' => null]);
        $this->assertDatabaseHas('historial_cambios', [
            'tabla' => 'consultas', 'registro_id' => $borrador->id, 'accion' => 'Restaurado',
        ]);
    }

    public function test_la_papelera_borra_para_siempre_lo_de_mas_de_30_dias(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $viejo = $this->borrador();
        $viejo->forceFill(['eliminada_por' => $admin->id])->save();
        $viejo->delete();
        Consulta::withTrashed()->whereKey($viejo->id)->update(['deleted_at' => now()->subDays(31)]);

        $reciente = $this->borrador();
        $reciente->forceFill(['eliminada_por' => $admin->id])->save();
        $reciente->delete();

        $r = $this->getJson('/api/consultas-eliminados')->assertOk();

        // El viejo se fue de verdad; el reciente sigue, con sus días restantes.
        $this->assertDatabaseMissing('consultas', ['id' => $viejo->id]);
        $ids = collect($r->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($reciente->id));
        $this->assertFalse($ids->contains($viejo->id));
    }
}

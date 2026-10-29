<?php

namespace Tests\Feature;

use App\Mail\CotizacionPorCorreo;
use App\Models\Ajuste;
use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\Impresion;
use App\Models\User;
use App\Services\Correo;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Configuracion > Envio de cotizaciones, y el correo que sale con la hoja.
 *
 * "¿Donde se modifica el texto base?" y "el mail salio sin la cotizacion
 * adjuntada" (29-10-2026): el texto lo escribe la empresa, y con la casilla
 * cargada el correo lo manda el servidor, con la hoja adjunta.
 */
class EnvioDeCotizacionesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ventas;

    private Consulta $consulta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);

        $this->admin = User::factory()->create(['role' => 'Administrador', 'name' => 'Admin Uno', 'email' => 'admin@ejemplo.test']);
        $this->ventas = User::factory()->create(['role' => 'Ventas', 'name' => 'Vendedora Dos', 'email' => 'ventas@ejemplo.test']);

        $this->consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'CLIENTE DE PRUEBA SA'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-10-29',
            'estado' => 'Confirmada',
            'usuario_id' => $this->ventas->id,
            'texto' => 'BARRA REDONDA',
            'validez_dias' => 7,
        ]);
    }

    private function casilla(array $cambios = []): array
    {
        return $cambios + [
            'texto_whatsapp' => Correo::TEXTO_WHATSAPP,
            'texto_correo' => "Hola {empresa}: va {cotizacion}.\n{quien_manda}",
            'host' => 'smtp.ejemplo.test',
            'puerto' => 587,
            'usuario' => 'cotizaciones@ejemplo.test',
            'clave' => 'clave-de-la-casilla',
            'remitente' => 'cotizaciones@ejemplo.test',
            'nombre' => 'La Empresa',
        ];
    }

    public function test_los_textos_vienen_por_defecto_y_solo_un_admin_los_cambia(): void
    {
        Sanctum::actingAs($this->ventas);

        $r = $this->getJson('/api/config/envios')->assertOk();
        $r->assertJsonPath('texto_whatsapp', Correo::TEXTO_WHATSAPP);
        $r->assertJsonPath('correo_listo', false);
        $r->assertJsonPath('es_admin', false);
        $r->assertJsonPath('casilla', null);

        $this->putJson('/api/config/envios', $this->casilla())->assertForbidden();
        $this->postJson('/api/config/envios/prueba')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/config/envios', $this->casilla(['texto_whatsapp' => 'Va {cotizacion}']))->assertOk();

        Sanctum::actingAs($this->ventas);
        $this->getJson('/api/config/envios')
            ->assertJsonPath('texto_whatsapp', 'Va {cotizacion}')
            ->assertJsonPath('correo_listo', true);
    }

    public function test_la_clave_se_guarda_cifrada_y_nunca_vuelve_al_navegador(): void
    {
        Sanctum::actingAs($this->admin);

        $r = $this->putJson('/api/config/envios', $this->casilla())->assertOk();
        $r->assertJsonPath('casilla.tiene_clave', true);
        $this->assertStringNotContainsString('clave-de-la-casilla', $r->getContent());

        $guardada = Ajuste::valor('correo_clave');
        $this->assertNotSame('clave-de-la-casilla', $guardada);
        $this->assertSame('clave-de-la-casilla', Crypt::decryptString($guardada));

        // Vacia, queda la que estaba.
        $this->putJson('/api/config/envios', $this->casilla(['clave' => '']))->assertOk();
        $this->assertSame('clave-de-la-casilla', Correo::clave());

        $this->putJson('/api/config/envios', $this->casilla(['clave' => '', 'borrar_clave' => true]))
            ->assertJsonPath('casilla.tiene_clave', false);
    }

    public function test_sin_casilla_no_se_manda_ni_se_registra(): void
    {
        Sanctum::actingAs($this->ventas);

        $this->postJson("/api/consultas/{$this->consulta->id}/correo", ['para' => 'cliente@ejemplo.test'])
            ->assertStatus(409);

        $this->assertSame(0, Impresion::count());
    }

    public function test_sale_con_la_hoja_adjunta_y_la_respuesta_va_a_quien_la_manda(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/config/envios', $this->casilla())->assertOk();

        Mail::fake();
        Sanctum::actingAs($this->ventas);

        $this->postJson("/api/consultas/{$this->consulta->id}/correo", [
            'para' => 'cliente@ejemplo.test',
            'nombre' => 'Cotización - CLIENTE DE PRUEBA SA - 29-10-2026',
        ])->assertOk()->assertJsonPath('mensaje', fn ($m) => str_contains($m, 'cliente@ejemplo.test'));

        Mail::assertSent(CotizacionPorCorreo::class, function (CotizacionPorCorreo $m) {
            $m->assertHasSubject('Cotización - CLIENTE DE PRUEBA SA - 29-10-2026');
            $m->assertFrom('cotizaciones@ejemplo.test', 'La Empresa');
            $m->assertHasReplyTo('ventas@ejemplo.test');
            $m->assertHasBcc('ventas@ejemplo.test');
            $m->assertSeeInHtml('Hola CLIENTE DE PRUEBA SA: va Cotización - CLIENTE DE PRUEBA SA - 29-10-2026.');
            $m->assertSeeInHtml('Vendedora Dos');

            $adjuntos = $m->attachments();
            $this->assertCount(1, $adjuntos);
            $this->assertStringEndsWith('.pdf', $adjuntos[0]->as);

            return $m->hasTo('cliente@ejemplo.test');
        });

        $envio = Impresion::sole();
        $this->assertSame('Correo', $envio->via);
        $this->assertSame('cliente@ejemplo.test', $envio->mail);
        $this->assertSame($this->ventas->id, $envio->usuario_id);
    }

    /** Un correo que no salio no queda anotado como enviado. */
    public function test_si_la_casilla_no_responde_no_queda_registrado_el_envio(): void
    {
        Sanctum::actingAs($this->admin);
        // Nadie escucha en ese puerto: la conexion se rechaza enseguida.
        $this->putJson('/api/config/envios', $this->casilla(['host' => '127.0.0.1', 'puerto' => 9]))->assertOk();

        $antes = DB::table('historial_cambios')->count();

        $r = $this->postJson("/api/consultas/{$this->consulta->id}/correo", ['para' => 'cliente@ejemplo.test']);

        $r->assertStatus(502);
        $this->assertStringContainsString('No quedó registrado', $r->json('message'));
        $this->assertSame(0, Impresion::count());
        $this->assertSame($antes, DB::table('historial_cambios')->count());
    }

    public function test_la_prueba_le_llega_a_quien_la_pide(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/config/envios', $this->casilla())->assertOk();

        Mail::fake();

        $this->postJson('/api/config/envios/prueba')
            ->assertOk()
            ->assertJsonPath('mensaje', fn ($m) => str_contains($m, 'admin@ejemplo.test'));
    }
}

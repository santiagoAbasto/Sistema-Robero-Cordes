<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Contacto;
use App\Models\Empresa;
use App\Models\EmpresaEnlace;
use App\Models\EmpresaRelacion;
use App\Models\RazonSocial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fusionar empresas con el nombre EXACTAMENTE igual, sin perder nada.
 *
 * El sistema viejo repetia la misma empresa una vez por contacto; la fusion las
 * une en una sola, mueve TODO lo que cuelga y archiva (no borra) las demas.
 */
class FusionarEmpresasDuplicadasTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = User::factory()->create(['role' => 'Administrador']);
    }

    private function cotizacion(Empresa $empresa): Consulta
    {
        return Consulta::create([
            'empresa_id' => $empresa->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-10-01',
            'estado' => 'Confirmada',
            'usuario_id' => $this->usuario->id,
        ]);
    }

    public function test_fusiona_solo_el_nombre_exacto_y_no_pierde_nada(): void
    {
        // --- Grupo a fusionar: tres fichas "ACME SA" ---
        $a = Empresa::create(['nombre' => 'ACME SA', 'cuit' => '30-1']);
        $b = Empresa::create(['nombre' => 'ACME SA']);                 // sin cuit (hueco a completar)
        $c = Empresa::create(['nombre' => 'ACME SA', 'cuit' => '30-2']); // cuit en conflicto -> observacion

        // b tiene mas cotizaciones => sobrevive.
        $this->cotizacion($a);
        $this->cotizacion($b);
        $this->cotizacion($b);
        $enPapelera = $this->cotizacion($b);
        $enPapelera->delete(); // soft-deleted: tambien tiene que moverse

        // Contactos con principal en dos fichas (conflicto) + uno comun.
        Contacto::create(['empresa_id' => $a->id, 'nombre' => 'Ana', 'principal' => true]);
        Contacto::create(['empresa_id' => $b->id, 'nombre' => 'Beto', 'principal' => true]);
        Contacto::create(['empresa_id' => $c->id, 'nombre' => 'Caro', 'principal' => false]);

        // Razones con habitual en dos fichas (conflicto).
        RazonSocial::create(['empresa_id' => $a->id, 'razon_social' => 'ACME SA', 'cuit' => '30-1', 'habitual' => true]);
        RazonSocial::create(['empresa_id' => $b->id, 'razon_social' => 'ACME SA', 'cuit' => '30-9', 'habitual' => true]);

        // Relaciones: Cliente en a y b (repetida -> se descarta la de a) + Proveedor solo en a (se mueve).
        EmpresaRelacion::create(['empresa_id' => $a->id, 'relacion' => 'Cliente']);
        EmpresaRelacion::create(['empresa_id' => $a->id, 'relacion' => 'Proveedor']);
        EmpresaRelacion::create(['empresa_id' => $b->id, 'relacion' => 'Cliente']);

        // Un enlace en c, que tambien tiene que terminar en la que sobrevive.
        EmpresaEnlace::create(['empresa_id' => $c->id, 'tipo' => 'Web', 'url' => 'acme.test', 'orden' => 1]);

        // --- Empresa con nombre DISTINTO: no se debe tocar ---
        $otra = Empresa::create(['nombre' => 'ACME SRL']);
        $deOtra = $this->cotizacion($otra);

        $totalConsultasAntes = Consulta::withTrashed()->count();

        $this->artisan('empresas:fusionar-duplicadas --aplicar')
            ->expectsConfirmation('¿Hiciste el respaldo y querés aplicar la fusión ahora?', 'yes')
            ->assertSuccessful();

        // --- La que sobrevive es b: activa, con el nombre intacto ---
        $b->refresh();
        $this->assertTrue((bool) $b->activa);
        $this->assertSame('ACME SA', $b->nombre);

        // --- a y c: archivadas (no borradas), fuera del nombre exacto ---
        foreach ([$a, $c] as $arch) {
            $arch->refresh();
            $this->assertFalse((bool) $arch->activa, "la #{$arch->id} deberia quedar archivada");
            $this->assertStringContainsString("fusionada en #{$b->id}", $arch->nombre);
        }

        // --- Nada se perdio: todas las cotizaciones (incl. papelera) estan en b ---
        $this->assertSame($totalConsultasAntes, Consulta::withTrashed()->count(), 'no se borro ninguna cotizacion');
        $this->assertSame(4, Consulta::withTrashed()->where('empresa_id', $b->id)->count());
        $this->assertSame(1, Consulta::onlyTrashed()->where('empresa_id', $b->id)->count(), 'la de la papelera tambien se movio');

        // --- Contactos: los tres en b, con UN solo principal ---
        $this->assertSame(3, Contacto::where('empresa_id', $b->id)->count());
        $this->assertSame(1, Contacto::where('empresa_id', $b->id)->where('principal', true)->count());

        // --- Razones: las dos en b, con UNA sola habitual ---
        $this->assertSame(2, RazonSocial::where('empresa_id', $b->id)->count());
        $this->assertSame(1, RazonSocial::where('empresa_id', $b->id)->where('habitual', true)->count());

        // --- Relaciones: Cliente (una sola) y Proveedor; sin duplicar ---
        $relaciones = EmpresaRelacion::where('empresa_id', $b->id)->pluck('relacion')->sort()->values()->all();
        $this->assertSame(['Cliente', 'Proveedor'], $relaciones);

        // --- El enlace de c termino en b ---
        $this->assertSame(1, EmpresaEnlace::where('empresa_id', $b->id)->count());

        // --- Datos: el cuit vacio se completo con el de a; el de c (conflicto) quedo en la observacion ---
        $this->assertSame('30-1', $b->cuit);
        $this->assertStringContainsString('30-2', (string) $b->observacion_general);

        // --- La empresa de nombre distinto quedo intacta ---
        $otra->refresh();
        $this->assertTrue((bool) $otra->activa);
        $this->assertSame('ACME SRL', $otra->nombre);
        $this->assertSame($otra->id, $deOtra->fresh()->empresa_id);
    }

    public function test_la_vista_previa_no_toca_nada(): void
    {
        Empresa::create(['nombre' => 'DUP SA']);
        Empresa::create(['nombre' => 'DUP SA']);

        $this->artisan('empresas:fusionar-duplicadas')->assertSuccessful();

        // Las dos siguen activas: la vista previa no cambia nada.
        $this->assertSame(2, Empresa::where('nombre', 'DUP SA')->where('activa', true)->count());
    }
}

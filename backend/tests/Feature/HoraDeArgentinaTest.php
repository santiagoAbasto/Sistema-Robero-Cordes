<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El sistema se usa en Argentina: la hora y la fecha de hoy son las de aca.
 *
 * En UTC las horas salian 3 adelantadas, y despues de las 21 h "hoy" ya era
 * mañana: una cotizacion emitida el 31 de diciembre a la noche se numeraba
 * con el año siguiente.
 */
class HoraDeArgentinaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_las_23_de_aca_todavia_es_hoy(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-12-31 23:30', new \DateTimeZone('America/Argentina/Buenos_Aires')));

        $this->assertSame('2026-12-31', today()->toDateString());
        $this->assertSame('23:30', now()->format('H:i'));
    }

    /**
     * Lo guardado en UTC pasa a hora de aca.
     *
     * Las horas del historial se escribian en UTC; la fecha de emision de las
     * cotizaciones viejas es su dia, sin hora, y no tiene que correrse al
     * anterior.
     */
    public function test_lo_ya_guardado_pasa_a_hora_de_aca(): void
    {
        DB::table('historial_cambios')->insert([
            'tabla' => 'empresas', 'registro_id' => 1, 'accion' => 'Alta', 'fecha' => '2026-09-30 15:00:00',
        ]);

        $vieja = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'APEX'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-07-15',
            'estado' => 'Confirmada',
            'usuario_id' => User::factory()->create()->id,
        ]);
        DB::table('consultas')->where('id', $vieja->id)->update(['emitida_el' => '2026-07-14 21:00:00']);

        (require database_path('migrations/2026_10_01_000300_hora_de_argentina.php'))->up();

        $this->assertSame('2026-09-30 12:00:00', DB::table('historial_cambios')->value('fecha'));
        $this->assertSame('2026-07-15', $vieja->fresh()->emitida_el->toDateString());
    }
}

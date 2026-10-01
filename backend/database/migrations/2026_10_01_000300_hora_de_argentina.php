<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El sistema pasa a hora de Argentina: lo ya guardado tiene que decir lo mismo.
 *
 * Las columnas TIMESTAMP (created_at, emitida_el...) se acomodan solas: MySQL
 * las guarda como instante y ahora las devuelve en -03:00. Las DATETIME no:
 * guardan el texto tal cual, y el que se escribio en UTC hay que llevarlo a
 * la hora de aca. Son las horas del historial de cambios, de los envios y de
 * las observaciones internas, que salian 3 horas adelantadas.
 *
 * Y la fecha de emision de las cotizaciones viejas es la del dia en que se
 * cotizaron, sin hora: guardada como medianoche UTC, en hora de aca seria las
 * 21 h del dia anterior. Se vuelve a poner, ya en hora de aca.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tresHorasAntes = DB::getDriverName() === 'sqlite'
            ? "datetime(fecha, '-3 hours')"
            : 'fecha - INTERVAL 3 HOUR';

        // Todo o nada: si se cortara a la mitad, el reintento del arranque
        // le restaria otras 3 horas a lo que ya se corrio.
        DB::transaction(function () use ($tresHorasAntes) {
            foreach (['historial_cambios', 'impresiones', 'observaciones'] as $tabla) {
                DB::table($tabla)->update(['fecha' => DB::raw($tresHorasAntes)]);
            }

            // Las del sistema viejo son las unicas emitidas sin quien las emitio.
            DB::table('consultas')
                ->whereNull('emitida_por')
                ->whereNotNull('emitida_el')
                ->update(['emitida_el' => DB::raw('fecha')]);
        });
    }

    public function down(): void
    {
        // No se vuelve atras: la hora de aca es la correcta.
    }
};

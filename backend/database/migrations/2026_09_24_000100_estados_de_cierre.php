<?php

use App\Models\Consulta;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los dos estados de cierre pasan a decir POR QUE se cerro.
 *
 * "Vencida" y "Sin vender" contaban el final pero no el motivo, y son dos
 * finales que la empresa mira distinto: a una cotizacion a la que se le paso
 * el tiempo sin respuesta se la puede volver a levantar; una que el cliente
 * rechazo ya tiene respuesta. Se renombran en vez de agregarse, porque eran
 * exactamente esos dos casos con otro nombre y sumar estados al lado dejaba
 * cuatro finales para dos cosas.
 *
 * De paso la columna deja de ser un enum de base.
 *
 * El enum obligaba a una migracion con SQL distinto por motor cada vez que
 * aparece un estado, y ademas no decia lo mismo en los dos lados: en MySQL es
 * un ENUM y en el SQLite de los tests un CHECK escrito con los valores de la
 * migracion original, que hay que reconstruir entero para tocarlo. La lista
 * que manda vive en Consulta::ESTADOS y la hace cumplir la validacion de
 * escritura, que es donde entra un estado que viene de afuera.
 *
 * Al 24-09-2026 ninguna de las 8.048 cotizaciones cargadas usa estos dos
 * valores, asi que el UPDATE no mueve ninguna fila. Va igual: la migracion
 * tiene que valer tambien en una base que si los tenga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultas', function (Blueprint $t) {
            $t->string('estado', 40)->default('Confirmada')->change();
        });

        DB::table('consultas')->where('estado', 'Sin vender')
            ->update(['estado' => Consulta::CERRADA]);

        DB::table('consultas')->where('estado', 'Vencida')
            ->update(['estado' => Consulta::VENCIDA]);
    }

    public function down(): void
    {
        DB::table('consultas')->where('estado', Consulta::CERRADA)
            ->update(['estado' => 'Sin vender']);

        DB::table('consultas')->where('estado', Consulta::VENCIDA)
            ->update(['estado' => 'Vencida']);

        Schema::table('consultas', function (Blueprint $t) {
            $t->enum('estado', [
                'Borrador', 'Sin cotizar', 'Confirmada', 'Vendida', 'Sin vender', 'Vencida',
            ])->default('Confirmada')->change();
        });
    }
};

<?php

use App\Models\ConsultaLinea;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los motivos de "no se cotiza" salen de una tabla, editable en Configuracion.
 *
 * Lista de ajustes del cliente, 14-10-2026: "motivo que no se cotiza,
 * administrable tambien en configuracion". Era una constante: agregar uno era
 * tocar el codigo. Lo mismo que se hizo con los motivos de cambio.
 *
 * Arranca con los cinco que ya estaban, y con los que alguien haya escrito en
 * una linea: el motivo se guarda como texto en la linea, asi que cambiar la
 * lista no toca ninguna cotizacion vieja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivos_no_cotiza', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('nombre', 120)->unique();
            $tabla->unsignedInteger('orden')->default(0);
            $tabla->boolean('activo')->default(true);
            $tabla->timestamps();
        });

        $orden = 0;
        $escritos = DB::table('consulta_lineas')->whereNotNull('motivo_no_cotiza')->distinct()->pluck('motivo_no_cotiza');

        foreach ([...ConsultaLinea::MOTIVOS_DECLINACION, ...$escritos] as $nombre) {
            $nombre = trim((string) $nombre);

            if ($nombre === '' || DB::table('motivos_no_cotiza')->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->exists()) {
                continue;
            }

            DB::table('motivos_no_cotiza')->insert([
                'nombre' => $nombre, 'orden' => ++$orden, 'activo' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('motivos_no_cotiza');
    }
};

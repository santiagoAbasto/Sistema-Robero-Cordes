<?php

use App\Models\ConsultaLinea;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los motivos de cambio salen de una tabla, no de una lista escrita en el codigo.
 *
 * "Agregar mas opciones de motivos de cambio, solo hay unos cuantos, debe ser
 * mas administrable". Estaban como constante: agregar uno era tocar el codigo
 * y volver a desplegar. Ahora el campo deja elegir o escribir, y lo que se
 * escribe queda en la lista para la proxima, igual que la condicion de pago.
 *
 * Arranca con los siete que ya estaban en uso —salen impresos al lado de la
 * diferencia, asi que cambiarlos ahora cambiaria cotizaciones viejas— mas el
 * que pidio Roberto. Los demas los escribe la empresa: un motivo inventado se
 * imprime y se le manda a un cliente.
 *
 * Los motivos ya escritos en las lineas se levantan de la base: si alguien
 * habia tipeado uno, aparece en la lista en vez de perderse.
 */
return new class extends Migration
{
    /** El unico que se agrega. Roberto lo pidio por su nombre. */
    private const NUEVO = 'No hay esa calidad';

    public function up(): void
    {
        Schema::create('motivos_cambio', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('nombre', 120)->unique();
            $tabla->unsignedInteger('orden')->default(0);
            $tabla->boolean('activo')->default(true);
            $tabla->timestamps();
        });

        $orden = 0;

        foreach ([...ConsultaLinea::MOTIVOS, self::NUEVO] as $nombre) {
            $this->sembrar($nombre, ++$orden);
        }

        // Los que alguien ya habia escrito a mano en una linea.
        $escritos = DB::table('consulta_lineas')
            ->whereNotNull('motivo_cambio')
            ->distinct()
            ->pluck('motivo_cambio');

        foreach ($escritos as $nombre) {
            $this->sembrar((string) $nombre, ++$orden);
        }
    }

    /** Sin repetir: la comparacion no mira mayusculas ni espacios de los bordes. */
    private function sembrar(string $nombre, int $orden): void
    {
        $nombre = trim($nombre);

        if ($nombre === '') {
            return;
        }

        $yaEsta = DB::table('motivos_cambio')
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->exists();

        if ($yaEsta) {
            return;
        }

        DB::table('motivos_cambio')->insert([
            'nombre' => $nombre,
            'orden' => $orden,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('motivos_cambio');
    }
};

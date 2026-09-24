<?php

namespace App\Console\Commands;

use App\Services\Migracion\OriginalesDelAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mete las filas de los CSV del Access adentro de la base.
 *
 * Es lo que hace que la pantalla "Antes y ahora" funcione fuera de la maquina
 * donde se hizo la importacion. Los CSV no se versionan ni se copian al
 * servidor —son datos de clientes—, asi que la unica forma de que el lado
 * "antes" llegue es viajando adentro de la base.
 *
 * Se puede correr las veces que haga falta: cada archivo reemplaza lo suyo y
 * no toca lo de los demas.
 */
class GuardarOriginales extends Command
{
    protected $signature = 'migracion:guardar-originales';

    protected $description = 'Guarda en la base las filas originales del Access, para la pantalla "Antes y ahora"';

    public function handle(): int
    {
        $carpeta = OriginalesDelAccess::carpeta();
        $archivos = glob($carpeta.'/*.csv') ?: [];

        if ($archivos === []) {
            $this->error("No hay ningun .csv en {$carpeta}.");
            $this->line('Son los que se exportaron del Access con mdb-export.');

            return self::FAILURE;
        }

        $resumen = [];

        foreach ($archivos as $ruta) {
            $nombre = basename($ruta);
            $filas = OriginalesDelAccess::delArchivo($ruta);

            DB::table('migracion_originales')->where('archivo', $nombre)->delete();

            // De a tandas: cotizaciones.csv son 8.048 filas y un solo insert
            // con todas se pasa del tamaño maximo de consulta.
            foreach (array_chunk($filas, 400, true) as $tanda) {
                DB::table('migracion_originales')->insert(array_map(
                    fn (int $i, array $fila) => [
                        'archivo' => $nombre,
                        'fila' => $i,
                        'datos' => json_encode($fila, JSON_UNESCAPED_UNICODE),
                    ],
                    array_keys($tanda),
                    $tanda,
                ));
            }

            $resumen[] = [$nombre, number_format(count($filas), 0, ',', '.')];
        }

        $this->newLine();
        $this->table(['archivo', 'filas guardadas'], $resumen);
        $this->info('Listo. Ahora viajan con la base: volvé a subirla para que lleguen al servidor.');

        return self::SUCCESS;
    }
}

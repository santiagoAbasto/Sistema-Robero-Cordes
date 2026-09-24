<?php

namespace App\Services\Migracion;

use Illuminate\Support\Facades\DB;

/**
 * Las filas del Access tal cual, vengan del archivo o de la base.
 *
 * Son la mitad "antes" de la pantalla de comparacion. Se leen del CSV cuando
 * esta a mano —es el caso de la maquina donde se hizo la importacion— y de la
 * tabla cuando no, que es el caso del servidor: los CSV no se versionan ni se
 * copian a la imagen, asi que alla la unica copia es la que viajo adentro de
 * la base.
 */
class OriginalesDelAccess
{
    /** Donde se dejan los CSV exportados del Access. */
    public static function carpeta(): string
    {
        return storage_path('app/migracion');
    }

    /**
     * Las filas de un CSV del Access.
     *
     * Sin limpiar nada a proposito: el lado "antes" tiene que mostrar el Ý
     * donde iba un Ø, la fecha 01/10/2424 y la mascara "-    -" sin telefono.
     * Es la mitad de la comparacion.
     *
     * @return list<array<string, string>>
     */
    public static function delArchivo(string $ruta): array
    {
        if (! is_file($ruta)) {
            return [];
        }

        $f = fopen($ruta, 'r');

        // Sin escape: el Access escribe las comillas duplicandolas, como manda
        // el formato. Los archivos no tienen una sola barra invertida.
        $cabecera = fgetcsv($f, 0, ',', '"', '');
        $filas = [];

        while (($fila = fgetcsv($f, 0, ',', '"', '')) !== false) {
            if (count($fila) === count($cabecera)) {
                $filas[] = array_combine($cabecera, $fila);
            }
        }

        fclose($f);

        return $filas;
    }

    /**
     * Las mismas filas, leidas de la tabla.
     *
     * Conserva el numero de fila original como clave. Ahi se apoya el
     * emparejado de las cotizaciones, que no tienen otra clave.
     *
     * @return array<int, array<string, string>>
     */
    public static function deLaBase(string $archivo): array
    {
        return DB::table('migracion_originales')
            ->where('archivo', $archivo)
            ->orderBy('fila')
            ->pluck('datos', 'fila')
            ->map(fn ($datos) => json_decode($datos, true) ?: [])
            ->all();
    }
}

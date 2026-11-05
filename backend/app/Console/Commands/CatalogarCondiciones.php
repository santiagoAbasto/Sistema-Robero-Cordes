<?php

namespace App\Console\Commands;

use App\Services\CatalogoDeCondiciones;
use Illuminate\Console\Command;

/**
 * Arma (o completa) la lista de condiciones de la ficha con lo que ya está cargado.
 *
 * La migración ya lo hizo una vez al crear las tablas. Esto sirve para volver a hacerlo cuando
 * entran fichas nuevas desde afuera (una importación del sistema viejo): vincula lo que falta y
 * suma a la lista los datos que se repiten. Por defecto sólo cuenta lo que haría.
 */
class CatalogarCondiciones extends Command
{
    protected $signature = 'condiciones:catalogar
        {--aplicar : Guardar los cambios (sin esto, sólo se muestra qué se haría)}
        {--minimo=2 : Cuántas veces tiene que repetirse un dato para entrar a la lista}';

    protected $description = 'Arma la lista de condiciones de la ficha con lo que ya está cargado (vista previa; --aplicar guarda)';

    public function handle(CatalogoDeCondiciones $catalogo): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $r = $catalogo->sembrar($aplicar, (int) $this->option('minimo'));

        $this->info($aplicar ? 'Listo, guardado.' : 'Vista previa: no se guardó nada. Con --aplicar se guarda.');
        $this->line('Condiciones nuevas:   '.count($r['tipos_nuevos']).($r['tipos_nuevos'] ? ' ('.implode(', ', $r['tipos_nuevos']).')' : ''));
        $this->line('Datos nuevos:         '.$r['opciones_nuevas']);
        $this->line('Fichas vinculadas:    '.$r['vinculados']);
        $this->line('Fuera de la lista:    '.$r['sueltos'].' fichas, '.$r['datos_sueltos'].' datos distintos');

        if ($r['muestra_de_sueltos'] !== []) {
            $this->newLine();
            $this->line('Los más repetidos entre los que quedan fuera de la lista:');
            $this->table(['Condición', 'Dato', 'Fichas'], array_map(fn ($f) => [$f['titulo'], mb_strimwidth($f['valor'], 0, 70, '…'), $f['n']], $r['muestra_de_sueltos']));
        }

        return self::SUCCESS;
    }
}

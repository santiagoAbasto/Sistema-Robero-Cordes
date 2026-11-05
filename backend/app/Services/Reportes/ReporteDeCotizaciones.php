<?php

namespace App\Services\Reportes;

use Closure;
use Illuminate\Database\Query\Builder;

/**
 * Lo común de los reportes que cuentan cotizaciones agrupadas por algo (empresa, año, quién la hizo):
 * la cuenta de cotizaciones y vendidas, y la plata cotizada y vendida separada por moneda.
 */
abstract class ReporteDeCotizaciones extends Reporte
{
    /**
     * @param  string  $grupo  la expresión SQL por la que se agrupa ("c.empresa_id")
     * @param  string  $etiquetas  lo que se pide además, ya agregado ("max(e.nombre) as nombre")
     * @param  ?Closure(Builder): void  $unir  joins que hagan falta para el grupo
     * @return array{0: array<string|int, array<string, mixed>>, 1: list<int>} los grupos y las monedas que aparecen
     */
    protected function agregar(string $grupo, string $etiquetas = '', ?Closure $unir = null): array
    {
        $cuenta = $this->cotizaciones();
        $unir && $unir($cuenta);

        $grupos = $cuenta
            ->selectRaw(($etiquetas !== '' ? $etiquetas.', ' : '')."{$grupo} as clave, count(*) as cotizaciones, "
                ."sum(case when c.estado = 'Vendida' then 1 else 0 end) as vendidas, count(distinct c.empresa_id) as empresas, max(c.fecha) as ultima")
            ->groupByRaw($grupo)->get();

        $importes = collect();
        if ($this->veImportes()) {
            $q = $this->unirTotales($this->cotizaciones());
            $unir && $unir($q);
            $importes = $q
                ->selectRaw("{$grupo} as clave, c.moneda_id, sum(".self::SQL_COTIZADO.') as cotizado, sum('.self::SQL_VENDIDO.') as vendido')
                ->groupByRaw($grupo.', c.moneda_id')->get();
        }

        $monedas = $this->monedasPorVolumen($importes, ['cotizado', 'vendido']);
        $filas = [];

        foreach ($grupos as $g) {
            $filas[(string) $g->clave] = (array) $g;
        }

        foreach ($importes as $i) {
            $mid = (int) ($i->moneda_id ?? 0);
            $filas[(string) $i->clave]["cotizado_{$mid}"] = round((float) $i->cotizado, 2);
            $filas[(string) $i->clave]["vendido_{$mid}"] = round((float) $i->vendido, 2);
        }

        return [$filas, $monedas];
    }

    /**
     * La parte de una fila que tienen todos: las cuentas, el porcentaje y una columna de plata por moneda.
     *
     * @param  array<string, mixed>  $g
     * @param  list<int>  $monedas
     * @return array<string, mixed>
     */
    protected function filaBase(array $g, array $monedas): array
    {
        $fila = [
            'cotizaciones' => (int) $g['cotizaciones'],
            'vendidas' => (int) $g['vendidas'],
            'vendidas_pct' => self::porcentaje((int) $g['vendidas'], (int) $g['cotizaciones']),
            'empresas' => (int) $g['empresas'],
            'ultima' => ! empty($g['ultima']) ? substr((string) $g['ultima'], 0, 10) : null,
        ];

        foreach ($monedas as $mid) {
            $fila["cotizado_{$mid}"] = (float) ($g["cotizado_{$mid}"] ?? 0);
            $fila["vendido_{$mid}"] = (float) ($g["vendido_{$mid}"] ?? 0);
        }

        return $fila;
    }
}

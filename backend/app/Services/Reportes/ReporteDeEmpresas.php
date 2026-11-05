<?php

namespace App\Services\Reportes;

/**
 * Por empresa —o por año—: cuánto se cotizó, cuánto se vendió, en qué proporción y por cuánta plata.
 *
 * "Vendido" es lo que se marcó como vendido en este sistema; el sistema anterior no lo guardó, así que
 * los años viejos figuran con cero vendido y eso no quiere decir que no se haya vendido.
 */
final class ReporteDeEmpresas extends ReporteDeCotizaciones
{
    public function clave(): string
    {
        return 'empresa';
    }

    public function titulo(): string
    {
        return $this->f->agrupar === 'anio' ? 'Por año' : 'Por empresa';
    }

    protected function armar(): array
    {
        $porAnio = $this->f->agrupar === 'anio';

        [$grupos, $monedas] = $porAnio
            ? $this->agregar(self::anio('c.fecha'))
            : $this->agregar('c.empresa_id', 'max(e.nombre) as nombre, max(e.activa) as activa');

        $filas = [];
        foreach ($grupos as $clave => $g) {
            $fila = $this->filaBase($g, $monedas) + ['clave' => $clave];

            if ($porAnio) {
                $fila += [
                    'anio' => (string) $clave,
                    'enlace' => '/buscador?'.http_build_query(['grano' => 'consultas', 'desde' => "{$clave}-01-01", 'hasta' => "{$clave}-12-31"]),
                ];
            } else {
                $fila += [
                    'empresa' => (string) ($g['nombre'] ?? "Empresa {$clave}"),
                    'enlace' => "/empresas/{$clave}",
                    // Las que cerraron se ven, pero apagadas.
                    'apagada' => ! (bool) ($g['activa'] ?? true),
                ];
            }

            $filas[] = $fila;
        }

        usort($filas, $porAnio
            ? fn ($a, $b) => strcmp($b['anio'], $a['anio'])
            : fn ($a, $b) => [$b['cotizaciones'], $a['empresa']] <=> [$a['cotizaciones'], $b['empresa']]);

        $primera = $porAnio
            ? ['clave' => 'anio', 'titulo' => 'Año', 'tipo' => 'texto']
            : ['clave' => 'empresa', 'titulo' => 'Empresa', 'tipo' => 'texto'];

        $columnas = [
            $primera,
            ['clave' => 'cotizaciones', 'titulo' => 'Cotizaciones', 'tipo' => 'numero'],
            ['clave' => 'vendidas', 'titulo' => 'Vendidas', 'tipo' => 'numero'],
            ['clave' => 'vendidas_pct', 'titulo' => '% vendidas', 'tipo' => 'porcentaje'],
            ...($porAnio ? [['clave' => 'empresas', 'titulo' => 'Empresas', 'tipo' => 'numero', 'sinTotal' => true]] : []),
            ['clave' => 'ultima', 'titulo' => 'Última cotización', 'tipo' => 'fecha'],
            ...$this->columnasDeImporte($monedas, ['cotizado' => 'Cotizado', 'vendido' => 'Vendido']),
        ];

        $filas = array_slice($filas, 0, 5000);

        return [
            'columnas' => $columnas,
            'filas' => $filas,
            'totales' => $this->totales($columnas, $filas, 'Total', $primera['clave'], ['vendidas_pct' => ['vendidas', 'cotizaciones']]),
            'avisos' => ['Lo vendido es lo que se marcó como vendido en este sistema: el sistema anterior no lo guardó, así que los años viejos figuran con cero.'],
        ];
    }
}

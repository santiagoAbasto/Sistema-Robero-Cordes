<?php

namespace App\Services\Reportes;

use App\Models\Consulta;
use Carbon\CarbonImmutable;

/**
 * Los vencimientos: de las cotizaciones todavía abiertas, cuántas vencen pronto, cuántas ya vencieron y
 * hace cuánto, y cuánta plata hay en cada tramo. Es el "dónde llamar primero" en números; la lista una
 * por una está en Vencimientos y seguimiento.
 *
 * Abierta es la que no se vendió ni se cerró. Las que vienen del sistema anterior nunca tuvieron validez
 * cargada: figuran aparte, en "sin fecha de vencimiento".
 */
final class ReporteDeVencimientos extends Reporte
{
    public function clave(): string
    {
        return 'vencimientos';
    }

    public function titulo(): string
    {
        return 'Vencimientos';
    }

    protected function armar(): array
    {
        $hoy = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $d = fn (int $dias) => $hoy->addDays($dias)->toDateString();

        // De lo más vencido a lo más lejano; el último es lo que no tiene fecha.
        $tramos = [
            ['v90', 'Vencidas hace más de 90 días', null],
            ['v30', 'Vencidas hace 31 a 90 días', null],
            ['v7', 'Vencidas hace 8 a 30 días', null],
            ['v0', 'Vencidas hace 1 a 7 días', null],
            ['hoy', 'Vencen hoy', 7],
            ['p7', 'Vencen en 1 a 7 días', 7],
            ['p30', 'Vencen en 8 a 30 días', 30],
            ['mas', 'Vencen en más de 30 días', null],
            ['sin', 'Sin fecha de vencimiento', null],
        ];

        $caso = 'case when c.vence_el is null then 8 when c.vence_el < ? then 0 when c.vence_el < ? then 1 when c.vence_el < ? then 2 '
            .'when c.vence_el < ? then 3 when c.vence_el < ? then 4 when c.vence_el < ? then 5 when c.vence_el < ? then 6 else 7 end';
        $cortes = [$d(-90), $d(-30), $d(-7), $d(0), $d(1), $d(8), $d(31)];

        $abiertas = fn () => $this->cotizaciones()->whereNotIn('c.estado', [...Consulta::ESTADOS_DE_CIERRE, 'Vendida']);

        $cuenta = $abiertas()->selectRaw("{$caso} as tramo, count(*) as cotizaciones, count(distinct c.empresa_id) as empresas", $cortes)
            ->groupBy('tramo')->get()->keyBy('tramo');

        $importes = $this->veImportes()
            ? $this->unirTotales($abiertas())->selectRaw("{$caso} as tramo, c.moneda_id, sum(".self::SQL_COTIZADO.') as cotizado', $cortes)
                ->groupBy('tramo', 'c.moneda_id')->get()
            : collect();

        $monedas = $this->monedasPorVolumen($importes, ['cotizado']);
        $filas = [];

        foreach ($tramos as $i => [$clave, $nombre, $porVencer]) {
            $c = $cuenta->get($i);
            $fila = [
                'clave' => $clave,
                'tramo' => $nombre,
                'cotizaciones' => (int) ($c->cotizaciones ?? 0),
                'empresas' => (int) ($c->empresas ?? 0),
                // Los que ya vencieron van a "sin respuesta"; los que vencen pronto, a "por vencer".
                'enlace' => match (true) {
                    str_starts_with($clave, 'v') => '/buscador?grano=consultas&sin_respuesta=1',
                    $porVencer !== null => "/buscador?grano=consultas&por_vencer={$porVencer}",
                    default => null,
                },
            ];

            foreach ($monedas as $mid) {
                $fila["cotizado_{$mid}"] = round((float) $importes->first(fn ($r) => (int) $r->tramo === $i && (int) ($r->moneda_id ?? 0) === $mid)?->cotizado, 2);
            }

            $filas[] = $fila;
        }

        $columnas = [
            ['clave' => 'tramo', 'titulo' => 'Tramo', 'tipo' => 'texto'],
            ['clave' => 'cotizaciones', 'titulo' => 'Cotizaciones abiertas', 'tipo' => 'numero'],
            ['clave' => 'empresas', 'titulo' => 'Empresas', 'tipo' => 'numero', 'sinTotal' => true],
            ...$this->columnasDeImporte($monedas, ['cotizado' => 'Cotizado']),
        ];

        $sin = (int) ($cuenta->get(8)->cotizaciones ?? 0);

        return [
            'columnas' => $columnas,
            'filas' => $filas,
            'totales' => $this->totales($columnas, $filas, 'Total', 'tramo'),
            'avisos' => $sin > 0 ? [number_format($sin, 0, ',', '.').' cotizaciones abiertas no tienen fecha de vencimiento (las del sistema anterior nunca la tuvieron).'] : [],
        ];
    }
}

<?php

namespace App\Services\Buscador;

use Carbon\CarbonImmutable;

/**
 * Los períodos de tiempo, como fechas.
 *
 * La IA entiende "el año pasado", pero no se le deja hacer la cuenta: dice cuál
 * es el período y las fechas las calcula este código, que no se equivoca de año
 * ni de mes. Las semanas empiezan el lunes.
 */
final class Periodo
{
    /** Los períodos que se pueden pedir por su nombre. */
    public const NOMBRES = [
        'hoy', 'ayer', 'esta_semana', 'semana_pasada', 'este_mes', 'mes_pasado',
        'este_anio', 'anio_pasado', 'ultimos_30_dias', 'ultimos_90_dias', 'ultimos_6_meses', 'ultimo_anio',
    ];

    /** @return array{desde: string, hasta: string}|null */
    public static function de(string $nombre, CarbonImmutable $hoy): ?array
    {
        $dia = $hoy->startOfDay();

        [$desde, $hasta] = match ($nombre) {
            'hoy' => [$dia, $dia],
            'ayer' => [$dia->subDay(), $dia->subDay()],
            'esta_semana' => [$dia->startOfWeek(), $dia->endOfWeek()],
            'semana_pasada' => [$dia->subWeek()->startOfWeek(), $dia->subWeek()->endOfWeek()],
            'este_mes' => [$dia->startOfMonth(), $dia->endOfMonth()],
            'mes_pasado' => [$dia->subMonthNoOverflow()->startOfMonth(), $dia->subMonthNoOverflow()->endOfMonth()],
            'este_anio' => [$dia->startOfYear(), $dia->endOfYear()],
            'anio_pasado' => [$dia->subYear()->startOfYear(), $dia->subYear()->endOfYear()],
            'ultimos_30_dias' => [$dia->subDays(30), $dia],
            'ultimos_90_dias' => [$dia->subDays(90), $dia],
            'ultimos_6_meses' => [$dia->subMonthsNoOverflow(6), $dia],
            'ultimo_anio' => [$dia->subYear(), $dia],
            default => [null, null],
        };

        return $desde === null ? null : self::fechas($desde, $hasta);
    }

    /** Los últimos N días, semanas, meses o años, hasta hoy. */
    public static function ultimos(int $cantidad, string $unidad, CarbonImmutable $hoy): ?array
    {
        $dia = $hoy->startOfDay();

        $desde = match ($unidad) {
            'dia' => $dia->subDays($cantidad),
            'semana' => $dia->subWeeks($cantidad),
            'mes' => $dia->subMonthsNoOverflow($cantidad),
            'anio' => $dia->subYears($cantidad),
            default => null,
        };

        return $desde === null ? null : self::fechas($desde, $dia);
    }

    /** Un mes entero. Sin año, el más reciente que ya empezó. */
    public static function mes(int $mes, ?int $anio, CarbonImmutable $hoy): array
    {
        $anio ??= $mes > $hoy->month ? $hoy->year - 1 : $hoy->year;
        $primero = CarbonImmutable::create($anio, $mes, 1, 0, 0, 0, $hoy->getTimezone());

        return self::fechas($primero, $primero->endOfMonth());
    }

    /** Un año entero. */
    public static function anio(int $anio, CarbonImmutable $hoy): array
    {
        $primero = CarbonImmutable::create($anio, 1, 1, 0, 0, 0, $hoy->getTimezone());

        return self::fechas($primero, $primero->endOfYear());
    }

    /** @return array{desde: string, hasta: string} */
    public static function fechas(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        return ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()];
    }
}

<?php

namespace App\Services\Buscador;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Lo que hace falta para leer una frase escrita a la ligera: sin tildes, sin
 * mayúsculas, sin signos, y con los números y las fechas como los escribe la gente.
 */
final class Texto
{
    public const MESES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
        'noviembre' => 11, 'diciembre' => 12,
    ];

    private const NUMEROS = [
        'un' => 1, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5,
        'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10, 'once' => 11,
        'doce' => 12, 'quince' => 15, 'veinte' => 20, 'treinta' => 30,
    ];

    /**
     * En minúsculas, sin tildes ni eñes, sin signos sueltos. Se conservan los
     * que forman parte de un dato: 0001-00001234, 2026-0011, 1.500,50, 12/10.
     */
    public static function normalizar(string $texto): string
    {
        $t = mb_strtolower(Str::ascii($texto));
        $t = preg_replace('/[^a-z0-9\s\-\/\.,]/', ' ', $t) ?? '';
        // Un punto, una coma, un guion o una barra sólo valen entre letras o números.
        $t = preg_replace('/(?<![a-z0-9])[\-\/\.,]+|[\-\/\.,]+(?![a-z0-9])/', ' ', $t) ?? '';

        return trim(preg_replace('/\s+/', ' ', $t) ?? '');
    }

    /** "dos" -> 2, "6" -> 6, "un" -> 1. */
    public static function numero(string $palabra): ?int
    {
        if (ctype_digit($palabra)) {
            return (int) $palabra;
        }

        return self::NUMEROS[$palabra] ?? null;
    }

    /** "12,5" y "12.5" son lo mismo. */
    public static function decimal(string $texto): float
    {
        return (float) str_replace(',', '.', $texto);
    }

    /**
     * Una palabra y sus posibles singulares: "clientes" -> clientes, cliente, client.
     * No se adivina cuál es el bueno: se compara con lo que hay (ver mismaFrase).
     *
     * @return list<string>
     */
    public static function variantes(string $palabra): array
    {
        $v = [$palabra];

        if (mb_strlen($palabra) > 3 && str_ends_with($palabra, 's')) {
            $v[] = mb_substr($palabra, 0, -1);
        }
        if (mb_strlen($palabra) > 4 && str_ends_with($palabra, 'es')) {
            $v[] = mb_substr($palabra, 0, -2);
        }

        return $v;
    }

    /**
     * ¿Dicen lo mismo, aunque una esté en plural? Palabra por palabra:
     * "barras redondas" = "barra redonda", "notas de credito" = "nota de credito".
     */
    public static function mismaFrase(string $a, string $b): bool
    {
        $pa = explode(' ', $a);
        $pb = explode(' ', $b);

        if (count($pa) !== count($pb)) {
            return false;
        }

        foreach ($pa as $i => $palabra) {
            if (array_intersect(self::variantes($palabra), self::variantes($pb[$i])) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Una fecha escrita como la escribe la gente: "12/10", "12/10/2026", "12-10-26",
     * "1 de octubre", "1 de octubre de 2026". Sin año, es la más reciente que ya pasó o es hoy.
     */
    public static function fecha(string $norm, CarbonImmutable $hoy): ?CarbonImmutable
    {
        $norm = trim($norm);

        if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})(?:[/\-.](\d{2}|\d{4}))?$#', $norm, $m)) {
            return self::armar((int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : null, $hoy);
        }

        $meses = implode('|', array_keys(self::MESES));

        if (preg_match("#^(\\d{1,2}) de ($meses)(?: de (\\d{4}|\\d{2}))?$#", $norm, $m)) {
            return self::armar((int) $m[1], self::MESES[$m[2]], isset($m[3]) ? (int) $m[3] : null, $hoy);
        }

        return null;
    }

    private static function armar(int $dia, int $mes, ?int $anio, CarbonImmutable $hoy): ?CarbonImmutable
    {
        if ($mes < 1 || $mes > 12 || $dia < 1 || $dia > 31) {
            return null;
        }

        if ($anio !== null && $anio < 100) {
            $anio += 2000;
        }

        $anioUsado = $anio ?? $hoy->year;

        if ($dia > cal_days_in_month(CAL_GREGORIAN, $mes, $anioUsado)) {
            return null;
        }

        $fecha = CarbonImmutable::create($anioUsado, $mes, $dia, 0, 0, 0, $hoy->getTimezone());

        // Sin año y todavía no llegó: se habla del año pasado.
        return $anio === null && $fecha->isAfter($hoy) ? $fecha->subYear() : $fecha;
    }
}

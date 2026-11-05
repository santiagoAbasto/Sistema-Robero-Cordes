<?php

namespace App\Services;

use App\Models\CondicionHabitual;
use App\Models\Consulta;
use Illuminate\Support\Str;

/**
 * Con qué condiciones arranca una cotización nueva: el juego de Configuración
 * Y lo que la empresa tiene cargado en su ficha. Si los dos hablan de lo mismo,
 * manda la FICHA (pedido del cliente, 04-11-2026: lo cargado en la ficha lo
 * pisaba el juego de Configuración y no se veía nunca en la hoja).
 *
 * LA MISMA REGLA corre en el navegador (frontend/src/lib/condiciones.ts) para
 * lo que se propone en pantalla; ésta es la de la API cuando no mandan
 * condiciones. Si se cambia una, se cambia la otra.
 *
 * Reglas:
 *  0. Sin juego elegido no entra nada (como siempre).
 *  1. Se parte de los bloques del juego elegido.
 *  2. Cada campo de la ficha marcado "se usa al cotizar" es un bloque. Si el
 *     juego tiene uno con el mismo título lo reemplaza en su lugar; si no, se
 *     agrega al final (antes del de validez). Los campos vacíos no entran.
 *  3. La condición de pago (la de la cotización, o la de la ficha, o el campo
 *     "Tipo de pago") reemplaza el bloque "Condic. de Pago" del juego. NO toca
 *     "Forma de Pago": ese es otro texto (moneda y tipo de cambio de la factura).
 *  4. Lo que el juego tiene y la ficha no, queda igual.
 *
 * Es una COPIA: cambiar la ficha mañana no cambia una cotización ya hecha.
 */
class CondicionesDeLaFicha
{
    /** Cómo se llama el bloque de pago en los juegos (títulos ya normalizados). */
    private const TITULOS_DE_PAGO = ['condic de pago', 'condiciones de pago', 'condicion de pago'];

    /** Campos de la ficha que ya alimentan la cabecera de la cotización: no son bloques. */
    private const DE_LA_CABECERA = ['tipo de pago', 'lista de precios', 'moneda habitual'];

    /**
     * @return array<int, array{titulo: ?string, texto: string, origen: string}>
     */
    public static function para(Consulta $consulta): array
    {
        // Sin juego elegido no entra nada, ni siquiera lo de la ficha: es la
        // regla de siempre (los juegos difieren en plazo y forma de pago) y es
        // la misma que sigue la pantalla, que propone al elegir el juego.
        if (blank($consulta->juego_condiciones)) {
            return [];
        }

        $bloques = CondicionHabitual::delJuego($consulta->juego_condiciones)
            ->map(fn (CondicionHabitual $c) => [
                'titulo' => $c->titulo,
                'texto' => $c->texto,
                'origen' => 'Automatica',
            ])
            ->all();

        $empresa = $consulta->empresa;

        if ($empresa === null) {
            return $bloques;
        }

        $campos = $empresa->campos()->where('usar_al_cotizar', true)->orderBy('orden')->get();

        // 2. Campos de la ficha.
        foreach ($campos as $campo) {
            if (in_array(self::normalizar($campo->titulo), self::DE_LA_CABECERA, true)) {
                continue;
            }

            if (blank($campo->titulo) || blank($campo->valor)) {
                continue;
            }

            $bloques = self::poner($bloques, $campo->titulo, trim($campo->valor));
        }

        // 3. Condición de pago, salvo que la ficha ya tenga un bloque de pago propio.
        $yaHayPagoDeLaFicha = collect($bloques)->contains(
            fn ($b) => $b['origen'] === 'De la ficha'
                && in_array(self::normalizar($b['titulo'] ?? ''), self::TITULOS_DE_PAGO, true)
        );

        $pago = trim((string) ($consulta->condicion_pago
            ?: $empresa->condicion_pago
            ?: $campos->first(fn ($c) => self::normalizar($c->titulo) === 'tipo de pago')?->valor));

        if ($pago !== '' && ! $yaHayPagoDeLaFicha) {
            $bloques = self::poner($bloques, 'Condic. de Pago', $pago, reemplazaA: self::TITULOS_DE_PAGO);
        }

        return $bloques;
    }

    /**
     * Pone un bloque de la ficha: reemplaza el del juego con el mismo título
     * (en su lugar) o, si no hay, lo agrega antes del de validez.
     *
     * @param  array<int, array{titulo: ?string, texto: string, origen: string}>  $bloques
     * @param  array<int, string>|null  $reemplazaA  títulos normalizados que cuentan como el mismo (el pago)
     */
    private static function poner(array $bloques, string $titulo, string $texto, ?array $reemplazaA = null): array
    {
        $nuevo = ['titulo' => $titulo, 'texto' => $texto, 'origen' => 'De la ficha'];
        $iguales = $reemplazaA ?? [self::normalizar($titulo)];

        foreach ($bloques as $i => $b) {
            if (in_array(self::normalizar($b['titulo'] ?? ''), $iguales, true)) {
                $bloques[$i] = $nuevo;

                return $bloques;
            }
        }

        // Antes del bloque de validez, que va siempre al final.
        foreach ($bloques as $i => $b) {
            if (str_contains(self::normalizar($b['titulo'] ?? ''), 'validez')) {
                array_splice($bloques, $i, 0, [$nuevo]);

                return $bloques;
            }
        }

        $bloques[] = $nuevo;

        return $bloques;
    }

    /** Sin tildes, en minúsculas y sin signos: "Condic. de Pago" = "condic de pago". */
    public static function normalizar(string $texto): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($texto))));
    }
}

<?php

namespace App\Services;

/**
 * Lee una ficha pegada: etiqueta y valor.
 *
 * Las consultas que llegan desde la web vienen como una ficha de dos columnas:
 *
 *     NOMBRE                      EMAIL
 *     Diego Arce               darce@implantestraumatologicos.com
 *
 * Al copiarla de la pantalla las columnas se aplanan: el valor de la izquierda
 * y la etiqueta de la derecha quedan en el mismo renglon, separados por un
 * TAB. Y a veces llega ordenada a mano, "NOMBRE Diego Arce".
 *
 * Leida como texto corrido, las etiquetas pasaban por datos: el contacto de
 * DGS ANTIPINA se llamaba "DATOS DE CONTACTO" y tenia el cargo "NOMBRE".
 *
 * Se parte todo en celdas —renglones cortados por TAB— y se recorre. Una
 * celda que ES una etiqueta toma como valor la celda siguiente; una que
 * EMPIEZA con una etiqueta toma el resto. Cuando una etiqueta vuelve a
 * aparecer, empieza otra ficha: una consulta con tres items trae tres veces
 * MATERIAL, FORMA y CANTIDAD.
 */
final class CamposDeFormulario
{
    /**
     * Las fichas del texto, en orden.
     *
     * Devuelve vacio si no es una ficha: hacen falta dos etiquetas distintas
     * como minimo. Una firma con "Tel: 4555-3700" tiene una sola, y tiene que
     * seguir leyendose como firma.
     *
     * @param  array<string, list<string>>  $etiquetas  campo => como se escribe
     * @return list<array<string, string>>  campo => valor
     */
    public static function leer(string $texto, array $etiquetas): array
    {
        $celdas = [];

        foreach (preg_split('/\R/u', $texto) ?: [] as $renglon) {
            // "...largo 970 mm.Nombre: Carlos": al copiar del mail se pierde el
            // salto y el rotulo queda pegado al punto. Un rotulo empieza celda.
            foreach (preg_split('/(?<=[.!?])\s*(?=\p{Lu}[\p{L} \/]{0,30}:)|\t/u', $renglon) ?: [] as $celda) {
                $celda = trim($celda);

                if ($celda !== '') {
                    $celdas[] = $celda;
                }
            }
        }

        // Como se escribe => campo. Las largas primero: "ACLARACIONES /
        // OBSERVACIONES" tiene que ganarle a "ACLARACIONES".
        $variantes = [];

        foreach ($etiquetas as $campo => $formas) {
            foreach ($formas as $forma) {
                $variantes[self::plano($forma)] = $campo;
            }
        }

        uksort($variantes, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));

        $fichas = [];
        $actual = [];

        for ($i = 0, $n = count($celdas); $i < $n; $i++) {
            [$campo, $valor, $consumeLaSiguiente] = self::etiqueta($celdas[$i], $celdas[$i + 1] ?? null, $variantes);

            if ($campo === null) {
                continue;
            }

            if ($consumeLaSiguiente) {
                $i++;
            }

            // La etiqueta ya estaba: empieza la ficha siguiente.
            if (array_key_exists($campo, $actual)) {
                $fichas[] = $actual;
                $actual = [];
            }

            $actual[$campo] = $valor;
        }

        $fichas[] = $actual;

        // Una ficha es una ficha si tiene al menos dos campos con algo escrito.
        return array_values(array_filter(
            array_map(fn ($f) => array_filter($f, fn ($v) => $v !== ''), $fichas),
            fn ($f) => count($f) >= 2,
        ));
    }

    /**
     * Si la celda es una etiqueta, cual y con que valor.
     *
     * @param  array<string, string>  $variantes
     * @return array{0: ?string, 1: string, 2: bool} campo, valor, si el valor era la celda siguiente
     */
    private static function etiqueta(string $celda, ?string $siguiente, array $variantes): array
    {
        $plana = self::plano($celda);

        foreach ($variantes as $variante => $campo) {
            $variante = (string) $variante;

            // La etiqueta sola: el valor es la celda siguiente, salvo que la
            // siguiente sea otra etiqueta. Entonces el campo vino vacio.
            if ($plana === $variante) {
                if ($siguiente === null || self::esEtiqueta($siguiente, $variantes)) {
                    return [$campo, '', false];
                }

                return [$campo, $siguiente, true];
            }

            // "NOMBRE Diego Arce", "EMAIL: darce@..." — la etiqueta tiene
            // que terminar ahi: EMAILS no es EMAIL.
            if (str_starts_with($plana, $variante)
                && preg_match('/^[\s:.\-\/]/u', $resto = mb_substr($plana, mb_strlen($variante)))) {
                // Sin dos puntos, la etiqueta va en mayusculas como en la web:
                // "Empresa Habilitada para..." es una oracion de la firma de
                // Tormicron, no una etiqueta.
                $rotulo = mb_substr($celda, 0, mb_strlen($variante));

                if (! preg_match('/^\s*[:.\-\/]/u', $resto) && $rotulo !== mb_strtoupper($rotulo)) {
                    continue;
                }

                // plano() cambia letra por letra, asi que el largo coincide
                // con el del original y se corta en el mismo lugar. "Cargo /
                // Área: Compras": lo que va de la barra a los dos puntos
                // tambien es el rotulo.
                $resto = preg_replace('/^\s*\/[^:\d]{1,30}:/u', '', mb_substr($celda, mb_strlen($variante)));
                $valor = trim((string) $resto, " \t:.-");

                return [$campo, $valor, false];
            }
        }

        return [null, '', false];
    }

    /** @param  array<string, string>  $variantes */
    private static function esEtiqueta(string $celda, array $variantes): bool
    {
        return array_key_exists(self::plano($celda), $variantes);
    }

    /**
     * En minusculas y sin acentos, letra por letra.
     *
     * No junta espacios ni saca signos: cada letra se cambia por una sola, asi
     * la posicion en el texto plano es la misma que en el original.
     */
    private static function plano(string $texto): string
    {
        return strtr(mb_strtolower(trim($texto)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}

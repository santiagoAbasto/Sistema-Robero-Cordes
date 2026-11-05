<?php

namespace App\Services;

/**
 * Resaltar en negrita, y de un color, los textos de una cotización (pedido del
 * cliente, 04-11-2026: "poner asterisco al principio y al final, y que elijan el
 * color de la negrita": lo usa gente grande, tiene que ser lo más simple).
 *
 *     Entrega: *90 días* salvo *venta previa*.
 *
 * Lo que va entre asteriscos sale en NEGRITA, como en WhatsApp. El COLOR se
 * elige una vez por texto y vale para todo lo resaltado en él: se guarda al
 * principio, como un encabezado que la pantalla esconde ("{#rojo}"). Sin
 * encabezado, el resaltado es negro.
 *
 * No se guarda HTML: primero se ESCAPA el texto y recién después se arma el
 * HTML, así que no hay forma de colar código en una hoja.
 *
 * Cuándo un asterisco cuenta (para que "5 * 10", "2*3" o "SA*" no se confundan):
 *  - el de apertura va al principio o después de algo que no es letra ni número,
 *    y lo que sigue no es un espacio: "*así", no "* así" ni "a*b";
 *  - el de cierre va después de algo que no es un espacio, y lo que sigue no es
 *    letra ni número: "así*", no "así *" ni "así*b";
 *  - no cruza renglones: lo resaltado tiene que estar en una misma línea.
 * Un asterisco que no cumple queda como un asterisco común.
 *
 * LA MISMA REGLA corre en el navegador (frontend/src/lib/formato.ts, que dibuja
 * la vista previa). Si se cambia una, se cambia la otra: las pruebas de las dos
 * usan los mismos casos.
 *
 * No se usa en la DESCRIPCIÓN del renglón: ese texto lo leen el buscador, el
 * enlazador de líneas y la IA, y un asterisco en el medio de una frase la
 * dejaría de encontrar.
 */
class FormatoDeTexto
{
    /** Colores con los que se imprime: legibles sobre blanco y en fotocopia. */
    public const COLORES = [
        'rojo' => '#b91c1c',
        'azul' => '#1d4ed8',
        'verde' => '#15803d',
        'naranja' => '#c2410c',
        'violeta' => '#6d28d9',
    ];

    private const ENCABEZADO = '/^\{#(rojo|azul|verde|naranja|violeta)\}/i';

    /**
     * El texto partido en trozos: lo resaltado va con negrita y, si se eligió
     * un color, con ese color. Lo demás, común.
     *
     * @return array<int, array{texto: string, negrita: bool, color: ?string}>
     */
    public static function trozos(?string $texto): array
    {
        [$color, $cuerpo] = self::separar($texto);

        if ($cuerpo === '') {
            return [];
        }

        $c = mb_str_split($cuerpo);
        $n = count($c);
        $trozos = [];
        $suelto = '';

        for ($i = 0; $i < $n; $i++) {
            if ($c[$i] === '*' && self::abre($c, $i)) {
                $j = self::cierre($c, $i);

                if ($j !== null) {
                    if ($suelto !== '') {
                        $trozos[] = ['texto' => $suelto, 'negrita' => false, 'color' => null];
                        $suelto = '';
                    }

                    $trozos[] = ['texto' => implode('', array_slice($c, $i + 1, $j - $i - 1)), 'negrita' => true, 'color' => $color];
                    $i = $j;

                    continue;
                }
            }

            $suelto .= $c[$i];
        }

        if ($suelto !== '') {
            $trozos[] = ['texto' => $suelto, 'negrita' => false, 'color' => null];
        }

        return $trozos;
    }

    /** @return array{0: ?string, 1: string}  [color elegido, texto sin el encabezado] */
    public static function separar(?string $texto): array
    {
        $texto ??= '';

        if (preg_match(self::ENCABEZADO, $texto, $m)) {
            return [strtolower($m[1]), substr($texto, strlen($m[0]))];
        }

        return [null, $texto];
    }

    /** El texto sin encabezado ni asteriscos: lo que leería quien no ve el formato. */
    public static function sinFormato(?string $texto): string
    {
        return implode('', array_column(self::trozos($texto), 'texto'));
    }

    /**
     * El HTML seguro para la hoja. Se imprime con {!! !!}: todo lo que viene
     * del texto sale escapado, y los saltos de línea como <br>.
     */
    public static function html(?string $texto): string
    {
        $html = '';

        foreach (self::trozos($texto) as $t) {
            $parte = nl2br(e($t['texto']));

            if ($t['negrita']) {
                $parte = '<b>'.$parte.'</b>';

                if ($t['color'] !== null) {
                    $parte = '<span style="color:'.self::COLORES[$t['color']].'">'.$parte.'</span>';
                }
            }

            $html .= $parte;
        }

        return $html;
    }

    private static function esLetraONumero(string $c): bool
    {
        return (bool) preg_match('/^[\p{L}\p{N}]$/u', $c);
    }

    private static function esEspacio(string $c): bool
    {
        return (bool) preg_match('/^\s$/u', $c);
    }

    /** ¿Este asterisco puede abrir un resaltado? */
    private static function abre(array $c, int $i): bool
    {
        $antes = $i > 0 ? $c[$i - 1] : null;
        $despues = $c[$i + 1] ?? null;

        return ($antes === null || (! self::esLetraONumero($antes) && $antes !== '*'))
            && $despues !== null && ! self::esEspacio($despues) && $despues !== '*';
    }

    /** El lugar del asterisco que cierra al de $i, o null si no hay en esa línea. */
    private static function cierre(array $c, int $i): ?int
    {
        for ($j = $i + 1, $n = count($c); $j < $n; $j++) {
            if ($c[$j] === "\n" || $c[$j] === "\r") {
                return null;
            }

            if ($c[$j] !== '*') {
                continue;
            }

            $despues = $c[$j + 1] ?? null;

            if (! self::esEspacio($c[$j - 1]) && $c[$j - 1] !== '*'
                && ($despues === null || (! self::esLetraONumero($despues) && $despues !== '*'))) {
                return $j;
            }
        }

        return null;
    }
}

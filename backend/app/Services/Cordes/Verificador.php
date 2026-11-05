<?php

namespace App\Services\Cordes;

use App\Services\Buscador\Texto;

/**
 * La REGLA DE ORO hecha código: lo que Cordes dice tiene que estar en los datos.
 *
 * Pedirle a una IA que "no invente" no alcanza. Acá se comprueba, sin IA y sin excepciones:
 *
 *  · Cada NÚMERO del texto (cifras, importes, medidas, fechas, códigos como «N04400» o «2026-0741 R1») tiene que
 *    aparecer en lo que devolvieron las herramientas o en lo que escribió la persona. Un número redondeado
 *    cuenta si es un redondeo correcto de uno de los datos («4,5» por 4,4983): redondear no es inventar.
 *  · Cada nombre en **negrita** (así marca Cordes lo importante) tiene que ser un nombre que devolvió una herramienta.
 *
 * Lo que no se puede comprobar se devuelve en `sinComprobar()`; quien llama decide qué hacer (reintentar, o no
 * mostrar el texto y mostrar los datos tal cual).
 */
final class Verificador
{
    /** @var list<float> */
    private array $numeros = [];

    /** @var list<string> textos de los datos, normalizados */
    private array $textos = [];

    /** Suma datos que SÍ valen: lo que devolvió una herramienta, o escribió la persona. */
    public function agregar(mixed $dato): void
    {
        if (is_array($dato)) {
            // Cuántos elementos trae una lista también es un dato: "son 3 empresas".
            if (array_is_list($dato) && $dato !== []) {
                $this->numeros[] = (float) count($dato);
            }

            foreach ($dato as $clave => $valor) {
                if (is_string($clave) && ! is_numeric($clave)) {
                    $this->textos[] = Texto::normalizar($clave);
                }

                $this->agregar($valor);
            }

            return;
        }

        if (is_bool($dato) || $dato === null) {
            return;
        }

        if (is_int($dato) || is_float($dato)) {
            $this->numeros[] = (float) $dato;

            return;
        }

        $t = (string) $dato;
        $this->textos[] = Texto::normalizar($t);

        foreach ($this->numerosDe($t) as $n) {
            // Los códigos y las fechas ya quedan guardados como texto.
            is_nan($n['valor']) || $this->numeros[] = $n['valor'];
        }
    }

    /**
     * Lo del texto que no sale de ningún dato.
     *
     * @return list<string>
     */
    public function sinComprobar(string $texto): array
    {
        $malos = [];

        // «20 de septiembre de 2026»: vale si ESA fecha está en los datos (no alcanza con que estén sus números sueltos).
        $texto = preg_replace_callback('/\b(\d{1,2})\s+de\s+('.implode('|', array_keys(Texto::MESES)).')(?:\s+de(?:l)?\s+(\d{4}))?/iu', function ($m) use (&$malos) {
            $mes = Texto::MESES[Texto::normalizar($m[2])] ?? null;

            if ($mes === null) {
                return $m[0];
            }

            $dia = sprintf('%02d-%02d', $mes, (int) $m[1]);
            $existe = isset($m[3]) && $m[3] !== ''
                ? $this->hayCadena(sprintf('%04d-%s', $m[3], $dia))
                : $this->hayCadenaTerminada('-'.$dia);

            $existe || $malos[] = trim($m[0]);

            return ' ';
        }, $texto) ?? $texto;

        foreach ($this->numerosDe($texto) as $n) {
            if (! $this->hayNumero($n['valor'], $n['decimales'], $n['crudo'])) {
                $malos[] = $n['crudo'];
            }
        }

        if (preg_match_all('/\*\*(.+?)\*\*/u', $texto, $m)) {
            foreach ($m[1] as $negrita) {
                // Lo que lleva números ya se comprobó arriba.
                if (preg_match('/\d/', $negrita) || $this->hayTexto($negrita)) {
                    continue;
                }

                $malos[] = trim($negrita);
            }
        }

        return array_values(array_unique($malos));
    }

    /**
     * Los números de un texto, con cómo se escriben en castellano: «1.486,28», «38,1», «4,5 kg», «04/11/2026».
     * Las fechas, los códigos con letras y las numeraciones de lista no cuentan como cantidades: se miran como texto.
     *
     * @return list<array{valor: float, decimales: int, crudo: string}>
     */
    public function numerosDe(string $texto): array
    {
        $salida = [];

        // Un "número" es un conjunto de cifras con sus separadores, con hasta tres letras antes (N04400, C-276, R1)
        // o después (316L, 38,1mm). Nunca se junta con la palabra de al lado.
        if (! preg_match_all('/(?<![\p{L}\d])[A-Za-z]{0,3}-?\d[\d.,\/\-:]*[\p{L}]{0,3}(?![\p{L}\d])/u', $texto, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $unidades = 'mm|cm|m|in|ft|kg|kgs|g|lb|lbs|ton|tn|mt|mts|kw|hs|hr|h|s';

        foreach ($m[0] as [$crudo, $pos]) {
            $limpio = rtrim($crudo, '.,;:-/');

            // "1." / "2." al principio de línea: es la numeración de una lista.
            if (preg_match('/^\d{1,2}\.$/', $crudo) && ($pos === 0 || str_ends_with(substr($texto, 0, $pos), "\n"))) {
                continue;
            }

            // "38,1mm", "20kg": el número con su unidad pegada.
            if (preg_match('/^(\d[\d.,]*?)(?:'.$unidades.')$/i', $limpio, $u)) {
                $limpio = rtrim($u[1], '.,');
            }

            // Una fecha o un código: no es una cantidad, se busca tal cual.
            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$|^\d{4}-\d{2}-\d{2}$|[A-Za-z]|\d-\d/', $limpio)) {
                $salida[] = ['valor' => NAN, 'decimales' => 0, 'crudo' => $limpio];

                continue;
            }

            $n = $this->aNumero($limpio);

            if ($n !== null) {
                $salida[] = $n + ['crudo' => $limpio];
            }
        }

        return $salida;
    }

    /** @return array{valor: float, decimales: int}|null */
    private function aNumero(string $t): ?array
    {
        // 1.486,28 · 1486,28 · 1486.28 · 38,1 · 4.510 (¿miles o decimales? si el grupo tiene 3 cifras y hay más de un punto, o también coma, son miles)
        if (preg_match('/^\d{1,3}(?:\.\d{3})+(?:,(\d+))?$/', $t, $m)) {
            $entero = str_replace('.', '', explode(',', $t)[0]);

            return ['valor' => (float) ($entero.(isset($m[1]) ? '.'.$m[1] : '')), 'decimales' => isset($m[1]) ? strlen($m[1]) : 0];
        }

        if (preg_match('/^\d+(?:[.,](\d+))?$/', $t, $m)) {
            return ['valor' => (float) str_replace(',', '.', $t), 'decimales' => isset($m[1]) ? strlen($m[1]) : 0];
        }

        return null;
    }

    private function hayNumero(float $valor, int $decimales, string $crudo): bool
    {
        // Fechas y códigos: tal cual, en algún dato.
        if (is_nan($valor)) {
            return $this->hayCadena($crudo);
        }

        $tolerancia = $decimales > 0 ? 0.5 * 10 ** -$decimales + 1e-9 : 1e-9;

        foreach ($this->numeros as $n) {
            if (abs($n - $valor) <= $tolerancia) {
                return true;
            }

            // «Redondeado»: 4,5 por 4,4983; también miles: «1.486» por 1486,28 no vale (es otro número).
            if ($decimales > 0 && abs(round($n, $decimales) - $valor) < 1e-9) {
                return true;
            }
        }

        return false;
    }

    /** Un código o una fecha, tal cual figura en algún dato (o su versión dd/mm/aaaa ↔ aaaa-mm-dd). */
    private function hayCadena(string $crudo): bool
    {
        $c = Texto::normalizar($crudo);
        $opciones = [$c];

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $crudo, $m)) {
            $opciones[] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            $opciones[] = sprintf('%02d/%02d/%04d', $m[1], $m[2], $m[3]);
        }

        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $crudo, $m)) {
            $opciones[] = "{$m[3]}/{$m[2]}/{$m[1]}";
        }

        foreach ($opciones as $o) {
            foreach ($this->textos as $t) {
                if ($t !== '' && str_contains($t, Texto::normalizar($o))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** ¿Alguna fecha ISO de los datos termina en «-mm-dd»? (una fecha escrita sin año) */
    private function hayCadenaTerminada(string $final): bool
    {
        foreach ($this->textos as $t) {
            if (preg_match('/\d{4}'.preg_quote($final, '/').'(?!\d)/', $t)) {
                return true;
            }
        }

        return false;
    }

    private function hayTexto(string $negrita): bool
    {
        $n = Texto::normalizar(trim($negrita, " \t.:,;"));

        if ($n === '') {
            return true;
        }

        foreach ($this->textos as $t) {
            if ($t !== '' && (str_contains($t, $n) || (mb_strlen($t) >= 4 && str_contains($n, $t)))) {
                return true;
            }
        }

        return false;
    }
}

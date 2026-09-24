<?php

namespace App\Services;

/**
 * Resuelve una formula escrita en texto.
 *
 * Las formulas son datos: viven en la tabla de formas y se pueden corregir sin
 * tocar codigo. Por eso NO se usa eval(): lo que hay guardado se lee token por
 * token y solo se permiten numeros, las medidas de esa forma, los operadores
 * + - * / ^ y las funciones de la lista. Cualquier otra cosa es un error, no
 * algo que se ejecuta.
 *
 * Ejemplo: "(pi * pow(diameter, 2) / 4 * length) / 1000"
 */
class EvaluadorDeFormulas
{
    /** @var list<array{tipo: string, valor: mixed}> */
    private array $tokens = [];

    private int $posicion = 0;

    /** @var array<string, callable> */
    private const FUNCIONES = [
        'abs' => 'abs',
        'max' => 'max',
        'min' => 'min',
        'pow' => 'pow',
        'sqrt' => 'sqrt',
    ];

    /**
     * @param  array<string, float>  $valores  Las medidas, ya en milimetros.
     *
     * @throws \InvalidArgumentException si la formula no se entiende
     */
    public function evaluar(string $formula, array $valores): float
    {
        $this->tokens = $this->separarEnTokens($formula);
        $this->posicion = 0;

        $resultado = $this->expresion($valores);

        if ($this->posicion < count($this->tokens)) {
            throw new \InvalidArgumentException(
                'Formula invalida cerca de "'.$this->tokens[$this->posicion]['valor'].'"'
            );
        }

        if (! is_finite($resultado)) {
            throw new \InvalidArgumentException('La formula no da un numero');
        }

        return $resultado;
    }

    /** Como evaluar(), pero devuelve null en vez de fallar. */
    public function evaluarONada(?string $formula, array $valores): ?float
    {
        if (! $formula) {
            return null;
        }

        try {
            return $this->evaluar($formula, $valores);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Deja usable una formula copiada de una planilla.
     *
     * Nadie escribe estas cuentas a mano: las traen de un Excel. Se le sacan
     * las cosas propias de la planilla y queda como las entiende el evaluador.
     */
    public function desdeExcel(string $texto): string
    {
        $pasos = [
            '/^\s*=/' => '',                    // el = del principio
            '/[×·]/u' => '*',
            '/÷/u' => '/',
            '/(\d),(\d)/' => '$1.$2',           // coma decimal: 2,5 -> 2.5
            // El Excel en español separa argumentos con punto y coma. Va
            // DESPUES de la coma decimal, para no confundir 2,5 con dos valores.
            '/;/' => ',',
            '/\bPI\s*\(\s*\)/i' => 'pi',
            '/\b(POTENCIA|POWER)\s*\(/i' => 'pow(',
            '/\b(RAIZ|SQRT)\s*\(/i' => 'sqrt(',
            '/\bABS\s*\(/i' => 'abs(',
            '/\bMAX\s*\(/i' => 'max(',
            '/\bMIN\s*\(/i' => 'min(',
        ];

        $formula = trim($texto);

        foreach ($pasos as $de => $a) {
            $formula = preg_replace($de, $a, $formula) ?? $formula;
        }

        return trim($formula);
    }

    /**
     * Los nombres de medida que usa la formula.
     *
     * Sirve para no dejar guardar una cuenta que nombra una medida que la forma
     * no pide: daria cero callado y el peso saldria mal sin que nadie lo note.
     *
     * @return list<string>
     */
    public function variables(string $formula): array
    {
        $tokens = $this->separarEnTokens($formula);
        $nombres = [];

        foreach ($tokens as $i => $token) {
            if ($token['tipo'] !== 'nombre' || $token['valor'] === 'pi') {
                continue;
            }

            // Si lo sigue un parentesis es una funcion, no una medida.
            if (($tokens[$i + 1]['valor'] ?? null) === '(') {
                continue;
            }

            $nombres[] = (string) $token['valor'];
        }

        return array_values(array_unique($nombres));
    }

    // ------------------------------------------------------------- gramatica

    private function expresion(array $valores): float
    {
        $valor = $this->termino($valores);

        while (in_array($this->proximo(), ['+', '-'], true)) {
            $operador = $this->consumir()['valor'];
            $derecha = $this->termino($valores);

            $valor = $operador === '+' ? $valor + $derecha : $valor - $derecha;
        }

        return $valor;
    }

    private function termino(array $valores): float
    {
        $valor = $this->potencia($valores);

        while (in_array($this->proximo(), ['*', '/'], true)) {
            $operador = $this->consumir()['valor'];
            $derecha = $this->potencia($valores);

            if ($operador === '/' && $derecha == 0.0) {
                throw new \InvalidArgumentException('La formula divide por cero');
            }

            $valor = $operador === '*' ? $valor * $derecha : $valor / $derecha;
        }

        return $valor;
    }

    private function potencia(array $valores): float
    {
        $valor = $this->unario($valores);

        if ($this->proximo() === '^') {
            $this->consumir();
            $valor = $valor ** $this->potencia($valores);
        }

        return $valor;
    }

    private function unario(array $valores): float
    {
        if ($this->proximo() === '+') {
            $this->consumir();

            return $this->unario($valores);
        }

        if ($this->proximo() === '-') {
            $this->consumir();

            return -$this->unario($valores);
        }

        return $this->primario($valores);
    }

    private function primario(array $valores): float
    {
        $token = $this->tokens[$this->posicion] ?? null;

        if (! $token) {
            throw new \InvalidArgumentException('Formula incompleta');
        }

        if ($token['tipo'] === 'numero') {
            $this->consumir();

            return (float) $token['valor'];
        }

        if ($token['tipo'] === 'nombre') {
            return $this->nombre($valores);
        }

        if ($token['valor'] === '(') {
            $this->consumir();
            $valor = $this->expresion($valores);
            $this->esperar(')');

            return $valor;
        }

        throw new \InvalidArgumentException('No se esperaba "'.$token['valor'].'"');
    }

    /** Una funcion, la constante pi, o una de las medidas de la forma. */
    private function nombre(array $valores): float
    {
        $nombre = $this->consumir()['valor'];

        if ($this->proximo() === '(') {
            $this->consumir();
            $argumentos = [];

            if ($this->proximo() !== ')') {
                do {
                    $argumentos[] = $this->expresion($valores);

                    if ($this->proximo() !== ',') {
                        break;
                    }

                    $this->consumir();
                } while (true);
            }

            $this->esperar(')');

            if (! isset(self::FUNCIONES[$nombre])) {
                throw new \InvalidArgumentException('Funcion no permitida: '.$nombre);
            }

            return (float) (self::FUNCIONES[$nombre])(...$argumentos);
        }

        if ($nombre === 'pi') {
            return M_PI;
        }

        // Una medida que no vino queda en cero: la validacion de mas arriba es
        // la que avisa que falta cargarla.
        return (float) ($valores[$nombre] ?? 0);
    }

    // --------------------------------------------------------------- lexico

    /** @return list<array{tipo: string, valor: mixed}> */
    private function separarEnTokens(string $formula): array
    {
        $texto = preg_replace('/\s+/', '', $formula) ?? '';
        $tokens = [];
        $i = 0;
        $largo = strlen($texto);

        while ($i < $largo) {
            $resto = substr($texto, $i);

            if (preg_match('/^\d+(?:\.\d+)?/', $resto, $m)) {
                $tokens[] = ['tipo' => 'numero', 'valor' => (float) $m[0]];
                $i += strlen($m[0]);

                continue;
            }

            if (preg_match('/^[a-z][a-z0-9_]*/i', $resto, $m)) {
                $tokens[] = ['tipo' => 'nombre', 'valor' => $m[0]];
                $i += strlen($m[0]);

                continue;
            }

            $caracter = $texto[$i];

            if (str_contains('+-*/^(),', $caracter)) {
                $tokens[] = ['tipo' => 'operador', 'valor' => $caracter];
                $i++;

                continue;
            }

            throw new \InvalidArgumentException('Caracter no permitido: '.$caracter);
        }

        return $tokens;
    }

    private function proximo(): ?string
    {
        $token = $this->tokens[$this->posicion] ?? null;

        return $token && $token['tipo'] !== 'numero' ? (string) $token['valor'] : null;
    }

    /** @return array{tipo: string, valor: mixed} */
    private function consumir(): array
    {
        if (! isset($this->tokens[$this->posicion])) {
            throw new \InvalidArgumentException('Formula incompleta');
        }

        return $this->tokens[$this->posicion++];
    }

    private function esperar(string $esperado): void
    {
        if ($this->proximo() !== $esperado) {
            throw new \InvalidArgumentException('Falta "'.$esperado.'" en la formula');
        }

        $this->consumir();
    }
}

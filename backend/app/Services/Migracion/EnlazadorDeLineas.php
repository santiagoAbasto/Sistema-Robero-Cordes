<?php

namespace App\Services\Migracion;

/**
 * Reconoce el material y la forma dentro del texto de una linea historica.
 *
 * Las 13.071 lineas que vinieron del sistema anterior son texto libre con el
 * precio al lado: "10 UN TIT GR1 BARRA 1.60 X 915 MM, C/U (+IVA)". El material
 * y la forma estan escritos ahi pero no enlazados, y sin ese enlace el
 * historial de precios por material —que es lo que promete la pantalla de
 * entrada— no existe.
 *
 * COMO BUSCA. No por substring. Buscar "ACE INOX" dentro de "ACE INOX F138"
 * devuelve el inoxidable generico y se pierde el F138, que es justamente lo
 * que distingue al material. Aca cada entrada del catalogo se parte en tokens
 * y se le exige que TODOS aparezcan en la descripcion; gana la que suma mas
 * peso.
 *
 * EL PESO sale de los datos, no de una lista escrita a mano: un token que
 * aparece en media tabla ("acero", "aleacion") no distingue nada, y uno que
 * aparece en una sola entrada ("f138", "n06600") lo dice todo.
 *
 * Y SOBRE TODO: no enlaza cuando no esta seguro. Una linea sin material se
 * completa despues; una enlazada al material equivocado ensucia el historial
 * de precios sobre el que se cotiza y nadie la revisa. Medido sobre muestras
 * al azar de las lineas reales, acierta 39 de cada 40 de las que enlaza.
 */
class EnlazadorDeLineas
{
    /** Relleno que no identifica nada, de ningun lado. */
    private const RELLENO = [
        'de', 'del', 'la', 'el', 'y', 'o', 'con', 'sin', 'para', 'por', 'en', 'al',
        'mm', 'cm', 'un', 'kg', 'tn', 'mt', 'm', 'x', 'iva', 'cu', 'pm', 'pkg',
    ];

    /**
     * Los nombres de forma, que son ruido cuando se busca el MATERIAL.
     *
     * "barra" no dice de que es la barra. Para buscar la forma es al reves, asi
     * que cada lado usa su propia lista: con una sola, las formas no
     * encontraban ni su propio nombre.
     */
    private const FORMAS_PALABRA = [
        'barra', 'tubo', 'chapa', 'cano', 'disco', 'anillo', 'varilla', 'alambre',
        'perfil', 'brida', 'arandela', 'esfera', 'planchuela', 'fleje', 'malla',
        'tuerca', 'bulon', 'tornillo', 'buje', 'placa',
        'redonda', 'cuadrada', 'hexagonal', 'rectangular',
    ];

    /** Un token con letras Y numeros: f138, c276, x750, a234, 316l. */
    private const DESIGNACION = '/^(?=.*[a-z])(?=.*\d)[a-z0-9]{3,}$/';

    /** ...salvo que sea una medida con la unidad pegada: 1000mm, 25kg. */
    private const MEDIDA = '/^\d+(mm|cm|m|kg|tn|gr|un|pulg)$/';

    /** @var array<int, array{id: int, tokens: array<string, true>}> */
    private array $candidatos = [];

    /** @var array<string, float> */
    private array $peso = [];

    /** Cuanto tiene que pesar el ganador. Sale de exigencia y del tamaño. */
    private float $minimo;

    private array $ruido;

    /**
     * @param  array<int, array{0: int, 1: string}>  $entradas  id y texto: nombres y alias
     * @param  bool  $material  true para el catalogo de materiales, false para formas
     * @param  float  $exigencia  que parte del peso maximo posible tiene que
     *                             alcanzar el ganador, entre 0 y 1. Va en
     *                             proporcion y no en un numero fijo porque el
     *                             peso depende del tamaño del catalogo: con
     *                             2.000 entradas el maximo es log(2.000)=7,6
     *                             y con 13 es log(13)=2,6, asi que un umbral
     *                             fijo de 3,9 no deja pasar nada en el
     *                             segundo.
     * @param  array<int, string>  $ruidoExtra  palabras que en este lado no
     *                                          identifican nada. Buscando
     *                                          material se le pasan los
     *                                          nombres de las formas: el
     *                                          catalogo tiene entradas como
     *                                          "Agujas" o "Malla Tejida", que
     *                                          son formas anotadas como
     *                                          material y enganchan el
     *                                          "BISMUTO AGUJAS" de cualquier
     *                                          descripcion.
     */
    public function __construct(
        array $entradas,
        private bool $material = true,
        private float $exigencia = 0.51,
        array $ruidoExtra = [],
    ) {
        $this->ruido = array_flip(array_merge(
            $material ? array_merge(self::RELLENO, self::FORMAS_PALABRA) : self::RELLENO,
            $ruidoExtra,
        ));

        $frecuencia = [];

        foreach ($entradas as [$id, $texto]) {
            $tokens = $this->tokens($texto);

            if ($tokens === [] || ! $this->sirveParaBuscar($tokens)) {
                continue;
            }

            $this->candidatos[] = ['id' => (int) $id, 'tokens' => array_flip($tokens)];

            foreach ($tokens as $t) {
                $frecuencia[$t] = ($frecuencia[$t] ?? 0) + 1;
            }
        }

        $total = max(count($this->candidatos), 1);

        foreach ($frecuencia as $t => $veces) {
            $this->peso[$t] = log($total / $veces);
        }

        // El maximo posible es el de un token que aparece en una sola entrada.
        $this->minimo = log(max($total, 2)) * $this->exigencia;
    }

    /** El id del material o la forma que nombra ese texto, o null si no esta claro. */
    public function elegir(string $descripcion): ?int
    {
        $presentes = array_flip($this->tokens($descripcion));

        if ($presentes === []) {
            return null;
        }

        $mejor = null;
        $puntajeMejor = 0.0;
        $empatado = false;

        foreach ($this->candidatos as $c) {
            // Se le exige que TODOS sus tokens esten en la descripcion.
            if (array_diff_key($c['tokens'], $presentes) !== []) {
                continue;
            }

            $puntaje = 0.0;

            foreach (array_keys($c['tokens']) as $t) {
                $puntaje += $this->peso[$t] ?? 0;
            }

            if ($puntaje > $puntajeMejor + 0.01) {
                [$mejor, $puntajeMejor, $empatado] = [$c, $puntaje, false];
            } elseif (abs($puntaje - $puntajeMejor) <= 0.01 && $mejor && $c['id'] !== $mejor['id']) {
                // Dos materiales distintos empatados: no se adivina.
                $empatado = true;
            }
        }

        if ($mejor === null || $empatado || $puntajeMejor < $this->minimo) {
            return null;
        }

        /*
          La guarda de precision, y solo para el material.

          Si la descripcion nombra una designacion que el ganador no cubre, no
          se enlaza: "TITANIO F136" no es "Titanio". No hace falta que el
          catalogo conozca al F136 — que no este cargado no lo vuelve menos
          especifico.

          Para la forma no corre: el resto de la descripcion es el material y
          las medidas, que la forma nunca va a cubrir.
        */
        if ($this->material) {
            foreach (array_keys(array_diff_key($presentes, $mejor['tokens'])) as $t) {
                if (preg_match(self::DESIGNACION, $t) && ! preg_match(self::MEDIDA, $t)) {
                    return null;
                }
            }
        }

        return $mejor['id'];
    }

    /**
     * Una entrada sirve para buscar si alguno de sus tokens dice algo solo.
     *
     * Las que solo tienen "50", "sc" o "a 210" no sirven: enganchan el
     * "APROX. 210 KGS" o el "S/C" de cualquier descripcion y devuelven un
     * material que no tiene nada que ver. Siguen en el catalogo y se eligen a
     * mano; lo que no se puede es adivinarlas desde el texto.
     *
     * @param  array<int, string>  $tokens
     */
    private function sirveParaBuscar(array $tokens): bool
    {
        foreach ($tokens as $t) {
            if (preg_match(self::DESIGNACION, $t) || (ctype_alpha($t) && mb_strlen($t) >= 4)) {
                return true;
            }
        }

        return false;
    }

    /** Las palabras de estos textos, para pasarlas de ruido al otro lado. */
    public function vocabulario(): array
    {
        return array_keys($this->peso);
    }

    /** @return array<int, string> */
    private function tokens(string $texto): array
    {
        preg_match_all('/[a-z0-9]+/', $this->plano($texto), $m);

        return array_values(array_filter(
            array_unique($m[0]),
            fn (string $t) => mb_strlen($t) > 1 && ! isset($this->ruido[$t]),
        ));
    }

    /** Todo a una sola forma de escribirse antes de comparar. */
    private function plano(string $texto): string
    {
        $t = mb_strtolower($texto);
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n']);

        /*
          El simbolo de diametro se va, no se convierte en letra.

          Pasarlo a "o" lo pegaba al numero de al lado y fabricaba
          designaciones que no existen: "Ø38.1" quedaba en "o381", que parece
          un grado de material y disparaba la guarda. Asi se perdia el enlace
          de toda descripcion que escribiera el diametro con el simbolo, que
          son la mayoria. En el Access el Ø quedo guardado como Ý.
        */
        $t = str_replace(['ø', 'Ø', 'ý', 'Ý', '⌀'], ' ', $t);

        // "GR 2", "GR.2" y "GRADO 2" son el mismo grado escrito de tres maneras.
        $t = preg_replace('/\bgrado\s*(\d+)/', 'gr$1', $t);
        $t = preg_replace('/\bgr[\s.]*(\d+)/', 'gr$1', $t);

        // "C-276" y "C 276" son uno solo; "25-22-2" tambien.
        $t = preg_replace('/(?<=[a-z0-9])[-.\/](?=[a-z0-9])/', '', $t);

        /*
          "AISI 316 L" es 316L: la letra suelta detras de un numero es el
          sufijo del grado, y 316L no es lo mismo que 316.

          La x queda afuera: en "1.60 X 915" separa dimensiones, no es ningun
          grado. Pegarla daba el token "160x", que parece una designacion y
          hacia que la guarda descartara la linea entera.
        */
        $t = preg_replace('/\b(\d{2,4})\s+(?!x\b)([a-z])\b/', '$1$2', $t);

        return trim(preg_replace('/\s+/', ' ', $t));
    }
}

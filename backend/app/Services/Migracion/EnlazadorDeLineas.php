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
 * de precios sobre el que se cotiza y nadie la revisa. Auditado el 05-11-2026
 * contra las 13.071 lineas locales: de 557 enlaces, 14 eran erroneos o dudosos
 * —una aleacion de aluminio con berilio enlazada al berilio, diez Hastelloy sin
 * grado que caian en el X— y ahora quedan sin enlazar.
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
     * Las palabras que son el nombre entero de una entrada: "Cobre", "Plata", "CRISOL".
     *
     * @var array<string, true>
     */
    private array $solas = [];

    /**
     * Palabras que el ruido de las formas tira, pero que dentro del nombre de un material hay que
     * respetar: "cobre" y "plata" ("ALAMBRE CON ALMA DE COBRE", "FUNDENTE SOLD. PLATA") y los numeros
     * de las formas ("CODO 90") que tambien son el grado de "Nimonic 90" o "Cu-Ni 45".
     *
     * @var array<string, true>
     */
    private array $exigibles = [];

    /**
     * De esas, las que nombran un material por si solas: un texto que las dice no se enlaza a un
     * material que no las dice.
     *
     * @var array<string, true>
     */
    private array $nombranMaterial = [];

    /**
     * Los tokens de todos los nombres y alias de cada material.
     *
     * @var array<int, array<string, true>>
     */
    private array $tokensDelMaterial = [];

    /**
     * Los materiales que comparten exactamente las mismas palabras, por huella: "Hastelloy X", "Hastelloy W" y
     * "Hastelloy" se reducen a las mismas. Solo en ellos la letra suelta es lo que distingue.
     *
     * @var array<string, array<int, true>>
     */
    private array $idsPorHuella = [];

    /** Palabras que no cuentan al medir cuan cerca esta una de otra: "COBRE Y TUNGSTENO", "PLATA CON 10% CADMIO". */
    private const CONECTORES = ['de', 'del', 'con', 'y', 'e', 'en', 'al', 'a', 'o', 'u', 'por', 'para', 'el', 'la', 'los', 'las', 'sobre'];

    /**
     * Los materiales que el catalogo deja marcados para no usar: pueden bloquear una linea dudosa, no ganarla.
     *
     * @var array<int, true>
     */
    private array $vetados = [];

    /**
     * Todas las palabras de cada material, de su nombre y de sus alias.
     *
     * @var array<int, array<string, true>>
     */
    private array $palabrasDelMaterial = [];

    /**
     * Los juegos de tokens de cada entrada, por id: "Acero Inoxidable" y "ACE INOX" son del mismo material.
     *
     * @var array<int, list<array<string, true>>>
     */
    private array $familias = [];

    /**
     * Las entradas pueden marcarse como alias con un tercer valor true: en un empate, el nombre
     * propio de un material le gana al alias de otro ("Monel 400" es un material y también un
     * alias de "Monel": la línea "MONEL 400 …" es del primero).
     *
     * @param  array<int, array{0: int, 1: string, 2?: bool}>  $entradas  id, texto y si es alias: nombres y alias
     * @param  bool  $material  true para el catalogo de materiales, false para formas
     * @param  float  $exigencia  que parte del peso maximo posible tiene que
     *                            alcanzar el ganador, entre 0 y 1. Va en
     *                            proporcion y no en un numero fijo porque el
     *                            peso depende del tamaño del catalogo: con
     *                            2.000 entradas el maximo es log(2.000)=7,6
     *                            y con 13 es log(13)=2,6, asi que un umbral
     *                            fijo de 3,9 no deja pasar nada en el
     *                            segundo.
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
     * @param  array<int, string>  $formasEnteras  las palabras que son el nombre entero de una forma
     *                                             ("CRISOL"): esas siguen siendo ruido aunque tambien sean
     *                                             un material.
     * @param  array<int, int>  $vetados  los ids de los materiales marcados para no usar ("... NO USAR"):
     *                                    el enlace nunca los elige, pero cuentan como rivales.
     */
    public function __construct(
        array $entradas,
        private bool $material = true,
        private float $exigencia = 0.51,
        array $ruidoExtra = [],
        array $formasEnteras = [],
        array $vetados = [],
    ) {
        $this->vetados = array_fill_keys(array_map('intval', $vetados), true);
        // Las palabras llegan como claves de un array y un "90" se vuelve entero: se las compara como texto.
        $ruidoExtra = array_map('strval', $ruidoExtra);

        foreach ($entradas as $entrada) {
            $palabras = $this->palabras($entrada[1]);

            if (count($palabras) === 1 && ctype_alpha($palabras[0]) && strlen($palabras[0]) >= 4) {
                $this->solas[$palabras[0]] = true;
            }

            $this->palabrasDelMaterial[(int) $entrada[0]] = ($this->palabrasDelMaterial[(int) $entrada[0]] ?? []) + array_fill_keys($palabras, true);
        }

        /*
          "Cobre" y "Plata" son materiales, pero hay formas con esas palabras
          ("ALAMBRE CON ALMA DE COBRE", "FUNDENTE SOLD. PLATA") y su vocabulario
          entero se toma como ruido para no enganchar "BISMUTO AGUJAS". Con eso
          "COBRE TUNGSTENO 70/30" quedaba en "Tungsteno Plata 70/30" —plata, no
          cobre— y "CROMO PIEDRA" en "Cobre Cromo": ni "cobre" ni "plata" contaban.

          Siguen siendo ruido para buscar, asi no cambia ningun peso ni se enlaza
          nada nuevo, pero un nombre que las dice las exige en el texto y un
          texto que las dice no se enlaza a un material que no las dice.

          Salvo que el nombre traiga una designacion fuerte: "TUNGSTENO 70W/30CU"
          o "C12200" ya dicen que es cobre. "20S" no cuenta —es el schedule de un
          caño—: sin esto, todo "SCH 20S" caia en "Cobre Plata 20S".

          Los numeros de las formas ("CODO 90", "CURVA 45") tambien eran ruido, y
          tiraban el 90 de "Nimonic 90" o el 45 de "Cu-Ni 45": "CUNI 90/10" caia
          en "Cu-Ni 45".
        */
        if ($material) {
            $this->nombranMaterial = array_flip(array_diff(array_intersect(array_keys($this->solas), $ruidoExtra), $formasEnteras));

            // Los numeros tambien: "Nimonic 90" y "Cu-Ni 45" no son "Nimonic" y "Cu-Ni" porque una forma diga "CODO 90".
            $this->exigibles = $this->nombranMaterial + array_flip(array_filter($ruidoExtra, 'ctype_digit'));
        }

        $this->ruido = array_flip(array_merge(
            $material ? array_merge(self::RELLENO, self::FORMAS_PALABRA) : self::RELLENO,
            $ruidoExtra,
        ));

        $frecuencia = [];

        foreach ($entradas as $entrada) {
            [$id, $texto] = $entrada;
            $tokens = $this->tokens($texto);

            if ($tokens === [] || ! $this->sirveParaBuscar($tokens)) {
                continue;
            }

            $palabras = array_flip($this->palabras($texto));

            $this->tokensDelMaterial[(int) $id] = ($this->tokensDelMaterial[(int) $id] ?? []) + array_flip($tokens);

            $this->candidatos[] = [
                'id' => (int) $id,
                'tokens' => array_flip($tokens),
                'palabras' => $palabras,
                // Las palabras de su nombre que el texto tiene que decir, salvo que una designacion fuerte ya lo identifique.
                'exige' => $this->designacionFuerte($tokens) ? [] : array_intersect_key($palabras, $this->exigibles),
                'huella' => implode(' ', array_keys(array_flip($tokens))),
                'letras' => [],
                'alias' => (bool) ($entrada[2] ?? false),
                'vetado' => isset($this->vetados[(int) $id]),
            ];

            $this->familias[(int) $id][] = array_flip($tokens);
            $this->idsPorHuella[implode(' ', array_keys(array_flip($tokens)))][(int) $id] = true;

            foreach ($tokens as $t) {
                $frecuencia[$t] = ($frecuencia[$t] ?? 0) + 1;
            }
        }

        /*
          La letra suelta de un grado ("Hastelloy X", "Hastelloy W") no es un token, pero hay que decirla: se exige solo
          cuando otro material comparte las mismas palabras y esa letra es lo unico que los distingue. En "Nichrome V
          80/20" o "Aleacion Fusible 98°C" la letra es una variante o una unidad, y no se exige.
        */
        foreach ($this->candidatos as $i => $c) {
            if (count($this->idsPorHuella[$c['huella']] ?? []) > 1) {
                $this->candidatos[$i]['letras'] = $this->letrasSueltas($c['palabras']);
            }
        }

        $total = max(count($this->candidatos), 1);

        foreach ($frecuencia as $t => $veces) {
            $this->peso[$t] = log($total / $veces);
        }

        // El maximo posible es el de un token que aparece en una sola entrada.
        $this->minimo = log(max($total, 2)) * $this->exigencia;
    }

    /**
     * El id del material o la forma que nombra ese texto, o null si no esta claro.
     *
     * @param  bool  $reglasDeHoy  false reproduce lo que hacia antes del 05-11-2026 —sin exigir "cobre", "plata" ni
     *                             los numeros, y sin descartar los materiales "NO USAR"—: sirve para revisar los
     *                             enlaces que se escribieron con esas reglas.
     */
    public function elegir(string $descripcion, bool $reglasDeHoy = true): ?int
    {
        $elegido = $this->decidir($descripcion, $reglasDeHoy);

        /*
          Las medidas no son el grado. "AISI 321, BARRA Ø305 X 500" quedaba en "Acero AISI 305", "MONEL 400 ...
          (TERMINADO A 60 X 203)" en "Monel 60" y "HASTELLOY B ... X C/U" en "Hastelloy X": un numero de una
          medida, o la X del "por unidad", se tomaba por el nombre del material.

          Se decide otra vez sin las medidas, y solo para validar: lo que ya no se enlazaba sigue sin enlazarse
          (sin las medidas se enlazarian unas cuantas mas, y eso se audita aparte).
        */
        if (! $reglasDeHoy || ! $this->material || $elegido === null || $this->sostiene($elegido, $descripcion)) {
            return $elegido;
        }

        return $this->decidir($this->sinLasMedidas($descripcion), true);
    }

    private function decidir(string $descripcion, bool $reglasDeHoy): ?int
    {
        $presentes = array_flip($this->tokens($descripcion));
        $dichas = array_flip($this->palabras($descripcion));

        if ($presentes === []) {
            return null;
        }

        // Los que llegan al mejor puntaje (casi siempre uno solo).
        $mejores = [];
        $puntajeMejor = 0.0;
        // Todos los que entran completos, con su puntaje: sirve para ver si hay otro material firme.
        $completos = [];

        foreach ($this->candidatos as $c) {
            // Se le exige que TODOS sus tokens esten en la descripcion.
            if (array_diff_key($c['tokens'], $presentes) !== []) {
                continue;
            }

            $puntaje = 0.0;

            foreach (array_keys($c['tokens']) as $t) {
                $puntaje += $this->peso[$t] ?? 0;
            }

            $completos[] = [$c, $puntaje];

            /*
              Las palabras de su nombre que el ruido tira pero que son parte del
              material: "Tungsteno Plata" dice plata. Si el texto no la dice, o el
              catalogo lo deja marcado para no usar, no puede ganar, pero sigue
              contando como rival: lo que antes quedaba sin enlazar por ser dudoso
              no empieza a enlazarse por esta regla.
            */
            if ($reglasDeHoy && ($c['vetado'] || array_diff_key($c['exige'], $dichas) !== [] || array_diff_key($c['letras'], $dichas) !== [])) {
                continue;
            }

            if ($puntaje > $puntajeMejor + 0.01) {
                [$mejores, $puntajeMejor] = [[$c], $puntaje];
            } elseif ($mejores !== [] && abs($puntaje - $puntajeMejor) <= 0.01) {
                $mejores[] = $c;
            }
        }

        if ($mejores === [] || $puntajeMejor < $this->minimo) {
            return null;
        }

        // Dos materiales distintos empatados: no se adivina, salvo que haya una razón objetiva.
        if (count(array_unique(array_column($mejores, 'id'))) > 1) {
            $mejores = $this->desempatar($mejores, $descripcion);

            if ($mejores === null) {
                return null;
            }
        }

        $mejor = $mejores[0];

        /*
          Un texto que nombra cobre o plata pegado al material, y un ganador que no los dice: son dos materiales.

          "COBRE TUNGSTENO 20/80" no es tungsteno: es otro material, que el catalogo trae con su
          composicion ("Cobre Tungsteno 80W/20Cu"). Lo mismo "MONEL Y COBRE" o "PLATA 99.85 + 0.15
          NIQUEL". Pero "ANODO PARA COBRE ACIDO) ... TI GR 2" o "MATRIZ DE EXTRUSION DE COBRE: INCONEL
          718" dicen cobre de pasada: el material es el titanio o el inconel, y el enlace es bueno.
        */
        if ($reglasDeHoy) {
            $ajenas = array_diff_key(array_intersect_key($dichas, $this->nombranMaterial), $this->palabrasDelMaterial[$mejor['id']] ?? []);

            if ($ajenas !== [] && $this->estaPegadoAlMaterial($descripcion, $ajenas, $this->tokensDelMaterial[$mejor['id']] ?? [])) {
                return null;
            }
        }

        /*
          Dos materiales distintos nombrados con firmeza: no se adivina.

          "ALEACION DE ALUMINIO CON 0.04% DE BERILIO" nombra al aluminio y al
          berilio. Ganaba el berilio por ser la palabra mas rara, y la linea
          quedaba en el historial de precios del berilio cuando es una
          aleacion de aluminio. Si otro material —que no sea una version mas
          general del ganador— tambien llega al puntaje minimo, la linea es
          ambigua y se deja sin enlazar: se completa despues a mano.

          El umbral es el 75% del minimo y no el minimo entero: "aluminio" pesa
          poco porque el catalogo tiene decenas de aleaciones de aluminio, y aun
          asi nombrarlo con otro material es una duda que vale la pena.

          Un material mas general no cuenta: "Titanio" dentro de "TIT GR2" es
          el mismo material dicho con menos detalle.
        */
        if ($this->material) {
            foreach ($completos as [$otro, $puntaje]) {
                if ($otro['id'] !== $mejor['id']
                    && $puntaje >= $this->minimo * 0.75
                    && ! $this->esVersionGeneral($otro['id'], $mejor['id'])) {
                    return null;
                }
            }
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
     * Si el texto dice de verdad ese material: algun nombre o alias suyo con todas sus palabras en el texto,
     * sin contar las medidas, y no marcado para no usar.
     *
     * No es lo mismo que elegir(): elegir() es prudente y deja sin enlazar lo dudoso. Esto solo contesta si el
     * material ya enlazado tiene sustento en lo escrito: "Acero AISI 305" no lo tiene en "AISI 321, BARRA Ø305
     * X 500" —el 305 es el diametro—, y "Acero AISI 347" si lo tiene en "ACERO INOXIDABLE AISI 347, VARILLA".
     */
    public function sostiene(int $material, string $descripcion): bool
    {
        if (isset($this->vetados[$material])) {
            return false;
        }

        foreach ($this->candidatos as $c) {
            if ($c['id'] !== $material) {
                continue;
            }

            // Sin las medidas, salvo el numero que es el grado de ESTE nombre: "INCONEL 625 X 58 X 275 MM" dice 625.
            $texto = $this->material ? $this->sinLasMedidas($descripcion, $c['palabras']) : $descripcion;

            if (array_diff_key($c['tokens'], array_flip($this->tokens($texto))) === []
                && array_diff_key($c['exige'], $dichasEn = array_flip($this->palabras($texto))) === []
                && array_diff_key($c['letras'], $dichasEn) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las letras sueltas de un nombre: la X de "Hastelloy X", la W de "Hastelloy W". No son tokens (una letra sola no
     * identifica nada) pero son el grado, y el texto tiene que decirlas. Las unidades ("98°C") no cuentan.
     *
     * @param  array<string, mixed>  $palabras
     * @return array<string, true>
     */
    private function letrasSueltas(array $palabras): array
    {
        $letras = [];
        $anterior = '';

        foreach (array_keys($palabras) as $palabra) {
            $palabra = (string) $palabra;

            // La letra que sigue a un numero es una unidad ("98°C"), no un grado. La "A" de "Corten A" tambien es un
            // grado: sin decirla, "ACERO CORTEN" caia en la A solo porque la B exigia su letra.
            if (strlen($palabra) === 1 && ctype_alpha($palabra) && ! ctype_digit($anterior)) {
                $letras[$palabra] = true;
            }

            $anterior = $palabra;
        }

        return $letras;
    }

    /**
     * Si el numero que abre una serie, o que va con su "MM", es el grado y no una medida: viene justo despues de la
     * palabra que lo nombra y los dos son parte del nombre del material. "INCONEL 625", "HASTELLOY C-276", "INCONEL X-750".
     *
     * @param  array<string, mixed>  $delNombre  las palabras del nombre del material que se revisa
     */
    private function esElGrado(string $sujeto, int $desde, string $numero, array $delNombre): bool
    {
        if ($delNombre === [] || ! preg_match('/\d+(?:[.,]\d+)?/', $numero, $n)) {
            return false;
        }

        $cifras = preg_replace('/\D/', '', $n[0]);

        if (! preg_match('/(\pL+)[\s.\-]*$/u', substr($sujeto, 0, $desde), $antes)) {
            return false;
        }

        $palabra = $this->plano($antes[1]);

        return isset($delNombre[$palabra.$cifras]) || (isset($delNombre[$cifras]) && isset($delNombre[$palabra]));
    }

    /**
     * El texto sin las medidas: los diametros marcados, las series "25.4 X 1000" y los numeros con su "MM", y la
     * X del precio por unidad ("X C/U", "X KG", "X M"). Queda lo que nombra al material.
     */
    private function sinLasMedidas(string $texto, array $delNombre = []): string
    {
        // Un numero con su unidad de largo si la trae: "50 MM", "1.22M", "3 M".
        $numero = '\d+(?:[.,]\d+)?(?:\s*(?:mm|mts?|mtos?|m)\b)?';
        // Se reemplaza por una barra y no por un espacio: sin ella "400 Ø 51 X 3 M" quedaba "400 M", y "400 M" se pega en "400m".
        $corte = ' | ';

        // Un diametro marcado, con su serie si la tiene: "Ø 31.75 X 50 MM", "Ø25.4MM X 1000MM".
        $t = preg_replace('/[ØøÝý]\s*'.$numero.'(?:\s*[xX*]\s*'.$numero.')*/iu', $corte, $texto);

        /*
          Una serie, que no empieza pegada a una letra ("X750" es un grado) ni es una pureza ("ALU 4X9" son cuatro nueves):
          "0.46 X 1220 X 1560", "8*2000*6000". Y un numero con su "MM" solo: "60 MM".

          El primer numero se conserva si es parte del nombre del material que se esta revisando y viene justo despues de
          la palabra que lo nombra: en "INCONEL 625 X 58 X 275 MM", "HASTELLOY C-276 X 500 MM" o "AISI 304 X 0.4 X 100" es el
          grado, escrito seguido de las medidas.
        */
        $sujeto = $t;
        $t = preg_replace_callback(
            '/(?<![\pL\d])(?![3-6]\s*[xX]\s*9(?![\d.,]))('.$numero.')(?:\s*[xX*]\s*'.$numero.')+|(?<![\pL\d])(\d+(?:[.,]\d+)?)\s*mm\b/iu',
            function (array $m) use ($sujeto, $corte, $delNombre): string {
                $primero = isset($m[1]) && $m[1][0] !== '' ? $m[1][0] : $m[2][0];

                return $this->esElGrado($sujeto, $m[0][1], $primero, $delNombre) ? $primero.$corte : $corte;
            },
            $t,
            -1,
            $cuantas,
            PREG_OFFSET_CAPTURE,
        );

        // La X del precio: "X C/U", "X KG", "X M", "X METRO". "HASTELLOY X CHAPA" sigue diciendo X.
        return preg_replace('~(?<![\pL\d])x\s+(?:c/?u|kgs?|k|m|mts?|mto|metros?|xm|u|un)(?![\pL\d])~iu', $corte, $t);
    }

    /**
     * Si alguna de estas palabras ("cobre", "plata") esta pegada a una del material elegido: hasta dos
     * palabras de por medio, sin contar numeros, conectores ni ruido, y sin cruzar ":", ";", "(" ni ")".
     *
     * @param  array<string, mixed>  $ajenas  las palabras de otro material que el texto dice
     * @param  array<string, true>  $delMaterial  los tokens del material elegido
     */
    private function estaPegadoAlMaterial(string $descripcion, array $ajenas, array $delMaterial): bool
    {
        $t = $this->plano($descripcion);
        preg_match_all('/[a-z0-9]+/', $t, $m, PREG_OFFSET_CAPTURE);
        $palabras = $m[0];

        foreach ($palabras as $i => [$palabra]) {
            if (! isset($ajenas[$palabra])) {
                continue;
            }

            foreach ([-1, 1] as $sentido) {
                $saltadas = 0;

                for ($j = $i + $sentido; isset($palabras[$j]); $j += $sentido) {
                    [$antes, $despues] = $sentido > 0 ? [$palabras[$j - 1], $palabras[$j]] : [$palabras[$j], $palabras[$j + 1]];
                    $entre = substr($t, $antes[1] + strlen($antes[0]), $despues[1] - $antes[1] - strlen($antes[0]));

                    if (preg_match('/[:;()]/', $entre)) {
                        break;
                    }

                    $otra = $palabras[$j][0];

                    if (isset($delMaterial[$otra])) {
                        return true;
                    }

                    if (preg_match('/^\d/', $otra) || in_array($otra, self::CONECTORES, true) || isset($this->ruido[$otra])) {
                        continue;
                    }

                    if (++$saltadas > 2) {
                        break;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Si el material $general es la version menos detallada del material $especifico.
     *
     * "Acero Inoxidable" es la version general de "Acero Inoxidable F138", y "Titanio" la de
     * "Titanio Grado 2": nombrar uno dentro del otro no es nombrar dos materiales. Se compara con
     * todos los nombres y alias de cada uno, no solo con el que casó: "ACE F138" no dice "inox"
     * pero es el mismo material que "Acero Inoxidable F138".
     */
    private function esVersionGeneral(int $general, int $especifico): bool
    {
        foreach ($this->familias[$general] ?? [] as $menos) {
            foreach ($this->familias[$especifico] ?? [] as $mas) {
                if (array_diff_key($menos, $mas) === []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Entre candidatos empatados de ids distintos, las dos únicas razones para elegir uno:
     *
     *  1. Uno tiene palabras propias que la línea no dice. "TUBO EN U" se reduce al token "tubo"
     *     ("en" y "u" no cuentan), igual que "TUBO": pero una línea que dice "TUBO 26.67 X 2.87"
     *     no dice "en", así que no es la forma en U. Si los dos entran completos, sigue el empate.
     *  2. Uno es nombre propio y el otro un alias: "Monel 400" es un material y a la vez un alias
     *     de "Monel"; la línea "MONEL 400 …" es del primero.
     *
     * Si después de eso quedan ids distintos, es empate y no se adivina.
     *
     * @param  list<array<string, mixed>>  $empatados
     * @return list<array<string, mixed>>|null
     */
    private function desempatar(array $empatados, string $descripcion): ?array
    {
        $dichas = array_flip($this->palabras($descripcion));

        $faltan = array_map(fn (array $c) => count(array_diff_key($c['palabras'], $dichas)), $empatados);
        $menos = min($faltan);
        $quedan = array_values(array_filter($empatados, fn ($c, $i) => $faltan[$i] === $menos, ARRAY_FILTER_USE_BOTH));

        if (count(array_unique(array_column($quedan, 'id'))) > 1) {
            $quedan = array_values(array_filter($quedan, fn ($c) => ! $c['alias']));
        }

        return count(array_unique(array_column($quedan, 'id'))) === 1 ? $quedan : null;
    }

    /**
     * Todas las palabras de un texto, también las que no cuentan como token ("en", "u", "de").
     *
     * @return array<int, string>
     */
    private function palabras(string $texto): array
    {
        /*
          La X entre dos numeros separa medidas, no nombra nada.

          "Hastelloy X" y "Hastelloy W" se reducen al mismo token, y la
          descripcion "HASTELLOY B Ø 31.75 X 70 MM" traia una "x" —la de las
          medidas— que le daba la victoria a la X. Entre dos grados que solo
          se distinguen por una letra, esa letra cuenta solo si esta dicha:
          "HASTELLOY X CHAPA 4.76 X 600" nombra el grado X; "HASTELLOY Ø 50 X
          200" no nombra ninguno y la linea queda sin enlazar.
        */
        // Entre dos numeros ("10X20"), o una X suelta que va antes de un numero ("MM X 2 MTS").
        // "X-750" no: ahi la X es parte del nombre del grado.
        $plano = preg_replace('/(?<=\d)\s*x\s*(?=\d)|(?<![a-z0-9])x(?=\s+\d)/', ' ', $this->plano($texto));

        preg_match_all('/[a-z0-9]+/', $plano, $m);

        return array_values(array_unique($m[0]));
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

    /**
     * Si entre estos tokens hay una designacion que identifica por si sola: "70w30cu", "c12200", "f138".
     * Un numero seguido de una letra ("20s", "40s") es un schedule o un tamaño, no una designacion.
     *
     * @param  array<int, string>  $tokens
     */
    private function designacionFuerte(array $tokens): bool
    {
        foreach ($tokens as $t) {
            if (preg_match(self::DESIGNACION, $t) && ! preg_match(self::MEDIDA, $t) && ! preg_match('/^\d+[a-z]$/', $t)) {
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

    /** @return array<int, string> las palabras que son el nombre entero de una entrada */
    public function palabrasSolas(): array
    {
        return array_keys($this->solas);
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
          "MAR DEL PLATA", "LA PLATA" y "COLOR PLATA" no son el metal: un cliente de Mar del Plata que pide
          un Inconel no esta pidiendo plata. Sin esto, "plata" dicha de pasada soltaba enlaces buenos.
        */
        $t = preg_replace('/\b(?:la|del)\s+plata\b|\bcolor(?:es)?\s+(?:de\s+)?(?:plata|cobre)\b/', ' ', $t);

        // "CUPRO NIQUEL 90/10" es cobre niquel 90/10.
        $t = preg_replace('/\bcupro[\s-]+(?=[a-z])/', 'cobre ', $t);

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

        // "Monel K –500", "K—500" y "K - 500" son el K-500: Word y Outlook
        // cambian el guion por uno largo, y suelta la K se pierde y gana el
        // Monel a secas. Solo una letra que sigue a una palabra: "7.2 M - 7.5
        // KG", "C/U - 75" y "REV.E - 400" no son grados.
        $t = preg_replace('/(?<=[a-z])(\s+[a-z])\s*[-\x{2010}-\x{2015}\x{2212}]\s*(?=\d)/u', '$1', $t);

        // "C-276" y "C 276" son uno solo; "25-22-2" tambien.
        $t = preg_replace('/(?<=[a-z0-9])[-.\/](?=[a-z0-9])/', '', $t);

        // "Ti" es el simbolo del titanio, y el catalogo lo abrevia TIT: "Ti. Gr. 4" es TIT GR4.
        $t = preg_replace('/\bti\b/', 'tit', $t);

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

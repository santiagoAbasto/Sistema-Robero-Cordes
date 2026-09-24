<?php

namespace App\Services;

use App\Models\Forma;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Unidad;
use App\Services\Migracion\EnlazadorDeLineas;

/**
 * Separa en lineas el mail o el WhatsApp que mando el cliente.
 *
 * Es el camino que corre siempre: con credencial de IA queda de respaldo, y
 * sin credencial es el unico. Lo que arma es una propuesta para revisar antes
 * de guardar.
 *
 * DOS REGLAS, y las dos salieron de mails reales.
 *
 * La primera: un renglon es una linea de pedido si pide una cantidad o dice
 * una medida. No si nombra un material. "Ante cualquier consulta estamos a
 * disposicion" no pide nada, y entraba como una linea de Cobre Aluminio.
 *
 * La segunda: el material se busca por palabras enteras, nunca por pedazo de
 * texto. El catalogo tiene alias de dos y tres letras —RE es Renio, TAN es
 * Tantalio, GRA es Grafito, CUAL es Cobre Aluminio— que caen adentro de
 * cualquier palabra: "entREGA", "TiTANiu", "GRAcias", "CUALquier". Un mail de
 * seis renglones devolvia seis lineas y ninguna era lo que el cliente pidio.
 *
 * Ante la duda no se elige nada. Un campo vacio se completa con el
 * desplegable de al lado; un material equivocado se imprime y se manda.
 */
class LectorDeSolicitud
{
    /** Lo que encabeza un renglon de una lista y no es parte del pedido. */
    private const VINETA = '/^\s*(?:[-–—*•·o]\s+|\d+[.)]\s+)/u';

    /**
     * Como escriben las formas cuando no escriben el nombre entero.
     *
     * Salio de leer las descripciones del archivo: "BAR RED", "ALA", "FLEJE".
     * Solo abrevia formas que ya estan en el catalogo; no inventa ninguna.
     */
    private const ABREVIATURAS = [
        'BARRA REDONDA' => ['BAR RED', 'BARRED', 'BARRA RED', 'REDONDO'],
        'BARRA HEXAGONAL' => ['BAR HEX', 'HEXAG'],
        'BARRA CUADRADA' => ['BAR CUAD', 'CUADRADA'],
        'BARRA RED. / VARILLA' => ['VARILLA'],
        'ALAMBRE' => ['ALAMB', 'ALA'],
        'CAÑO' => ['CANIO', 'SCH'],
        'CHAPA' => ['PLACA'],
        'PLANCHUELA' => ['PLANCH', 'FLEJE'],
        'TUBO' => ['TUB'],
    ];

    /** Donde guarda la linea, en milimetros, cada medida de la forma. */
    private const COLUMNA = [
        'diameter' => 'diametro_mm', 'outer' => 'diametro_mm',
        'side' => 'diametro_mm', 'across' => 'diametro_mm',
        'wall' => 'espesor_mm', 'height' => 'espesor_mm',
        'width' => 'ancho_mm', 'length' => 'largo_mm',
    ];

    private EnlazadorDeLineas $buscadorDeMaterial;

    /** @var array<string, true> las palabras que nombran materiales */
    private array $vocabulario = [];

    public function interpretar(string $texto): array
    {
        $materiales = Material::with('alias')->where('activo', true)->get();
        $formas = Forma::where('activo', true)->get();
        $unidades = Unidad::where('activo', true)->get();

        $this->prepararBuscador($formas);

        $lineas = [];
        $sinReconocer = [];

        foreach ($this->separarRenglones($texto) as $renglon) {
            $linea = $this->leerRenglon($renglon, $materiales, $formas, $unidades);

            if ($linea === null) {
                $sinReconocer[] = $renglon;

                continue;
            }

            $lineas[] = $linea;
        }

        return ['lineas' => $lineas, 'sin_reconocer' => $sinReconocer];
    }

    /**
     * El buscador de materiales por palabras enteras.
     *
     * Es el mismo que enlazo las 13.071 lineas del sistema anterior: parte
     * cada entrada del catalogo en palabras y le exige que todas aparezcan.
     * Los nombres de las formas entran como ruido porque el catalogo tiene
     * materiales que en realidad son formas mal anotadas.
     */
    private function prepararBuscador($formas): void
    {
        $vocabularioDeFormas = (new EnlazadorDeLineas(
            $formas->map(fn ($f) => [$f->id, $f->nombre])->all(),
            material: false,
        ))->vocabulario();

        $this->buscadorDeMaterial = new EnlazadorDeLineas(
            [
                ...Material::query()->where('activo', true)->get(['id', 'nombre'])
                    ->map(fn ($m) => [$m->id, $m->nombre])->all(),
                ...MaterialAlias::query()->get(['material_id', 'alias'])
                    ->map(fn ($a) => [$a->material_id, $a->alias])->all(),
            ],
            material: true,
            ruidoExtra: $vocabularioDeFormas,
        );

        // Para corregir tipeos: solo palabras, nunca designaciones.
        $this->vocabulario = [];

        foreach ($this->buscadorDeMaterial->vocabulario() as $token) {
            if (ctype_alpha($token) && mb_strlen($token) >= 6) {
                $this->vocabulario[$token] = true;
            }
        }
    }

    /** Un renglón por línea de pedido, sin la viñeta de la lista. */
    private function separarRenglones(string $texto): array
    {
        $renglones = preg_split('/[\r\n]+|(?<=\))\s*[,;]\s*/u', $texto) ?: [];

        $limpios = array_map(
            fn ($r) => trim(preg_replace(self::VINETA, '', trim($r)) ?? ''),
            $renglones,
        );

        return array_values(array_filter($limpios, fn ($r) => mb_strlen($r) >= 6));
    }

    private function leerRenglon($renglon, $materiales, $formas, $unidades): ?array
    {
        $normalizado = $this->normalizar($renglon);

        $forma = $this->buscarForma($normalizado, $renglon, $formas);
        [$cantidad, $unidad] = $this->buscarCantidad($normalizado, $unidades);
        $medidas = $this->buscarMedidas($renglon, $forma);

        /*
          Que hace que un renglon sea una linea de pedido: que pida una
          cantidad o que diga una medida.

          Antes lo decidia el material, y como se lo buscaba por pedazo de
          texto, "Desde ya, muchas GRAcias" era una linea de Grafito. Un
          saludo no pide nada; una linea de pedido siempre pide cuanto o de
          que medida.
        */
        $esPedido = $cantidad !== null || $medidas['texto'] !== null;

        if (! $esPedido) {
            return null;
        }

        $material = $this->buscarMaterial($renglon, $materiales);

        return [
            'descripcion' => trim($renglon),
            'material_id' => $material?->id,
            'material' => $material?->nombre,
            'forma_id' => $forma?->id,
            'forma' => $forma?->nombre,
            'dimensiones' => $medidas['texto'],
            'diametro_mm' => $medidas['diametro_mm'],
            'espesor_mm' => $medidas['espesor_mm'],
            'ancho_mm' => $medidas['ancho_mm'],
            'largo_mm' => $medidas['largo_mm'],
            'cantidad' => $cantidad,
            'unidad_venta_id' => $unidad?->id,
            'unidad' => $unidad?->codigo,
            // Lo que pidió el cliente arranca igual a lo que se va a cotizar.
            'igual_a_lo_pedido' => true,
            // Las variantes —aerea y maritima, tramos de cantidad— se agregan
            // con un clic en la linea. La misma clave que devuelve la IA, para
            // que la pantalla no tenga que preguntar de donde vino la lectura.
            'alternativas' => [],
        ];
    }

    private function normalizar(string $texto): string
    {
        // Quita separadores para que "HAST C-276" y "HASTC276" se parezcan.
        return preg_replace('/[^A-Z0-9]/', '', $this->sinAcentos($texto)) ?? '';
    }

    /** El texto en mayusculas y sin simbolos, pero con los espacios. */
    private function conEspacios(string $texto): string
    {
        return trim(preg_replace('/[^A-Z0-9]+/', ' ', $this->sinAcentos($texto)) ?? '');
    }

    private function sinAcentos(string $texto): string
    {
        return strtr(mb_strtoupper($texto, 'UTF-8'), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'Ü' => 'U', 'Ñ' => 'N',
        ]);
    }

    /**
     * El material, buscado por palabras enteras.
     *
     * Ver la nota de arriba: por pedazo de texto, los alias cortos del
     * catalogo enganchaban cualquier palabra del mail.
     *
     * Se prueba el renglon como vino y tambien corrigiendole un tipeo. Si dos
     * lecturas distintas dan dos materiales distintos, no se elige ninguno:
     * la duda la resuelve quien revisa, no esto.
     */
    private function buscarMaterial(string $renglon, $materiales): ?Material
    {
        /*
          El schedule y las pulgadas se sacan antes de buscar.

          "SCH40S" parece la designacion de un material —letras y numeros
          pegados— y hace que el buscador desconfie y no elija nada. Es la
          medida del caño, no el material del caño.
        */
        $limpio = preg_replace('/\bSCH\s*\d+\w*|"/iu', ' ', $renglon) ?? $renglon;

        $encontrados = [];

        foreach ($this->comoPudoHaberseEscrito($limpio) as $variante) {
            $id = $this->buscadorDeMaterial->elegir($variante);

            if ($id !== null) {
                $encontrados[$id] = true;
            }
        }

        return count($encontrados) === 1
            ? $materiales->firstWhere('id', array_key_first($encontrados))
            : null;
    }

    /**
     * El renglon como vino, y como habria quedado sin el tipeo.
     *
     * Los mails vienen con errores de tipeo: "Titaniu Grado 7" por Titanio.
     * Buscando por palabras enteras eso no encuentra nada y la linea entra
     * sin material aunque el cliente lo haya dicho.
     *
     * Solo palabras de seis letras para arriba y solo una letra de
     * diferencia. Con menos letras las palabras se parecen demasiado entre
     * si, y las designaciones —316L, C276, GR7— no se tocan nunca: ahi una
     * letra de diferencia es otro material, no un error.
     *
     * @return list<string>
     */
    private function comoPudoHaberseEscrito(string $renglon): array
    {
        $variantes = [$renglon];

        if ($this->vocabulario === []) {
            return $variantes;
        }

        preg_match_all('/\p{L}{6,}/u', $renglon, $m);

        foreach ($m[0] as $palabra) {
            $plano = mb_strtolower($this->sinAcentos($palabra));

            if (isset($this->vocabulario[$plano])) {
                continue;
            }

            foreach ($this->parecidas($plano) as $candidata) {
                $variantes[] = str_replace($palabra, $candidata, $renglon);
            }

            // Una palabra mal escrita por renglon alcanza. Probar todas las
            // combinaciones de todas no termina mas y no agrega nada.
            if (count($variantes) > 1) {
                break;
            }
        }

        return $variantes;
    }

    /**
     * Las palabras del catalogo a una letra de esta.
     *
     * @return list<string>
     */
    private function parecidas(string $palabra): array
    {
        $cerca = [];

        foreach (array_keys($this->vocabulario) as $token) {
            if (abs(mb_strlen($token) - mb_strlen($palabra)) > 1) {
                continue;
            }

            if (levenshtein($palabra, $token) === 1) {
                $cerca[] = $token;

                // Si se parece a media tabla no era un tipeo.
                if (count($cerca) > 4) {
                    return [];
                }
            }
        }

        return $cerca;
    }

    /**
     * La forma, buscada en el catalogo y no en una lista escrita a mano.
     *
     * Antes eran ocho sinonimos fijos. El catalogo tiene diecinueve formas
     * activas, asi que once no se reconocian nunca: una arandela, un disco,
     * un anillo, una brida o un alambre entraban sin forma. Y sin forma no
     * hay donde poner las medidas, y sin medidas no hay peso ni precio.
     *
     * Gana el nombre mas largo que aparezca escrito, y da lo mismo el plural:
     * el cliente escribe "3 Barras", "2 Discos", "4 Caños".
     */
    private function buscarForma(string $normalizado, string $renglon, $formas): ?Forma
    {
        $mejor = null;
        $largoMejor = 0;

        foreach ($formas as $forma) {
            $candidato = $this->normalizar($forma->nombre);

            // Menos de cuatro letras no alcanza para distinguir nada.
            if (mb_strlen($candidato) < 4 || mb_strlen($candidato) <= $largoMejor) {
                continue;
            }

            foreach ([$candidato, $this->plural($candidato)] as $variante) {
                if (str_contains($normalizado, $variante)) {
                    $mejor = $forma;
                    $largoMejor = mb_strlen($candidato);

                    break;
                }
            }
        }

        if ($mejor !== null) {
            return $mejor;
        }

        /*
          Recien si no escribieron el nombre entero, las abreviaturas.

          Van sobre el texto con los espacios puestos y pegadas a un borde de
          palabra: "ALA" es alambre en "TIT GR4 ALA Ø 3.18", pero adentro de
          otra palabra no es nada.

          BARRA a secas no esta y no es un olvido: el catalogo tiene activas
          BARRA REDONDA y BARRA RED. / VARILLA, y cual de las dos es queda
          para que lo diga CORDES. Adivinar define el peso que se factura.
        */
        $conEspacios = $this->conEspacios($renglon);

        foreach (self::ABREVIATURAS as $nombre => $claves) {
            foreach ($claves as $clave) {
                if (! preg_match('/\b'.preg_quote($clave, '/').'S?\b/', $conEspacios)) {
                    continue;
                }

                $forma = $formas->firstWhere('nombre', $nombre);

                if ($forma) {
                    return $forma;
                }
            }
        }

        /*
          "BARRA" a secas, pero con el diametro marcado.

          El catalogo tiene BARRA desactivada y activas BARRA REDONDA y
          BARRA RED. / VARILLA, asi que "3 barras de 127 x 25.4" no dice cual
          es y se deja vacia. Pero "3 Barras de Ø127mm x 25.4mm" si lo dice:
          una barra con diametro es redonda, y lo escribio el cliente, no lo
          supone el sistema. Una hexagonal se escribe entre caras.

          Si CORDES prefiere otra, se cambia el nombre de acá o se activa la
          forma que corresponda.
        */
        if (preg_match('/\bBARRAS?\b/', $conEspacios)
            && preg_match('/Ø\s*\d|\bDIA\.?\s*\d/iu', $renglon)) {
            return $formas->firstWhere('nombre', 'BARRA REDONDA');
        }

        return null;
    }

    /** "CAÑO" -> "CAÑOS", "CHAPA" -> "CHAPAS", "PERFIL" -> "PERFILES". */
    private function plural(string $palabra): string
    {
        return $palabra.(preg_match('/[AEIOU]$/', $palabra) ? 'S' : 'ES');
    }

    /** "6 UN", "2 c/u", "3 metros" al principio del renglón. */
    private function buscarCantidad(string $normalizado, $unidades): array
    {
        if (! preg_match('/^(\d+(?:[.,]\d+)?)/', $normalizado, $m)) {
            return [null, null];
        }

        $cantidad = (float) str_replace(',', '.', $m[1]);

        $porTexto = [
            'METRO' => 'MT', 'METROS' => 'MT', 'MTS' => 'MT', 'MT' => 'MT',
            'KILO' => 'KG', 'KILOS' => 'KG', 'KG' => 'KG',
            'CU' => 'C/U', 'UN' => 'UN', 'UNIDAD' => 'UN', 'UNIDADES' => 'UN',
        ];

        foreach ($porTexto as $texto => $codigo) {
            if (preg_match('/^\d+(?:[.,]\d+)?'.$texto.'/', $normalizado)) {
                return [$cantidad, $unidades->firstWhere('codigo', $codigo)];
            }
        }

        return [$cantidad, $unidades->firstWhere('codigo', 'UN')];
    }

    /**
     * Las medidas escritas, cada una en el campo que le toca.
     *
     * El orden lo pone la forma, no este metodo: una barra redonda escrita
     * "38.1 X 145" es diametro y largo, y una chapa escrita "2 X 1000 X 2000"
     * es espesor, ancho y largo. Antes se tomaban siempre los dos primeros
     * numeros como diametro y largo, asi que la chapa entraba con 2 mm de
     * diametro y 1000 de largo, y el 2000 se perdia. De ahi sale el peso.
     *
     * @return array{texto: ?string, diametro_mm: ?float, espesor_mm: ?float, ancho_mm: ?float, largo_mm: ?float}
     */
    private function buscarMedidas(string $renglon, ?Forma $forma): array
    {
        $medidas = [
            'texto' => null, 'diametro_mm' => null,
            'espesor_mm' => null, 'ancho_mm' => null, 'largo_mm' => null,
        ];

        /*
          Los caños vienen en pulgadas y con schedule. La medida se guarda
          como la escriben —«1.5" SCH40S x 3340mm»— porque asi la pide el
          cliente y asi la busca el proveedor; pasarla a milimetros es otra
          cosa.
        */
        $pulgadas = '/\d+(?:[.,]\d+)?\s*"\s*(?:SCH\s*\d+\w*)?(?:\s*[xX]\s*\d+(?:[.,]\d+)?\s*(?:MM)?)?/iu';

        if (preg_match($pulgadas, $renglon, $m)) {
            $medidas['texto'] = trim($m[0]);

            return $medidas;
        }

        $numeros = [];
        $marcado = false;

        // "38.1 X 145 MM" · "2 X 1000 X 2000" · "DIA 65 X 145MM" · "Ø127mm x 25.4mm"
        $serie = '/(DIA\s*|Ø\s*)?\d+(?:[.,]\d+)?(?:\s*(?:MM\s*)?[xX]\s*\d+(?:[.,]\d+)?){1,2}(?:\s*MM)?/iu';

        if (preg_match($serie, $renglon, $m)) {
            $medidas['texto'] = trim($m[0]);
            $marcado = trim($m[1] ?? '') !== '';
            $limpio = preg_replace('/\s*(?:MM|DIA|Ø)\s*/iu', ' ', $m[0]) ?? '';
            $numeros = array_values(array_filter(
                array_map('trim', preg_split('/[xX]/', $limpio) ?: []),
                fn ($n) => $n !== '',
            ));
        } elseif (preg_match('/(DIA\s*|Ø\s*)?(\d+(?:[.,]\d+)?)\s*MM/iu', $renglon, $m)) {
            // Una sola medida, pero con su unidad puesta: "ALAMBRE 0,70 MM".
            $medidas['texto'] = trim($m[0]);
            $marcado = trim($m[1] ?? '') !== '';
            $numeros = [$m[2]];
        }

        /*
          Sin forma reconocida, dos numeros se leen como diametro y largo.

          Es lo que son en las formas del catalogo que piden dos medidas:
          primero la seccion, despues el largo. Y si el cliente puso el
          simbolo Ø delante, el primero es un diametro y no hace falta
          suponer nada. Con tres numeros y sin forma no se arriesga: cual de
          ellos es el espesor depende de la forma.
        */
        $orden = $forma?->ordenEnQueSeEscriben() ?: null;

        /*
          Y lo mismo si la forma no declara medidas.

          BRIDA, PERFIL y ESFERA estan en el catalogo sin campos cargados. Al
          reconocerlas, las medidas escritas se perdian en silencio: peor que
          no reconocer la forma.
        */
        $orden ??= count($numeros) === 2 || $marcado ? ['diameter', 'length'] : [];

        foreach ($numeros as $i => $n) {
            $columna = self::COLUMNA[$orden[$i] ?? ''] ?? null;

            if ($columna === null || $medidas[$columna] !== null) {
                continue;
            }

            $medidas[$columna] = (float) str_replace(',', '.', $n);
        }

        return $medidas;
    }
}

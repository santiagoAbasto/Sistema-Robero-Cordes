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
        // VARILLA es BARRA REDONDA desde el 30-09-2026: "Barra Red / Varilla
        // es lo mismo que Barra Redonda". Antes apuntaba a BARRA RED. /
        // VARILLA, que se unifico.
        'BARRA REDONDA' => ['BAR RED', 'BARRED', 'BARRA RED', 'REDONDO', 'VARILLA'],
        'BARRA HEXAGONAL' => ['BAR HEX', 'HEXAG', 'HEXAGONAL'],
        'BARRA CUADRADA' => ['BAR CUAD', 'CUADRADA'],
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

    /**
     * Los titulos de columna de una tabla de pedido y que dato es cada uno.
     * El interno va antes que el diametro: "Diametro interno" no es el
     * exterior.
     */
    private const TITULOS = [
        '/^(?:DIAMETRO|DIAM)\s+(?:INTERNO|INTERIOR|INT)$/' => 'interior',
        '/^(?:DIAMETRO|DIAM|OD)(?:\s+(?:EXTERNO|EXTERIOR|EXT))?$/' => 'diameter',
        '/^(?:ESPESOR|ESP|PARED)$/' => 'wall',
        '/^ANCHO$/' => 'width',
        '/^(?:LADO|SECCION)$/' => 'side',
        '/^(?:LARGO|LONGITUD|LONG)$/' => 'length',
        '/^(?:MEDIDAS?|DIMENSIONES)$/' => 'medidas',
        '/^(?:MATERIAL|CALIDAD|ALEACION)$/' => 'material',
        '/^FORMA$/' => 'forma',
        // La columna que describe el item entero: forma, norma, material y
        // medida en un texto ("CODO 90° ... AISI 310 6" SCH40S"). No es la forma.
        '/^(?:PRODUCTO|DESCRIPCION|DETALLE|ARTICULO)$/' => 'descripcion',
        '/^(?:UNID|UNIDAD|UM)$/' => 'unidad',
        '/^(?:CANT|CANTIDAD|QTY|PIEZAS|PZAS)$/' => 'cantidad',
        '/^(?:ITEM|POS|POSICION|NRO|N)$/' => 'item',
    ];

    /**
     * Los rotulos de cada medida en una ficha, en el orden en que las leen
     * las formas. Se juntan despues en "dimensiones".
     */
    private const ROTULOS_DE_MEDIDA = [
        'm_diam' => ['DIAMETRO', 'DIÁMETRO', 'DIAM'],
        'm_lado' => ['LADO', 'SECCION', 'SECCIÓN', 'SECC'],
        'm_ancho' => ['ANCHO'],
        'm_esp' => ['ESPESOR', 'ESP'],
        'm_med' => ['MEDIDAS', 'MEDIDA', 'DIMENSIONES'],
        'm_largo' => ['LONGITUD', 'LARGO', 'LONG'],
    ];

    /** Las columnas de una tabla que son una medida. */
    private const MEDIDAS_DE_TABLA = ['diameter', 'interior', 'wall', 'width', 'side', 'length', 'medidas'];

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

        // Una tabla —una fila de titulos y un item por fila— se lee por columna.
        $tabla = $this->fichasDeTabla($texto);

        if ($tabla !== []) {
            foreach ($tabla as $fila) {
                $linea = $this->leerFicha($fila['ficha'], $materiales, $formas, $unidades);

                if ($linea === null) {
                    $sinReconocer[] = implode(' ', $fila['ficha']);

                    continue;
                }

                // Cada medida a su columna por el titulo, no por el orden en
                // que vienen: en "Largo | Diametro" el primero no es el diametro.
                if ($fila['medidas'] !== []) {
                    foreach (['diametro_mm', 'espesor_mm', 'ancho_mm', 'largo_mm'] as $columna) {
                        $linea[$columna] = null;
                    }

                    foreach ($fila['medidas'] as $clave => $mm) {
                        $linea[self::COLUMNA[$clave]] = $mm;
                    }
                }

                $lineas[] = $linea;
            }

            return ['lineas' => $lineas, 'sin_reconocer' => $sinReconocer];
        }

        // Una ficha trae cada dato rotulado —la de la web, una captura
        // vertical—: se lee por campo.
        $fichas = $this->fichas($texto, $formas);

        if ($fichas !== []) {
            foreach ($fichas as $ficha) {
                $linea = $this->leerFicha($ficha, $materiales, $formas, $unidades);

                $linea === null ? $sinReconocer[] = implode(' ', $ficha) : $lineas[] = $linea;
            }

            return ['lineas' => $lineas, 'sin_reconocer' => $sinReconocer];
        }

        // El material dicho en un renglon que no pide nada vale para los items
        // de alrededor que no nombran el suyo: el header "En ALLOY 20..." para
        // los que siguen, y el "aisi 420" suelto para el item de arriba.
        $pendienteMaterial = null;
        // "Cantidad: 6" en su renglon, antes del item que lo lleva.
        $pendienteCantidad = null;

        foreach ($this->separarRenglones($texto) as $renglon) {
            $linea = $this->leerRenglon($renglon, $materiales, $formas, $unidades);

            if ($linea === null) {
                $sinReconocer[] = $renglon;

                if (($mat = $this->materialDicho($renglon, $materiales)) !== null) {
                    $pendienteMaterial = $mat;

                    // Y para el item de arriba, si quedo sin material: una barra
                    // y abajo "aisi 420" es el material de esa barra.
                    $arriba = array_key_last($lineas);
                    if ($arriba !== null && $lineas[$arriba]['material_id'] === null
                        && empty($lineas[$arriba]['pedido_material'])) {
                        $this->ponerMaterial($lineas[$arriba], $mat);
                    }
                }

                continue;
            }

            // "Ti. Gr. 4 Ø4,76" y abajo "10 Barras": el renglon que solo dice
            // cuantas —sin material ni medida— es la cantidad del de arriba.
            $arriba = array_key_last($lineas);

            if ($arriba !== null && $lineas[$arriba]['cantidad'] === null
                && $linea['material_id'] === null && $linea['dimensiones'] === null
                && $this->soloDiceCuantas($linea['descripcion'])) {
                $lineas[$arriba]['descripcion'] .= ' '.$linea['descripcion'];
                $lineas[$arriba]['cantidad'] = $linea['cantidad'];
                $lineas[$arriba]['unidad_venta_id'] = $linea['unidad_venta_id'];
                $lineas[$arriba]['unidad'] = $linea['unidad'];
                $lineas[$arriba]['forma_id'] ??= $linea['forma_id'];
                $lineas[$arriba]['forma'] ??= $linea['forma'];

                continue;
            }

            // "Cantidad: 6" en su renglon, antes del item: se guarda para el
            // proximo item que no traiga la suya.
            if ($linea['cantidad'] !== null && $linea['material_id'] === null
                && $linea['dimensiones'] === null && $linea['forma_id'] === null
                && $this->soloDiceCuantas($linea['descripcion'])) {
                $pendienteCantidad = [
                    'cantidad' => $linea['cantidad'],
                    'unidad_venta_id' => $linea['unidad_venta_id'],
                    'unidad' => $linea['unidad'],
                ];

                continue;
            }

            if ($linea['material_id'] === null && empty($linea['pedido_material']) && $pendienteMaterial !== null) {
                $this->ponerMaterial($linea, $pendienteMaterial);
            }

            if ($linea['cantidad'] === null && $pendienteCantidad !== null) {
                $linea = [...$linea, ...$pendienteCantidad];
            }
            $pendienteCantidad = null;

            $lineas[] = $linea;
        }

        return ['lineas' => $lineas, 'sin_reconocer' => $sinReconocer];
    }

    /**
     * Si el renglon dice cuantas y nada mas: "Cantidad: 6", "10 Barras", "6
     * unidades". Uno que ademas nombra un articulo —"1 unidad – Media cupla DN
     * 40"— es un item aunque el catalogo no conozca el articulo: antes se
     * tomaba por la cantidad del item de al lado y se perdia sin aviso (Farca,
     * 14-10).
     */
    private function soloDiceCuantas(string $renglon): bool
    {
        // ponytail: hasta dos palabras ademas de los numeros; si aparece un
        // "6 barras largas" que deba engancharse, mirar el vocabulario en vez
        // de contar.
        $palabras = preg_split(
            '/\s+/',
            $this->conEspacios(preg_replace('/\d+(?:[.,]\d+)?/', ' ', $renglon) ?? ''),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        return count($palabras) <= 2;
    }

    /**
     * Las fichas: cada dato del item con su rotulo.
     *
     * Llegan de dos lados. El "DETALLE SOLICITADO" de la web, con las columnas
     * aplanadas por el copiar y pegar —el rotulo al final de un renglon y el
     * valor en el siguiente—:
     *
     *     MATERIAL
     *     AISI 420        FORMA
     *     barra redonda
     *     DIMENSIONES
     *     Ø10mm x 3 metros de largo      CANTIDAD
     *     3 metros
     *
     * Y las capturas de pantalla, que la IA copia en vertical: la forma sola en
     * un renglon y abajo cada medida y el material con su rotulo —
     *
     *     BARRA CUADRADA
     *     Seccion: 12 mm
     *     Longitud: 1000 mm
     *     Material: Acero ASTM A276 420
     *
     * Leidas renglon por renglon salian varias lineas sueltas y se perdian el
     * material o la medida. Es UN item, y cada dato ya dice que es.
     *
     * Eran dos lecturas con dos listas de rotulos, y la de la web —que solo
     * conocia DIMENSIONES— ganaba con "MATERIAL / DIAMETRO / LARGO / CANTIDAD"
     * y perdia el diametro y el largo sin aviso. Ahora es una, con todos.
     *
     * Hacen falta dos datos del item. Un mail que diga "Material: titanio" en
     * un renglon suelto sigue leyendose como mail.
     *
     * @return list<array<string, string>>
     */
    private function fichas(string $texto, $formas): array
    {
        $lineas = preg_split('/\R/u', $texto) ?: [];

        // Un renglon que es SOLO una forma se marca "FORMA: ...": es la forma
        // de una captura. Con numero o dos puntos no es una forma suelta
        // ("barra 12 x 1000" es una linea normal), y si arriba quedo el rotulo
        // FORMA solo, es su valor, como viene la web.
        foreach ($lineas as $i => $linea) {
            $l = trim($linea);

            if ($l === '' || str_contains($l, ':') || preg_match('/\d/u', $l) || mb_strlen($l) > 30) {
                continue;
            }

            $celdas = preg_split('/\t/u', trim($lineas[$i - 1] ?? '')) ?: [];

            if ($this->conEspacios((string) end($celdas)) === 'FORMA') {
                continue;
            }

            if ($this->buscarForma($this->normalizar($l), $l, $formas) !== null) {
                $lineas[$i] = 'FORMA: '.$l;
            }
        }

        $fichas = CamposDeFormulario::leer(implode("\n", $lineas), [
            'forma' => ['FORMA'],
            'material' => ['MATERIAL'],
            'cantidad' => ['CANTIDAD', 'CANT'],
            // Cada medida en su rotulo: se juntan despues.
            ...self::ROTULOS_DE_MEDIDA,
            // No arma la linea: se reconoce para que no se lea como una.
            'aclaraciones' => ['ACLARACIONES / OBSERVACIONES', 'ACLARACIONES', 'OBSERVACIONES'],
        ]);

        // Las aclaraciones no cuentan: "Material: titanio" y "Observaciones:
        // urgente" en un mail no hacen un item.
        return array_values(array_filter(array_map(fn ($f) => $this->armarFicha($f), $fichas)));
    }

    /**
     * Una ficha leida, hecha item: las medidas juntas en el orden en que las
     * leen las formas —lo que mide la seccion primero, el largo al final—.
     * Hacen falta dos datos del item; si no los tiene, null.
     *
     * @param  array<string, string>  $f
     * @return array<string, string>|null
     */
    private function armarFicha(array $f): ?array
    {
        $medidas = array_filter(array_map(fn ($rotulo) => $f[$rotulo] ?? null, array_keys(self::ROTULOS_DE_MEDIDA)));

        $ficha = array_filter([
            'material' => $f['material'] ?? null,
            'forma' => $f['forma'] ?? null,
            'dimensiones' => $medidas === [] ? null : implode(' X ', $medidas),
            'cantidad' => $f['cantidad'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return count($ficha) >= 2 ? $ficha : null;
    }

    /**
     * Las tablas: una fila de titulos y abajo un item por fila.
     *
     * Llegan de dos maneras. Pegadas de un Excel o copiadas de una captura,
     * cada fila en su renglon con las celdas separadas por "|" o por tabs:
     *
     *     Item | Diametro externo | Largo  | Material | cant
     *     1    | 114 mm           | 105 mm | INCONEL  | 1
     *
     * O en vertical, una celda por renglon: asi copio la IA la tabla de
     * TOSAMA (14-10) —"Item", "Diametro externo", "Largo", "Material",
     * "cant", y despues "1", "114 mm", "105 mm"—. Leida renglon por renglon,
     * cada "114 mm" era un item con cantidad 114.
     *
     * Los titulos dicen que es cada celda, asi que cada fila se arma como una
     * ficha y se lee como las demas. Si una fila no cierra —una medida sin
     * numero, una cantidad que no es numero— la tabla esta corrida y ahi
     * termina: mejor un item de menos que uno armado con celdas de otro.
     *
     * @return list<array{ficha: array<string, string>, medidas: array<string, float>}>
     */
    private function fichasDeTabla(string $texto): array
    {
        $renglones = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $texto) ?: []),
            fn ($r) => $r !== '',
        ));

        // Por filas: los titulos en un renglon y cada item en el suyo.
        foreach ($renglones as $i => $renglon) {
            $titulos = array_map(fn ($c) => $this->titulo($c), $this->celdas($renglon));

            if (! $this->sonTitulos($titulos)) {
                continue;
            }

            $filas = [];

            foreach (array_slice($renglones, $i + 1) as $siguiente) {
                // La raya que separa los titulos en una tabla de markdown.
                if (preg_match('/^[\s|:\-–—]+$/u', $siguiente)) {
                    continue;
                }

                $celdas = $this->celdas($siguiente);

                // Las celdas vacias del final se pierden al copiar: "| 9138 | |
                // |" llega sin las ultimas. Se completan, siempre que el renglon
                // tenga celdas —un "Saludos" suelto no es una fila—.
                if (count($celdas) >= 2 && count($celdas) < count($titulos)) {
                    $celdas = array_pad($celdas, count($titulos), '');
                }

                if (count($celdas) !== count($titulos) || ! $this->esFila($titulos, $celdas)) {
                    break;
                }

                $filas[] = $celdas;
            }

            if ($filas !== []) {
                return array_map(fn ($f) => $this->fichaDeFila($titulos, $f), $filas);
            }
        }

        // En vertical: los titulos de a uno por renglon, y abajo las celdas de
        // a una, fila tras fila.
        foreach ($renglones as $i => $renglon) {
            $titulos = [];

            while (isset($renglones[$i + count($titulos)])
                && ($titulo = $this->titulo($renglones[$i + count($titulos)])) !== null) {
                $titulos[] = $titulo;
            }

            if (! $this->sonTitulos($titulos)) {
                continue;
            }

            $filas = [];

            foreach (array_chunk(array_slice($renglones, $i + count($titulos)), count($titulos)) as $celdas) {
                if (count($celdas) !== count($titulos) || ! $this->esFila($titulos, $celdas)) {
                    break;
                }

                $filas[] = $celdas;
            }

            // La primera fila de titulos decide: si abajo no hay filas que
            // cierren, no es una tabla, y probar desde el titulo siguiente
            // leeria las celdas corridas.
            return array_map(fn ($f) => $this->fichaDeFila($titulos, $f), $filas);
        }

        return [];
    }

    /**
     * Que dato es una columna, por su titulo: "Diámetro externo", "cant.",
     * "Largo (mm)". La unidad del titulo vale para las celdas que no la traen.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function titulo(string $celda): ?array
    {
        if (mb_strlen($celda) > 30 || preg_match('/\d/', $celda)) {
            return null;
        }

        $titulo = $this->conEspacios($celda);
        $unidad = null;

        if (preg_match('/^(.+?)\s+(?:EN\s+)?(MM|CM|MTS?|METROS?|M)$/', $titulo, $m)) {
            [$titulo, $unidad] = [$m[1], $m[2]];
        }

        foreach (self::TITULOS as $patron => $clave) {
            if (preg_match($patron, $titulo)) {
                return [$clave, $unidad];
            }
        }

        return null;
    }

    /** Las celdas de una fila: separadas por "|", por tabs o por dos espacios. */
    private function celdas(string $renglon): array
    {
        $partes = match (true) {
            str_contains($renglon, '|') => explode('|', trim($renglon, " \t|")),
            str_contains($renglon, "\t") => explode("\t", $renglon),
            default => preg_split('/\s{2,}/u', $renglon) ?: [],
        };

        return array_map('trim', $partes);
    }

    /** Tres columnas reconocidas, y que alguna diga que se pide. */
    private function sonTitulos(array $titulos): bool
    {
        $claves = array_column(array_filter($titulos), 0);

        return count($claves) >= 3
            && array_intersect($claves, ['material', 'forma', 'descripcion', ...self::MEDIDAS_DE_TABLA]) !== [];
    }

    /**
     * Que la fila cierre con sus titulos. Una medida sin numero o una cantidad
     * que no es numero quiere decir que las celdas estan corridas.
     */
    private function esFila(array $titulos, array $celdas): bool
    {
        $pide = false;

        foreach ($titulos as $i => $titulo) {
            $valor = trim($celdas[$i] ?? '');

            if ($titulo === null || $valor === '') {
                continue;
            }

            if (in_array($titulo[0], self::MEDIDAS_DE_TABLA, true) && ! preg_match('/\d/', $valor)) {
                return false;
            }

            if ($titulo[0] === 'cantidad' && ! preg_match('/^\d+(?:[.,]\d+)?\s*[A-Za-z.]{0,6}$/u', $valor)) {
                return false;
            }

            $pide = $pide || $titulo[0] !== 'item';
        }

        return $pide;
    }

    /**
     * Una fila hecha ficha. Las medidas van juntas en "dimensiones", como las
     * escribio el cliente, y cada una ademas en milimetros con su clave para
     * ponerla en su columna.
     *
     * @return array{ficha: array<string, string>, medidas: array<string, float>}
     */
    private function fichaDeFila(array $titulos, array $celdas): array
    {
        $ficha = [];
        $textos = [];
        $largos = [];
        $medidas = [];

        foreach ($titulos as $i => $titulo) {
            $valor = trim($celdas[$i] ?? '');

            if ($titulo === null || $valor === '' || $titulo[0] === 'item') {
                continue;
            }

            [$clave, $unidad] = $titulo;

            if (! in_array($clave, self::MEDIDAS_DE_TABLA, true)) {
                $ficha[$clave] = $valor;

                continue;
            }

            // Sin unidad escrita, la del titulo: "Largo (m)" y "3" son 3 metros.
            if ($unidad !== null && preg_match('/^\d+(?:[.,]\d+)?$/', $valor)) {
                $valor .= ' '.$unidad;
            }

            // El largo al final, que es como se escribe una medida: "25 X 3 M".
            $clave === 'length' ? $largos[] = $valor : $textos[] = $valor;

            // En pulgadas queda como texto: «1 1/2"» no es 1 mm.
            if (isset(self::COLUMNA[$clave])
                && preg_match('/^(\d+(?:[.,]\d+)?)\s*(MM|CM|MTS?|METROS?|M)?$/iu', $valor, $m)) {
                $medidas[$clave] = $this->aMilimetros($m[1], $m[2] ?? '');
            }
        }

        if ([...$textos, ...$largos] !== []) {
            $ficha['dimensiones'] = implode(' X ', [...$textos, ...$largos]);
        }

        return ['ficha' => $ficha, 'medidas' => $medidas];
    }

    /**
     * Una ficha es una linea: se arma el renglon con los datos en el orden
     * que el lector ya sabe leer —cantidad, forma, material, medida— y se lee
     * como cualquier otro. Asi la forma, la cantidad y las medidas salen de
     * las mismas reglas que un mail escrito a mano.
     *
     * Lo que cambia es el material: aca el cliente lo escribio en su campo, asi
     * que se sabe exactamente que pidio aunque el catalogo no lo tenga tal cual.
     * "AISI 420" no es ninguno de los cinco 420 cargados —B, C, F, M, J2— y
     * elegir uno seria adivinar el acero. Queda escrito en lo que pidio el
     * cliente, y la linea marcada como distinta para que alguien elija.
     *
     * @param  array<string, string>  $ficha
     */
    private function leerFicha(array $ficha, $materiales, $formas, $unidades): ?array
    {
        $renglon = implode(' ', array_filter([
            $ficha['cantidad'] ?? null,
            $ficha['unidad'] ?? null,
            $ficha['forma'] ?? null,
            $ficha['material'] ?? null,
            $ficha['descripcion'] ?? null,
            $ficha['dimensiones'] ?? null,
        ]));

        $linea = $this->leerRenglon($renglon, $materiales, $formas, $unidades);

        if ($linea === null || blank($ficha['material'] ?? null) || $linea['material_id'] !== null) {
            return $linea;
        }

        return [
            ...$linea,
            'igual_a_lo_pedido' => false,
            'pedido_material' => $ficha['material'],
            'pedido_forma' => $linea['forma'] ?? ($ficha['forma'] ?? null),
        ];
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
            // Las claves numericas de un array llegan como int: "420" es 420.
            if (ctype_alpha((string) $token) && mb_strlen((string) $token) >= 6) {
                $this->vocabulario[$token] = true;
            }
        }
    }

    /** Un renglón por línea de pedido, sin la viñeta de la lista. */
    private function separarRenglones(string $texto): array
    {
        // Tambien antes de un rotulo de la ficha de contacto pegado al pedido:
        // "...largo 970 mm.Nombre: Carlos". Una lista cerrada, porque cortar
        // antes de cualquier "Palabra:" rompe "de largo. Material: Titanio".
        // Despues de un parentesis y una coma, solo si sigue otro item con su
        // cantidad: "Brida ciega DN 100 (4"), Clase 300." es UN item, y cortado
        // ahi la clase quedaba suelta y sin reconocer (Farca, 14-10).
        $renglones = preg_split('/[\r\n]+|(?<=\))\s*[,;]\s*(?=\d)|(?<!\/)(?<!\/ )(?=\b(?:NOMBRE|CARGO|EMPRESA|DIRECCI[OÓ]N|TEL[EÉ]FONO|CELULAR|WHATSAPP|E-?MAIL|WEB)\b[^:\r\n]{0,20}:)/iu', $texto) ?: [];

        $limpios = array_map(
            fn ($r) => trim(preg_replace(self::VINETA, '', trim($r)) ?? ''),
            $renglones,
        );

        // Lo muy corto es ruido ("Hola", "Gracias")... salvo una medida con su
        // Ø: "Ø4,76" solo en su renglon es el diametro del pedido.
        return array_values(array_filter(
            $limpios,
            fn ($r) => mb_strlen($r) >= 6 || preg_match('/^[Ø⌀]\s*\d/u', $r),
        ));
    }

    private function leerRenglon($renglon, $materiales, $formas, $unidades): ?array
    {
        $normalizado = $this->normalizar($renglon);

        $forma = $this->buscarForma($normalizado, $renglon, $formas);
        [$cantidad, $unidad] = $this->buscarCantidad($renglon, $unidades);
        // El rango de largo se lee antes y se saca del renglon: si no, en
        // "Ø 50 largo 2,80 - 3,20 m" el 2,80 entraba como largo, en milimetros.
        [$largoMin, $largoMax, $rango] = $this->largosDe($renglon);
        $medidas = $this->buscarMedidas($rango === null ? $renglon : str_replace($rango, ' ', $renglon), $forma);

        if ($rango !== null) {
            $medidas['texto'] = trim(($medidas['texto'] ?? '').' '.$rango);
        }

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

        // Si el catalogo no lo encontro, la designacion que nombro el cliente
        // queda como lo que pidio: en "CODO 90° ... ASTM A403 AISI 310 ..."
        // (Roberto, 14-10) el material se perdia. No se elige ningun grado:
        // "AISI 310" puede ser el 310, el 310S o el 310H, lo decide quien cotiza.
        $pedido = $material === null ? $this->designacionAisi($renglon) : null;

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
            // Con un rango de largo, el largo es el rango: el promedio lo saca la linea.
            'largo_mm' => $largoMin === null ? $medidas['largo_mm'] : null,
            'largo_min_mm' => $largoMin,
            'largo_max_mm' => $largoMax,
            'cantidad' => $cantidad,
            'unidad_venta_id' => $unidad?->id,
            'unidad' => $unidad?->codigo,
            // Con o sin costura, laminada, la norma: lo que el cliente ya
            // escribio en el renglon no hace falta volver a tipearlo.
            'caracteristicas' => $this->caracteristicasDe($renglon),
            // Lo que pidió el cliente arranca igual a lo que se va a cotizar,
            // salvo que haya nombrado un material que no esta en el catalogo.
            'igual_a_lo_pedido' => $pedido === null,
            ...($pedido === null ? [] : ['pedido_material' => $pedido, 'pedido_forma' => $forma?->nombre]),
            // Las variantes —aerea y maritima, tramos de cantidad— se agregan
            // con un clic en la linea. La misma clave que devuelve la IA, para
            // que la pantalla no tenga que preguntar de donde vino la lectura.
            'alternativas' => [],
        ];
    }

    /**
     * Las caracteristicas del producto que ya vienen escritas en el renglon.
     *
     * "Caño s/c", "barra estirada", "bajo ASTM B348": lo que completa al
     * material y la medida. Se lee como viene —s/c y c/c son como lo escriben
     * en los mails— y se deja listo para imprimir. Las normas salen tal cual
     * estan nombradas (ASTM, AMS), que es lo que el cliente certifica.
     */
    private function caracteristicasDe(string $renglon): ?string
    {
        $t = ' '.mb_strtolower($renglon).' ';
        $encontradas = [];

        if (preg_match('~(?<![a-z])s\s*/\s*c(?![a-z])|sin\s+costura~u', $t)) {
            $encontradas[] = 'SIN COSTURA';
        } elseif (preg_match('~(?<![a-z])c\s*/\s*c(?![a-z])|con\s+costura~u', $t)) {
            $encontradas[] = 'CON COSTURA';
        }

        // "Largos aleatorios", "random", "L/A", "R/L": no es una medida, es como
        // viene el material. Como caracteristica sale impreso; como medida se
        // perdia (lista del cliente, 14-10-2026).
        if (preg_match('~largos?\s+(?:aleatori|random|variable)|(?<![a-z])random(?![a-z])|(?<![a-z])[lr]\s*/\s*[al](?![a-z])~u', $t)) {
            $encontradas[] = 'LARGOS ALEATORIOS';
        }

        $procesos = [
            'LAMINADA' => 'laminad', 'ESTIRADA EN FRIO' => 'estirad', 'FORJADA' => 'forjad',
            'RECTIFICADA' => 'rectificad', 'RECOCIDA' => 'recocid',
        ];
        foreach ($procesos as $canonico => $raiz) {
            if (str_contains($t, $raiz)) {
                $encontradas[] = $canonico;
            }
        }

        if (preg_match_all('~\b(ASTM|AMS|API|SAE)\s*([A-Z]?\s?\d{2,4}[A-Z]?)~iu', $renglon, $m, PREG_SET_ORDER)) {
            foreach ($m as $norma) {
                $encontradas[] = mb_strtoupper($norma[1].' '.preg_replace('/\s+/', '', $norma[2]));
            }
        }

        $texto = implode(' · ', array_values(array_unique($encontradas)));

        return $texto !== '' ? mb_substr($texto, 0, 120) : null;
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
     * El material que nombra un renglon que no pide nada: el que el cliente
     * escribe una vez para todo el pedido ("En ALLOY 20 - UNS N08020:") o en su
     * propio renglon ("aisi 420", "Material: Acero ASTM A276 420").
     *
     * Devuelve el id del catalogo si lo encuentra; si no, el texto del cliente,
     * para dejarlo como "lo que pidio" y que alguien elija el grado —"AISI 420"
     * no es ninguno de los 420 cargados, elegir uno seria adivinar el acero.
     *
     * @return array{id: ?int, nombre: string}|null
     */
    private function materialDicho(string $renglon, $materiales): ?array
    {
        $rotulado = (bool) preg_match('/^\s*(?:en\s+|por\s+material\s+|material\s*:)/iu', $renglon);

        // Sin el rotulo ni el "En ...", y sin la puntuacion de los bordes.
        $limpio = trim(preg_replace('/^\s*(?:en\s+|por\s+material\s+|material\s*:?\s*)/iu', '', trim($renglon)) ?? $renglon);
        $limpio = trim($limpio, " \t.:;-");

        // Primero el catalogo: el renglon, el texto limpio, y la primera parte
        // antes de un guion o barra ("ALLOY 20 - UNS N08020" -> "ALLOY 20").
        foreach ([$renglon, $limpio, preg_split('~\s[-\/]\s~', $limpio)[0] ?? $limpio] as $cand) {
            if (($m = $this->buscarMaterial($cand, $materiales)) !== null) {
                return ['id' => $m->id, 'nombre' => $m->nombre];
            }
        }

        // No esta en el catalogo, pero se ve que es un material. Una oracion
        // larga sin rotulo no cuenta: "trabajamos acero de primera" no es un item.
        if (($rotulado || mb_strlen($limpio) <= 40) && $this->pareceMaterial($limpio)) {
            return ['id' => null, 'nombre' => mb_substr(mb_strtoupper($limpio, 'UTF-8'), 0, 120)];
        }

        return null;
    }

    /** La designacion AISI que nombra el texto: "AISI 310", "AISI 316L". */
    private function designacionAisi(string $texto): ?string
    {
        return preg_match('/\bAISI\s*(\d{3}[A-Z]{0,3})\b/iu', $texto, $m)
            ? 'AISI '.mb_strtoupper($m[1], 'UTF-8')
            : null;
    }

    /** Si un texto nombra un material: una familia, o una norma con su numero. */
    private function pareceMaterial(string $texto): bool
    {
        $t = $this->sinAcentos($texto);

        return (bool) preg_match(
            '/\b(ACERO|ACEROS|INOX|INOXIDABLE|TITANIO|MONEL|INCONEL|INCOLOY|HASTELLOY|ALLOY|ALUMINIO|'
            .'BRONCE|COBRE|NIQUEL|NICKEL|LATON|ZINC|PLOMO|MAGNESIO|MOLIBDENO|TUNGSTENO|COBALTO|TANTALIO|'
            .'ZIRCONIO|GRAFITO|NITINOL|KANTHAL|DUPLEX|CARBONO)\b/u', $t
        ) || (bool) preg_match('/\b(AISI|ASTM|ASME|AMS|SAE|UNS|API|DIN)\s*[A-Z]?\s*\d/u', $t);
    }

    /** Pone el material en una linea: del catalogo, o como "lo que pidio". */
    private function ponerMaterial(array &$linea, array $mat): void
    {
        if ($mat['id'] !== null) {
            $linea['material_id'] = $mat['id'];
            $linea['material'] = $mat['nombre'];

            return;
        }

        $linea['igual_a_lo_pedido'] = false;
        $linea['pedido_material'] = $mat['nombre'];
        $linea['pedido_forma'] ??= $linea['forma'] ?? null;
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
     * El nombre tiene que estar escrito en palabras enteras, y da lo mismo el
     * plural: "3 Barras", "2 Discos", "4 Caños". Con las formas del sistema
     * anterior prendidas (14-10) se busca entre unas 250, y adentro de otra
     * palabra aparecian solas: JUNTA en "planilla adjunta", RECTA en "entrega
     * directa", MONEDAS en "otras monedas".
     *
     * Gana la que tiene sus medidas cargadas —"caño ... segun plano" es un
     * CAÑO, no un PLANO—, y entre iguales el nombre mas largo: "brida ciega"
     * es BRIDA CIEGA y no BRIDA.
     */
    private function buscarForma(string $normalizado, string $renglon, $formas): ?Forma
    {
        $conEspacios = $this->conEspacios($renglon);
        $mejor = null;
        $puntaje = [-1, 0];

        foreach ($formas as $forma) {
            // Menos de cuatro letras no alcanza para distinguir nada.
            if (mb_strlen($this->normalizar($forma->nombre)) < 4) {
                continue;
            }

            $nombre = $this->conEspacios($forma->nombre);

            // El plural en cada palabra —"barras redondas", "juntas
            // espiraladas"—, y "1/2" escrito como "media": "media cupla" es la
            // 1/2 CUPLA, no la CUPLA.
            $palabras = explode(' ', $nombre);
            $media = ($palabras[0] ?? '') === '1' && ($palabras[1] ?? '') === '2';
            $patron = ($media ? '(?:1\s+2|MEDIA)\s+' : '')
                .implode('(?:E?S)?\s+', array_map(fn ($p) => preg_quote($p, '/'), $media ? array_slice($palabras, 2) : $palabras))
                .'(?:E?S)?';

            if (! preg_match('/\b'.$patron.'\b/u', $conEspacios)) {
                continue;
            }

            $este = [empty($forma->campos) ? 0 : 1, mb_strlen($nombre)];

            if ($este > $puntaje) {
                $mejor = $forma;
                $puntaje = $este;
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

          BARRA a secas no esta en la lista: se resuelve abajo, despues de
          que las abreviaturas de hexagonal y cuadrada tuvieron su turno.
        */
        foreach (self::ABREVIATURAS as $nombre => $claves) {
            foreach ($claves as $clave) {
                // El plural va con S o con ES: "hexagonales" es HEXAGONAL + ES.
                if (! preg_match('/\b'.preg_quote($clave, '/').'(?:E?S)?\b/', $conEspacios)) {
                    continue;
                }

                $forma = $formas->firstWhere('nombre', $nombre);

                if ($forma) {
                    return $forma;
                }
            }
        }

        /*
          "BARRA" a secas es BARRA REDONDA.

          Estuvo pendiente de CORDES y se dejaba vacia: el catalogo tenia
          BARRA, BARRA REDONDA y BARRA RED. / VARILLA, y adivinar define el
          peso que se factura. El 30-09-2026 lo resolvieron: "todo lo que dice
          barra, convertilo en barra redonda". Las tres quedaron en una.

          Corre al final a proposito: "BARRA HEXAGONAL" o "BAR CUAD" ya
          encontraron su forma arriba y no llegan aca.

          Pero si el renglon dice de que otro tipo es y arriba no se reconocio
          —"barras octogonales", una forma que no tiene abreviatura— no se la
          vuelve redonda: una barra vacia se completa a mano, una redonda
          equivocada se factura con el peso de otra pieza.
        */
        if (preg_match('/\bBARRAS?\b/', $conEspacios)
            && ! preg_match('/\b(HEXAG|CUADRAD|RECTANG|OCTOG|CANULAD|PLACAD)/', $conEspacios)) {
            return $formas->firstWhere('nombre', 'BARRA REDONDA');
        }

        return null;
    }

    /**
     * "6 UN", "2 c/u", "3 metros" al principio del renglón.
     *
     * Se lee del renglón como se escribió, con sus espacios y comas. Leído
     * del texto normalizado, que no tiene ninguno, la cantidad se pegaba al
     * número de al lado: "10 Ø4,76" daba 10476 y "1,5 MT" daba 15.
     */
    private function buscarCantidad(string $renglon, $unidades): array
    {
        // "Cantidad: 6", "Cant.: 2", "Cant: 1": lo dice con todas las letras,
        // sea en su renglon suelto o al final del item ("... - Cant.: 1.").
        if (preg_match('/\bcant(?:idad)?\.?\s*:?\s*(\d+(?:[.,]\d+)?)\b/iu', $renglon, $mc)) {
            return [(float) str_replace(',', '.', $mc[1]), $unidades->firstWhere('codigo', 'UN')];
        }

        if (! preg_match('/^\s*(\d+(?:[.,]\d+)*)(.*)$/su', $renglon, $m)
            && ! $this->cantidadAlFinal($renglon, $m)) {
            return [null, null];
        }

        // Como se escribe acá: coma decimal, y punto de miles ("1.000 KG").
        $numero = match (true) {
            str_contains($m[1], ',') => str_replace(['.', ','], ['', '.'], $m[1]),
            (bool) preg_match('/^\d{1,3}(\.\d{3})+$/', $m[1]) => str_replace('.', '', $m[1]),
            default => $m[1],
        };

        $cantidad = (float) $numero;
        $resto = $this->normalizar($m[2]);

        $porTexto = [
            'METRO' => 'MT', 'METROS' => 'MT', 'MTS' => 'MT', 'MT' => 'MT',
            'KILO' => 'KG', 'KILOS' => 'KG', 'KG' => 'KG',
            'CU' => 'C/U', 'UN' => 'UN', 'UNIDAD' => 'UN', 'UNIDADES' => 'UN',
        ];

        foreach ($porTexto as $texto => $codigo) {
            if (str_starts_with($resto, $texto)) {
                return [$cantidad, $unidades->firstWhere('codigo', $codigo)];
            }
        }

        return [$cantidad, $unidades->firstWhere('codigo', 'UN')];
    }

    /**
     * "Ti. Gr. 4 Ø4,76 ASTM F 67 10 Barras": lo que se cuenta, al final.
     *
     * Solo un entero pegado a una palabra que cuenta piezas y cerrando el
     * renglon. Y no si lo de antes es un grado o una norma: en "Titanio
     * grado 2 barras" el 2 es el grado, no cuantas.
     */
    private function cantidadAlFinal(string $renglon, &$m): bool
    {
        if (! preg_match('/^(.*?)(?<![\d.,])(\d+)\s*((?:BARRAS?|PIEZAS?|PZAS?|UNIDADES|UN)\.?)\s*$/iu', $renglon, $f)
            || preg_match('/(?:\b(?:GR|GRADO|AISI|SAE|ASTM|UNS|TIPO|F)|[xX×Ø⌀])[\s.]*$/iu', $f[1])) {
            return false;
        }

        $m = [$f[0], $f[2], $f[3]];

        return true;
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
        $numero = '\d+(?:[.,]\d+)?';
        $unidad = '(?:\s*(?:MM|CM|MTS?|METROS?|M)(?![A-Z]))?';

        /*
          Los caños vienen en pulgadas y con schedule, con o sin fraccion:
          «1 1/2" SCH 40», «3/4"», «1 1/2" x 1 1/4"». La medida se guarda como
          la escribe el cliente —asi la pide y asi la busca el proveedor—, y si
          el largo viene aparte en mm, se toma. Antes «1 1/2"» se leia «2"»: la
          fraccion se perdia, y la reduccion «1 1/2" x 1 1/4"» quedaba en «2"».
        */
        $valPulg = '(?:\d+\s+\d+\s*\/\s*\d+|\d+\s*\/\s*\d+|'.$numero.')';
        // El diametro en mm entre parentesis no corta el schedule: «6" (168,3MM)
        // SCH40S» es un caño de 6" SCH40S, no uno de 6" sin schedule.
        $pulgadas = '/(?:[Ø⌀]\s*|DIA\s*)?'.$valPulg.'\s*"\s*(?:\(\s*'.$numero.'\s*MM\s*\)\s*)?(?:SCH\.?\s*\d+\w*)?'
            .'(?:\s*[xX]\s*'.$valPulg.'\s*"?\s*(?:SCH\.?\s*\d+\w*)?)?/iu';

        if (preg_match($pulgadas, $renglon, $m)) {
            $medidas['texto'] = trim($m[0]);

            // El largo escrito aparte en mm: «... x 1500 mm», «Largo: 1500 mm».
            if (preg_match('/(?:x|largo)[\s:]*('.$numero.')\s*(MM|CM|MTS?|METROS?|M)\b/iu', $renglon, $l)) {
                $medidas['largo_mm'] = $this->aMilimetros($l[1], $l[2]);
            }

            return $medidas;
        }

        $numeros = [];
        $marcado = false;

        /*
          Cada numero con su unidad, si la tiene: "Ø10mm x 3 metros de largo".

          Antes la unica unidad que se leia era MM, y al final de la serie. El
          largo de "Ø10mm x 3 metros" entraba como 3 mm —una arandela— y el
          peso salia mil veces mas chico. Sin unidad escrita, milimetros, que
          es como escribe casi todo el mundo.
        */

        // "38.1 X 145 MM" · "2 X 1000 X 2000" · "DIA 65 X 145MM" · "Ø127mm x 25.4mm" · "Ø10mm x 3 metros"
        // "76 mm largo 970 mm": LARGO separa como la x. Y "70 mm y largo 75 mm"
        // tambien (Farca, 14-10): sin el "y" el largo se perdia.
        $serie = '/(DIA\s*|Ø\s*)?'.$numero.$unidad.'(?:\s*(?:X|(?:Y\s+)?LARGO)\s*'.$numero.$unidad.'){1,2}/iu';

        if (preg_match($serie, $renglon, $m)) {
            $medidas['texto'] = trim($m[0]);
            $marcado = trim($m[1] ?? '') !== '';

            foreach (preg_split('/\s*(?:X|(?:Y\s+)?LARGO)\s*/i', $m[0]) ?: [] as $pieza) {
                if (preg_match('/('.$numero.')\s*(MM|CM|MTS?|METROS?|M)?/iu', $pieza, $q)) {
                    $numeros[] = $this->aMilimetros($q[1], $q[2] ?? '');
                }
            }
        } elseif (preg_match('/(DIA\s*|Ø\s*)?('.$numero.')\s*MM/iu', $renglon, $m)) {
            // Una sola medida, pero con su unidad puesta: "ALAMBRE 0,70 MM".
            $medidas['texto'] = trim($m[0]);
            $marcado = trim($m[1] ?? '') !== '';
            $numeros = [$this->aMilimetros($m[2], 'MM')];
        } elseif (preg_match('/[Ø⌀]\s*('.$numero.')(?!\d|[.,]\d|\s*\/)/u', $renglon, $m)) {
            // "Ø4,76" sin MM: el simbolo ya dice que es un diametro, y sin
            // unidad es en milimetros. DIA no: tambien es "dia de entrega".
            $medidas['texto'] = trim($m[0]);
            $marcado = true;
            $numeros = [$this->aMilimetros($m[1], 'MM')];
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

            $medidas[$columna] = $n;
        }

        return $medidas;
    }

    /** "3" y "METROS" son 3000 mm. Sin unidad, ya esta en milimetros. */
    /**
     * El largo escrito como rango: "en largos de 3 a 6 metros", "largo 2,80 -
     * 3,20 m", "entre 3 y 6 m". Va a largo minimo y maximo, y la linea calcula
     * con el promedio (ver LargosVariablesTest).
     *
     * En milimetros solo con la palabra LARGO: "de 2 a 3 mm" puede ser un
     * espesor. En metros alcanza con "de" o "entre": nada mas se mide asi.
     *
     * @return array{0: ?float, 1: ?float, 2: ?string} minimo, maximo y lo escrito
     */
    private function largosDe(string $renglon): array
    {
        $n = '(\d+(?:[.,]\d+)?)';
        $metros = '(MTS?|METROS?|M)(?![A-Z])';
        $cualquiera = '(MM|CM|MTS?|METROS?|M)(?![A-Z])';

        $patrones = [
            '/\bLARGOS?\s*(?:DE|ENTRE)?\s*'.$n.'\s*(?:'.$cualquiera.')?\s*(?:A|Y|-|\/)\s*'.$n.'\s*'.$cualquiera.'/iu',
            '/\b(?:DE|ENTRE)\s+'.$n.'\s*(?:'.$metros.')?\s*(?:A|Y|-)\s*'.$n.'\s*'.$metros.'/iu',
        ];

        foreach ($patrones as $patron) {
            if (preg_match($patron, $renglon, $m)) {
                $min = $this->aMilimetros($m[1], ($m[2] ?? '') !== '' ? $m[2] : $m[4]);
                $max = $this->aMilimetros($m[3], $m[4]);

                if ($min > 0 && $max > $min) {
                    return [$min, $max, trim($m[0])];
                }
            }
        }

        return [null, null, null];
    }

    private function aMilimetros(string $valor, string $unidad): float
    {
        $factor = match (true) {
            (bool) preg_match('/^(M|MTS?|METROS?)$/i', $unidad) => 1000,
            strcasecmp($unidad, 'CM') === 0 => 10,
            default => 1,
        };

        return (float) str_replace(',', '.', $valor) * $factor;
    }
}

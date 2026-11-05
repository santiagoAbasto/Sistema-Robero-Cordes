<?php

namespace App\Services\Migracion;

/**
 * Saca las medidas de la descripcion de una linea historica.
 *
 * "TIT GR2 BARRA 1.60 X 915 MM" son 1,60 mm de diametro y 915 de largo. El
 * sistema anterior lo escribia asi y nunca lo guardo en campos: al abrir una
 * cotizacion vieja para editarla, el material y la forma aparecen pero las
 * medidas estan vacias y hay que volver a tipearlas mirando el texto.
 *
 * EL ORDEN NO ES EL DE LOS CAMPOS, y esa es la trampa: la forma declara sus
 * medidas en un orden y el sistema anterior las escribia en otro. Por eso cada
 * forma dice aca en que orden viene escrito SU texto, verificado mirando
 * descripciones reales del archivo.
 *
 * Las formas que no estan en la lista no se tocan. Sin saber que significa
 * cada numero, cargarlos es inventar una medida, y de la medida sale el peso
 * que se factura.
 *
 * Auditado el 05-11-2026 contra las 13.071 lineas locales, linea por linea: de
 * 546 lecturas, 63 estaban mal —caños en pulgadas con schedule leidos como
 * exterior 40 y pared 760, numeros cortados, metros tomados por milimetros,
 * alambres rectangulares— y ya no se cargan o se leen bien. Lo que no se puede
 * leer con seguridad se deja vacio: la descripcion queda entera en la linea y
 * se completa a mano.
 */
class MedidasDelTexto
{
    /**
     * El orden en que el sistema anterior escribia las medidas de cada forma.
     *
     * Las claves son las de Forma::campos. Solo entran las formas cuyo orden se
     * verifico mirando descripciones reales del archivo.
     *
     * @var array<string, list<string>>
     */
    public const ORDEN = [
        // Redondos: diametro y largo.
        'BARRA' => ['diameter', 'length'],
        'BARRA REDONDA' => ['diameter', 'length'],
        'ALAMBRE' => ['diameter', 'length'],
        'VARILLA' => ['diameter', 'length'],
        'VARILLA DE APORTE' => ['diameter', 'length'],

        // Huecos: exterior, pared y largo.
        'CAÑO' => ['outer', 'wall', 'length'],
        'TUBO' => ['outer', 'wall', 'length'],

        // Diametro y espesor.
        'DISCO' => ['diameter', 'height'],
    ];

    /** Las formas que son largas y finas: su largo es mucho mas que su diametro. */
    private const FILIFORMES = ['ALAMBRE', 'VARILLA', 'VARILLA DE APORTE'];

    /** Las formas redondas: un diametro y un largo, nunca tres numeros. */
    private const REDONDOS = ['BARRA', 'BARRA REDONDA', 'ALAMBRE', 'VARILLA', 'VARILLA DE APORTE'];

    /**
     * Como se escribe de verdad la barra redonda en las descripciones: "BARRA", "BARRAS", "BARRA RED", "BAR RED",
     * "BAR.RED." y "BARRED". Con solo buscar "BARRA REDONDA" quedaban afuera las 2.457 barras de Monel 400.
     */
    private const BARRA_REDONDA = '/(?<![\pL\d])(?:BARRA\s+REDONDA|BARRAS?\s*RED\.?|BAR\.?\s*RED\.?|BARRED|BARRAS?)(?![\pL\d])/iu';

    /** Si despues de "BARRA" viene esto, no es redonda: su medida no es un diametro. */
    private const NO_ES_REDONDA = '/^\s*(?:DE\s+)?(?:CUADRAD[AO]S?|CUAD\.?|HEXAGONAL(?:ES)?|EXAGONAL(?:ES)?|HEX\.?|OCTOGONAL(?:ES)?|RECTANGULAR(?:ES)?|RECT\.?|PLANAS?|PLANOS?|PLANCHUELAS?|HUECAS?|HUECOS?|PERFORADAS?|PERFILADAS?)(?![\pL])/iu';

    /** Palabras de pedido y de oferta: con otra medida en la misma linea, no se sabe cual es la del articulo. */
    private const OFERTA = '/(?<![\pL])(?:OFREC\pL*|ALTERNATIV\pL*|PEDI\pL*|PIDE\pL*|PIDEN|BUSCA\pL*|CONSULT\pL*|REQUIERE\pL*|SE\s+COBRA|EN\s+LUGAR\s+DE)(?![\pL])/iu';

    /**
     * Los armados: una cesta, un canasto, una serpentina o un conjunto nombran una barra o un tubo que es solo una pieza de
     * otra cosa ("CESTA ... CON GANCHOS PARA CALZAR BARRA 19X45 MM"): esa medida no es la del articulo.
     */
    private const ARMADO = '/(?<![\pL])(?:CEST[AO]S?|CANAST\pL*|SERPENTIN\pL*|CONJUNTOS?|CONFORMAD\pL*|ENSAMBL\pL*|ARMAD[OA]S?)(?![\pL])/iu';

    /** Cuantos caracteres despues de la forma puede empezar la medida. */
    private const CERCA = 26;

    /*
      LOS PLANOS QUEDAN AFUERA, a proposito.

      Chapa, planchuela y fleje no tienen un orden estable en el archivo: hay
      "CHAPA 1 X 1220 X 3048" donde el 1 es el espesor y hay "CHAPA DE 910 X
      600 X 16 MM" donde el espesor es el 16. Medido sobre una muestra al azar,
      leerlas daba una de cada tres al reves — y del espesor sale el peso que
      se factura.

      Cual es el orden de verdad, o si conviven los dos, lo sabe quien las
      cargaba. Hasta entonces se dejan sin medidas: la descripcion esta entera
      en la linea y se completan a mano.
    */

    /**
     * Las medidas en milimetros que nombra ese texto, para esa forma.
     *
     * @return array<string, float> clave de Forma::campos => milimetros
     */
    public function leer(string $descripcion, string $forma): array
    {
        $orden = self::ORDEN[mb_strtoupper(trim($forma))] ?? null;

        if ($orden === null) {
            return [];
        }

        $numeros = $this->numeros($descripcion, mb_strtoupper(trim($forma)));

        if ($numeros === []) {
            return [];
        }

        // Un redondo es un diametro y un largo: con tres numeros ("BARRA 10 X 25 X 1000") es una seccion rectangular.
        if (count($numeros) > 2 && in_array(mb_strtoupper(trim($forma)), self::REDONDOS, true)) {
            return [];
        }

        $medidas = [];

        foreach ($numeros as $i => $escrito) {
            if (! isset($orden[$i])) {
                break;
            }

            /*
              Un largo escrito "1.906" o "6.000" es ambiguo: con punto de miles son
              1906 y 6000 mm, y como decimales serian 1,9 y 6 mm de un tubo, que no
              existen. Un largo en milimetros no se mide a la micra: no se carga.
            */
            if ($orden[$i] === 'length' && preg_match('/^\d{1,2}[.,]\d{3}$/', $escrito)) {
                continue;
            }

            $medidas[$orden[$i]] = (float) str_replace(',', '.', $escrito);
        }

        /*
          Un redondo es mas largo que grueso.

          "TUNGSTENO ALAMBRE 3 X 0,76 MM" leido como diametro y largo da un
          alambre de 0,76 mm de largo, que no existe: es un fleje de 3 mm de
          ancho por 0,76 de espesor escrito con el nombre de otra forma. Sin
          saber cual de las dos cosas es, no se carga ninguna.
        */
        if (isset($medidas['diameter'], $medidas['length'])
            && $medidas['length'] < $medidas['diameter']) {
            return [];
        }

        // "BARRA 40 X 40" es casi seguro la seccion de una barra cuadrada, no un diametro de 40 y un largo de 40.
        if (isset($medidas['diameter'], $medidas['length'])
            && in_array(mb_strtoupper(trim($forma)), self::REDONDOS, true)
            && $medidas['length'] === $medidas['diameter']) {
            return [];
        }

        // Una medida de cero no es una medida: es una cifra cortada o un dato mal escrito.
        foreach ($medidas as $valor) {
            if ($valor <= 0) {
                return [];
            }
        }

        /*
          Un alambre o una varilla son largos.

          "ALAMBRE RECTANGULAR 0.52 X 0.72MM" son los dos lados de la seccion, no
          un diametro y un largo: un alambre de 0,72 mm de largo no existe. Un
          pedazo con menos de diez veces su diametro de largo no es un alambre.
        */
        if (isset($medidas['diameter'], $medidas['length'])
            && in_array(mb_strtoupper(trim($forma)), self::FILIFORMES, true)
            && $medidas['length'] < $medidas['diameter'] * 10) {
            return [];
        }

        /*
          Un hueco no puede tener la pared mas gruesa que su radio.

          "CAÑO WLD 2" S10 X 400MM" leido a ciegas daba exterior 10 y pared 400:
          una pared de 40 centimetros en un caño de 1. Si los numeros no cierran
          como un tubo, es que no son un exterior y una pared, y no se carga nada.
        */
        if (isset($medidas['outer'], $medidas['wall'])
            && $medidas['outer'] <= $medidas['wall'] * 2) {
            return [];
        }

        /*
          Y su largo no es menor que su pared: un tubo mas corto que el grosor de
          lo que lo forma no existe. El largo es lo dudoso: el exterior y la pared
          se conservan.
        */
        if (isset($medidas['wall'], $medidas['length']) && $medidas['length'] < $medidas['wall']) {
            unset($medidas['length']);
        }

        return $medidas;
    }

    /** Si mas adelante hay otro par de numeros con aspecto de medida ("Ø 63.5 X 200", "63.5 X 200 MM"). */
    private function hayOtraSerie(string $resto): bool
    {
        return (bool) preg_match('/(?<![\pL\d])(?:Ø\s*)?\d{1,5}(?:[.,]\d{1,3})?\s*[xX]\s*\d{1,5}(?:[.,]\d{1,3})?(?:\s*MM)?/u', $resto);
    }

    /**
     * Donde nombra el texto a la forma: [donde empieza, cuantas letras tiene el nombre usado], o null si no la nombra.
     *
     * Casi todas se escriben como se llaman. La barra redonda no: dice "BARRA", "BAR RED"... y se la reconoce con
     * cuidado, porque "BARRA" a secas tambien es la cuadrada, la hexagonal o la rectangular, y esas no tienen diametro.
     *
     * @return array{0: int, 1: int}|null
     */
    private function ubicarLaForma(string $t, string $forma): ?array
    {
        if ($forma !== 'BARRA REDONDA') {
            $donde = mb_stripos($t, $forma);

            return $donde === false ? null : [$donde, mb_strlen($forma)];
        }

        if (! preg_match(self::BARRA_REDONDA, $t, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        // El desplazamiento viene en bytes; el resto del lector cuenta letras.
        $donde = mb_strlen(substr($t, 0, $m[0][1]));
        $largo = mb_strlen($m[0][0]);

        if (preg_match(self::NO_ES_REDONDA, mb_substr($t, $donde + $largo, 30))) {
            return null;
        }

        return [$donde, $largo];
    }

    /**
     * La primera serie de numeros separados por X que parezca una medida.
     *
     * Se le exige el "MM" al final —o el simbolo de diametro adelante— porque
     * en el texto hay muchos otros numeros: la cantidad, el precio, el plazo,
     * el numero de cotizacion. Sin esa exigencia, "COTIZA 20552299 ... 2 U
     * RECTANGULO 50 X 3000" daba medidas de cualquier cosa.
     *
     * Las pulgadas quedan afuera: "PLANCHUELA 1\"1/4 X 3/8\"" necesita
     * convertir fracciones y no vale el riesgo por las pocas que son.
     *
     * @return list<string> las cifras tal como estan escritas ("6,35", "1.906")
     */
    private function numeros(string $descripcion, string $forma): array
    {
        $t = str_replace(['Ø', 'ø', 'Ý', 'ý'], ' Ø ', $descripcion);

        /*
          Se mira solo de la forma en adelante.

          Antes se tomaba la primera serie de numeros de toda la descripcion, y
          en un texto como "TRAJERON EQUIPO INCOLOY 800HT PARA HIDROGENO..."
          salian medidas de la prosa. Lo que describe al articulo viene despues
          de nombrar la forma.
        */
        if (preg_match(self::ARMADO, $descripcion)) {
            return [];
        }

        $sitio = $this->ubicarLaForma($t, $forma);

        if ($sitio === null) {
            return [];
        }

        [$donde, $largoDelNombre] = $sitio;

        /*
          Y solo lo que viene pegado a la forma.

          "MONEL K500 - CONT: (LAS BARRAS MACIZAS SE PODRIAN ENTREGAR EN DOS
          TRAMOS DE 170 A 300 MM)" nombra la forma en medio de una frase y los
          numeros que siguen no son su medida. La medida de un articulo se
          escribe al lado de la forma, no treinta palabras despues.
        */
        // "PLACA TUBO Ø1250 X 110" es un tubo-placa, un disco: no es un tubo de pared 110.
        if (preg_match('/\bPLACAS?\s+$/iu', mb_substr($t, 0, $donde))) {
            return [];
        }

        $t = mb_substr($t, $donde + $largoDelNombre);
        $ventana = mb_substr($t, 0, self::CERCA);

        // Un rango —"5/7 X 45/50 MM"— no es una medida: son dos opciones.
        if (preg_match('#\d\s*/\s*\d#', $ventana)) {
            return [];
        }

        // Hasta donde puede EMPEZAR la medida. Una vez que empieza se lee entera:
        // cortar el texto a los 26 caracteres partia el ultimo numero ("Ø114,3 X
        // 6,07" quedaba en 6) y cargaba una pared de 6 mm donde eran 6,07.
        $limite = strlen($ventana);

        // El numero no puede ir pegado a una letra: "S10" y "SCH40" son la serie de un
        // caño, no una medida en milimetros.
        // Tampoco la cola de un decimal: en "ØE25.4" el ".4" no es un numero aparte. "ØE" y
        // "ØEXT" son el diametro exterior y cuentan como la marca.
        $patron = '/(?<![\pL\d])(?<![\d][.,])(?<marca>Ø\s*(?:EXT\.?|E)?\s*)?'
            .'(?<n>\d{1,5}(?:[.,]\d{1,3})?)'
            .'(?:\s*[xX]\s*\d{1,5}(?:[.,]\d{1,3})?){0,2}'
            .'(?<cola>\s*(?:MM|mm|Mm)?)/u';

        if (! preg_match_all($patron, $t, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        // Un numero suelto con su "MM" y sin marca de diametro antes de la serie: no se sabe
        // si es una medida del articulo o de otra cosa ("EN LUGAR DE", "DIAM. 6 MM OFRECIDO").
        $suelto = false;

        foreach ($m as $hit) {
            if ($hit[0][1] >= $limite) {
                break;
            }

            $texto = $hit[0][0];

            // Pulgadas: no se convierten.
            if (str_contains($texto, '"') || str_contains($texto, "''")) {
                continue;
            }

            /*
              Si antes de estos numeros hay una medida en pulgadas o un schedule,
              los numeros de despues ya no son el exterior y la pared en
              milimetros: `CAÑO 2" SCH 40 X 6000 MM` es 2 pulgadas, serie 40 y
              6000 de largo. Se lee como exterior 40 y pared 6000 si no se corta.
              Sin saber que es cada cosa no se carga ninguna.
            */
            $antes = substr($t, 0, $hit[0][1]);

            if (str_contains($antes, '"') || str_contains($antes, "''")
                || preg_match('/\b(?:SCH|SCHEDULE)\b/i', $antes)) {
                return [];
            }

            /*
              Si lo que sigue es otra unidad de largo, la ultima cifra no son
              milimetros: "ALAMBRE Ø 0.5 X 3 MET" son 3 metros, no 3 mm.
            */
            $despues = substr($t, $hit[0][1] + strlen($texto), 12);

            if (preg_match('/^\s*(?:M|MT|MTS|MTO|MTOS|MET|METRO|METROS|CM|KG|KGS|GR)\b/i', $despues)) {
                return [];
            }

            // "4.5/7 M" es un rango de largos: son dos opciones, no una medida.
            if (preg_match('#^\s*/\s*\d#', $despues)) {
                return [];
            }

            $tieneMarca = trim((string) ($hit['marca'][0] ?? '')) !== '';
            $tieneMm = trim((string) ($hit['cola'][0] ?? '')) !== '';
            $serie = $tieneMarca ? substr($texto, strlen((string) $hit['marca'][0])) : $texto;
            $numeros = preg_split('/\s*[xX]\s*/', trim(preg_replace('/\s*(MM|mm|Mm)\s*/u', '', $serie)));
            $numeros = array_values(array_filter($numeros, fn ($n) => $n !== ''));

            // Una medida es una serie de dos o tres numeros, o uno solo si
            // viene marcado como diametro o con su unidad.
            $sirve = count($numeros) >= 2
                ? ($tieneMm || $tieneMarca)
                : ($tieneMarca && $tieneMm);

            if (! $sirve) {
                // Un numero solo con una de las dos marcas (el "Ø" o el "MM") y la otra no: no se sabe si es la medida.
                if (count($numeros) === 1 && ($tieneMm || $tieneMarca)) {
                    $suelto = true;
                }

                continue;
            }

            if ($suelto) {
                return [];
            }

            /*
              Lo pedido y lo ofrecido en la misma linea: no se adivina cual es.

              "PIDEN 17-4PH BARRA 55.56 X 1800 SE OFRECE 17-4PH BARRA 63.5 X 17..." cotiza lo ofrecido, y el
              primer par de numeros es lo pedido. Con una palabra de oferta o de pedido y otra medida mas
              adelante, no se carga ninguna.
            */
            $resto = substr($t, $hit[0][1] + strlen($texto));

            if (preg_match(self::OFERTA, $descripcion) && $this->hayOtraSerie($resto)) {
                return [];
            }

            return array_slice($numeros, 0, 3);
        }

        return [];
    }
}

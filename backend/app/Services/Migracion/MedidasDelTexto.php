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
 * Medido sobre muestras al azar de las lineas reales: 29 de cada 30 correctas.
 * Lo que no se puede leer con esa seguridad se deja vacio — la descripcion
 * queda entera en la linea y se completa a mano.
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

    /** Cuantos caracteres despues de la forma se busca la medida. */
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

        $medidas = [];

        foreach ($numeros as $i => $valor) {
            if (! isset($orden[$i])) {
                break;
            }

            $medidas[$orden[$i]] = $valor;
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

        return $medidas;
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
     * @return list<float>
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
        $donde = mb_stripos($t, $forma);

        if ($donde === false) {
            return [];
        }

        /*
          Y solo lo que viene pegado a la forma.

          "MONEL K500 - CONT: (LAS BARRAS MACIZAS SE PODRIAN ENTREGAR EN DOS
          TRAMOS DE 170 A 300 MM)" nombra la forma en medio de una frase y los
          numeros que siguen no son su medida. La medida de un articulo se
          escribe al lado de la forma, no treinta palabras despues.
        */
        $t = mb_substr($t, $donde + mb_strlen($forma), self::CERCA);

        // Un rango —"5/7 X 45/50 MM"— no es una medida: son dos opciones.
        if (preg_match('#\d\s*/\s*\d#', $t)) {
            return [];
        }

        $patron = '/(?<marca>Ø\s*)?'
            .'(?<n>\d{1,5}(?:[.,]\d{1,3})?)'
            .'(?:\s*[xX]\s*\d{1,5}(?:[.,]\d{1,3})?){0,2}'
            .'(?<cola>\s*(?:MM|mm|Mm)?)/u';

        if (! preg_match_all($patron, $t, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($m as $hit) {
            $texto = $hit[0][0];

            // Pulgadas: no se convierten.
            if (str_contains($texto, '"') || str_contains($texto, "''")) {
                continue;
            }

            $tieneMarca = trim((string) ($hit['marca'][0] ?? '')) !== '';
            $tieneMm = trim((string) ($hit['cola'][0] ?? '')) !== '';
            $numeros = preg_split('/\s*[xX]\s*/', trim(preg_replace('/\s*(MM|mm|Mm|Ø)\s*/u', '', $texto)));
            $numeros = array_values(array_filter($numeros, fn ($n) => $n !== ''));

            // Una medida es una serie de dos o tres numeros, o uno solo si
            // viene marcado como diametro o con su unidad.
            $sirve = count($numeros) >= 2
                ? ($tieneMm || $tieneMarca)
                : ($tieneMarca && $tieneMm);

            if (! $sirve) {
                continue;
            }

            return array_map(
                fn (string $n) => (float) str_replace(',', '.', $n),
                array_slice($numeros, 0, 3),
            );
        }

        return [];
    }
}

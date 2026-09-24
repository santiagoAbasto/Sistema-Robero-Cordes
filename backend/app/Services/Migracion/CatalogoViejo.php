<?php

namespace App\Services\Migracion;

/**
 * Lo que hay que arreglar de los datos del sistema viejo.
 *
 * Son reglas de limpieza, no de negocio: acá no se decide nada comercial, solo
 * se traduce lo que el sistema anterior escribía de otra manera.
 */
class CatalogoViejo
{
    /**
     * Las trece formas que se pueden calcular sin dudar.
     *
     * Solo entran las que significan EXACTAMENTE lo mismo que una forma
     * nuestra. Las demás —"Barra Canulada", que es hueca, o "Tuerca Hexag.
     * Bridada", que no es una brida— entran sin fórmula: un nombre parecido no
     * alcanza para asignarles una cuenta, y un peso mal calculado no se nota
     * mirando la pantalla.
     *
     * @var array<string, string> nombre en el sistema viejo => forma nuestra
     */
    public const FORMULAS_SEGURAS = [
        'Barra Red. / Varilla' => 'BARRA REDONDA',
        'Alambre' => 'BARRA REDONDA',
        'Barra Cuadrada' => 'BARRA CUADRADA',
        'Barra hexagonal' => 'BARRA HEXAGONAL',
        'Barra Rectangular' => 'BARRA RECTANGULAR',
        'Chapa' => 'CHAPA',
        'Planchuela' => 'PLANCHUELA',
        'Disco' => 'DISCO',
        'Esferas' => 'ESFERA',
        'Tubo' => 'TUBO',
        'Caño' => 'CAÑO',
        'Arandela' => 'ARANDELA',
        'Anillos / Aros' => 'ANILLO',
    ];

    /**
     * Cómo se relacionaba cada empresa, en una letra.
     *
     * @var array<string, string>
     */
    public const RELACIONES = [
        'C' => 'Cliente',
        'P' => 'Proveedor',
        'S' => 'Servicio',
        'F' => 'Proveedor',
        'V' => 'Cliente',
    ];

    /**
     * El diámetro escrito como lo escribía el sistema viejo.
     *
     * En esa base el símbolo Ø quedó guardado como Ý: aparece 3581 veces y
     * siempre delante de una medida ("BAR Ý 1.6 X 915MM") o de las siglas de
     * exterior, interior y nominal. No es un problema de codificación —los
     * acentos vienen bien— sino una sustitución del sistema anterior.
     */
    public static function texto(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $limpio = trim(str_replace('Ý', 'Ø', $valor));

        return $limpio !== '' ? $limpio : null;
    }

    /**
     * El telefono, si es un telefono.
     *
     * En el Access TELEF1..5 eran Text(12) con mascara "NN-NNNN-NNNN", asi que
     * la ficha sin llenar quedo guardada igual: "-    -" aparece 3667 veces
     * sobre 4830 valores. No es un numero viejo ni un dato a rescatar, es el
     * formulario vacio. Alcanza con pedir un digito que no sea cero.
     */
    public static function telefono(?string $valor): ?string
    {
        $limpio = self::texto($valor);

        if ($limpio === null) {
            return null;
        }

        /*
          El formulario del Access tenia el numero partido en casillas
          —caracteristica, prefijo, numero— y las que quedaban sin llenar
          dejaban igual su guion: "-    -2520" es el 2520 y "4201-  -5000" es
          el 4201-5000. Se junta la separacion sobrante y se sacan los guiones
          de los extremos. Un digito no se toca nunca. Son 991.
        */
        $limpio = preg_replace('/\s*-\s*/', '-', $limpio);
        $limpio = trim(preg_replace('/-{2,}/', '-', $limpio), '- ');

        return preg_match('/[1-9]/', $limpio) ? $limpio : null;
    }

    /**
     * El mismo material, escrito de dos maneras.
     *
     * El Access dice "Acero AISI 304" y nuestro catalogo decia "AISI 304". Por
     * nombre no se reconocen y por codigo tampoco, asi que entraban como dos
     * materiales: uno con la densidad que confirmo la empresa y otro —el que
     * la gente elige, porque es como lo llaman ellos— sin densidad o con la
     * del inventario. Cotizar con el equivocado cambia el peso facturado.
     *
     * Solo estan los seis que son el mismo material sin lugar a duda. "Acero
     * AISI 316L" y "AISI 316" NO estan: parecen lo mismo y no lo son.
     *
     * @var array<string, string> como lo escribe el Access => como lo teniamos
     */
    public const MISMO_MATERIAL = [
        'Acero AISI 304' => 'AISI 304',
        'Acero AISI 316' => 'AISI 316',
        'Acero AISI 316TI' => 'AISI 316TI',
        'Acero Duplex 2205' => 'DUPLEX 2205',
        'Acero 25-22-2 (2RE69)' => '25-22-2',
        'Titanio Grado 5' => 'TITANIO GR5',
    ];

    /** CORDES existe desde 1938: nada anterior puede ser una cotizacion suya. */
    public const PRIMER_ANIO = 1938;

    /**
     * Si la fecha cae fuera de la vida de la empresa, es un error de carga.
     *
     * Para los dos lados. Antes solo se miraba hacia atras y pasaban 17 filas
     * con el año tipeado de mas —una figura en 2424— que entraban como
     * cotizaciones del futuro y se ordenaban arriba de todo en la ficha del
     * cliente. No se corrigen a ciegas: se marcan.
     */
    public static function fechaDudosa(?string $valor): bool
    {
        $fecha = self::fecha($valor);

        if ($fecha === null) {
            return false;
        }

        return $fecha < self::PRIMER_ANIO.'-01-01' || $fecha > date('Y-m-d');
    }

    /**
     * Las fechas vienen MM/DD/AA y hay que decidir el siglo.
     *
     * Con dos dígitos "12/04/00" puede ser 2000 o 1900. El corte va en el año
     * en curso: nada de este archivo puede ser del futuro, así que un año
     * mayor al actual es del siglo pasado.
     */
    public static function fecha(?string $valor): ?string
    {
        if (blank($valor)) {
            return null;
        }

        // Exportado bien, el Access entrega la fecha completa y no hay nada
        // que adivinar. El rango real del archivo es 1989-2026: las "11
        // cotizaciones anteriores a 1938" que se reportaban no existian, las
        // fabricaba el adivinador de siglo sobre un export al que le faltaba.
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', trim($valor), $iso)) {
            return checkdate((int) $iso[2], (int) $iso[3], (int) $iso[1])
                ? "{$iso[1]}-{$iso[2]}-{$iso[3]}"
                : null;
        }

        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})#', trim($valor), $p)) {
            return null;
        }

        [, $mes, $dia, $anio] = $p;
        $anio = (int) $anio;

        if ($anio < 100) {
            $tope = (int) date('y');
            $anio += $anio <= $tope ? 2000 : 1900;
        }

        if (! checkdate((int) $mes, (int) $dia, $anio)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }

    /**
     * Los cinco renglones de una cotizacion vieja, agrupados en items.
     *
     * PE1..PE5 no son cinco articulos: son las cinco lineas de una pantalla de
     * ancho fijo (varchar(66) las cinco) con una casilla de precio al lado de
     * cada una. La descripcion de un articulo se derrama por varios renglones
     * y el precio se escribe en el renglon donde TERMINA:
     *
     *   PE1  NITINOL #5 ALAMBRE 2MM, EN ROLLO, RECOCIDO RECTO, COLOR   PR1  0
     *   PE2  OXIDO, TEMP AF 85C MINIMO, UTS 1069 MPA, ELONG 10% MIN    PR2  0
     *   PE3  PEDIDO MINIMO 130 METROS, X M                             PR3  41.7
     *   PE4  110 DIAS                                                  PR4  0
     *
     * Eso es UN articulo de 41,70 y un plazo de entrega, no cuatro productos.
     *
     * Medido sobre las 8048 filas reales: de las 720 que tienen un solo
     * renglon, 553 llevan el precio en esa misma posicion y una sola en otra;
     * el 82% de los renglones con precio termina en marca de unidad o "(+IVA)"
     * contra el 3% de los que no lo tienen; y el 98% de los renglones sin
     * precio ni siquiera arranca con una cantidad.
     *
     * Lo que queda despues del ultimo precio —plazos, notas— sale por
     * "sueltos": no es un articulo pero tampoco se tira.
     *
     * @return array{items: list<array{descripcion: string, precio: float, cantidad: float|null, unidad: string|null}>, sueltos: list<string>}
     */
    public static function renglones(array $c): array
    {
        $items = [];
        $buffer = [];
        $sueltos = [];

        for ($i = 1; $i <= 5; $i++) {
            $texto = self::texto($c["PE{$i}"] ?? null);

            // El primer renglon suele ser la fecha tipeada de nuevo: en 1231 de
            // 1259 casos es identica a la columna FECHA. Es un encabezado.
            if ($i === 1 && $texto !== null && self::esSoloFecha($texto)) {
                continue;
            }

            if ($texto === null) {
                continue;
            }

            $buffer[] = $texto;
            $precio = (float) ($c["PR{$i}"] ?? 0);

            if ($precio <= 0) {
                continue;
            }

            $descripcion = implode(' ', $buffer);
            $buffer = [];

            [$cantidad, $unidad] = self::cantidadDe($descripcion);

            $items[] = compact('descripcion', 'precio', 'cantidad', 'unidad');
        }

        return ['items' => $items, 'sueltos' => $buffer ?: $sueltos];
    }

    /** Un renglon que es solo una fecha: "24-04-2026", "8-1-16". */
    private static function esSoloFecha(string $texto): bool
    {
        return (bool) preg_match('#^\d{1,2}[-/.]\d{1,2}[-/.]\d{2,4}$#', trim($texto));
    }

    /**
     * La cantidad que el vendedor escribio al principio del renglon.
     *
     * "10 UN TIT GR1 BARRA 1.60 X 915 MM" son diez unidades. Aparece en 5060
     * de los 13072 renglones con precio. En los otros 8012 la cantidad no esta
     * en ningun lado del Access: queda en null. Poner 1 seria inventar el
     * numero por el que se multiplica el precio.
     *
     * @return array{0: float|null, 1: string|null}
     */
    public static function cantidadDe(string $texto): array
    {
        if (! preg_match('#^\s*(\d+(?:[.,]\d+)?)\s*(UN|C/U|KG|TN|MT|M2|M|PZ|PIEZAS?|METROS?|KILOS?)\b#iu', $texto, $p)) {
            return [null, null];
        }

        $cantidad = (float) str_replace(',', '.', $p[1]);

        $unidad = match (mb_strtoupper($p[2])) {
            'M', 'MT', 'METRO', 'METROS' => 'MT',
            'KG', 'KILO', 'KILOS' => 'KG',
            'C/U' => 'C/U',
            'TN' => 'TN',
            'UN', 'PZ', 'PIEZA', 'PIEZAS' => 'UN',
            default => null,
        };

        return [$cantidad > 0 ? $cantidad : null, $unidad];
    }

    /** "METALES: Aceros Inoxidables" queda en "Aceros Inoxidables". */
    public static function familia(?string $cruda): string
    {
        $limpia = trim(preg_replace('/^[^:]+:\s*/u', '', (string) $cruda));

        return $limpia !== '' ? $limpia : 'Sin familia';
    }

    /**
     * La moneda, como la nombra el sistema nuevo.
     *
     * Los australes son de antes de 1992: se conservan como estaban, sin
     * convertir. Convertir treinta años de inflación a pesos de hoy sería
     * inventar un número que nadie pidió.
     *
     * @return array{0: string, 1: bool} nombre y si se pudo reconocer
     */
    public static function moneda(?string $cruda): array
    {
        return match (mb_strtoupper(trim((string) $cruda))) {
            'DOLAR BILLETE', 'DOLARES', 'DOLAR' => ['DOLAR BILLETE BNA VENDEDOR', true],
            'PESOS', 'PESOS BILLETE' => ['PESOS', true],
            'EUROS', 'EURO' => ['EURO BILLETE BNA VENDEDOR', true],
            default => ['PESOS', false],
        };
    }
}

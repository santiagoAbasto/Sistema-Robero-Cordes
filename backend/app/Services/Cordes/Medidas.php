<?php

namespace App\Services\Cordes;

use App\Models\Forma;
use App\Services\Buscador\Texto;

/**
 * Lee las medidas que una persona escribe con sus palabras.
 *
 *   «38,1 x 145 mm» · «38,1x145» · «Ø20 de diámetro y 1 metro de largo» · «espesor 3 ancho 1000 largo 2000»
 *   «1 1/2 pulgadas x 6 m» · «largos aleatorios de 3 a 6 metros» · «5 barras» · «x 3 piezas»
 *   «caño de 2 pulgadas schedule 40» · «DN50 sch 80 de 6 metros»
 *
 * Es un lector de reglas, no de IA: siempre lee igual, no inventa y dice qué le quedó sin entender. Los números sueltos
 * se asignan a las medidas de la forma EN EL ORDEN EN QUE SE ESCRIBEN (una chapa se escribe «espesor x ancho x largo»:
 * leerla en el orden del formulario cargaría el 2 como ancho), el que dice el propio catálogo (Forma::ordenEnQueSeEscriben).
 */
final class Medidas
{
    public const NUM = '(?:\d+\s+\d+\/\d+|\d+\/\d+|\d+(?:[.,]\d+)?)';

    public const UNI = '(?:milimetros?|mm|centimetros?|cm|metros?|mts?|m|pulgadas?|pulg|in|pies|pie|ft)(?![a-z])';

    /** Diámetro nominal (DN, en mm) → pulgadas, como figura en la tabla de caños. */
    private const DN = [
        8 => '1/4', 10 => '3/8', 15 => '1/2', 20 => '3/4', 25 => '1', 32 => '1 1/4', 40 => '1 1/2', 50 => '2', 65 => '2 1/2', 80 => '3',
        90 => '3 1/2', 100 => '4', 125 => '5', 150 => '6', 200 => '8', 250 => '10', 300 => '12', 350 => '14', 400 => '16', 450 => '18',
        500 => '20', 550 => '22', 600 => '24', 650 => '26', 700 => '28', 750 => '30', 800 => '32', 850 => '34', 900 => '36',
    ];

    /** Cómo se llama cada medida cuando se la nombra, y qué claves de forma puede ser (la primera que la forma tenga). */
    private const NOMBRES = [
        'diametro exterior|diam exterior|diametro ext|diam ext|exterior|od' => ['outer', 'diameter'],
        'diametro interior|diam interior|diametro int|diam int|interior' => ['inner'],
        'diametro|diam|dia' => ['diameter', 'outer'],
        'espesor|pared|grosor|esp' => ['wall', 'height'],
        'ancho' => ['width'],
        'largo|longitud|long' => ['length'],
        'lado' => ['side'],
        'distancia entre caras|entre caras|caras' => ['across'],
        'alto|altura' => ['height'],
    ];

    private const COSAS = 'piezas?|unidades?|barras?|chapas?|tubos?|canos?|discos?|bridas?|aros?|anillos?|perfiles?|alambres?|esferas?|arandelas?|planchuelas?|varillas?|rollos?|bobinas?';

    private const PALABRAS = ['un' => 1, 'una' => 1, 'uno' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10, 'doce' => 12];

    /* ------------------------------------------------------------- limpiar */

    /** El texto, listo para leer: «Ø20» y «2"» dicen lo que dicen, «38,1x145» se separa, y «1.500» son mil quinientos. */
    public static function limpiar(string $texto): string
    {
        $t = str_replace(['Ø', 'ø', '⌀', 'ϕ', 'Φ', 'φ'], ' diametro ', $texto);
        $t = preg_replace('/(\d)\s*(?:"|”|″|\'\')/u', '$1 pulgadas ', $t) ?? $t;
        $t = str_replace(['×', '✕', '✖'], ' x ', $t);

        for ($i = 0; $i < 3; $i++) {
            $t = preg_replace('/(?<=\d)\s*[xX*]\s*(?=\d)/u', ' x ', $t) ?? $t;
        }

        // «1.500 mm»: un punto con tres cifras detrás y sin cero adelante son miles, no decimales.
        $t = preg_replace('/(?<![\d.,])([1-9]\d{0,2})\.(\d{3})(?![\d.,])/', '$1$2', $t) ?? $t;

        return self::enLetraYFraccion(Texto::normalizar($t));
    }

    /**
     * Cómo se dicen las medidas de palabra: «3/4 de pulgada», «media pulgada», «pulgada y media», «tres cuartos de pulgada»,
     * y la calidad abreviada «titanio gr 2». Se leen ya normalizadas (sin tildes ni mayúsculas).
     */
    private static function enLetraYFraccion(string $n): string
    {
        // «3/4 de pulgada», «2 de mm»: el «de» entre el número y la unidad sobra.
        $n = preg_replace('/(\d)\s+de\s+(pulgadas?|pulg|mm|milimetros?|cm|centimetros?|metros?|mts?)(?![a-z])/', '$1 $2', $n) ?? $n;

        $cuartos = ['un' => 1, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3];
        $octavos = ['un' => 1, 'uno' => 1, 'tres' => 3, 'cinco' => 5, 'siete' => 7];
        $n = preg_replace_callback('/\b(un|uno|una|dos|tres)\s+cuartos?\s+de\s+pulgadas?/', fn ($m) => $cuartos[$m[1]].'/4 pulgadas', $n) ?? $n;
        $n = preg_replace_callback('/\b(un|uno|tres|cinco|siete)\s+octavos?\s+de\s+pulgadas?/', fn ($m) => $octavos[$m[1]].'/8 pulgadas', $n) ?? $n;
        $n = preg_replace('/\bmedia\s+pulgadas?/', '1/2 pulgadas', $n) ?? $n;
        $n = preg_replace('/\bpulgadas?\s+y\s+media\b/', '1 1/2 pulgadas', $n) ?? $n;
        $n = preg_replace('/\bpulgadas?\s+y\s+cuarto\b/', '1 1/4 pulgadas', $n) ?? $n;
        $n = preg_replace('/\b(\d{1,2})\s+pulgadas?\s+y\s+media\b/', '$1 1/2 pulgadas', $n) ?? $n;
        $n = preg_replace('/\b(\d{1,2})\s+pulgadas?\s+y\s+cuarto\b/', '$1 1/4 pulgadas', $n) ?? $n;
        $n = preg_replace('/\b(\d{1,2})\s+pulgadas?\s+y\s+tres\s+cuartos\b/', '$1 3/4 pulgadas', $n) ?? $n;
        $n = preg_replace('/\buna\s+pulgadas?\b/', '1 pulgada', $n) ?? $n;

        $n = self::numerosEnPalabras($n);

        // «titanio gr 2», «gr5»: la calidad («grado»), salvo que sea «500 gr» (gramos).
        return preg_replace('/(?<![\d.,])(?<!\d\s)\b(?:gr|gdo)\s*(?=\d)/', 'grado ', $n) ?? $n;
    }

    private const UNIDADES_EN_PALABRAS = [
        'cero' => 0, 'un' => 1, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9,
        'diez' => 10, 'once' => 11, 'doce' => 12, 'trece' => 13, 'catorce' => 14, 'quince' => 15, 'dieciseis' => 16, 'diecisiete' => 17, 'dieciocho' => 18, 'diecinueve' => 19,
        'veinte' => 20, 'veintiuno' => 21, 'veintiun' => 21, 'veintiuna' => 21, 'veintidos' => 22, 'veintitres' => 23, 'veinticuatro' => 24, 'veinticinco' => 25,
        'veintiseis' => 26, 'veintisiete' => 27, 'veintiocho' => 28, 'veintinueve' => 29, 'treinta' => 30, 'cuarenta' => 40, 'cincuenta' => 50, 'sesenta' => 60,
        'setenta' => 70, 'ochenta' => 80, 'noventa' => 90, 'cien' => 100, 'ciento' => 100,
    ];

    /**
     * «veinte milímetros», «un metro», «treinta y cinco mm», «medio metro»: el número dicho con palabras, pero sólo cuando va pegado a una unidad
     * (así «una barra» o «dos chapas» no se tocan). Se leen ya normalizadas.
     */
    private static function numerosEnPalabras(string $n): string
    {
        $uni = '(?:milimetros?|mm|centimetros?|cm|metros?|mts?|pulgadas?|pulg|pies|pie)(?![a-z])';
        $palabra = '(?:'.implode('|', array_map('preg_quote', array_keys(self::UNIDADES_EN_PALABRAS))).')';
        $n = preg_replace('/\bmedio\s+(metro|milimetro|centimetro|pie)\b/', '0,5 $1', $n) ?? $n;

        // «treinta y cinco mm»: decena + y + unidad
        $n = preg_replace_callback('/\b(treinta|cuarenta|cincuenta|sesenta|setenta|ochenta|noventa)\s+y\s+(uno|un|dos|tres|cuatro|cinco|seis|siete|ocho|nueve)\s+(?='.$uni.')/',
            fn ($m) => (self::UNIDADES_EN_PALABRAS[$m[1]] + self::UNIDADES_EN_PALABRAS[$m[2]]).' ', $n) ?? $n;

        return preg_replace_callback('/(?<![a-z\d])('.$palabra.')\s+(?='.$uni.')/', fn ($m) => self::UNIDADES_EN_PALABRAS[$m[1]].' ', $n) ?? $n;
    }

    /* -------------------------------------------------------------- números */

    public static function numero(string $s): ?float
    {
        $s = trim(str_replace(',', '.', $s));

        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $s, $m) && (int) $m[3] > 0) {
            return (int) $m[1] + (int) $m[2] / (int) $m[3];
        }

        if (preg_match('/^(\d+)\/(\d+)$/', $s, $m) && (int) $m[2] > 0) {
            return (int) $m[1] / (int) $m[2];
        }

        return is_numeric($s) ? (float) $s : null;
    }

    /** «metros» → «m», «pulgadas» → «in»: los códigos que entiende el cálculo de peso. */
    public static function unidad(?string $u): ?string
    {
        if ($u === null || $u === '') {
            return null;
        }

        return match (true) {
            str_starts_with($u, 'mil') || $u === 'mm' => 'mm',
            str_starts_with($u, 'cen') || $u === 'cm' => 'cm',
            str_starts_with($u, 'met') || str_starts_with($u, 'mt') || $u === 'm' => 'm',
            str_starts_with($u, 'pulg') || $u === 'in' => 'in',
            str_starts_with($u, 'pie') || $u === 'ft' => 'ft',
            default => null,
        };
    }

    /** @return array<string, float> */
    private static function aMm(): array
    {
        return ['mm' => 1.0, 'cm' => 10.0, 'm' => 1000.0, 'in' => 25.4, 'ft' => 304.8];
    }

    /* ------------------------------------------------------------------ caño */

    /**
     * El caño de la tabla: diámetro nominal, schedule y largo en metros. Saca lo que lee del texto.
     *
     * @return array{nominal: ?string, schedule: ?string, metros: ?float, texto: string}
     */
    public static function cano(string $w): array
    {
        $nominal = null;
        $schedule = null;
        $metros = null;

        if (preg_match('/(?<![a-z])dn\s*(\d{1,3})(?![\d])/', $w, $m) && isset(self::DN[(int) $m[1]])) {
            $nominal = self::DN[(int) $m[1]].'"';
            $w = str_replace($m[0], ' ', $w);
        } elseif (preg_match('/('.self::NUM.')\s*(?:pulgadas?|pulg|in)(?![a-z])/', $w, $m)) {
            $v = self::numero($m[1]);

            if ($v !== null) {
                $nominal = self::nombreDeNominal($v);
                $w = str_replace($m[0], ' ', $w);
            }
        }

        // «caño 1 1/2 sch 80», «2 sch 40»: el nominal es el número que va pegado al schedule, aunque no diga pulgadas.
        if ($nominal === null && (preg_match('/('.self::NUM.')\s+(?=(?:sch|schedule|cedulas?|std|xxs|xs)(?![a-z]))/', $w, $m)
            || preg_match('/(?:canos?|tuberias?)\s+(?:de\s+)?('.self::NUM.')(?![\d.,\/]|\s*(?:mm|cm|m\b|metros?|x\b))/', $w, $m))) {
            $v = self::numero($m[1]);

            if ($v !== null && $v > 0 && $v <= 48) {
                $nominal = self::nombreDeNominal($v);
                $w = preg_replace('/'.preg_quote($m[1], '/').'/', ' ', $w, 1) ?? $w;
            }
        }

        if (preg_match('/(?:sch|schedule|cedula|cedulas)\.?\s*(\d{1,3}s?|std|xxs|xs)(?![a-z0-9])/', $w, $m)
            || preg_match('/(?<![a-z0-9])(std|xxs|xs)(?![a-z0-9])/', $w, $m)) {
            $schedule = strtoupper($m[1]);
            $w = str_replace($m[0], ' ', $w);
        }

        if (preg_match('/('.self::NUM.')\s*(?:metros?|mts?|m)(?![a-z])/', $w, $m) && ($v = self::numero($m[1])) !== null) {
            $metros = $v;
            $w = str_replace($m[0], ' ', $w);
        } elseif (preg_match('/('.self::NUM.')\s*(?:milimetros?|mm)(?![a-z])/', $w, $m) && ($v = self::numero($m[1])) !== null && $v >= 500) {
            $metros = $v / 1000;
            $w = str_replace($m[0], ' ', $w);
        }

        return ['nominal' => $nominal, 'schedule' => $schedule, 'metros' => $metros, 'texto' => $w];
    }

    /** 2 → «2"»; 2,5 → «2 1/2"»; 0,5 → «1/2"»: como se llama en la tabla. */
    public static function nombreDeNominal(float $v): string
    {
        $entero = (int) floor($v + 1e-9);
        $resto = $v - $entero;
        $fracciones = ['0.125' => '1/8', '0.25' => '1/4', '0.375' => '3/8', '0.5' => '1/2', '0.75' => '3/4'];
        $f = null;

        foreach ($fracciones as $valor => $texto) {
            if (abs($resto - (float) $valor) < 0.02) {
                $f = $texto;
            }
        }

        return trim(($entero > 0 ? (string) $entero : '').($f ? ($entero > 0 ? ' ' : '').$f : '')).'"';
    }

    /* ---------------------------------------------------------------- medidas */

    /**
     * Las medidas de una forma, las piezas y el largo variable. Lo que lee lo saca del texto.
     *
     * @return array{medidas: array<string, array{valor: float, unidad: string}>, piezas: ?float, largo_variable: ?array{min: float, max: float, unidad: string, promedio: float},
     *               sobran: int, asumidas: array<string, string>, texto: string}
     */
    public static function leer(string $w, ?Forma $forma): array
    {
        $campos = $forma ? array_values(array_filter($forma->camposDelCalculo(), fn ($c) => $c['tipo'] === 'numero')) : [];
        $claves = array_column($campos, 'clave');
        $orden = $forma ? array_values(array_intersect($forma->ordenEnQueSeEscriben(), $claves)) : [];
        $medidas = [];
        $piezas = null;
        $variable = null;
        $sobran = 0;
        $asumidas = [];
        $num = self::NUM;
        $uni = self::UNI;

        // Piezas: «x 3 piezas», «tres unidades» (palabra genérica, puede ir con x) o «5 barras» (sólo si el número no es parte de «38,1 x 145 barras»).
        $palabras = implode('|', array_keys(self::PALABRAS));
        $w = preg_replace_callback('/(?<![a-z\d.,])(?:x|por)\s*(\d+|'.$palabras.')\s+(?:piezas?|unidades?|un|u)(?![a-z])/', function ($m) use (&$piezas) {
            $piezas ??= is_numeric($m[1]) ? (float) $m[1] : (float) self::PALABRAS[$m[1]];

            return ' ';
        }, $w, 1) ?? $w;
        $w = preg_replace_callback('/(?<!x\s)(?<!por\s)(?<![a-z\d.,])(\d+|'.$palabras.')\s+(?:'.self::COSAS.')(?![a-z])(?:\s+de\s+(?=[a-z]))?/', function ($m) use (&$piezas) {
            $piezas ??= is_numeric($m[1]) ? (float) $m[1] : (float) self::PALABRAS[$m[1]];

            return ' ';
        }, $w, 1) ?? $w;

        // Largo variable: «largos aleatorios de 3 a 6 metros», «3200-3500 mm de largo», «largo entre 3 y 6 m».
        $rango = "(?<a>{$num})\\s*(?<ua>{$uni})?\\s*(?:a|y|-|hasta)\\s*(?<b>{$num})\\s*(?<ub>{$uni})?";
        $cue = '(?:largos?|longitud(?:es)?)';
        $variables = '(?:variables?|aleatori[oa]s?|libres?|a eleccion)';
        foreach ([
            "/{$cue}\\s*{$variables}?\\s*(?:de|entre|:)?\\s*{$rango}/",
            "/{$rango}\\s*(?:de\\s+)?{$cue}/",
            "/{$variables}\\s*(?:de|entre|:)?\\s*{$rango}/",
        ] as $re) {
            if (preg_match($re, $w, $m)) {
                $a = self::numero($m['a']);
                $b = self::numero($m['b']);
                $u = self::unidad($m['ub'] ?? '') ?? self::unidad($m['ua'] ?? '') ?? (max((float) $a, (float) $b) <= 30 ? 'm' : 'mm');

                if ($a !== null && $b !== null) {
                    [$lo, $hi] = [min($a, $b), max($a, $b)];
                    $variable = ['min' => $lo, 'max' => $hi, 'unidad' => $u, 'promedio' => ($lo + $hi) / 2];
                    $w = preg_replace($re, ' ', $w, 1) ?? $w;

                    break;
                }
            }
        }

        // Medidas con nombre: «diámetro 20», «20 de espesor», «largo de 1,5 m».
        foreach (self::NOMBRES as $alias => $candidatas) {
            $clave = null;

            foreach ($candidatas as $c) {
                if (in_array($c, $claves, true) && ! isset($medidas[$c])) {
                    $clave = $c;

                    break;
                }
            }

            if ($clave === null || ($clave === 'length' && $variable !== null)) {
                continue;
            }

            // Primero «3 mm de espesor» / «24 entre caras» (el número delante del nombre): así «espesor» no se lleva el número del que sigue.
            foreach ([
                "/(?<![a-z\\d.,])(?<n>{$num})\\s*(?<u>{$uni})?\\s*(?:de\\s+)?(?:{$alias})(?![a-z])/",
                "/(?<![a-z])(?:{$alias})\\s*(?:de|=|:)?\\s*(?<n>{$num})\\s*(?<u>{$uni})?/",
            ] as $re) {
                if (preg_match($re, $w, $m) && ($v = self::numero($m['n'])) !== null) {
                    $explicita = self::unidad($m['u'] ?? '');
                    $unidad = $explicita ?? self::unidadSupuesta($clave, $v);
                    $explicita === null && $unidad !== 'mm' && $asumidas[$clave] = $unidad;
                    $medidas[$clave] = ['valor' => $v, 'unidad' => $unidad];
                    $w = preg_replace($re, ' ', $w, 1) ?? $w;

                    break;
                }
            }
        }

        // Lo que se escribió con unidad o con «x», EN EL ORDEN EN QUE SE ESCRIBIÓ: «3 mm 1000 x 2000» es espesor 3, ancho 1000, largo 2000
        // (no «1000 x 2000» primero y el 3 al final).
        $fichas = [];

        // Una secuencia con «x»: «38,1 x 145 mm», «2 x 1000 x 2000», «1 in x 6 m».
        if (preg_match("/(?<![a-z\\d.,])({$num})\\s*({$uni})?(?:\\s*x\\s*({$num})\\s*({$uni})?)+/", $w, $secuencia, PREG_OFFSET_CAPTURE)) {
            $fichas[] = ['pos' => $secuencia[0][1], 'largo' => strlen($secuencia[0][0]), 'tipo' => 'sec', 'texto' => $secuencia[0][0]];
        }

        // Un número suelto con su unidad: «de 6 metros» es el largo; los demás, la próxima medida que falte.
        if (preg_match_all("/(?<![a-z\\d.,])({$num})\\s*({$uni})/", $w, $sueltos, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($sueltos as $x) {
                $dentro = false;

                foreach ($fichas as $f) {
                    $dentro = $dentro || ($f['tipo'] === 'sec' && $x[0][1] >= $f['pos'] && $x[0][1] < $f['pos'] + $f['largo']);
                }

                ! $dentro && $fichas[] = ['pos' => $x[0][1], 'largo' => strlen($x[0][0]), 'tipo' => 'uni', 'texto' => $x[0][0], 'v' => $x[1][0], 'u' => $x[2][0]];
            }
        }

        usort($fichas, fn ($a, $b) => $a['pos'] <=> $b['pos']);
        $tapar = [];

        foreach ($fichas as $f) {
            $tapar[] = [$f['pos'], $f['largo']];

            if ($f['tipo'] === 'sec') {
                preg_match_all("/({$num})\\s*({$uni})?/", $f['texto'], $partes, PREG_SET_ORDER);
                $libres = array_values(array_filter($orden, fn ($k) => ! isset($medidas[$k])));
                // Una unidad escrita al final vale para los números que no traen la suya: «25,4 x 1000 mm».
                $porDefecto = 'mm';
                $hayUnidad = false;

                foreach (array_reverse($partes) as $p) {
                    if (! empty($p[2]) && ($u = self::unidad($p[2]))) {
                        $porDefecto = $u;
                        $hayUnidad = true;

                        break;
                    }
                }

                foreach ($partes as $i => $p) {
                    $v = self::numero($p[1]);
                    $k = $libres[$i] ?? null;

                    if ($v === null) {
                        continue;
                    }

                    if ($k === null) {
                        $sobran++;

                        continue;
                    }

                    $propia = ! empty($p[2]) ? self::unidad($p[2]) : null;
                    $unidad = $propia ?? ($hayUnidad ? $porDefecto : self::unidadSupuesta($k, $v));
                    $propia === null && ! $hayUnidad && $unidad !== 'mm' && $asumidas[$k] = $unidad;
                    $medidas[$k] = ['valor' => $v, 'unidad' => $unidad];
                }

                continue;
            }

            $v = self::numero($f['v']);
            $u = self::unidad($f['u']);
            $libres = array_values(array_filter($orden, fn ($k) => ! isset($medidas[$k])));

            if ($v === null || $u === null) {
                continue;
            }

            $k = ($u === 'm' && in_array('length', $libres, true) && $variable === null) ? 'length' : ($libres[0] ?? null);

            if ($k === null || ($k === 'length' && $variable !== null)) {
                $sobran++;

                continue;
            }

            $medidas[$k] = ['valor' => $v, 'unidad' => $u];
        }

        // Lo ya leído se saca del texto (con espacios del mismo largo, así las posiciones de lo demás no se mueven).
        foreach (array_reverse($tapar) as [$pos, $largo]) {
            $w = substr($w, 0, $pos).str_repeat(' ', $largo).substr($w, $pos + $largo);
        }

        // Lo que queda: números sin nombre ni unidad («x 500», «de 20»). Son medidas, en el orden en que se escriben.
        if (preg_match_all("/(?<![a-z\\d.,])({$num})(?![a-z\\d])/", $w, $sueltos)) {
            $libres = array_values(array_filter($orden, fn ($k) => ! isset($medidas[$k]) && ! ($k === 'length' && $variable !== null)));

            foreach ($sueltos[1] as $n) {
                $v = self::numero($n);
                $k = array_shift($libres);

                if ($v === null) {
                    continue;
                }

                if ($k === null) {
                    $sobran++;

                    continue;
                }

                $unidad = self::unidadSupuesta($k, $v);
                $unidad !== 'mm' && $asumidas[$k] = $unidad;
                $medidas[$k] = ['valor' => $v, 'unidad' => $unidad];
            }

            $w = preg_replace("/(?<![a-z\\d.,])({$num})(?![a-z\\d])/", ' ', $w) ?? $w;
        }

        // El largo variable se calcula con el promedio —así lo hace el sistema para el peso y el factor—.
        if ($variable !== null && in_array('length', $claves, true)) {
            $medidas['length'] = ['valor' => $variable['promedio'], 'unidad' => $variable['unidad']];
        }

        return ['medidas' => $medidas, 'piezas' => $piezas, 'largo_variable' => $variable, 'sobran' => $sobran, 'asumidas' => $asumidas, 'texto' => trim(preg_replace('/\s+/', ' ', $w) ?? $w)];
    }

    /**
     * La unidad de un número escrito sin unidad. Milímetros, salvo un LARGO chico («3 de largo»): una barra de 3 mm de largo no existe,
     * es de 3 metros. Cuando se asume se avisa, no se calla.
     */
    private static function unidadSupuesta(string $clave, float $valor): string
    {
        return $clave === 'length' && $valor > 0 && $valor < 30 ? 'm' : 'mm';
    }

    /** El valor en milímetros (para comparar o mostrar). */
    public static function enMm(float $valor, string $unidad): float
    {
        return $valor * (self::aMm()[$unidad] ?? 1.0);
    }
}

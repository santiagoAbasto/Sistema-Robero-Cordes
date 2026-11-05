<?php

namespace App\Services\Cordes;

use App\Models\Forma;
use App\Services\Buscador\Catalogo;
use App\Services\Buscador\InterpretePorReglas;
use App\Services\Buscador\ResolverDePedido;
use App\Services\Buscador\Texto;
use Illuminate\Support\Str;

/**
 * Entiende qué se está preguntando, con el código y los datos del sistema (sin IA).
 *
 * Es el cerebro de Cordes: la IA es un refuerzo para lo que esto no logra interpretar, no al revés. Reconoce LA PREGUNTA
 * (peso, precio, caño o schedule, fórmula, material, empresa, cotización, conversión, cuenta, ranking, ayuda) y LAS COSAS
 * de las que se habla, usando el propio catálogo: los materiales y sus alias, las formas, las provincias, los períodos,
 * y las medidas (ver Medidas). Lo que devuelve es un PLAN: qué se quiere y con qué datos. No consulta nada ni contesta:
 * eso lo hace Redactor, con las mismas herramientas de solo lectura que usa la IA.
 *
 * Si no está seguro de qué se pregunta, devuelve null: no adivina.
 */
final class Entiende
{
    public const CUE_PESO = '/\b(?:cuanto\s+(?:pesa|pesan|pesaria|pesaran|peso)|cuantos?\s+(?:kilos?|kgs?|kilogramos?|toneladas?|gramos?)\s+(?:pesa|pesan|tiene|tienen|son|da|salen)|pesa|pesan|pesos?\s+(?:de|del|total|teorico)|peso\s+teorico|\bpeso\b|calcul\w*\s+(?:el\s+)?peso|sacar\s+(?:el\s+)?peso|(?:kilos?|kgs?|kilogramos?)\s+de\s+(?:una?|un|\d)|cuant[oa]s?\s+(?:kilos?|kgs?|kilogramos?)\s+(?:de|hay))\b/';

    public const CUE_PRECIO = '/\b(?:a\s+cuanto|cuanto\s+(?:(?:se|le|les|nos|lo|la)\s+)*(?:cotiz\w*|cobr\w*|vend\w*|sal\w*|cuest\w*|val\w*|pag\w*)|precios?|ultimo\s+precio|precio\s+promedio|valor\s+de|cotizacion\s+de\s+\w+\s+(?:a\s+)?cuanto)\b/';

    private const CUE_EVOLUCION = '/\b(?:evolucion\w*|tendencia|(?:historial|historico)\s+de\s+precios?|como\s+(?:viene|vino|fue|se\s+movio|evoluciono)\s+(?:el\s+)?precio|precios?\b.*\b(?:por\s+(?:ano|anio|mes)|mes\s+a\s+mes|ano\s+a\s+ano)|(?:subio|bajo|aumento|aumentaron|variacion|vario|variaron)\b.*\bprecio|precio\b.*\b(?:subio|bajo|aumento|aumentaron|variacion|vario|variaron))\b/';

    private const CUE_FORMULA = '/\b(?:formulas?|como\s+se\s+(?:calcula|saca|obtiene|hace)|como\s+(?:calculo|saco)|calculo\s+del\s+(?:peso|volumen)|que\s+medidas\s+(?:lleva|pide|necesita|hay\s+que))\b/';

    private const CUE_MATERIAL = '/\b(?:densidad|uns|w\.?\s?nr|alias|sinonimos?|familia|composicion|que\s+es\s+(?:el|la|un|una)\s+\w+|datos?\s+(?:del|de)\s+(?:material|aleacion)|como\s+se\s+llama|propiedades|tiene\s+densidad)\b/';

    private const CUE_EMPRESA = '/\b(?:contame|contanos|decime|dame|mostrame|ver\s+la\s+ficha|quiero\s+ver|abri|abrime|ficha|informacion|info|datos?|telefono|tel|celular|whatsapp|mail|correo|email|contactos?|direccion|domicilio|cuit|quien\s+es|que\s+sabemos|ultima\s+cotizacion|ultima\s+vez|cuando\s+(?:fue|le\s+cotizamos|se\s+le\s+cotizo)|cuanto\s+(?:le|les)\s+(?:cotizamos|vendimos|facturamos)|cotizamos\s+a|cotizaciones\s+(?:de|a))\b/';

    private const CUE_RANKING = '/\b(?:ranking|top\s*\d*|mejores?\s+clientes?|(?:que|cuales)\s+(?:empresas?|clientes?|materiales?|formas?)\s+(?:mas|menos)|(?:quien|quienes)\s+(?:mas|menos)|mas\s+(?:cotizad\w*|compr\w*|vendid\w*|pedid\w*)|los?\s+mas\s+(?:cotizad\w*|compr\w*))\b/';

    private const CUE_CANO = '/\b(?:cano|canos|tuberia|tuberias|schedule|schedules|cedula|sch|dn\s*\d+)\b/';

    private const CUE_AYUDA = '/^\s*(?:hola|buenas|buen\s+dia|buenos\s+dias|buenas\s+tardes|buenas\s+noches|ayuda|ayudame|que\s+(?:podes|sabes|puedes)\s+hacer|que\s+haces|como\s+funciona|para\s+que\s+servis|menu|opciones|instrucciones|gracias)\b/';

    private const UNIDADES_CONVERTIR = 'milimetros?|mm|centimetros?|cm|metros?|mts?|m|pulgadas?|pulg|in|pies|pie|ft|kilogramos?|kilos?|kgs?|kg|gramos?|g|libras?|lbs?|lb|toneladas?|ton|tn';

    /** Palabras que piden comparar: una comparación necesita dos respuestas y un análisis, no una. */
    public const CUE_COMPARACION = '/\b(?:compar\w*|contra|versus|vs|diferenc\w*|difier\w*|cuanto\s+(?:mas|menos)|(?:mas|menos)\s+(?:pesa\w*|pesad\w*|livian\w*|barat\w*|car[oa]s?)\s+(?:que|de\s+lo\s+que)|que\s+(?:es\s+)?(?:pesa|cuesta|sale)?\s*(?:mas|menos)\s+\w+|(?:pesa\w*|pesad\w*|cuesta\w*|sale\w*|vale\w*)\s+(?:mas|menos)\b|cual\s+(?:es\s+)?(?:el\s+)?(?:mas|menos|pesa|cuesta|sale|conviene|vale)\b|(?:mas|menos)\s+que|igual\s+que|respecto\s+(?:de|a))\b/';

    private const COSAS_PEDIDAS = 'barras?|chapas?|tubos?|canos?|discos?|bridas?|aros?|anillos?|perfiles?|alambres?|esferas?|arandelas?|planchuelas?|varillas?|rollos?|bobinas?|codos?|curvas?|bulones?|tornillos?|tuercas?';

    private const CANTIDAD = '(?:\d+|un|una|uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|doce)';

    /** Claves de material que son palabras comunes: no cuentan como un material más. */
    /** Claves de material que son palabras comunes del castellano: no cuentan como un material (por ejemplo «cual», un alias viejo de Cobre Aluminio). */
    private const NO_SON_MATERIAL = ['cual', 'cuales', 'cuanto', 'cuantos', 'cuanta', 'cuantas', 'como', 'donde', 'cuando', 'quien', 'quienes', 'para', 'pero', 'sobre', 'entre', 'desde', 'hasta', 'menos', 'tiene', 'tienen', 'hubo', 'fueron', 'esta', 'estan', 'este', 'esto', 'esos', 'esas', 'estos', 'estas', 'todo', 'toda', 'todos', 'todas', 'otro', 'otra', 'otros', 'otras', 'mismo', 'misma', 'ambos', 'cada', 'algun', 'alguna', 'algunos', 'unos', 'unas', 'nuestro', 'nuestra', 'tambien', 'ademas', 'luego', 'entonces', 'despues', 'antes', 'ahora', 'aqui', 'alli', 'todavia', 'nunca', 'siempre', 'quiero', 'quiere', 'necesito', 'saber', 'dame', 'decime', 'contame', 'mostrame', 'pasame', 'favor', 'hola', 'gracias', 'buenas', 'buenos', 'dias', 'tardes', 'noches', 'ayuda', 'precio', 'precios', 'peso', 'pesos', 'pesa', 'pesan', 'kilo', 'kilos', 'largo', 'largos', 'ancho', 'espesor', 'altura', 'diametro', 'lado', 'pared', 'medida', 'medidas', 'forma', 'formas', 'barra', 'barras', 'chapa', 'chapas', 'tubo', 'tubos', 'cano', 'canos', 'disco', 'discos', 'brida', 'bridas', 'anillo', 'anillos', 'perfil', 'perfiles', 'alambre', 'alambres', 'esfera', 'esferas', 'pieza', 'piezas', 'unidad', 'unidades', 'material', 'materiales', 'empresa', 'empresas', 'cliente', 'clientes', 'cotizacion', 'cotizaciones', 'pedido', 'pedidos', 'factura', 'facturas', 'remito', 'remitos', 'fecha', 'total', 'suma', 'resta', 'promedio', 'minimo', 'maximo', 'ultimo', 'ultima', 'ultimos', 'primera', 'primero', 'segunda', 'segundo', 'tercera', 'tercero', 'semana', 'meses', 'anos', 'anio', 'anios', 'hoy', 'ayer', 'manana', 'pasado', 'pasada', 'proximo', 'proxima', 'normal', 'grande', 'chico', 'chica', 'medio', 'media', 'mitad', 'doble', 'triple', 'igual', 'distinto', 'distinta', 'mejor', 'peor', 'mayor', 'menor', 'bajo', 'baja', 'nuevo', 'nueva', 'viejo', 'vieja', 'libre', 'libres', 'segun', 'durante', 'mediante'];

    public function __construct(private readonly Catalogo $catalogo) {}

    /** @var array<string, ?string> */
    private array $memoCompuesta = [];

    /**
     * ¿La pregunta junta varias cosas que el motor no puede resolver de una vez? (comparar, varios materiales o piezas, peso y precio…)
     * Devuelve el motivo, en castellano; null si es una sola cosa. El motor no las contesta (contestaría una parte y callaría el resto):
     * se las deja a la IA, que las divide, o al descompositor de código (Descompone).
     */
    public function compuesta(string $texto): ?string
    {
        return $this->memoCompuesta[$texto] ??= $this->calcularCompuesta($texto);
    }

    private function calcularCompuesta(string $texto): ?string
    {
        $w = Medidas::limpiar($texto);

        return match (true) {
            (bool) preg_match(self::CUE_COMPARACION, $w) => 'compara varias cosas',
            preg_match_all('/(?<![\d,.])'.self::CANTIDAD.'\s+(?:'.self::COSAS_PEDIDAS.')\b/', $w) >= 2 => 'pide varias piezas distintas',
            (bool) preg_match('/\b(?:y|e|o|u|mas)\s+'.self::CANTIDAD.'\s+de\s+\d/', $w) => 'pide varias piezas distintas',
            $this->variasFormas($w) => 'nombra varias formas',
            count($this->materialesNombrados($w)) >= 2 => 'nombra varios materiales',
            (bool) preg_match('/\b(?:una?|un|el|la)\s+[a-z]+(?:\s+[a-z\d,.]+){0,8}\s+(?:o|u)\s+(?:una?|un|el|la)\s+[a-z]+/', $w) => 'ofrece elegir entre dos cosas',
            (bool) preg_match(self::CUE_PESO, $w) && preg_match(self::CUE_PRECIO, $w) => 'pide el peso y el precio juntos',
            (bool) preg_match('/\b(?:si|siendo|sabiendo|con)\b.*\bpesa\w*\s+(?:\w+\s+){0,2}\d+(?:[.,]\d+)?\s*(?:kg|kgs|kilos?|g|gr|gramos?|lb|lbs|libras?|ton|tn)\b/', $w) => 'trae un peso como dato y pide una cuenta',
            preg_match_all('/\bcuant[oa]s?\b/', $w) >= 2 => 'hace más de una pregunta',
            preg_match_all('/\b(?:sch|schedule|cedula)\s*\d/', $w) >= 2 => 'pide más de un schedule',
            $this->familias($w) >= 2 && preg_match('/\b(?:y|e|ademas|tambien|despues)\b/', $w) => 'hace más de una pregunta',
            $this->coordinados($w) !== null => 'pide lo mismo de dos cosas',
            default => null,
        };
    }

    /** Cuántas CLASES de pregunta hay en el texto (peso, precio, fórmula, material, tabla, ranking, conversión, contacto). */
    private function familias(string $w): int
    {
        return (preg_match(self::CUE_PESO, $w) ? 1 : 0)
            + (preg_match(self::CUE_PRECIO, $w) || preg_match(self::CUE_EVOLUCION, $w) ? 1 : 0)
            + (preg_match(self::CUE_FORMULA, $w) ? 1 : 0)
            + (preg_match(self::CUE_MATERIAL, $w) ? 1 : 0)
            + (preg_match('/\btabla\b/', $w) ? 1 : 0)
            + (preg_match(self::CUE_RANKING, $w) ? 1 : 0)
            + ($this->convertir($w) !== null ? 1 : 0)
            + (preg_match('/\b(?:telefono|mail|correo|direccion|cuit|contactos?)\b/', $w) ? 1 : 0);
    }

    /**
     * «Densidad del monel y del inconel 625», «fórmula de la barra y de la chapa», «cuánto mide un caño de 2 sch 40 y uno de 3 sch 80»,
     * «cuántos mm son 2 pulgadas y 3 pulgadas»: lo mismo pedido de dos cosas (o más). Devuelve las preguntas completas (ya normalizadas),
     * o null si no es eso. Sólo cuando las cosas son del mismo tipo: «de 20 mm de diámetro y de 1 metro de largo» es UNA pieza, no dos.
     *
     * @return list<string>|null
     */
    public function coordinados(string $w): ?array
    {
        return $this->porCambioDeMaterial($w)
            ?? $this->variosSchedules($w)
            ?? $this->variasConversiones($w)
            ?? $this->itemParecido($w)
            ?? $this->listaDeMateriales($w)
            ?? $this->porSustitucion($w);
    }

    /** «Ranking de empresas que compraron monel y de las que compraron titanio»: la misma pregunta con el otro material. */
    private function porCambioDeMaterial(string $w): ?array
    {
        if (! preg_match('/^(?<izq>.+?)\s+(?:y|e|o|u)\s+(?<der>(?:tambien\s+)?(?:(?:de|del|para|con)\s+)?(?:(?:las|los|la|el)\s+)?(?:que|con|de)\s+.+)$/', $w, $m)
            || count($this->materialesNombrados($m['izq'])) !== 1 || count($this->materialesNombrados($m['der'])) !== 1
            || $this->materialesNombrados($m['izq']) === $this->materialesNombrados($m['der'])) {
            return null;
        }

        $a = $this->claveDeMaterial($m['izq']);
        $b = $this->claveDeMaterial($m['der']);

        return $a !== null && $b !== null && $a !== $b ? [$m['izq'], trim(str_replace(" {$a} ", " {$b} ", ' '.$m['izq'].' '))] : null;
    }

    /** «Peso de caño 1/2 sch 40 y 3/4 sch 40 de monel de 6 m»: cada diámetro con su schedule, y lo demás es de todos. */
    private function variosSchedules(string $w): ?array
    {
        $n = Medidas::NUM;

        if (preg_match_all("/(?:{$n})\\s*(?:pulgadas?\\s*)?(?:sch|schedule|cedula)\\s*\\d+s?/", $w, $m, PREG_OFFSET_CAPTURE) < 2 || count($m[0]) > 5) {
            return null;
        }

        $primero = $m[0][0][1];
        $ultimo = end($m[0]);
        $prefijo = trim(substr($w, 0, $primero));
        $cola = trim(substr($w, $ultimo[1] + strlen($ultimo[0])));
        $entre = trim(substr($w, $primero, $ultimo[1] - $primero));

        // Entre un caño y otro sólo puede haber conectores.
        if (preg_replace('/(?:'.$n.')\s*(?:pulgadas?\s*)?(?:sch|schedule|cedula)\s*\d+s?|\b(?:y|e|o|u|de|con)\b|[,\s]/', '', $entre) !== '') {
            return null;
        }

        return array_map(fn ($t) => trim($prefijo.' '.$t[0].' '.$cola), $m[0]);
    }

    /** «Cuántos mm son 2 pulgadas y 3 pulgadas», «convertí 10 kg a libras y a gramos»: una conversión por cada valor o por cada destino. */
    private function variasConversiones(string $w): ?array
    {
        $n = Medidas::NUM;
        $u = self::UNIDADES_CONVERTIR;

        if (preg_match("/^(?<cue>.*?cuant[oa]s?\\s+(?:{$u})\\s+(?:son|hay\\s+en|tiene[n]?|equivalen?|mide[n]?)\\s+)(?<a>{$n}\\s*(?:{$u}))\\s+(?:y|e)\\s+(?<b>{$n}\\s*(?:{$u}))(?<resto>.*)\$/", $w, $m)) {
            return [trim($m['cue'].$m['a'].$m['resto']), trim($m['cue'].$m['b'].$m['resto'])];
        }

        if (preg_match("/^(?<pre>.*?(?:convert\\w*|pasa\\w*|transform\\w*|expresa\\w*)\\s+{$n}\\s*(?:{$u})\\s+)(?:a|en)\\s+(?<t1>[a-z]+)\\s+(?:y|e)\\s+(?:a|en)?\\s*(?<t2>[a-z]+)\$/", $w, $m)
            && $this->codigoDeUnidad($m['t1']) !== null && $this->codigoDeUnidad($m['t2']) !== null) {
            return [trim($m['pre'].'a '.$m['t1']), trim($m['pre'].'a '.$m['t2'])];
        }

        return null;
    }

    /** «Cuánto mide un caño de 2 sch 40 y uno de 3 sch 80»: «uno de…» es otro del mismo (un caño). */
    private function itemParecido(string $w): ?array
    {
        if (! preg_match('/^(?<izq>.+?)\s+(?:y|e|o|u)\s+(?:uno|una|otro|otra|\d+)\s+(?<der>de\s+.+)$/', $w, $m)
            || ! preg_match('/(?<![a-z\d])(?:un|una|uno|\d+)\s+(?<n>[a-z]+)\s+(?=de\s)/', $m['izq'], $q, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $sustantivo = $q['n'][0];

        if (! preg_match('/^(?:'.self::COSAS_PEDIDAS.')$/', $sustantivo) && $this->catalogo->forma($sustantivo) === null) {
            return null;
        }

        return [$m['izq'], trim(substr($m['izq'], 0, $q[0][1]).' 1 '.$sustantivo.' '.$m['der'])];
    }

    /** «Densidad de monel, inconel 625 y titanio grado 2»: la misma pregunta por cada material. */
    private function listaDeMateriales(string $w): ?array
    {
        $claves = $this->clavesNombradas($w);

        if (count($claves) < 2 || count($claves) > 5) {
            return null;
        }

        $relleno = '(?:\s|,|\by\b|\be\b|\bo\b|\bu\b|\bde\b|\bdel\b|\bla\b|\bel\b)*';
        $inicio = $claves[0]['pos'];
        $fin = end($claves)['pos'] + mb_strlen(end($claves)['clave']);

        for ($i = 1; $i < count($claves); $i++) {
            $anterior = $claves[$i - 1];
            $entre = substr($w, $anterior['pos'] + mb_strlen($anterior['clave']), $claves[$i]['pos'] - ($anterior['pos'] + mb_strlen($anterior['clave'])));

            if (! preg_match('/^'.$relleno.'$/', $entre)) {
                return null;
            }
        }

        $antes = trim(substr($w, 0, $inicio));
        $despues = trim(substr($w, $fin));

        return array_map(fn ($c) => trim($antes.' '.$c['clave'].' '.$despues), $claves);
    }

    /** «Fórmula de la barra y de la chapa»: se busca, en lo que ya se dijo, la cosa del mismo tipo que la nueva, y se la cambia. */
    private function porSustitucion(string $w): ?array
    {
        if (! preg_match('/^(?<izq>.+?)\s+(?:y|e|o|u)\s+(?<der>(?:de\s+(?:la|las|los|el)|del|de|en|durante)\s+.+)$/', $w, $m)) {
            return null;
        }

        $prep = '(?:de\s+(?:la|las|los|el)|del|de|en|durante)';
        $np = fn (string $t) => preg_split('/\s+(?='.$prep.'\s)/', $t, 2)[0];
        $tipoDer = $this->tipoDeCosa($np($m['der']));

        if ($tipoDer === null || ! preg_match_all('/(?<=^|\s)'.$prep.'(?=\s)/', $m['izq'], $occ, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $inicios = array_map(fn ($o) => $o[1], $occ[0]);

        // De la última a la primera: la cosa que se cambia es la más cercana del mismo tipo.
        for ($i = count($inicios) - 1; $i >= 0; $i--) {
            $desde = $inicios[$i];
            $hasta = $inicios[$i + 1] ?? strlen($m['izq']);

            if ($this->tipoDeCosa(substr($m['izq'], $desde, $hasta - $desde)) === $tipoDer) {
                return [$m['izq'], trim(substr($m['izq'], 0, $desde).' '.$m['der'].' '.substr($m['izq'], $hasta))];
            }
        }

        return null;
    }

    /** De qué tipo es una cosa nombrada («de la chapa» → forma, «del monel» → material, «de 30 mm» → medidas, «del mes pasado» → período). */
    private function tipoDeCosa(string $np): ?string
    {
        $np = trim(preg_replace('/^(?:de\s+(?:la|las|los|el)|del|de|en|durante)\s+/', '', trim($np)) ?? $np);
        $np = trim(preg_replace('/^(?:un|una|uno|el|la|los|las)\s+/', '', $np) ?? $np);

        return match (true) {
            $np === '' => null,
            (bool) preg_match('/\b(?:diametro|largo|ancho|espesor|alto|altura|lado|pared|longitud)\b/', $np) => null,
            (bool) preg_match('/\b(?:sch|schedule|cedula)\s*\d/', $np) => 'schedule',
            (bool) preg_match('/\b(?:20\d\d|mes|meses|ano|anos|anio|semana|trimestre|semestre|hoy|ayer)\b/', $np) => 'periodo',
            $this->materialesNombrados($np) !== [] => 'material',
            $this->catalogo->forma(implode(' ', array_slice(explode(' ', $np), 0, 2))) !== null || $this->catalogo->forma(explode(' ', $np)[0]) !== null => 'forma',
            (bool) preg_match('/^\d+(?:[.,]\d+)?(?:\s*x\s*\d+(?:[.,]\d+)?)*\s*(?:mm|cm|m|pulgadas?|metros?)?$/', $np) => 'medidas',
            default => null,
        };
    }

    /**
     * Los materiales distintos que se nombran en un texto (ya normalizado), sin repetir lo ya tomado.
     *
     * @return list<string>
     */
    public function materialesDe(string $w): array
    {
        return $this->materialesNombrados($w);
    }

    /** Cómo está escrito el material en el texto (la clave del catálogo, normalizada), para poder cambiarlo por otro. */
    public function claveDeMaterial(string $w): ?string
    {
        return $this->clavesNombradas($w)[0]['clave'] ?? null;
    }

    /**
     * Los materiales que se nombran en un texto (ya normalizado), del más largo al más corto y sin repetir lo ya tomado:
     * «titanio grado 2» es uno, no «titanio» y otro. Las claves de menos de 4 letras («ver», «est») son abreviaturas viejas que chocan con palabras comunes.
     *
     * @return list<array{clave: string, canonico: string, pos: int}> en el orden en que aparecen
     */
    private function clavesNombradas(string $w): array
    {
        $claves = array_filter(array_keys($this->catalogo->materiales()), fn ($k) => mb_strlen((string) $k) >= 4 && ! in_array($k, self::NO_SON_MATERIAL, true));
        usort($claves, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));
        $resto = " {$w} ";
        $hallados = [];

        foreach ($claves as $k) {
            $pos = strpos($resto, " {$k} ");

            if ($pos !== false) {
                $hallados[] = ['clave' => (string) $k, 'canonico' => (string) $this->catalogo->materiales()[$k], 'pos' => $pos];
                // Se tapa lo tomado con espacios del mismo largo, para que las posiciones sigan valiendo.
                $resto = substr($resto, 0, $pos + 1).str_repeat(' ', strlen($k)).substr($resto, $pos + 1 + strlen($k));
            }
        }

        usort($hallados, fn ($a, $b) => $a['pos'] <=> $b['pos']);

        // Una posición para el texto sin el espacio del principio.
        return array_map(fn ($h) => ['clave' => $h['clave'], 'canonico' => $h['canonico'], 'pos' => $h['pos']], $hallados);
    }

    /**
     * Los materiales distintos que se nombran en un texto (ya normalizado), sin repetir lo ya tomado.
     *
     * @return list<string>
     */
    private function materialesNombrados(string $w): array
    {
        return array_values(array_unique(array_column($this->clavesNombradas($w), 'canonico')));
    }

    private function variasFormas(string $w): bool
    {
        preg_match_all('/\b('.self::COSAS_PEDIDAS.')\b/', $w, $m);

        return count(array_unique(array_map(fn ($c) => rtrim($c, 's'), $m[1]))) >= 2;
    }

    /**
     * @param  array{borrador: array<string, mixed>, restos: list<string>, entendio: bool}|null  $reglas  si ya se interpretó con las reglas, para no repetirlo
     * @return array{intencion: string, datos: array<string, mixed>}|null
     */
    public function plan(string $texto, ?array $reglas = null): ?array
    {
        $w = Medidas::limpiar($texto);
        $reglas ??= (new InterpretePorReglas($this->catalogo))->interpretar($texto);
        $b = $reglas['borrador'];
        // El material lo detecto yo: el de las reglas puede tomar un número («145») por un código de aleación.
        [$material, $materialTexto] = $this->materialYSuClave($w, $b);
        $b['material'] = $material;
        $b['material_texto'] = $materialTexto;
        $b = array_filter($b, fn ($v) => $v !== null);

        if (preg_match(self::CUE_AYUDA, $w)) {
            return ['intencion' => 'ayuda', 'datos' => []];
        }

        // Una cotización por su número: «la 2026-0741», «cotización 2026-0741 R1», «pedido 4500123».
        if (preg_match('/\b(?<n>\d{4}-\d{3,5})(?:\s*r\s*\d+)?\b/', $w, $m) && ! preg_match('/\b(?:factura|remito|comprobante)s?\b/', $w)) {
            return ['intencion' => 'cotizacion', 'datos' => ['numero' => $m['n']]];
        }

        if (preg_match('/\b(?:cotizacion|pedido|presupuesto|oc)\s+(?:n(?:ro|umero|o)?\.?\s*)?(?<n>[a-z]*-?\d{4,}[a-z0-9\-]*)\b/', $w, $m)) {
            return ['intencion' => 'cotizacion', 'datos' => ['numero' => $m['n']]];
        }

        // Varias cosas juntas (comparar, dos materiales, peso y precio, dos conversiones…): el motor no contesta a medias; las resuelve la IA o el descompositor.
        if ($this->compuesta($texto) !== null) {
            return null;
        }

        if ($c = $this->convertir($w)) {
            return ['intencion' => 'convertir', 'datos' => $c];
        }

        if ($expresion = $this->cuenta($texto)) {
            return ['intencion' => 'calcular', 'datos' => ['expresion' => $expresion, 'legible' => $this->cuentaLegible($texto)]];
        }

        $cano = Medidas::cano($w);
        // Un «1/4"» o «2 pulgadas» sólo es un caño si la forma que se nombra lo es (o no se nombra ninguna): «chapa de 1/4"» es una chapa.
        $formaNombrada = $this->forma($b);
        $nominalEsDeCano = $cano['nominal'] !== null && ($formaNombrada === null || $formaNombrada->usa_cano || $formaNombrada->nombre === 'TUBO');
        $hayCano = $nominalEsDeCano || $cano['schedule'] !== null || preg_match(self::CUE_CANO, $w) || preg_match('/\bcanos?\b/', $w);

        // «medidas de la barra hexagonal»: qué hay que medir (y cómo se calcula).
        if (preg_match('/\bmedidas?\s+(?:de|del|que\s+lleva|para)\s+(?:la|el|una?|las|los)?\s*\w+/', $w) && $this->forma($b) && ! preg_match('/\d/', $w)) {
            return ['intencion' => 'formula', 'datos' => ['forma' => $this->forma($b)?->nombre, 'texto_forma' => $b['forma'] ?? null]];
        }

        // «qué materiales hay de titanio», «qué formas hay»: el catálogo.
        if (preg_match('/\b(?:que|cuales)\s+(?<q>materiales|aleaciones|formas|familias)\s+(?:de\s+materiales\s+)?(?:hay|tenemos|tienen|manejan|trabajan|existen|estan\s+cargad\w+|cargamos)\b(?:\s+(?:de|del|en)\s+(?<f>[a-z0-9 .\-]{2,40}))?/', $w, $m)
            || preg_match('/\blista\s+de\s+(?<q>materiales|formas)(?:\s+(?:de|del)\s+(?<f>[a-z0-9 .\-]{2,40}))?/', $w, $m)) {
            return ['intencion' => 'catalogo', 'datos' => ['que' => $m['q'] === 'formas' ? 'formas' : 'materiales', 'termino' => $m['q'] === 'familias' ? null : (isset($m['f']) ? trim($m['f']) : null)]];
        }

        if (preg_match(self::CUE_FORMULA, $w)) {
            return ['intencion' => 'formula', 'datos' => ['forma' => $this->forma($b)?->nombre, 'texto_forma' => $b['forma'] ?? null]];
        }

        if (preg_match(self::CUE_PESO, $w)) {
            return ['intencion' => 'peso', 'datos' => $this->datosDePeso($texto, $w, $b, $cano, $hayCano)];
        }

        if (preg_match(self::CUE_EVOLUCION, $w)) {
            return ['intencion' => 'evolucion', 'datos' => ['filtros' => $this->filtros($b), 'empresa' => $this->empresa($w, $b), 'por' => preg_match('/\b(?:por\s+mes|mensual\w*|mes\s+a\s+mes)\b/', $w) ? 'mes' : 'anio', 'texto' => $texto]];
        }

        if (preg_match(self::CUE_PRECIO, $w)) {
            return ['intencion' => 'precio', 'datos' => ['filtros' => $this->filtros($b), 'empresa' => $this->empresa($w, $b), 'texto' => $texto]];
        }

        if ($hayCano && ! preg_match(self::CUE_RANKING, $w)) {
            return ['intencion' => 'cano', 'datos' => ['nominal' => $cano['nominal'], 'schedule' => $cano['schedule'], 'metros' => $cano['metros'], 'material' => $b['material'] ?? null, 'texto' => $texto]];
        }

        // «cuántas cotizaciones hicimos por mes», «materiales por año»: se cuenta y se reparte.
        if (preg_match('/\b(?:cuant[oa]s?|como\s+viene|distribucion|reparto|desglos\w+|resumen)\b.*\bpor\s+(?<d>provincia|localidad|rubro|mes|ano|anio|estado|empresa|material|forma|vendedor|usuario|moneda|tipo)\b/', $w, $m)
            || preg_match('/\bpor\s+(?<d>provincia|localidad|rubro|mes|ano|anio|estado|empresa|material|forma|vendedor|usuario|moneda)\b/', $w, $m)) {
            return ['intencion' => 'agrupar', 'datos' => ['por' => $this->dimension($m['d']), 'vista' => $this->vistaDeLaPregunta($w, $m['d']), 'filtros' => $this->filtros($b), 'texto' => $texto]];
        }

        if (preg_match(self::CUE_RANKING, $w)) {
            return ['intencion' => 'ranking', 'datos' => ['filtros' => $this->filtros($b), 'por' => $this->rankingPor($w, $b), 'medida' => preg_match('/\b(?:compr\w*|vendid\w*)\b/', $w) ? 'vendidas' : 'cotizaciones', 'texto' => $texto]];
        }

        if (preg_match(self::CUE_MATERIAL, $w)) {
            $nombre = $b['material'] ?? null;

            // «densidad del unobtanium»: no se lo conoce, pero se pregunta por él: el catálogo sugiere los más parecidos.
            if ($nombre === null && preg_match('/\b(?:densidad|uns|w\.?\s?nr|alias|familia|propiedades|composicion|que\s+es)\s+(?:del?|de\s+la|el|la|los)\s+(?<m>[a-z0-9][a-z0-9 .\-]{1,38}?)\s*$/', $w, $m)) {
                $nombre = trim($m['m']);
            }

            if ($nombre !== null) {
                return ['intencion' => 'material', 'datos' => ['material' => $nombre, 'aspecto' => $this->aspectoDeMaterial($w)]];
            }
        }

        if (preg_match(self::CUE_EMPRESA, $w) && ($nombre = $this->empresa($w, $b))) {
            return ['intencion' => 'empresa', 'datos' => ['nombre' => $nombre, 'aspecto' => $this->aspectoDeEmpresa($w), 'filtros' => $this->filtros($b), 'texto' => $texto]];
        }

        return null;
    }

    /* ------------------------------------------------------------------ peso */

    /**
     * @param  array<string, mixed>  $b
     * @param  array{nominal: ?string, schedule: ?string, metros: ?float, texto: string}  $cano
     * @return array<string, mixed>
     */
    private function datosDePeso(string $texto, string $w, array $b, array $cano, bool $hayCano): array
    {
        $forma = $this->forma($b);

        // Un caño con diámetro nominal y schedule es de la tabla de caños, lo diga o no la palabra.
        if (($cano['nominal'] !== null || $cano['schedule'] !== null) && ($forma === null || $forma->usa_cano || $forma->nombre === 'TUBO')) {
            $forma = Forma::query()->where('activo', true)->where('usa_cano', true)->first() ?? $forma;
        }

        $esCano = $forma?->usa_cano || ($forma === null && $hayCano);
        $resto = $esCano ? $cano['texto'] : $this->sinPalabrasDeCatalogo($w, $b);
        $leidas = Medidas::leer($resto, $esCano ? null : $forma);

        return [
            'forma' => $forma?->nombre, 'material' => $b['material'] ?? null, 'material_texto' => $b['material_texto'] ?? null, 'es_cano' => $esCano,
            'medidas' => $leidas['medidas'], 'piezas' => $leidas['piezas'], 'largo_variable' => $leidas['largo_variable'], 'asumidas' => $leidas['asumidas'],
            'cano' => $esCano ? ['nominal' => $cano['nominal'], 'schedule' => $cano['schedule'], 'metros' => $cano['metros']] : null,
            'texto' => $texto,
        ];
    }

    /** La forma que se nombra, como modelo. */
    private function forma(array $b): ?Forma
    {
        $id = isset($b['forma']) ? $this->catalogo->forma((string) $b['forma']) : null;

        return $id ? Forma::query()->find($id) : null;
    }

    /**
     * El texto sin el material ni la forma: así «316», «grado 2» o «304L» no se leen como medidas.
     *
     * @param  array<string, mixed>  $b
     */
    private function sinPalabrasDeCatalogo(string $w, array $b): string
    {
        $quitar = [];

        foreach ($this->catalogo->materiales() as $clave => $canonico) {
            if (str_contains(" {$w} ", " {$clave} ")) {
                $quitar[] = $clave;
            }
        }

        if (isset($b['material']) && is_string($b['material'])) {
            $quitar[] = Texto::normalizar($b['material']);
        }

        usort($quitar, fn ($a, $c) => mb_strlen($c) <=> mb_strlen($a));

        foreach (array_unique($quitar) as $q) {
            if ($q !== '') {
                $w = preg_replace('/(?<![a-z\d])'.preg_quote($q, '/').'(?![a-z\d])/', ' ', $w) ?? $w;
            }
        }

        return trim(preg_replace('/\s+/', ' ', $w) ?? $w);
    }

    /* --------------------------------------------------- conversiones y cuentas */

    /** @return array{valor: string, de: string, a: string}|null */
    private function convertir(string $w): ?array
    {
        $u = self::UNIDADES_CONVERTIR;
        $n = Medidas::NUM;

        foreach ([
            "/(?<v>{$n})\\s*(?<de>{$u})(?![a-z])\\s+(?:a|en|son|equivalen?(?:\\s+a)?|a\\s+cuantos?)\\s*(?<a>{$u})(?![a-z])/",
            "/cuant[oa]s?\\s+(?<a>{$u})(?![a-z])\\s+(?:son|hay\\s+en|tiene[n]?|equivalen?(?:\\s+a)?|mide[n]?)\\s+(?:(?:una?|un)\\s+)?(?<v>{$n})?\\s*(?<de>{$u})(?![a-z])/",
            "/(?:convert\\w*|pasa\\w*|transform\\w*|expresa\\w*)\\s+(?<v>{$n})\\s*(?<de>{$u})(?![a-z])\\s+(?:a|en)\\s+(?<a>{$u})(?![a-z])/",
        ] as $re) {
            if (preg_match($re, $w, $m)) {
                $de = $this->codigoDeUnidad($m['de']);
                $a = $this->codigoDeUnidad($m['a']);

                if ($de && $a && $de !== $a) {
                    return ['valor' => ($m['v'] ?? '') !== '' ? $m['v'] : '1', 'de' => $de, 'a' => $a];
                }
            }
        }

        return null;
    }

    private function codigoDeUnidad(string $u): ?string
    {
        return match (true) {
            in_array($u, ['mm'], true) || str_starts_with($u, 'mil') => 'mm',
            $u === 'cm' || str_starts_with($u, 'cen') => 'cm',
            $u === 'm' || str_starts_with($u, 'met') || str_starts_with($u, 'mt') => 'm',
            $u === 'in' || str_starts_with($u, 'pulg') => 'in',
            $u === 'ft' || str_starts_with($u, 'pie') => 'ft',
            in_array($u, ['kg', 'kgs'], true) || str_starts_with($u, 'kilo') => 'kg',
            $u === 'g' || str_starts_with($u, 'gram') => 'g',
            in_array($u, ['lb', 'lbs'], true) || str_starts_with($u, 'libra') => 'lb',
            in_array($u, ['ton', 'tn'], true) || str_starts_with($u, 'tonel') => 'ton',
            default => null,
        };
    }

    /** Una cuenta: «cuánto es 1059,84 + 365,52», «20% de 450». Sin la palabra, sólo si tiene + - * /. Se lee del texto original: normalizar borra los signos. */
    private function cuenta(string $texto): ?string
    {
        $t = mb_strtolower(Str::ascii($texto));
        $t = trim(str_replace(['¿', '?', '='], ' ', $t));
        $n = Medidas::NUM;

        if (preg_match("/(?<p>{$n})\\s*%\\s*de\\s*(?<n>{$n})/", $t, $m)) {
            return '('.str_replace(',', '.', $m['n']).' * '.str_replace(',', '.', $m['p']).') / 100';
        }

        if (preg_match('/^\s*(?<cue>cuanto\s+(?:es|da|son|seria)|calcula\w*|resolve\w*|cuenta(?:\s+de)?|resultado\s+de)?\s*(?<e>[\d\s.,+\-*\/x()^]+?)\s*$/', $t, $m)
            && preg_match('/\d\s*[+\-*\/x^]\s*[\d(]/', $m['e'])) {
            $conCue = ($m['cue'] ?? '') !== '';
            $e = trim($m['e']);

            // Sin la palabra, una «x» entre números son medidas, no una multiplicación.
            if (! $conCue && (preg_match('/\dx\d|\d\s+x\s+\d/', $e) || ! preg_match('/[+*\/^]|\d\s*-\s*\d/', $e))) {
                return null;
            }

            return str_replace(['x', '^'], ['*', '**'], $e);
        }

        return null;
    }

    /** La cuenta como la escribió la persona: «20% de 450», «1059,84 + 365,52». */
    private function cuentaLegible(string $texto): string
    {
        $t = mb_strtolower(Str::ascii($texto));
        $t = trim(preg_replace('/^\s*(?:cuanto\s+(?:es|da|son|seria)|calcula\w*|resolve\w*|cuenta(?:\s+de)?|resultado\s+de)\s*/', '', str_replace(['¿', '?', '='], ' ', $t)) ?? $t);

        return preg_replace('/\s+/', ' ', $t) ?? $t;
    }

    /* ------------------------------------------------------ filtros y empresas */

    /**
     * Los filtros que las reglas entendieron, para las preguntas de precio y de ranking.
     *
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function filtros(array $b): array
    {
        $f = array_intersect_key($b, array_flip(array_diff(ResolverDePedido::CLAVES, ['vista', 'texto', 'empresa', 'orden'])));
        unset($f['periodo']);

        if (($b['material'] ?? null) === null) {
            unset($f['material']);
        }

        return $f;
    }

    /** El nombre de la empresa de la que se habla (o su id si viene «(id 12)»), si se puede encontrar. */
    private function empresa(string $w, array $b): ?string
    {
        // «…ALFA METALES SA (id 5)»: lo que mandan las opciones para elegir una empresa (la normalización ya sacó los paréntesis).
        if (preg_match('/\bid\s+(\d+)\b/', $w, $m)) {
            return 'id:'.$m[1];
        }

        if (is_string($b['empresa'] ?? null) && $b['empresa'] !== '') {
            return $b['empresa'];
        }

        $cue = 'contame|contanos|decime|dame|mostrame|abri|abrime|ficha|informacion|info|datos|telefono|tel|celular|whatsapp|mail|correo|email|contactos?|direccion|domicilio|cuit|'
            .'quien\s+es|que\s+sabemos|ultima\s+cotizacion|ultima\s+vez|cotizamos|cotizaciones|cotizo|cotizacion|vendimos|facturamos|compro|compraron|compra|cuando\s+fue';

        // «de acme sa», «a acme», «la empresa acme»: lo que sigue, hasta una palabra que ya habla de otra cosa.
        $fin = 'en|de|del|durante|este|esta|el|la|los|las|desde|hasta|por|que|y|con|sin|para|cuando|cuanto|cuantos|monel|titanio';
        if (preg_match('/\b(?:'.$cue.')\b\s*(?:(?:de|del|a|al|para|con|sobre|la\s+empresa|el\s+cliente|a\s+la\s+empresa|la\s+firma)\s+)?(?<n>[a-z0-9&.\-]+(?:\s+[a-z0-9&.\-]+){0,4}?)(?=\s+(?:'.$fin.')\b|\s*$)/', $w, $m)) {
            $nombre = trim($m['n']);
            $nombre = preg_replace('/^(?:de|del|a|al|la|el|los|las|una?|empresa|cliente)\s+/', '', $nombre) ?? $nombre;
            $palabras = array_filter(explode(' ', $nombre), fn ($p) => ! in_array($p, ['de', 'del', 'la', 'el', 'una', 'un', 'empresa', 'cliente', 'proveedor', 'ultima', 'cotizacion', 'cotizaciones'], true) && mb_strlen($p) >= 2);

            // «cotizaciones de este mes», «las del año pasado»: un período no es una empresa.
            $esPeriodo = (bool) preg_match('/^(?:(?:este|esta|ese|esos|estos|el|la|los|las|del|de|ultimo|ultima|ultimos|ultimas|proximo|proxima)\s+)*(?:mes|meses|ano|anos|anio|anios|semana|semanas|trimestre|semestre|dia|dias|hoy|ayer|manana|pasado|pasada|20\d\d)(?:\s+(?:pasado|pasada|anterior|proximo|proxima|actual|que\s+viene))?$/', implode(' ', $palabras));

            if ($palabras !== [] && ! $esPeriodo && ! $this->esDelCatalogo(implode(' ', $palabras))) {
                return implode(' ', $palabras);
            }
        }

        return null;
    }

    private function aspectoDeEmpresa(string $w): string
    {
        return match (true) {
            (bool) preg_match('/\b(?:telefono|tel|celular|whatsapp|mail|correo|email|contactos?|llamar|hablar)\b/', $w) => 'contacto',
            (bool) preg_match('/\b(?:ultima\s+cotizacion|ultima\s+vez|cuando\s+(?:fue|le\s+cotizamos|se\s+le\s+cotizo))\b/', $w) => 'ultima',
            (bool) preg_match('/\b(?:cuanto\s+(?:le|les)\s+(?:cotizamos|vendimos|facturamos)|importe|monto|total)\b/', $w) => 'importes',
            (bool) preg_match('/\b(?:direccion|domicilio|donde\s+queda|ubicacion|cuit)\b/', $w) => 'datos',
            default => 'ficha',
        };
    }

    private function aspectoDeMaterial(string $w): string
    {
        return match (true) {
            (bool) preg_match('/\bdensidad\b/', $w) => 'densidad',
            (bool) preg_match('/\b(?:uns|w\.?\s?nr)\b/', $w) => 'normas',
            (bool) preg_match('/\b(?:alias|sinonimos?|como\s+se\s+llama)\b/', $w) => 'alias',
            default => 'ficha',
        };
    }

    /**
     * El material que se nombra y cómo se lo nombró: la clave más larga del catálogo (con letras) que aparece. Los números solos no son material.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function materialYSuClave(string $w, array $b): array
    {
        $mejor = null;
        $clave = null;
        $largo = 0;

        foreach ($this->catalogo->materiales() as $k => $canonico) {
            if (! preg_match('/[a-z]/', $k) || mb_strlen($k) <= $largo || in_array($k, self::NO_SON_MATERIAL, true) || ! str_contains(" {$w} ", " {$k} ")) {
                continue;
            }

            // Un alias que es una forma («agujas», «malla») no es el material.
            if ($this->catalogo->forma($k) !== null) {
                continue;
            }

            [$mejor, $clave, $largo] = [$canonico, $k, mb_strlen($k)];
        }

        // «acero inoxidable 304»: el nombre general («Acero Inoxidable») no alcanza; con el código es el 304.
        if ($mejor !== null && ($codigo = $this->codigoDeMaterial($w, (string) $clave))) {
            $porCodigo = $this->materialConCodigo($codigo, (string) $clave);

            if ($porCodigo !== null) {
                return [$porCodigo, trim($clave.' '.$codigo)];
            }
        }

        $reglas = $b['material'] ?? null;

        if ($mejor === null && is_string($reglas) && preg_match('/[a-z]/i', $reglas)) {
            return [$reglas, Texto::normalizar($reglas)];
        }

        return [$mejor, $clave];
    }

    /** El código que sigue al nombre del material («304» en «acero inoxidable 304»), si no es una medida ni parte del nombre. */
    private function codigoDeMaterial(string $w, string $clave): ?string
    {
        if (preg_match('/'.preg_quote($clave, '/').'\s+(?:(?:tipo|grado|gr|aisi|sae|astm|uns)\s+)?(?<c>[a-z]*\d[a-z0-9\-]*)(?![a-z0-9])(?!\s*(?:x|mm|cm|m|metros?|pulgadas?|pulg|in)\b)/', $w, $m)
            && ! str_contains($clave, $m['c'])) {
            // Tres o cuatro cifras sueltas, o un código con letras («316l», «c276»): no «2», que sería una medida.
            return preg_match('/^(?:\d{3,4}|[a-z]+\d+[a-z0-9\-]*|\d+[a-z]+[a-z0-9\-]*)$/', $m['c']) ? $m['c'] : null;
        }

        return null;
    }

    /** El material del catálogo que lleva ese código, preferido el que también nombra a la familia («aisi 304» antes que «acero inoxidable 304 hc»). */
    private function materialConCodigo(string $codigo, string $clave): ?string
    {
        $mejor = null;
        $largo = PHP_INT_MAX;

        foreach ($this->catalogo->materiales() as $k => $canonico) {
            if (! str_contains(" {$k} ", " {$codigo} ") || mb_strlen($k) >= $largo) {
                continue;
            }

            [$mejor, $largo] = [$canonico, mb_strlen($k)];
        }

        return $mejor;
    }

    /** ¿Es algo del catálogo —un material, una forma, una provincia, una localidad— y no el nombre de una empresa? */
    private function esDelCatalogo(string $nombre): bool
    {
        $n = Texto::normalizar($nombre);

        return isset($this->catalogo->materiales()[$n]) || $this->catalogo->forma($n) !== null
            || $this->catalogo->provincia($n) !== null && Texto::normalizar((string) $n) === $n && mb_strlen($n) > 3 && in_array($n, $this->catalogo->provinciasEn($n), true)
            || in_array($n, $this->catalogo->localidadesEn($n), true);
    }

    private function dimension(string $d): string
    {
        return match ($d) {
            'ano', 'anio' => 'anio', 'vendedor' => 'usuario', default => $d,
        };
    }

    /** De qué se está hablando cuando se cuenta: empresas, cotizaciones, renglones o facturas. */
    private function vistaDeLaPregunta(string $w, string $por): string
    {
        return match (true) {
            (bool) preg_match('/\b(?:facturas?|remitos?|comprobantes?)\b/', $w) => 'comprobantes',
            (bool) preg_match('/\b(?:renglon(?:es)?|items?|material\s+cotizado|lo\s+cotizado)\b/', $w) || in_array($por, ['material', 'forma'], true) => 'renglones',
            // «cuántas cotizaciones por provincia»: se cuentan cotizaciones (no empresas) repartidas por la provincia de la empresa.
            $por === 'provincia' && preg_match('/\b(?:cotizaci\w*|pedidos?|presupuestos?)\b/', $w) && ! preg_match('/\b(?:empresas?|clientes?)\b/', $w) => 'consultas',
            (bool) preg_match('/\b(?:empresas?|clientes?|proveedor(?:es)?)\b/', $w) && in_array($por, ['provincia', 'localidad', 'rubro'], true) => 'empresas',
            in_array($por, ['provincia', 'localidad', 'rubro'], true) => 'empresas',
            default => 'consultas',
        };
    }

    /** @param  array<string, mixed>  $b */
    private function rankingPor(string $w, array $b): string
    {
        return match (true) {
            (bool) preg_match('/\b(?:materiales?|aleaciones?)\b/', $w) && ! isset($b['material']) => 'material',
            (bool) preg_match('/\bformas?\b/', $w) && ! isset($b['forma']) => 'forma',
            (bool) preg_match('/\b(?:vendedor\w*|usuarios?|quien\s+cotiz\w*)\b/', $w) => 'usuario',
            default => 'empresa',
        };
    }
}

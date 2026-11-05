<?php

namespace App\Services\Cordes;

use App\Models\Forma;
use App\Models\Localidad;
use App\Models\Provincia;
use App\Models\Rubro;
use App\Models\User;
use App\Services\Buscador\Catalogo;
use App\Services\Buscador\InterpretePorReglas;
use App\Services\Buscador\Texto;

/**
 * Resuelve CON EL CÓDIGO las preguntas que juntan varias cosas, sin IA: comparar dos piezas o dos materiales, sumar varios ítems,
 * "cuánto pesa y cuánto cuesta", cuántas piezas salen de una chapa, un peso que ya se sabe por una cantidad de piezas, dos listas
 * una contra la otra. Es lo que contesta cuando la IA no está o no pudo.
 *
 * Cómo: parte la pregunta en pedazos (cada ítem, cada pregunta), completa lo que le falta a cada pedazo con lo que dice el otro
 * ("una de 20 mm" lleva el material y la forma del primero), le pasa cada pedazo al motor (Entiende + Redactor: las mismas fórmulas,
 * las mismas densidades) y arma la respuesta con las cuentas que salen de esos resultados. Nunca inventa un número: todo es de una
 * consulta o de una cuenta sobre ellas. Y siempre dice cómo entendió cada parte, para que se pueda corregir.
 *
 * Si no está seguro de cómo partir la pregunta devuelve null: no adivina (entonces sigue la búsqueda por texto).
 */
final class Descompone
{
    private const COSAS = 'barras?|chapas?|tubos?|canos?|discos?|bridas?|aros?|anillos?|perfiles?|alambres?|esferas?|arandelas?|planchuelas?|platinas?|varillas?|rollos?|bobinas?|piezas?|unidades?|codos?|curvas?|bulones?|tornillos?|tuercas?';

    private const CANTIDAD = '(?:\d+|un|una|uno|otra|otro|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|doce)';

    /** Lo que separa un ítem de otro. «con» y «que» sólo cuentan si lo que sigue arranca un ítem nuevo. */
    private const CONECTOR = '(?:contra|versus|vs|comparad[oa]s?\s+con|frente\s+a|respecto\s+(?:de|a)|y|e|o|u|que|con|mas)';

    /** Palabras de la pregunta que no son parte de ningún ítem. */
    private const RUIDO = '/\b(?:compar\w*|diferenc\w*|difier\w*|cuanto|cuantos|cuantas|cual|cuales|que|mas|menos|pesa\w*|peso|pesos|entre|igual(?:es)?|mism[oa]s?|identic\w+|lo|total|tambien|ademas|el|la|los|las|decime|dame|contame|necesito|quiero|saber)\b/';

    private readonly Entiende $entiende;

    private readonly Redactor $redactor;

    private readonly Herramientas $h;

    private readonly InterpretePorReglas $reglas;

    /** @var (callable(string): void)|null */
    private $alPaso;

    public function __construct(private readonly User $usuario, private readonly Catalogo $catalogo, ?callable $alPaso = null)
    {
        $this->entiende = new Entiende($catalogo);
        $this->redactor = new Redactor($usuario, $catalogo);
        $this->h = new Herramientas($usuario, $catalogo);
        $this->reglas = new InterpretePorReglas($catalogo);
        $this->alPaso = $alPaso;
    }

    /** @return array<string, mixed>|null una respuesta completa, o null si no se pudo partir la pregunta con seguridad */
    public function resolver(string $texto): ?array
    {
        $texto = trim(preg_replace('/\s+/', ' ', preg_replace('/\s*\+\s*/', ' y ', mb_substr($texto, 0, 400)) ?? '') ?? '');
        $w = Medidas::limpiar($texto);
        $pedazos = $this->pedazosInterrogativos($texto);

        $estrategias = count($pedazos) >= 2 ? [] : [
            fn () => $this->rendimiento($w),
            fn () => $this->pesoYaConocido($w),
        ];

        if (count($pedazos) >= 2) {
            // Varias preguntas escritas juntas: cada una al motor, con lo que le falta tomado de las otras.
            $estrategias[] = fn () => $this->variasPreguntas($pedazos);
        } else {
            // Una sola pregunta con varias cosas adentro: comparar, sumar; o lo mismo pedido de dos cosas («densidad del monel y del inconel»).
            $estrategias[] = fn () => $this->comparar($w);
            $estrategias[] = fn () => $this->variasPreguntas($pedazos !== [] ? $pedazos : [$w]);
        }

        foreach ($estrategias as $estrategia) {
            try {
                $r = $estrategia();
            } catch (\Throwable $e) {
                report($e);
                $r = null;
            }

            if ($r !== null) {
                return $r;
            }
        }

        return null;
    }

    private function paso(string $texto): void
    {
        $this->alPaso && ($this->alPaso)($texto);
    }

    /** @param  array<string, mixed>  $sobre */
    private function resp(array $sobre): array
    {
        return $sobre + [
            'fuente' => 'codigo', 'mensaje' => null, 'verificada' => true, 'aviso' => null, 'pedido' => null, 'borrador' => null,
            'tablas' => [], 'atajos' => [], 'opciones' => null, 'seguir' => [], 'no_entendi' => [], 'pasos' => [], 'analisis' => null,
        ];
    }

    private function n(float|int|null $v, int $dec = 2): string
    {
        return $v === null ? '—' : number_format((float) $v, $dec, ',', '.');
    }

    /** Un número sin ceros de más: 4 y no 4,00; 38,1 y no 38,10. */
    private function m(float $v, int $dec = 2): string
    {
        $t = number_format($v, $dec, ',', '.');

        return str_contains($t, ',') ? rtrim(rtrim($t, '0'), ',') : $t;
    }

    /* ============================================================ rendimiento de una chapa */

    /** «¿Cuántas piezas de 150 x 150 salen de una chapa de 1000 x 2000?»: filas rectas, de las dos maneras de acostar la pieza. */
    private function rendimiento(string $w, ?array $chapa = null): ?array
    {
        $n = Medidas::NUM;
        $u = Medidas::UNI;
        $sale = '(?:se\s+(?:pueden\s+)?(?:sacan?|obtienen?|cortan?)|puedo\s+sacar|salen?|entran?|caben?|sacar|obtengo|sacamos)';
        $placa = '(?:una?\s+)?(?:chapa|placa|plancha|lamina|planchuela|platina|hoja)?\s*(?:de\s+)?';

        $a = $b = $c = $d = $ua = $ub = null;

        if (preg_match("/cuant[oa]s?\\s+(?:piezas?|cortes?|unidades?|pedazos?|recortes?|cuadrados?|rectangulos?|discos?)?\\s*(?:de\\s+)?(?<a>{$n})\\s*(?:x|por)\\s*(?<b>{$n})\\s*(?<ua>{$u})?\\s+{$sale}\\s+(?:de|en)\\s+{$placa}(?<c>{$n})\\s*(?:x|por)\\s*(?<d>{$n})\\s*(?<ub>{$u})?/", $w, $m)) {
            [$a, $b, $c, $d, $ua, $ub] = [$m['a'], $m['b'], $m['c'], $m['d'], $m['ua'] ?? null, $m['ub'] ?? null];
        } elseif (preg_match("/cuant[oa]s?\\s+(?:piezas?|cortes?|unidades?|pedazos?)?\\s*{$sale}\\s+(?:de|en)\\s+{$placa}(?<c>{$n})\\s*(?:x|por)\\s*(?<d>{$n})\\s*(?<ub>{$u})?\\s*(?:si\\s+(?:son|miden|las\\s+piezas\\s+son)|de|con\\s+piezas\\s+de|cortando\\s+(?:de)?)\\s*(?<a>{$n})\\s*(?:x|por)\\s*(?<b>{$n})\\s*(?<ua>{$u})?/", $w, $m)) {
            [$a, $b, $c, $d, $ua, $ub] = [$m['a'], $m['b'], $m['c'], $m['d'], $m['ua'] ?? null, $m['ub'] ?? null];
        } elseif ($chapa !== null && preg_match("/cuant[oa]s?\\s+(?:piezas?|cortes?|unidades?|pedazos?|recortes?|cuadrados?|rectangulos?|discos?)?\\s*(?:de\\s+)?(?<a>{$n})\\s*(?:x|por)\\s*(?<b>{$n})\\s*(?<ua>{$u})?\\s+{$sale}\\s*$/", $w, $m)) {
            // «¿y cuántas piezas de 200 x 200 salen?»: de la chapa de la que se venía hablando.
            [$a, $b, $c, $d, $ua, $ub] = [$m['a'], $m['b'], (string) $chapa[0], (string) $chapa[1], $m['ua'] ?? null, 'mm'];
        }

        if ($a === null) {
            return null;
        }

        $this->paso('Calculando cuántas piezas entran…');
        $aMm = function (string $v, ?string $unidad, bool $metrosSiEsChico): float {
            $x = (float) (Medidas::numero($v) ?? 0);
            $u = $unidad !== null && $unidad !== '' ? Medidas::unidad($unidad) : null;

            return $u !== null ? Medidas::enMm($x, $u) : ($metrosSiEsChico ? $x * 1000 : $x);
        };

        $chico = (float) (Medidas::numero((string) $c) ?? 0) <= 12 && (float) (Medidas::numero((string) $d) ?? 0) <= 12 && ($ub === null || $ub === '');
        [$pa, $pb] = [$aMm($a, $ua ?: null, false), $aMm($b, $ua ?: null, false)];
        [$pc, $pd] = [$aMm($c, $ub ?: null, $chico), $aMm($d, $ub ?: null, $chico)];

        if (min($pa, $pb, $pc, $pd) <= 0) {
            return null;
        }

        $uno = (int) floor($pc / $pa + 1e-9) * (int) floor($pd / $pb + 1e-9);
        $dos = (int) floor($pc / $pb + 1e-9) * (int) floor($pd / $pa + 1e-9);
        $mejor = max($uno, $dos);

        if ($mejor === 0) {
            return $this->resp(['mensaje' => "La pieza de **{$this->m($pa)} × {$this->m($pb)} mm** no entra en una chapa de **{$this->m($pc)} × {$this->m($pd)} mm** (ni acostada de las dos maneras)."]);
        }

        $aprovecha = $mejor * $pa * $pb / ($pc * $pd) * 100;
        $maneras = [
            [(int) floor($pc / $pa + 1e-9), (int) floor($pd / $pb + 1e-9), $uno, "{$this->m($pa)} a lo ancho (de {$this->m($pc)}) y {$this->m($pb)} a lo largo (de {$this->m($pd)})"],
            [(int) floor($pc / $pb + 1e-9), (int) floor($pd / $pa + 1e-9), $dos, "{$this->m($pb)} a lo ancho (de {$this->m($pc)}) y {$this->m($pa)} a lo largo (de {$this->m($pd)})"],
        ];
        $distintas = $pa !== $pb;
        $frase = "En una chapa de **{$this->m($pc)} × {$this->m($pd)} mm** entran **{$mejor} piezas** de **{$this->m($pa)} × {$this->m($pb)} mm**"
            .($distintas && $uno !== $dos ? ' (acostando la pieza de la mejor manera)' : '').", y se aprovecha el **{$this->m($aprovecha, 1)} %** de la chapa.";

        return $this->resp([
            '_chapa' => [$pc, $pd],
            'mensaje' => $frase."\n*Es el corte en filas rectas, sin descontar el espesor de la sierra ni el borde de la chapa.*",
            'tablas' => [[
                'titulo' => 'Cómo entran', 'columnas' => ['Pieza acostada', 'Filas', 'Columnas', 'Piezas'],
                'filas' => array_map(fn ($x) => [$x[3], (string) $x[1], (string) $x[0], (string) $x[2]], $distintas ? $maneras : [$maneras[0]]),
                'acciones' => array_fill(0, $distintas ? 2 : 1, []),
            ]],
            'seguir' => ['cuánto pesa una chapa de monel de 3 x 1000 x 2000', 'cuánto pesa una barra de monel de 20 x 1000'],
        ]);
    }

    /* ======================================================== un peso que ya se sabe, por una cantidad */

    /** «Si una pieza pesa 2,7646 kg y son 12, ¿cuánto pesan en total?» */
    private function pesoYaConocido(string $w): ?array
    {
        $n = Medidas::NUM;
        $masa = '(?<u>kg|kgs|kilos?|kilogramos?|g|gr|gramos?|lb|lbs|libras?|ton|tn|toneladas?)';

        if (! preg_match("/(?:pesa\\w*|pesan|pesaria|peso\\s+de)\\s+(?:\\w+\\s+){0,2}?(?<p>{$n})\\s*{$masa}(?![a-z])/", $w, $p)
            && ! preg_match("/(?<p>{$n})\\s*{$masa}(?![a-z])\\s*(?:cada\\s+una|c\\/?u|la\\s+pieza|por\\s+pieza|cada\\s+pieza|la\\s+unidad|por\\s+unidad)/", $w, $p)) {
            return null;
        }

        $cosas = 'piezas?|unidades?|barras?|chapas?|tubos?|canos?|discos?|bridas?|aros?|anillos?|perfiles?|rollos?|bolsas?|cajas?|u\b';

        if (! preg_match("/(?:son|tengo|hay|compro|pido|pedimos|necesito|llevo|vendo|mando|cortamos|armo)\\s+(?<c>\\d+)\\b(?:\\s*(?:{$cosas}))?/", $w, $c)
            && ! preg_match("/(?<c>\\d+)\\s*(?:{$cosas})(?![a-z])/", preg_replace("/{$n}\\s*{$masa}/", ' ', $w) ?? $w, $c)) {
            return null;
        }

        $valor = Medidas::numero($p['p']);
        $cantidad = (float) $c['c'];

        if ($valor === null || $valor <= 0 || $cantidad <= 0 || $cantidad > 1_000_000) {
            return null;
        }

        $this->paso('Haciendo la cuenta…');
        $aKg = fn (string $u) => match (true) {
            in_array($u, ['g', 'gr'], true) || str_starts_with($u, 'gram') => 0.001,
            in_array($u, ['lb', 'lbs'], true) || str_starts_with($u, 'libra') => 0.45359237,
            in_array($u, ['ton', 'tn'], true) || str_starts_with($u, 'tonel') => 1000.0,
            default => 1.0,
        };
        $kgPieza = $valor * $aKg($p['u']);
        $total = $kgPieza * $cantidad;
        $original = $this->m($valor, 4).' '.$p['u'];

        return $this->resp([
            'mensaje' => "**{$this->m($cantidad, 0)}** piezas de **{$original}** pesan **{$this->n($total, 4)} kg** en total ({$this->m($cantidad, 0)} × {$this->n($kgPieza, 4)} kg).",
            'seguir' => ['cuánto pesa una barra de monel de 38,1 x 145 mm', 'cuántos kg son 12 libras'],
        ]);
    }

    /* ======================================================================= varias preguntas en una */

    /** Lo que abre una pregunta nueva: «cuánto…», «qué es…», «tabla de…», «fórmula…», «dame…». */
    private const CUES = '(?:tambien\s+)?(?:cuant\w*|cual\w*|que\s|quien\w*|como\s|donde|cuando|a\s+cuanto|dame|decime|contame|mostrame|pasame|dime|el\s+precio|el\s+peso|la\s+formula|su\s+precio|su\s+peso|tabla\b|formula\b|densidad\b|ranking\b)';

    /** El principio de una pregunta (qué se pregunta) separado de su asunto (de qué): «cuánto pesa» + «una barra de monel…». */
    private const CUE_INICIAL = '/^(?:(?:y|tambien|ademas)\s+)*(?<cue>(?:a\s+)?cuant[oa]s?(?:\s+(?:mas|menos))?\s+(?:pesa\w*|cuesta\w*|sale\w*|vale\w*|cotiz\w*|cobr\w*|mide\w*)|cuant[oa]s?\s+(?:kilos?|kgs?)\s+(?:pesa\w*|de|hay)|que\s+(?:es|son)|cual\s+(?:es|son)(?:\s+(?:el|la|los|las))?(?:\s+\w+)?|dame|decime|contame|mostrame|pasame|dime|tabla|formula|densidad|precio|peso|ranking)\b(?<sujeto>.*)$/';

    /**
     * Las preguntas que vienen juntas: una por «?» y una por cada «y cuánto…», «y qué…», «y la fórmula…».
     *
     * @return list<string> ya normalizadas
     */
    private function pedazosInterrogativos(string $texto): array
    {
        $piezas = [];

        foreach (preg_split('/\?+\s*¿?/u', $texto) ?: [$texto] as $bloque) {
            $bloque = trim($bloque, " \t\n¿?.,;");

            if ($bloque === '') {
                continue;
            }

            foreach (preg_split('/\s+(?:y|e|ademas|tambien|despues)\s+(?='.self::CUES.')/', Medidas::limpiar($bloque)) ?: [] as $pedazo) {
                if (trim($pedazo) !== '') {
                    $piezas[] = trim($pedazo);
                }
            }
        }

        return $piezas;
    }

    /**
     * «Densidad del monel y del inconel 625» son dos preguntas: la segunda lleva lo mismo que la primera, con la otra cosa.
     *
     * @param  list<string>  $pedazos
     * @return list<string>
     */
    private function expandirCoordinados(array $pedazos): array
    {
        $salida = [];

        foreach ($pedazos as $p) {
            $salida = array_merge($salida, $this->entiende->coordinados($p) ?? [$p]);
        }

        return $salida;
    }

    /**
     * «Cuánto pesa… y cuánto cuesta»: el «cuánto cuesta» no dice de qué; es de lo mismo. Se le agrega el asunto del pedazo más cercano que lo tenga.
     *
     * @param  list<string>  $pedazos
     * @return list<string>
     */
    private function conAsunto(array $pedazos): array
    {
        $partes = [];

        foreach ($pedazos as $i => $p) {
            if (preg_match(self::CUE_INICIAL, $p, $m)) {
                $asunto = trim($m['sujeto']);
                $relleno = '(?:de|del|la|el|lo|los|las|eso|esto|ese|esa|tambien|todo|en|total|juntos?|juntas?)';
                $partes[$i] = ['asunto' => preg_match('/^'.$relleno.'(?:\s+'.$relleno.')*$/', $asunto) ? '' : $asunto, 'tiene_cue' => true];
            } else {
                $partes[$i] = ['asunto' => '', 'tiene_cue' => false];
            }
        }

        foreach ($partes as $i => $parte) {
            if (! $parte['tiene_cue'] || $parte['asunto'] !== '' || preg_match('/\b(?:eso|esto|ese|esa|lo\s+mismo)\b/', $pedazos[$i])) {
                continue;
            }

            for ($d = 1; $d < count($partes); $d++) {
                foreach ([$i - $d, $i + $d] as $j) {
                    if (isset($partes[$j]) && $partes[$j]['asunto'] !== '') {
                        $pedazos[$i] = trim($pedazos[$i].' '.$partes[$j]['asunto']);
                        break 2;
                    }
                }
            }
        }

        // «Qué es el monel y cuánto pesa una barra de 20 x 1000»: el material es el del otro pedazo, si éste no dice ninguno.
        foreach ($pedazos as $i => $p) {
            if (! preg_match('/^(?:y\s+|tambien\s+)*(?:(?:a\s+)?cuant[oa]s?\s+(?:mas\s+|menos\s+)?(?:pesa\w*|cuesta\w*|sale\b|vale\w*|cotiz\w*|cobr\w*)|(?:el\s+)?(?:precio|peso)\b)/', $p) || $this->entiende->materialesDe($p) !== []) {
                continue;
            }

            foreach ($pedazos as $j => $otro) {
                if ($j !== $i && ($clave = $this->entiende->claveDeMaterial($otro)) !== null) {
                    $pedazos[$i] = trim($p.' de '.$clave);
                    break;
                }
            }
        }

        return array_values($pedazos);
    }

    /** «¿Y cuánto es eso en libras?»: el último peso, en otra unidad. */
    private function convertirLoAnterior(string $parte, float $kg): ?string
    {
        if (! preg_match('/^(?:y\s+)?(?:cuant[oa]s?\s+(?:es|son|seria|serian|da|vale|pesa)|a\s+cuanto\s+equivale|pasalo|pasame|convertilo|en\s+cuanto\s+es)\s+(?:eso|esto|ese|esa|todo\s+eso|lo\s+mismo)?\s*(?:en|a)\s+(?<u>[a-z]+)\b/', $parte, $m)
            && ! preg_match('/^(?:y\s+)?cuant[oa]s?\s+(?<u>libras?|gramos?|toneladas?|kilos?|kilogramos?|lbs?|kgs?)\s*$/', $parte, $m)) {
            return null;
        }

        [$factor, $nombre] = match (true) {
            str_starts_with($m['u'], 'libra') || in_array($m['u'], ['lb', 'lbs'], true) => [1 / 0.45359237, 'lb'],
            str_starts_with($m['u'], 'gram') || in_array($m['u'], ['g', 'gr'], true) => [1000.0, 'g'],
            str_starts_with($m['u'], 'tonel') || in_array($m['u'], ['ton', 'tn'], true) => [0.001, 't'],
            str_starts_with($m['u'], 'kilo') || $m['u'] === 'kg' => [1.0, 'kg'],
            default => [null, ''],
        };

        return $factor === null ? null : "**{$this->n($kg, 4)} kg** son **{$this->n($kg * $factor, 4)} {$nombre}**.";
    }

    /**
     * Una lista que las reglas entienden («cuántas empresas hay en Córdoba»), contada con el mismo buscador de la pantalla.
     *
     * @return array{mensaje: string, atajo: array<string, mixed>, pedido: array<string, string>}|null
     */
    private function contarLista(string $parte, ?string &$sustantivo): ?array
    {
        $tiene = preg_match('/\b(cotizaciones|pedidos|empresas|clientes|facturas|remitos|renglones)\b/', $parte, $sm);
        $tiene && $sustantivo = $sm[1];
        $texto = $tiene || $sustantivo === null ? $parte : $sustantivo.' '.$parte;
        $r = $this->reglas->interpretar($texto);
        $filtros = array_diff_key($r['borrador'], ['vista' => 1, 'texto' => 1]);

        if ($filtros === [] && ! isset($r['borrador']['vista'])) {
            return null;
        }

        $vista = $r['borrador']['vista'] ?? $this->vistaDeLaFrase($texto);
        $res = $this->h->ejecutar('buscar', ['vista' => $vista, 'filtros' => $filtros, 'limite' => 1]);

        if (isset($res['error'])) {
            return null;
        }

        $cosa = ['empresas' => 'empresas', 'consultas' => 'cotizaciones y pedidos', 'renglones' => 'renglones', 'comprobantes' => 'facturas y remitos'][$vista] ?? 'resultados';
        $etiqueta = $filtros !== [] ? $this->etiquetaDeLado($filtros, [], $parte) : 'En total';
        $pedido = $res['filtros_aplicados'] ?? [];

        return [
            'mensaje' => "**{$etiqueta}**: **{$this->n((int) $res['total'], 0)}** {$cosa}.",
            'atajo' => ['etiqueta' => 'Ver: '.mb_substr($etiqueta, 0, 30).' ('.$this->n((int) $res['total'], 0).')', 'icono' => 'lista', 'accion' => ['tipo' => 'pedido', 'pedido' => $pedido]],
            'pedido' => $pedido,
        ];
    }

    /**
     * Varias preguntas en una: cada una al motor, y lo que a una le falta (de qué material, de qué chapa) lo toma de las otras.
     *
     * @param  list<string>  $pedazos
     */
    private function variasPreguntas(array $pedazos): ?array
    {
        $pedazos = $this->expandirCoordinados($pedazos);

        if (count($pedazos) < 2 || count($pedazos) > 6) {
            return null;
        }

        $this->paso('Separando la pregunta en partes…');
        $pedazos = $this->conAsunto($pedazos);
        $mensajes = [];
        $tablas = [];
        $atajos = [];
        $seguir = [];
        $pedido = null;
        $noEntendi = [];
        $opciones = null;
        $ultimoKg = null;
        $chapa = null;
        $sustantivo = null;
        $entendidas = 0;

        foreach ($pedazos as $i => $parte) {
            $this->paso('Resolviendo la parte '.($i + 1).' de '.count($pedazos).'…');

            if ($ultimoKg !== null && ($texto = $this->convertirLoAnterior($parte, $ultimoKg)) !== null) {
                $mensajes[] = '*Entendí: «'.mb_substr($parte, 0, 90).'»*'."\n".$texto;
                $entendidas++;

                continue;
            }

            if (($rend = $this->rendimiento($parte, $chapa)) !== null) {
                $mensajes[] = '*Entendí: «'.mb_substr($parte, 0, 90).'»*'."\n".$rend['mensaje'];
                $tablas = array_merge($tablas, array_slice($rend['tablas'], 0, 1));
                $chapa = $rend['_chapa'] ?? $chapa;
                $entendidas++;

                continue;
            }

            // «¿Y cuánto pesa esa chapa de monel de 3 mm?»: «esa» es la chapa de la que se venía hablando.
            if ($chapa !== null && preg_match('/\b(?:esa|esta|dicha|la\s+misma)\s+(?:chapa|placa|plancha|lamina)\b/', $parte)) {
                $parte = trim($parte.' '.$this->m($chapa[0]).' x '.$this->m($chapa[1]).' mm');
            }

            $plan = $this->entiende->plan($parte);
            $r = $plan !== null ? $this->redactor->redactar($plan) : null;

            // «Cuántas empresas hay en Córdoba»: no es una pregunta del motor, es una lista: se cuenta.
            if ($r === null && ($lista = $this->contarLista($parte, $sustantivo)) !== null) {
                $mensajes[] = '*Entendí: «'.mb_substr($parte, 0, 90).'»*'."\n".$lista['mensaje'];
                $atajos[] = $lista['atajo'];
                $pedido ??= $lista['pedido'];
                $entendidas++;

                continue;
            }

            if ($r === null) {
                $mensajes[] = '**«'.mb_substr($parte, 0, 80).'»**: no pude armar esta parte; la podés mandar sola con el botón de abajo.';
                $atajos[] = ['etiqueta' => 'Preguntar: '.mb_substr($parte, 0, 40), 'icono' => 'pregunta', 'accion' => ['tipo' => 'preguntar', 'texto' => $parte]];
                $noEntendi[] = $parte;

                continue;
            }

            $entendidas++;
            $mensajes[] = '*Entendí: «'.mb_substr($parte, 0, 90).'»*'."\n".trim((string) ($r['mensaje'] ?? ''));
            $tablas = array_merge($tablas, array_slice($r['tablas'] ?? [], 0, 1));
            $atajos = array_merge($atajos, $r['atajos'] ?? []);
            $seguir = array_merge($seguir, $r['seguir'] ?? []);
            $pedido ??= $r['pedido'] ?? null;
            $opciones ??= $r['opciones'] ?? null;

            if (isset($r['_calculo']['kg_total']) && ! $r['_calculo']['por_metro']) {
                $ultimoKg = (float) $r['_calculo']['kg_total'];
            }

            $medidas = $plan['datos']['medidas'] ?? [];

            if (isset($medidas['width'], $medidas['length'])) {
                $chapa = [Medidas::enMm((float) $medidas['width']['valor'], $medidas['width']['unidad']), Medidas::enMm((float) $medidas['length']['valor'], $medidas['length']['unidad'])];
            }
        }

        if ($entendidas === 0) {
            return null;
        }

        return $this->resp([
            'mensaje' => implode("\n\n", array_filter($mensajes, fn ($m) => $m !== '')),
            'tablas' => array_slice($tablas, 0, 3),
            'atajos' => $this->sinRepetir($atajos),
            'opciones' => $opciones,
            'seguir' => array_slice(array_values(array_unique($seguir)), 0, 3),
            'pedido' => $pedido,
            'no_entendi' => $noEntendi,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $atajos
     * @return list<array<string, mixed>>
     */
    private function sinRepetir(array $atajos): array
    {
        $vistos = [];
        $salida = [];

        foreach ($atajos as $a) {
            $clave = ($a['etiqueta'] ?? '').'|'.json_encode($a['accion'] ?? []);

            if (! isset($vistos[$clave])) {
                $vistos[$clave] = true;
                $salida[] = $a;
            }
        }

        return array_slice($salida, 0, Respuesta::MAX_ATAJOS);
    }

    /* =============================================================================== comparar */

    private function comparar(string $w): ?array
    {
        $pesa = (bool) preg_match('/\b(?:pesa\w*|pesad\w*|livian\w*|peso|pesos|kilos?|kgs?|kilogramos?)\b/', $w);
        $precio = (bool) preg_match('/\b(?:precios?|cuest\w*|sale|salen|vale|valen|caro|cara|baratos?|baratas?|a\s+cuanto)\b/', $w);

        $intentos = match (true) {
            $pesa && ! $precio => [fn () => $this->compararPesos($w), fn () => $this->compararMateriales($w)],
            $precio && ! $pesa => [fn () => $this->compararPrecios($w)],
            $pesa && $precio => [],
            default => [fn () => $this->compararPesosSiHayMedidas($w), fn () => $this->compararMateriales($w), fn () => $this->compararFormas($w), fn () => $this->compararListas($w)],
        };

        foreach ($intentos as $intento) {
            try {
                $r = $intento();
            } catch (\Throwable $e) {
                report($e);
                $r = null;
            }

            if ($r !== null) {
                return $r;
            }
        }

        return null;
    }

    /** «Diferencia entre el monel y el inconel 625»: sus datos del catálogo, lado a lado. */
    private function compararMateriales(string $w): ?array
    {
        if (preg_match('/\b(?:cotizaciones?|pedidos?|empresas?|clientes?|facturas?|remitos?|renglones?|precios?|ranking)\b/', $w) || preg_match('/\d+(?:[.,]\d+)?\s*(?:x|mm|cm|m|pulgadas?)\b/', $w)) {
            return null;
        }

        $claves = array_column($this->entiendeClaves($w), 'canonico');

        if (count(array_unique($claves)) === 1) {
            return $this->preguntarLaFamilia($w);
        }

        if (count(array_unique($claves)) < 2 || count(array_unique($claves)) > 5) {
            return null;
        }

        $this->paso('Mirando los materiales del catálogo…');
        $datos = [];

        foreach (array_unique($claves) as $nombre) {
            $r = $this->h->ejecutar('material', ['texto' => $nombre]);
            $m = collect($r['materiales'] ?? [])->first(fn ($x) => Texto::normalizar($x['nombre']) === Texto::normalizar($nombre)) ?? ($r['materiales'][0] ?? null);

            if ($m === null) {
                return null;
            }

            $datos[] = $m;
        }

        $f = fn (string $titulo, callable $valor) => array_merge([$titulo], array_map($valor, $datos));
        $filas = [
            $f('Familia', fn ($m) => (string) ($m['familia'] ?? '—')),
            $f('Densidad (g/cm³)', fn ($m) => $m['densidad_g_cm3'] !== null ? $this->n($m['densidad_g_cm3'], 4) : '—'),
            $f('UNS', fn ($m) => (string) ($m['uns'] ?? '—')),
            $f('W.Nr', fn ($m) => (string) ($m['w_nr'] ?? '—')),
            $f('Renglones cotizados', fn ($m) => $this->n($m['renglones_cotizados'], 0)),
        ];
        $conDensidad = array_values(array_filter($datos, fn ($m) => $m['densidad_g_cm3'] !== null));
        $cierre = '';

        if (count($conDensidad) >= 2) {
            usort($conDensidad, fn ($a, $b) => $b['densidad_g_cm3'] <=> $a['densidad_g_cm3']);
            $mayor = $conDensidad[0];
            $menor = $conDensidad[count($conDensidad) - 1];
            $cierre = $mayor['densidad_g_cm3'] === $menor['densidad_g_cm3']
                ? "\nTienen **la misma densidad**."
                : "\nEl más denso es **{$mayor['nombre']}** (**{$this->n($mayor['densidad_g_cm3'], 4)} g/cm³**): a igual medida pesa **{$this->m($mayor['densidad_g_cm3'] / $menor['densidad_g_cm3'])} veces** lo que **{$menor['nombre']}**.";
        }

        return $this->resp([
            'mensaje' => 'Comparo '.implode(' y ', array_map(fn ($m) => "**{$m['nombre']}**", $datos)).' con los datos del catálogo.'.$cierre,
            'tablas' => [['titulo' => 'Materiales lado a lado', 'columnas' => array_merge(['Dato'], array_map(fn ($m) => (string) $m['nombre'], $datos)), 'filas' => $filas, 'acciones' => array_fill(0, count($filas), [])]],
            'seguir' => ['cuánto pesa una barra de '.$datos[0]['nombre'].' de 20 x 1000 mm', 'a cuánto cotizamos '.$datos[0]['nombre']],
        ]);
    }

    /**
     * «Monel contra inconel»: «inconel» no es un material, es una familia (600, 625, X-750…). Se pregunta cuál, con botones.
     */
    private function preguntarLaFamilia(string $w): ?array
    {
        $partes = preg_split('/\s+(?:contra|versus|vs|con|y|o|u|e)\s+/', preg_replace('/\b(?:compar\w*|diferenc\w*|difier\w*|entre|cual|cuales|que|es|son|mas|menos|pesado|pesada|denso|densa|liviano|liviana)\b/', ' ', $w) ?? $w) ?: [];

        foreach ($partes as $p) {
            $p = trim(preg_replace('/\s+/', ' ', $p) ?? $p);

            if (mb_strlen($p) < 4 || $this->entiende->materialesDe($p) !== []) {
                continue;
            }

            $r = $this->h->ejecutar('material', ['texto' => $p]);
            $materiales = array_slice(array_values(array_filter($r['materiales'] ?? [], fn ($m) => str_contains(Texto::normalizar($m['nombre']), $p))), 0, 6);

            if (count($materiales) >= 2) {
                usort($materiales, fn ($a, $b) => $b['renglones_cotizados'] <=> $a['renglones_cotizados']);

                return $this->resp([
                    'mensaje' => "«{$p}» no es un solo material: hay varios. ¿Cuál querés comparar?",
                    'opciones' => ['pregunta' => '¿Cuál?', 'opciones' => array_map(fn ($m) => ['etiqueta' => $m['nombre'], 'enviar' => str_replace($p, Texto::normalizar($m['nombre']), $w)], $materiales)],
                ]);
            }
        }

        return null;
    }

    /** Los materiales que se nombran, con cómo se llaman en el catálogo. */
    private function entiendeClaves(string $w): array
    {
        return array_map(fn ($n) => ['canonico' => $n], $this->entiende->materialesDe($w));
    }

    /** «Diferencia entre barra redonda y barra cuadrada»: qué medidas lleva cada una y cómo se calcula. */
    private function compararFormas(string $w): ?array
    {
        if (preg_match('/\b(?:cotizaciones?|pedidos?|empresas?|clientes?|facturas?|remitos?|renglones?|precios?|ranking)\b/', $w) || preg_match('/\d/', $w)) {
            return null;
        }

        $partes = preg_split('/\s+(?:contra|versus|vs|y|e|o|u|con|que)\s+/', preg_replace('/\b(?:compar\w*|diferenc\w*|difier\w*|entre|la|el|las|los|de|del)\b/', ' ', $w) ?? $w) ?: [];
        $ids = [];

        foreach ($partes as $p) {
            $p = trim(preg_replace('/\s+/', ' ', $p) ?? $p);
            $id = $p !== '' ? $this->catalogo->forma($p) : null;
            $id !== null && $ids[$id] = true;
        }

        if (count($ids) < 2 || count($ids) > 4) {
            return null;
        }

        $this->paso('Mirando las fórmulas de las formas…');
        $formas = Forma::query()->whereIn('id', array_keys($ids))->get();
        $filas = [];

        foreach ($formas as $f) {
            $medidas = collect($f->camposDelCalculo())->where('tipo', 'numero')->map(fn ($c) => $c['label'])->implode(' × ');
            $filas[] = [$f->nombre, $medidas !== '' ? $medidas : '—', filled($f->expresion) ? $this->formulaLegible((string) $f->expresion, $f) : ($f->usa_cano ? 'se calcula con el caño de la tabla' : 'sin fórmula de peso cargada')];
        }

        return $this->resp([
            'mensaje' => 'Comparo '.implode(' y ', array_map(fn ($f) => "**{$f->nombre}**", $formas->all())).': qué medidas lleva cada una y cómo se calcula su volumen.',
            'tablas' => [['titulo' => 'Formas lado a lado', 'columnas' => ['Forma', 'Medidas', 'Volumen de una pieza (cm³)'], 'filas' => $filas, 'acciones' => array_fill(0, count($filas), [])]],
            'seguir' => ['cuánto pesa una '.mb_strtolower((string) $formas->first()->nombre).' de monel de 20 x 1000 mm'],
        ]);
    }

    private function formulaLegible(string $expresion, Forma $forma): string
    {
        $t = $expresion;

        foreach ($forma->camposDelCalculo() as $c) {
            $t = preg_replace('/\b'.preg_quote($c['clave'], '/').'\b/', $c['label'], $t) ?? $t;
        }

        $t = preg_replace('/pow\(\s*([^,()]+?)\s*,\s*2\s*\)/', '$1²', $t) ?? $t;
        $t = preg_replace('/\bpi\b/', 'π', $t) ?? $t;
        $t = preg_replace('/(\d)\.(\d)/', '$1,$2', $t) ?? $t;

        return trim(str_replace(['*', '/'], ['×', '÷'], $t));
    }

    /** Sin la palabra «pesa» pero con piezas y medidas («una barra de monel de 20 x 1000 contra una de 30 x 1000»): son pesos. */
    private function compararPesosSiHayMedidas(string $w): ?array
    {
        return preg_match('/\d/', $w) && preg_match('/\b(?:'.self::COSAS.')\b/', $w) ? $this->compararPesos($w, pideElPeso: false) : null;
    }

    /**
     * Parte el texto en ítems: corta en cada conector (contra, vs, y, o, que, con…) sólo si lo que sigue ARRANCA un ítem nuevo.
     *
     * @param  callable(string): bool  $arrancaItem
     * @return list<array{texto: string, conector: ?string}>
     */
    private function segmentar(string $w, callable $arrancaItem): array
    {
        preg_match_all('/(?<=\s)('.self::CONECTOR.')(?=\s)|((?<!\d),(?!\d))/u', $w, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $segmentos = [];
        $desde = 0;
        $conector = null;

        foreach ($m as $hallazgo) {
            [$texto, $pos] = $hallazgo[0];
            $resto = ltrim(substr($w, $pos + strlen($texto)));
            $izquierda = trim(substr($w, $desde, $pos - $desde));

            if ($izquierda !== '' && $arrancaItem($resto) && $pos >= $desde) {
                $segmentos[] = ['texto' => $izquierda, 'conector' => $conector];
                $desde = $pos + strlen($texto);
                $conector = trim($texto);
            }
        }

        $segmentos[] = ['texto' => trim(substr($w, $desde)), 'conector' => $conector];

        return array_values(array_filter($segmentos, fn ($s) => $s['texto'] !== ''));
    }

    private function arrancaItemDePieza(string $resto): bool
    {
        if (preg_match('/^(?:'.self::CANTIDAD.')\s+(?:de\b|'.self::COSAS.'\b)/', $resto) || preg_match('/^de\s+\d/', $resto)) {
            return true;
        }

        $r = $this->sinDeterminantes($resto);

        return (bool) preg_match('/^(?:'.self::COSAS.')\b/', $r) || (bool) preg_match('/^(?:grado|gr|tipo)\s*\S+/', $r) || $this->empiezaConForma($r) || $this->empiezaConMaterial($r);
    }

    private function sinDeterminantes(string $resto): string
    {
        $palabras = explode(' ', trim($resto));

        while ($palabras !== [] && in_array($palabras[0], ['el', 'la', 'los', 'las', 'de', 'del', 'un', 'una', 'lo'], true)) {
            array_shift($palabras);
        }

        return implode(' ', $palabras);
    }

    /** ¿Lo que sigue empieza nombrando una forma? («cuadrada de 50», «barra redonda»): en el catálogo, con una a tres palabras. */
    private function empiezaConForma(string $resto): bool
    {
        $palabras = explode(' ', $this->sinDeterminantes($resto));

        for ($n = min(3, count($palabras)); $n >= 1; $n--) {
            if ($this->catalogo->forma(implode(' ', array_slice($palabras, 0, $n))) !== null) {
                return true;
            }
        }

        return false;
    }

    /** ¿Lo que sigue empieza nombrando un material? («inconel 625 que…», «el monel»): la primera palabra tiene que ser parte del nombre. */
    private function empiezaConMaterial(string $resto): bool
    {
        $palabras = explode(' ', $this->sinDeterminantes($resto));

        for ($n = min(3, count($palabras)); $n >= 1; $n--) {
            $ventana = array_slice($palabras, 0, $n);

            if ($this->entiende->materialesDe(implode(' ', $ventana)) !== [] && ($n === 1 || $this->entiende->materialesDe(implode(' ', array_slice($ventana, 1))) !== $this->entiende->materialesDe(implode(' ', $ventana)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * «El titanio grado 5 contra el grado 2»: lo que sólo dice la calidad es el mismo material de la otra parte, con otra calidad.
     *
     * @param  list<string>  $textos
     * @return list<string>
     */
    private function variantesDeCalidad(array $textos): array
    {
        foreach ($textos as $i => $t) {
            if (! preg_match('/^(?:el\s+|la\s+)?(?:grado|gr|tipo)\s*(?<g>[a-z0-9\-\.]+)\s*$/', $t, $m)) {
                continue;
            }

            foreach ($textos as $j => $otro) {
                if ($j !== $i && preg_match('/\b(?:grado|tipo)\s*[a-z0-9\-\.]+/', $otro)) {
                    $textos[$i] = preg_replace('/\b(?:grado|tipo)\s*[a-z0-9\-\.]+/', 'grado '.$m['g'], $otro, 1) ?? $t;
                    break;
                }
            }
        }

        return $textos;
    }

    /* ----------------------------------------------------------------------- comparar pesos */

    private function compararPesos(string $w, bool $pideElPeso = true): ?array
    {
        // Sin una forma ni una medida («monel contra inconel, cuál pesa más») no hay una pieza que pesar: se comparan los materiales.
        if (! preg_match('/\d/', $w) && ! preg_match('/\b(?:'.self::COSAS.')\b/', $w) && $this->entiende->coordinados($w) === null) {
            return null;
        }

        $segmentos = array_values(array_filter(
            $this->segmentar($w, fn (string $r) => $this->arrancaItemDePieza($r)),
            fn ($s) => (bool) preg_match('/[a-z\d]/', preg_replace(self::RUIDO, ' ', $s['texto']) ?? $s['texto']),
        ));

        if (count($segmentos) < 2 || count($segmentos) > 6) {
            return null;
        }

        $this->paso('Separando los ítems…');
        $textos = $this->variantesDeCalidad(array_column($segmentos, 'texto'));
        $items = [];

        foreach ($segmentos as $i => $s) {
            $item = $this->itemDePeso($textos[$i]);

            if ($item === null) {
                return null;
            }

            $items[] = $item + ['conector' => $s['conector']];
        }

        $this->completarItems($items);

        foreach ($items as $i => &$it) {
            $this->paso('Calculando el peso del ítem '.($i + 1).'…');
            $it['respuesta'] = $this->redactor->redactar(['intencion' => 'peso', 'datos' => $it['datos']]);
            $it['calculo'] = $it['respuesta']['_calculo'] ?? null;
        }
        unset($it);

        $comparar = (bool) preg_match(Entiende::CUE_COMPARACION, $w);
        $alternativas = ! $comparar && count(array_filter($items, fn ($i) => in_array($i['conector'], ['o', 'u'], true))) === count($items) - 1;
        // Se suma sólo si se pide el conjunto («cuánto pesan», «en total», «todo»); «cuánto pesa A y B» da cada uno por separado.
        $suma = ! $comparar && ! $alternativas && (bool) preg_match('/\b(?:pesan|total|juntas?|juntos|todo|todas|todos|en\s+conjunto|en\s+suma)\b/', $w);

        return $this->componerPesos($items, $comparar, $alternativas, $suma, $pideElPeso);
    }

    /** @return array{seg: string, igual: bool, datos: array<string, mixed>}|null */
    private function itemDePeso(string $seg): ?array
    {
        $igual = (bool) preg_match('/\b(?:igual(?:es)?|mism[oa]s?|identic\w+)\b/', $seg);
        $limpio = trim(preg_replace('/\s+/', ' ', preg_replace(self::RUIDO, ' ', $seg) ?? $seg) ?? $seg);

        if ($limpio === '' || ! preg_match('/[a-z\d]/', $limpio)) {
            return null;
        }

        $plan = $this->entiende->plan('cuanto pesa '.$limpio);

        if ($plan === null || $plan['intencion'] !== 'peso') {
            return null;
        }

        // «5 de 30 x 1000»: la cantidad va sola, sin decir qué son.
        if (($plan['datos']['piezas'] ?? null) === null && preg_match('/^(?:de\s+)?(?:(?<n>\d+)|(?<p>un|una|uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|doce|otra|otro))\s+de\b/', $limpio, $q)) {
            $palabras = ['un' => 1, 'una' => 1, 'uno' => 1, 'otra' => 1, 'otro' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10, 'doce' => 12];
            $plan['datos']['piezas'] = (float) (($q['n'] ?? '') !== '' ? $q['n'] : $palabras[$q['p']]);
        }

        return ['seg' => $limpio, 'igual' => $igual, 'datos' => $plan['datos'], 'heredado' => []];
    }

    /**
     * «Una de 20 mm» es de la misma forma y material que la anterior; «igual» copia las medidas; el largo se comparte si falta.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function completarItems(array &$items): void
    {
        $vecino = function (int $i, callable $tiene) use (&$items): ?int {
            for ($d = 1; $d < count($items); $d++) {
                foreach ([$i - $d, $i + $d] as $j) {
                    if (isset($items[$j]) && $tiene($items[$j])) {
                        return $j;
                    }
                }
            }

            return null;
        };

        foreach ($items as $i => &$it) {
            $d = $it['datos'];
            $prefijo = [];

            if (empty($d['forma']) && empty($d['es_cano']) && ($j = $vecino($i, fn ($x) => ! empty($x['datos']['forma']) && empty($x['datos']['es_cano']))) !== null) {
                $prefijo[] = $items[$j]['datos']['forma'];
                $it['heredado'][] = 'la forma ('.mb_strtolower((string) $items[$j]['datos']['forma']).')';
            }

            if (empty($d['material']) && ($j = $vecino($i, fn ($x) => ! empty($x['datos']['material']))) !== null) {
                $prefijo[] = 'de '.($items[$j]['datos']['material_texto'] ?? $items[$j]['datos']['material']);
                $it['heredado'][] = 'el material ('.$items[$j]['datos']['material'].')';
            }

            if ($prefijo !== []) {
                $plan = $this->entiende->plan('cuanto pesa '.implode(' ', $prefijo).' '.$it['seg']);

                if ($plan !== null && $plan['intencion'] === 'peso') {
                    $piezas = $it['datos']['piezas'] ?? null;
                    $it['datos'] = $plan['datos'];
                    ($it['datos']['piezas'] ?? null) === null && $piezas !== null && $it['datos']['piezas'] = $piezas;
                }
            }
        }
        unset($it);

        // Medidas: el largo se comparte si falta; con «igual» (o si no dijo ninguna) se copian todas las de la misma forma.
        foreach ($items as $i => &$it) {
            $d = &$it['datos'];

            if (! empty($d['es_cano']) || empty($d['forma'])) {
                continue;
            }

            $forma = Forma::query()->where('nombre', $d['forma'])->first();
            $claves = $forma ? array_values(array_intersect($forma->ordenEnQueSeEscriben(), array_column($forma->camposDelCalculo(), 'clave'))) : [];
            $mismaForma = fn ($x) => ($x['datos']['forma'] ?? null) === $d['forma'] && empty($x['datos']['es_cano']);
            $conLargo = fn ($x) => isset($x['datos']['medidas']['length']) && empty($x['datos']['es_cano']) && ! empty($x['datos']['forma']);

            foreach ($claves as $k) {
                if (isset($d['medidas'][$k]) || ($d['largo_variable'] ?? null) !== null && $k === 'length') {
                    continue;
                }

                $j = $vecino($i, fn ($x) => $k === 'length' ? $conLargo($x) : ($mismaForma($x) && isset($x['datos']['medidas'][$k])));

                if ($j !== null) {
                    $d['medidas'][$k] = $items[$j]['datos']['medidas'][$k];
                    $nombre = collect($forma->camposDelCalculo())->firstWhere('clave', $k)['label'] ?? ($k === 'length' ? 'Largo' : 'Medida');
                    $it['heredado'][] = 'el '.mb_strtolower((string) $nombre).' ('.$this->m((float) $d['medidas'][$k]['valor']).' '.$d['medidas'][$k]['unidad'].')';
                }
            }

            unset($d);
        }
        unset($it);

        // Los caños: el schedule y el largo también se comparten.
        foreach ($items as $i => &$it) {
            if (empty($it['datos']['es_cano']) || empty($it['datos']['cano'])) {
                continue;
            }

            foreach (['schedule', 'metros'] as $k) {
                if ($it['datos']['cano'][$k] === null && ($j = $vecino($i, fn ($x) => ! empty($x['datos']['cano'][$k]))) !== null) {
                    $it['datos']['cano'][$k] = $items[$j]['datos']['cano'][$k];
                    $it['heredado'][] = $k === 'schedule' ? 'el schedule ('.$it['datos']['cano']['schedule'].')' : 'el largo ('.$this->m((float) $it['datos']['cano']['metros']).' m)';
                }
            }
        }
        unset($it);
    }

    /** «barra redonda de Monel…» → «barras redondas de Monel…»: sólo las palabras que nombran la forma, hasta el primer «de». */
    private function enPlural(string $descripcion): string
    {
        [$forma, $resto] = array_pad(explode(' de ', $descripcion, 2), 2, null);
        $plural = implode(' ', array_map(fn ($p) => preg_match('/[aeiou]$/u', $p) ? $p.'s' : (str_ends_with($p, 's') ? $p : $p.'es'), explode(' ', $forma)));

        return $resto === null ? $plural : $plural.' de '.$resto;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function componerPesos(array $items, bool $comparar, bool $alternativas, bool $suma = false, bool $pideElPeso = true): ?array
    {
        $fallaron = array_filter($items, fn ($i) => $i['calculo'] === null);
        $lineas = [];
        $filas = [];
        $atajos = [];

        foreach ($items as $i => $it) {
            $c = $it['calculo'];
            $num = $i + 1;

            if ($c === null) {
                $lineas[] = "- **{$num}.** «{$it['seg']}»: ".trim(strip_tags((string) ($it['respuesta']['mensaje'] ?? 'no pude calcularlo'))).' (no entra en la cuenta)';
                $filas[] = [(string) $num, $it['seg'], '—', '—', '—'];

                continue;
            }

            $piezas = (float) $c['piezas'];
            $unidad = $c['por_metro'] ? ' kg por metro' : ' kg';
            $detalle = $piezas > 1 ? " ({$this->m($piezas, 0)} piezas, cada una {$this->n($c['kg_pieza'], 4)} kg)" : '';
            $nombre = $piezas > 1 ? $this->m($piezas, 0).' '.$this->enPlural($c['descripcion']) : $c['descripcion'];
            $lineas[] = "- **{$num}.** {$nombre}: **{$this->n($c['kg_total'], 4)}{$unidad}**{$detalle}";
            $filas[] = [(string) $num, $nombre, $this->m($piezas, 0), $this->n($c['kg_pieza'], 4), $this->n($c['kg_total'], 4).($c['por_metro'] ? ' (por metro)' : '')];
            $atajos[] = ['etiqueta' => 'Ver el ítem '.$num, 'icono' => 'pregunta', 'accion' => ['tipo' => 'preguntar', 'texto' => 'cuánto pesa '.$it['seg']]];
        }

        $buenos = array_values(array_filter($items, fn ($i) => $i['calculo'] !== null));
        $heredados = [];

        foreach ($items as $i => $it) {
            $it['heredado'] !== [] && $heredados[] = 'El ítem '.($i + 1).' toma '.implode(' y ', array_unique($it['heredado'])).' del otro.';
        }

        $resumen = '';
        $mismaBase = count(array_unique(array_map(fn ($b) => $b['calculo']['por_metro'], $buenos))) <= 1;

        if (count($buenos) >= 2 && $mismaBase) {
            $porMetro = $buenos[0]['calculo']['por_metro'];
            $u = $porMetro ? ' kg por metro' : ' kg';

            if ($comparar) {
                $orden = $buenos;
                usort($orden, fn ($a, $b) => $b['calculo']['kg_total'] <=> $a['calculo']['kg_total']);
                $mayor = $orden[0];
                $menor = $orden[count($orden) - 1];
                $idx = fn ($x) => array_search($x, $items, true) + 1;
                $dif = $mayor['calculo']['kg_total'] - $menor['calculo']['kg_total'];

                $resumen = $dif < 1e-9
                    ? 'Pesan **lo mismo**.'
                    : 'El ítem **'.$idx($mayor)."** pesa **{$this->m($mayor['calculo']['kg_total'] / max($menor['calculo']['kg_total'], 1e-12))} veces** lo que el ítem **".$idx($menor)."**: **{$this->n($dif, 4)}{$u}** más.";
            } elseif ($alternativas) {
                $liviano = $buenos[0];
                foreach ($buenos as $b) {
                    $b['calculo']['kg_total'] < $liviano['calculo']['kg_total'] && $liviano = $b;
                }
                $resumen = 'El más liviano es el ítem **'.(array_search($liviano, $items, true) + 1).'**.';
            } elseif ($suma) {
                $total = array_sum(array_map(fn ($b) => $b['calculo']['kg_total'], $buenos));
                $resumen = "En total pesan **{$this->n($total, 4)}{$u}**".(count($fallaron) > 0 ? ' (sin contar lo que no se pudo calcular)' : '').'.';
                $filas[] = ['', 'Total', '', '', $this->n($total, 4).($porMetro ? ' (por metro)' : '')];
            }
        } elseif (count($buenos) >= 2) {
            $resumen = 'Unos están **por metro** y otros **por pieza**: no los sumo ni los comparo con una cuenta. Pasame el largo de los que faltan.';
        }

        $titulo = match (true) {
            $comparar => 'Comparo estos ítems',
            $alternativas => 'Elegís entre estos ítems',
            $suma => 'Sumo estos ítems',
            default => 'Calculo cada ítem',
        };
        $mensaje = implode("\n", array_filter([$titulo.':', ...$lineas, $resumen, $heredados !== [] ? '*'.implode(' ', $heredados).'*' : null]));

        if ($fallaron !== [] && count($fallaron) === count($items)) {
            // Todas piden algún dato (sobre todo el material o las medidas): se dice qué falta de cada una, para que se complete de una vez.
            $conForma = count(array_filter($items, fn ($i) => ! empty($i['datos']['forma']) || ! empty($i['datos']['es_cano']))) === count($items);

            // Si nadie pidió el peso («cuánto mide un caño de 2 sch 40 y uno de 3 sch 80»), no se arma una respuesta de pesos: sigue otra manera.
            return $conForma && $pideElPeso ? $this->resp([
                'mensaje' => "Para calcular el peso me falta un dato de cada una:\n".implode("\n", array_map(fn ($i, $n) => "- **{$n}.** ".trim(strip_tags((string) ($i['respuesta']['mensaje'] ?? ''))), $items, range(1, count($items)))),
                'seguir' => ['cuánto pesa una barra de monel de 38,1 x 145 mm', 'cuánto pesa una chapa de monel de 3 x 1000 x 2000'],
            ]) : null;
        }

        $r = $this->resp([
            'mensaje' => $mensaje,
            'tablas' => [['titulo' => 'Peso por ítem', 'columnas' => ['Ítem', 'Descripción', 'Piezas', 'Peso por pieza (kg)', 'Peso total (kg)'], 'filas' => $filas, 'acciones' => array_fill(0, count($filas), [])]],
            'atajos' => array_slice($atajos, 0, Respuesta::MAX_ATAJOS),
            'seguir' => count($fallaron) === 1 ? [] : ['cuánto pesa una barra de monel de 38,1 x 145 mm', 'tabla de caños schedule 40'],
        ]);

        // Si un solo ítem no se pudo, se muestran sus opciones (qué material, qué medida) para completarlo.
        if (count($fallaron) === 1 && ! empty(reset($fallaron)['respuesta']['opciones'])) {
            $r['opciones'] = reset($fallaron)['respuesta']['opciones'];
        }

        return $r;
    }

    /* ----------------------------------------------------------------------- comparar precios */

    private function compararPrecios(string $w): ?array
    {
        if (! $this->usuario->veImportes()) {
            return $this->resp(['mensaje' => 'No tenés permiso para ver precios ni importes. Si lo necesitás, pedile a un administrador que lo habilite en *Quién ve qué*.']);
        }

        $segmentos = $this->segmentar($w, fn (string $r) => $this->arrancaItemDePieza($r) && ! preg_match('/^(?:'.self::CANTIDAD.')\s+de\b/', $r));

        if (count($segmentos) < 2 || count($segmentos) > 4) {
            return null;
        }

        $this->paso('Separando lo que comparás…');
        $textos = $this->variantesDeCalidad(array_column($segmentos, 'texto'));
        $items = [];

        foreach ($textos as $texto) {
            $limpio = trim(preg_replace('/\s+/', ' ', preg_replace('/\b(?:compar\w*|diferenc\w*|difier\w*|cuanto|cual|cuales|que|mas|menos|caro|cara|baratos?|baratas?|conviene|precios?|el|la|los|las|del|lo|entre|igual(?:es)?|cuest\w*|sale|salen|vale|valen|cotiz\w*|a|es|son|esta|estan|seria|sera|resulta|queda)\b/', ' ', $texto) ?? $texto) ?? $texto);

            if ($limpio === '') {
                continue;
            }

            $plan = $this->entiende->plan('a cuanto cotizamos '.$limpio);

            if ($plan === null || $plan['intencion'] !== 'precio') {
                return null;
            }

            // Si el pedazo nombra un material, lo que el motor tomó por empresa es parte de ese nombre («inconel 625»), no una empresa.
            $empresa = $this->entiende->materialesDe($limpio) !== [] ? null : ($plan['datos']['empresa'] ?? null);
            $items[] = ['seg' => $limpio, 'filtros' => $plan['datos']['filtros'] ?? [], 'empresa' => $empresa];
        }

        if (count($items) < 2) {
            return null;
        }

        // Lo que no dijo uno (la forma, el año, la moneda) lo dice el otro: se aplica a los dos. El material nunca se copia.
        $compartidas = [];

        foreach ($items as $it) {
            foreach ($it['filtros'] as $k => $v) {
                if (! in_array($k, ['material', 'material_id'], true)) {
                    $compartidas[$k] ??= $v;
                }
            }
        }

        $materialDeOtro = null;

        foreach ($items as $it) {
            $materialDeOtro ??= $it['filtros']['material'] ?? null;
        }

        foreach ($items as &$it) {
            $it['filtros'] += $compartidas;

            // Si un pedazo sólo dice la forma («…versus chapa»), el material es el del otro.
            if (! isset($it['filtros']['material']) && ! isset($it['filtros']['material_id']) && $materialDeOtro !== null) {
                $it['filtros']['material'] = $materialDeOtro;
            }

            if ($it['empresa'] !== null) {
                $it['filtros']['empresa'] = $it['empresa'];
            }

            if (array_intersect_key($it['filtros'], array_flip(['material', 'material_id', 'forma', 'forma_id', 'empresa'])) === []) {
                return null;
            }
        }
        unset($it);

        foreach ($items as $i => $it) {
            $this->paso('Mirando a cuánto se cotizó el ítem '.($i + 1).'…');
            $r = $this->h->ejecutar('precios', ['filtros' => $it['filtros']]);
            $items[$i]['precios'] = isset($r['error']) ? null : $r;
            $items[$i]['etiqueta'] = $this->etiquetaDeFiltros($it['filtros'], $it['seg']);
        }

        return $this->componerPrecios($items, (bool) preg_match('/\b(?:barat\w*|menos\s+caro|conviene|economic\w*)\b/', $w));
    }

    /** @param  array<string, mixed>  $f */
    private function etiquetaDeFiltros(array $f, string $respaldo): string
    {
        $partes = array_filter([$f['material'] ?? null, isset($f['forma']) ? 'en '.mb_strtolower((string) $f['forma']) : null, isset($f['empresa']) ? 'a '.$f['empresa'] : null]);

        return $partes !== [] ? implode(' ', $partes) : $respaldo;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function componerPrecios(array $items, bool $pideElBarato = false): ?array
    {
        $filas = [];
        $lineas = [];
        $grupos = [];

        foreach ($items as $i => $it) {
            $num = $i + 1;
            $est = $it['precios']['estadisticas'] ?? [];

            if ($est === []) {
                $lineas[] = "- **{$it['etiqueta']}**: no encontré precios cotizados.";

                continue;
            }

            foreach ($est as $e) {
                $unidad = $e['unidad'] === '—' ? 'sin unidad cargada' : $e['unidad'];
                $filas[] = [(string) $num, $it['etiqueta'], $e['moneda'], $unidad, (string) $e['renglones'], $this->n($e['promedio']), $e['ultimo_precio'] === null ? '—' : $this->n($e['ultimo_precio']), $this->n($e['minimo']).' – '.$this->n($e['maximo'])];
                $grupos[$e['moneda'].'|'.$e['unidad']][$i] = $e;
            }
        }

        if ($filas === []) {
            return $this->resp(['mensaje' => "No encontré precios cotizados para comparar:\n".implode("\n", $lineas)]);
        }

        // La moneda y la unidad que tienen todos (si hay varias, la que más renglones junta): sólo ahí hay una cuenta honesta.
        $conPrecios = count(array_filter($items, fn ($x) => ($x['precios']['estadisticas'] ?? []) !== []));
        $comunes = array_filter($grupos, fn ($g) => $conPrecios >= 2 && count($g) === $conPrecios);
        uasort($comunes, fn ($a, $b) => array_sum(array_column($b, 'renglones')) <=> array_sum(array_column($a, 'renglones')));

        if ($comunes !== []) {
            $clave = array_key_first($comunes);
            $g = $comunes[$clave];
            [$moneda, $unidad] = explode('|', $clave);
            ksort($g);
            $partes = [];

            foreach ($g as $i => $e) {
                $partes[] = "**{$items[$i]['etiqueta']}**: promedio **{$this->n($e['promedio'])}**".($e['ultimo_precio'] !== null ? ", último **{$this->n($e['ultimo_precio'])}**" : '').", {$e['renglones']} ".($e['renglones'] === 1 ? 'renglón' : 'renglones');
            }

            $mensaje = 'En **'.$moneda.'**'.($unidad !== '—' ? " por **{$unidad}**" : ' (sin unidad cargada)').":\n- ".implode("\n- ", $partes);

            if (count($g) === 2) {
                $ids = array_keys($g);
                [$a, $b] = [$g[$ids[0]]['promedio'], $g[$ids[1]]['promedio']];

                if ($a > 0 && $b > 0 && abs($a - $b) > 1e-9) {
                    [$caro, $barato] = $a > $b ? [$ids[0], $ids[1]] : [$ids[1], $ids[0]];
                    $pct = abs($a - $b) / min($a, $b) * 100;
                    $mensaje .= $pideElBarato
                        ? "\nEl más barato es **{$items[$barato]['etiqueta']}**: **{$this->m($pct, 1)} %** menos que **{$items[$caro]['etiqueta']}** (en promedio, {$this->n(abs($a - $b))} de diferencia)."
                        : "\nEl más caro es **{$items[$caro]['etiqueta']}**: cuesta **{$this->m($pct, 1)} %** más que **{$items[$barato]['etiqueta']}** (en promedio, {$this->n(abs($a - $b))} de diferencia).";
                } else {
                    $mensaje .= "\nEn promedio cuestan **lo mismo**.";
                }
            }
        } else {
            $mensaje = 'No los comparo con una cuenta: están cotizados en **monedas o unidades distintas** (los dólares no se suman ni se comparan con los pesos). Te dejo cada uno por separado.';
        }

        $atajos = [];

        foreach ($items as $i => $it) {
            if (! empty($it['precios']['filtros_aplicados'])) {
                $atajos[] = ['etiqueta' => 'Ver los renglones de '.mb_substr($it['etiqueta'], 0, 30), 'icono' => 'lista', 'accion' => ['tipo' => 'pedido', 'pedido' => $it['precios']['filtros_aplicados']]];
            }
        }

        $mensaje = implode("\n", array_filter([$mensaje, ...$lineas, '*El promedio junta todo lo cotizado con ese filtro, medidas distintas incluidas: para comparar de verdad, acotá por forma o medida.*']));

        return $this->resp([
            'mensaje' => $mensaje,
            'tablas' => [['titulo' => 'A cuánto se cotizó cada uno', 'columnas' => ['Ítem', 'Qué', 'Moneda', 'Unidad', 'Renglones', 'Promedio', 'Último', 'Mínimo – máximo'], 'filas' => $filas, 'acciones' => array_fill(0, count($filas), [])]],
            'atajos' => $atajos,
            'seguir' => array_slice(array_values(array_filter(array_map(fn ($it) => isset($it['filtros']['material']) ? 'cómo viene el precio de '.$it['filtros']['material'].' por año' : null, $items))), 0, 3),
        ]);
    }

    /* ----------------------------------------------------------------------- comparar listas */

    /** «Empresas de Córdoba contra Santa Fe», «cotizaciones de 2025 vs 2026»: la cuenta de cada lado y los botones para ver cada una. */
    private function compararListas(string $w): ?array
    {
        // Sólo si se pide comparar: «ranking de empresas que compraron monel y de las que compraron titanio» son dos rankings, no dos conteos.
        if (! preg_match(Entiende::CUE_COMPARACION, $w)) {
            return null;
        }

        $sustantivo = preg_match('/\b(cotizaciones|pedidos|empresas|clientes|facturas|remitos|renglones)\b/', $w, $sm) ? $sm[1] : '';
        preg_match_all('/(?<=\s)(?:contra|versus|vs|comparad[oa]s?\s+con|frente\s+a|respecto\s+(?:de|a)|con|y|o|que)(?=\s)/', $w, $m, PREG_OFFSET_CAPTURE);

        foreach ($m[0] as [$con, $pos]) {
            $izq = trim(substr($w, 0, $pos));
            $der = trim(substr($w, $pos + strlen($con)));
            $lado = fn (string $t) => $this->reglas->interpretar(trim(preg_replace('/\b(?:compar\w*|diferenc\w*|cuanto|cuantas|cuantos|cual|cuales|mas|menos|entre|tiene|tienen|hay|hubo)\b/', ' ', $t) ?? $t));
            $a = $lado($izq);
            $b = $lado($der);

            // «Las de 2026»: falta el sustantivo; es el mismo del otro lado.
            if (array_diff_key($b['borrador'], ['vista' => 1, 'texto' => 1]) === [] && $sustantivo !== '') {
                $b = $lado($sustantivo.' '.$der);
            }

            $fa = array_diff_key($a['borrador'], ['vista' => 1, 'texto' => 1]);
            $fb = array_diff_key($b['borrador'], ['vista' => 1, 'texto' => 1]);

            if ($fa === [] || $fb === []) {
                continue;
            }

            // Lo que falta en un lado lo toma del otro; lo que cambia es justo lo que se compara.
            $fa2 = $fa + $fb;
            $fb2 = $fb + $fa;

            if ($fa2 == $fb2) {
                continue;
            }

            $vista = $a['borrador']['vista'] ?? $b['borrador']['vista'] ?? $this->vistaDeLaFrase($w);
            $cuentas = [];

            foreach ([$fa2, $fb2] as $k => $filtros) {
                $this->paso('Contando el lado '.($k + 1).'…');
                $r = $this->h->ejecutar('buscar', ['vista' => $vista, 'filtros' => $filtros, 'limite' => 1]);

                if (isset($r['error'])) {
                    continue 2;
                }

                $cuentas[] = ['total' => (int) $r['total'], 'pedido' => $r['filtros_aplicados'] ?? [], 'etiqueta' => $this->etiquetaDeLado($filtros, $filtros === $fa2 ? $fb2 : $fa2, $k === 0 ? $izq : $der)];
            }

            if (count($cuentas) !== 2) {
                continue;
            }

            [$x, $y] = $cuentas;
            $cosa = ['empresas' => 'empresas', 'consultas' => 'cotizaciones y pedidos', 'renglones' => 'renglones', 'comprobantes' => 'facturas y remitos'][$vista] ?? 'resultados';
            $dif = $x['total'] - $y['total'];
            $cierre = $dif === 0
                ? 'Tienen **la misma cantidad**.'
                : '**'.($dif > 0 ? $x['etiqueta'] : $y['etiqueta']).'** tiene **'.$this->n(abs($dif), 0).'** más'.($x['total'] > 0 && $y['total'] > 0 ? ' (**'.$this->m(max($x['total'], $y['total']) / min($x['total'], $y['total'])).' veces**)' : '').'.';

            return $this->resp([
                'mensaje' => "Comparo {$cosa}:\n- **{$x['etiqueta']}**: **{$this->n($x['total'], 0)}**\n- **{$y['etiqueta']}**: **{$this->n($y['total'], 0)}**\n{$cierre}",
                'tablas' => [['titulo' => 'Cuántos hay de cada lado', 'columnas' => ['Qué', 'Cantidad'], 'filas' => [[$x['etiqueta'], $this->n($x['total'], 0)], [$y['etiqueta'], $this->n($y['total'], 0)]], 'acciones' => [[], []]]],
                'atajos' => array_values(array_filter([
                    $x['pedido'] !== [] ? ['etiqueta' => 'Ver: '.mb_substr($x['etiqueta'], 0, 30).' ('.$this->n($x['total'], 0).')', 'icono' => 'lista', 'accion' => ['tipo' => 'pedido', 'pedido' => $x['pedido']]] : null,
                    $y['pedido'] !== [] ? ['etiqueta' => 'Ver: '.mb_substr($y['etiqueta'], 0, 30).' ('.$this->n($y['total'], 0).')', 'icono' => 'lista', 'accion' => ['tipo' => 'pedido', 'pedido' => $y['pedido']]] : null,
                ])),
                'pedido' => $x['pedido'] !== [] ? $x['pedido'] : null,
            ]);
        }

        return null;
    }

    private function vistaDeLaFrase(string $w): string
    {
        return match (true) {
            (bool) preg_match('/\b(?:facturas?|remitos?|comprobantes?)\b/', $w) => 'comprobantes',
            (bool) preg_match('/\b(?:empresas?|clientes?|proveedor(?:es)?)\b/', $w) => 'empresas',
            (bool) preg_match('/\b(?:renglon(?:es)?|material\s+cotizado)\b/', $w) => 'renglones',
            default => 'consultas',
        };
    }

    /** «cordoba» → «Córdoba»: el nombre como está en el catálogo. */
    private function nombreDe(string $clave, string $valor): string
    {
        try {
            $nombre = match ($clave) {
                'provincia' => ($id = $this->catalogo->provincia($valor)) ? Provincia::query()->whereKey($id)->value('nombre') : null,
                'localidad' => ($id = $this->catalogo->localidad($valor)) ? Localidad::query()->whereKey($id)->value('nombre') : null,
                'rubro' => ($id = $this->catalogo->rubro($valor)) ? Rubro::query()->whereKey($id)->value('nombre') : null,
                default => null,
            };
        } catch (\Throwable) {
            $nombre = null;
        }

        return $nombre !== null && $nombre !== '' ? (string) $nombre : mb_convert_case($valor, MB_CASE_TITLE);
    }

    /**
     * Cómo se llama cada lado: lo que lo distingue del otro («Córdoba», «2025»); si no se puede, lo que dijo la persona.
     *
     * @param  array<string, mixed>  $propios
     * @param  array<string, mixed>  $otros
     */
    private function etiquetaDeLado(array $propios, array $otros, string $texto): string
    {
        $dif = array_filter($propios, fn ($v, $k) => ! array_key_exists($k, $otros) || $otros[$k] != $v, ARRAY_FILTER_USE_BOTH);
        $partes = [];

        if (isset($dif['desde']) || isset($dif['hasta'])) {
            $desde = (string) ($dif['desde'] ?? $propios['desde'] ?? '');
            $hasta = (string) ($dif['hasta'] ?? $propios['hasta'] ?? '');
            $anio = substr($desde, 0, 4);
            $partes[] = $desde !== '' && $hasta !== '' && $desde === $anio.'-01-01' && $hasta === $anio.'-12-31'
                ? $anio
                : trim(($desde !== '' ? date('d/m/Y', (int) strtotime($desde)) : '').' a '.($hasta !== '' ? date('d/m/Y', (int) strtotime($hasta)) : ''), ' a');
        }

        foreach (array_diff_key($dif, ['desde' => 1, 'hasta' => 1]) as $k => $v) {
            is_scalar($v) && $partes[] = $this->nombreDe((string) $k, (string) $v);
        }

        if ($partes !== []) {
            return implode(' · ', $partes);
        }

        $limpio = trim(preg_replace('/\s+/', ' ', preg_replace('/\b(?:compar\w*|diferenc\w*|cuanto|cuantas|cuantos|cual|cuales|mas|menos|entre|las|los|el|la|de|del|en)\b/', ' ', $texto) ?? $texto) ?? $texto);

        return $limpio !== '' ? mb_substr($limpio, 0, 60) : 'este lado';
    }
}

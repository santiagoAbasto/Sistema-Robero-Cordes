<?php

namespace App\Services\Buscador;

use App\Models\Consulta;
use Carbon\CarbonImmutable;

/**
 * Entiende una frase como "a qué empresas les cotice monel este año" con reglas,
 * sin IA: no cuesta nada, responde al instante y siempre da lo mismo.
 *
 * Es el modo básico de Cordes IA: entiende lo que se pregunta seguido (períodos,
 * estados, materiales, formas, provincias, "hace más de 6 meses que no les
 * cotizo", "sin respuesta", "compra parcial"…). Lo que no entiende lo devuelve
 * como "restos"; con una IA configurada, esos casos se le pasan a ella.
 *
 * Devuelve un BORRADOR con los mismos nombres que usa la IA (ver ResolverDePedido),
 * que después se convierte en un pedido válido.
 */
final class InterpretePorReglas
{
    private const N = '(?<n>\d+|un|una|uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|quince|veinte|treinta)';

    private const UNIDAD = '(?<u>dias?|semanas?|mes(?:es)?|anos?)';

    /** Verbos y palabras de relleno: no dicen qué buscar. */
    private const RELLENO = [
        'a', 'al', 'de', 'del', 'la', 'las', 'el', 'los', 'lo', 'un', 'una', 'unos', 'unas', 'y', 'o', 'e', 'u',
        'que', 'con', 'en', 'por', 'para', 'se', 'me', 'te', 'le', 'les', 'nos', 'su', 'sus', 'mi', 'mis',
        'es', 'son', 'fue', 'fueron', 'hay', 'ya', 'no', 'si', 'mas', 'menos', 'muy', 'todo', 'todos', 'toda',
        'todas', 'cada', 'como', 'cuando', 'donde', 'cual', 'cuales', 'favor', 'quiero', 'necesito', 'quisiera',
        'podes', 'puedes', 'ver', 'tengo', 'tenemos', 'tiene', 'tienen', 'hace', 'hacen', 'esa', 'ese', 'esos',
        'esas', 'esta', 'este', 'estos', 'estas', 'sobre', 'segun', 'entre', 'hasta', 'desde', 'durante',
        'empresa', 'empresas', 'cliente', 'clientes', 'proveedor', 'proveedores', 'cotizacion', 'cotizaciones',
        'pedido', 'pedidos', 'renglon', 'renglones', 'item', 'items', 'material', 'materiales', 'factura',
        'facturas', 'remito', 'remitos', 'comprobante', 'comprobantes', 'registro', 'registros', 'datos',
        'estan', 'esten', 'estaban', 'estuvieron', 'ahora', 'hoy', 'solo', 'solamente', 'tambien', 'otra', 'otras',
        'otro', 'otros', 'nosotros', 'yo', 'ellos', 'ellas', 'q', 'sin', 'son', 'cuit', 'cuil', 'numero', 'nro', 'nº',
    ];

    public function __construct(private readonly Catalogo $catalogo) {}

    /**
     * @return array{borrador: array<string, mixed>, restos: list<string>, entendio: bool}
     */
    public function interpretar(string $original): array
    {
        $hoy = $this->catalogo->hoy();
        $w = Texto::normalizar($original);
        $b = [];

        $consumir = function (string $regex, callable $alHallar) use (&$w): bool {
            $hallado = false;
            $nuevo = preg_replace_callback($regex, function (array $m) use ($alHallar, &$hallado) {
                $hallado = true;
                $alHallar($m);

                return ' ';
            }, $w, 1);
            $w = trim(preg_replace('/\s+/', ' ', $nuevo ?? $w) ?? $w);

            return $hallado;
        };

        // De qué se habla: el primer sustantivo que aparece manda ("cotizaciones de clientes" es de cotizaciones).
        $vista = $this->vistaPorLosSustantivos($w);

        /* ---------------------------------------------- "hace más de 6 meses que no" */

        $nocot = '(?:no|nunca)\s+(?:se\s+)?(?:les?\s+)?(?:cotiz\w*|cotic\w*)';
        $aMeses = function (array $m): ?int {
            $n = Texto::numero($m['n']);
            if ($n === null) {
                return null;
            }

            return match (true) {
                str_starts_with($m['u'], 'ano') => $n * 12,
                str_starts_with($m['u'], 'semana') => max(1, (int) round($n * 7 / 30)),
                str_starts_with($m['u'], 'dia') => max(1, (int) round($n / 30)),
                default => $n,
            };
        };
        $sinCotizar = function (array $m) use (&$b, $aMeses) {
            $meses = $aMeses($m);
            $meses !== null && $b['sin_cotizar_meses'] = $meses;
        };

        foreach ([
            '/\b(?:hace|desde hace)\s+(?:mas de\s+|mas que\s+)?'.self::N.'\s+'.self::UNIDAD.'\s+que\s+'.$nocot.'\b/',
            '/\b'.$nocot.'(?:\s+nada)?(?:\s+(?:en|desde|hace|durante))?(?:\s+los\s+ultimos)?\s+(?:hace\s+)?(?:mas de\s+|mas que\s+)?'.self::N.'\s+'.self::UNIDAD.'\b/',
            '/\bsin\s+cotizar(?:les)?\s+(?:hace|desde hace|en)\s+(?:mas de\s+)?'.self::N.'\s+'.self::UNIDAD.'\b/',
        ] as $regex) {
            if ($consumir($regex, $sinCotizar)) {
                break;
            }
        }

        /* ------------------------------------------------- vencimientos y estados */

        $consumir(
            '/\b(?:que\s+)?(?:vencen|vence|por vencer|a vencer)\b(?:\s+(?:en|dentro de|durante))?(?:\s+(?:los|las))?(?:\s+proxim[oa]s)?'
            .'(?:\s+'.self::N.'\s+(?<u>dias?|semanas?))?(?:\s+(?<cuando>esta semana|este mes|hoy|manana))?/',
            function (array $m) use (&$b) {
                $dias = 7;
                if (($m['n'] ?? '') !== '' && ($n = Texto::numero($m['n'])) !== null) {
                    $dias = str_starts_with($m['u'], 'semana') ? $n * 7 : $n;
                } elseif (($m['cuando'] ?? '') === 'este mes') {
                    $dias = 30;
                } elseif (in_array($m['cuando'] ?? '', ['hoy', 'manana'], true)) {
                    $dias = 2;
                }
                $b['por_vencer_dias'] = $dias;
            },
        );

        $consumir('/\bsin\s+(?:respuesta|contestar|que (?:nadie )?contest\w+)\b|\bno\s+(?:contestaron|respondieron|contestan|responden)\b|\bnadie\s+(?:contesto|respondio)\b/', function () use (&$b) {
            $b['sin_respuesta'] = true;
        });

        $consumir('/\bcompras?\s+(?<c>parcial(?:es)?|total(?:es)?)\b|\b(?<p>parcialmente)\s+(?:compradas?|vendidas?)\b|\bcompraron\s+(?<q>solo|solamente|todo|todos)\b(?:\s+(?:algunos|algunas|una parte))?/', function (array $m) use (&$b) {
            $parcial = str_starts_with($m['c'] ?? '', 'parcial') || ($m['p'] ?? '') !== '' || in_array($m['q'] ?? '', ['solo', 'solamente'], true);
            $b['compra'] = $parcial ? 'parcial' : 'total';
        });

        // "Las empresas que compraron monel": compraron, no sólo se les cotizó.
        $consumir('/\b(?:compraron|compro|nos compraron|nos compro)\b/', function () use (&$b) {
            $b['compra'] ??= 'vendida';
        });

        $consumir('/\b(?:orden(?:es)? de compra|oc|o\.c)\s*(?:n(?:ro|umero|o)?\s*)?(?<v>[a-z]*-?\d[a-z0-9\-\/\.]*)/', function (array $m) use (&$b) {
            $b['orden_de_compra'] = strtoupper($m['v']);
        });

        /* ---------------------------------------- números de comprobante, cotización, CUIT */

        $consumir('/\b(?<c>20\d{2}-\d{3,5})\b/', function (array $m) use (&$b, &$vista) {
            $b['texto'] = $m['c'];
            $vista ??= 'consultas';
        });
        $consumir('/\b(?:facturas?|remitos?|comprobantes?|notas? de (?:credito|debito))\s*(?:[abc]\s+)?(?:n(?:ro|umero|o)?\s*)?(?<v>\d{1,5}-\d{1,10}|\d{4,})\b/', function (array $m) use (&$b, &$vista) {
            $b['comprobante'] = $m['v'];
            $vista = 'comprobantes';
        });
        // "facturas de octubre", "remitos": el tipo de comprobante. (Con un número ya se buscó por el número.)
        foreach (['/\bnotas? de credito\b/' => 'Nota de crédito', '/\bnotas? de debito\b/' => 'Nota de débito', '/\bfacturas?\b(?!\s*(?:a|b|c)?\s*(?:n|\d))/' => 'Factura', '/\bremitos?\b/' => 'Remito'] as $regex => $tipo) {
            $consumir($regex, function () use (&$b, $tipo) {
                $b['tipo_comprobante'] = $tipo;
            });
        }
        $consumir('/\b(?<v>\d{4}-\d{4,10})\b/', function (array $m) use (&$b, &$vista) {
            $b['comprobante'] = $m['v'];
            $vista = 'comprobantes';
        });
        $consumir('/\b(?<a>\d{2})-?(?<b>\d{8})-?(?<c>\d)\b/', function (array $m) use (&$b, &$vista) {
            $b['texto'] = "{$m['a']}-{$m['b']}-{$m['c']}";
            $vista ??= 'empresas';
        });

        /* ----------------------------------------------------------------- período */

        $this->periodo($w, $consumir, $b, $hoy);

        $consumir('/\bfacturad[oa]s?\b/', function () use (&$b) {
            $b['fecha_de'] = 'factura';
        });

        /* -------------------------------------------------------- estados y tipos */

        $consumir('/\bborradores?(?:\s+sin\s+emitir)?\b|\bsin\s+emitir\b/', function () use (&$b) {
            $b['estado'] = 'Borrador';
        });
        $consumir('/\b(?:vendid[oa]s?|ventas?|se vendieron|vendimos|concretad[oa]s?)\b/', function () use (&$b) {
            $b['estado'] ??= 'Vendida';
        });
        $consumir('/\bdeclinad[oa]s?\b/', function () use (&$b) {
            $b['estado'] = Consulta::DECLINADA;
        });
        $consumir('/\bvencid[oa]s?\b/', function () use (&$b) {
            $b['estado'] = Consulta::VENCIDA;
        });
        $consumir('/\b(?:emitid[oa]s?|confirmad[oa]s?)\b/', function () use (&$b) {
            $b['estado'] = 'Confirmada';
        });
        $cerrado = $consumir('/\bcerrad[oa]s?\b|\bcerraron\b|\bcerro\b/', fn () => null);
        $consumir('/\b(?:hechas?|hizo|hicieron|cargad[oa]s?|armad[oa]s?)\s+(?:por\s+)?(?<q>[a-z]{2,15})\b/', function (array $m) use (&$b) {
            $b['quien'] = $m['q'];
        });
        $consumir('/\bpedidos?\b/', function () use (&$b) {
            $b['tipo'] = 'Pedido';
        });
        $consumir('/\bobservaci(?:on|ones)\b/', function () use (&$b) {
            $b['tipo'] = 'Observacion';
        });

        // "Vendidas con compra parcial": lo de la compra ya dice que se vendió.
        if (isset($b['compra'], $b['estado']) && $b['estado'] === 'Vendida') {
            unset($b['estado']);
        }

        /* ------------------------------------------------------- la empresa y sus datos */

        $consumir('/\b(?:inactiv[oa]s?|dad[oa]s? de baja|que (?:ya )?no existen|fuera de servicio)\b/', function () use (&$b) {
            $b['activa'] = 'inactivas';
        });
        $consumir('/\b(?:solo |solamente )?(?:las |los )?activ[oa]s\b/', function () use (&$b) {
            $b['activa'] = 'activas';
        });
        $consumir('/\bclientes?\b/', function () use (&$b) {
            $b['relacion'] = 'Cliente';
        });
        $consumir('/\bproveedor(?:es)?\b/', function () use (&$b) {
            $b['relacion'] = 'Proveedor';
        });
        $consumir('/\b(?:con|que tienen|tienen|que usan|y|e)\s+(?:el\s+)?(?:whatsapp|wasap|whatsap|wsp|guasap)\b/', function () use (&$b) {
            $b['con_whatsapp'] = true;
        });
        $consumir('/\b(?:con|que tienen|tienen|y|e)\s+(?:mail|correo|email|e mail)\b/', function () use (&$b) {
            $b['con_mail'] = true;
        });
        $consumir('/\bresponsables? inscript\w*\b|\binscript[oa]s?\b/', function () use (&$b) {
            $b['condicion_iva'] = 'Responsable Inscripto';
        });
        $consumir('/\bmonotribut\w*\b/', function () use (&$b) {
            $b['condicion_iva'] = 'Monotributo';
        });
        $consumir('/\bexent[oa]s?\b/', function () use (&$b) {
            $b['condicion_iva'] = 'Exento';
        });
        $consumir('/\bconsumidor(?:es)? finales?\b/', function () use (&$b) {
            $b['condicion_iva'] = 'Consumidor Final';
        });
        $consumir('/\ben\s+(?<m>dolares|dolar|usd|u s d|euros?|pesos)\b/', function (array $m) use (&$b) {
            $b['moneda'] = $m['m'];
        });
        $consumir('/\b(?:con )?(?:pago|pagan|condicion de pago)\s+(?:a\s+)?(?<d>\d{1,3})\s+dias\b/', function (array $m) use (&$b) {
            $b['condicion_pago'] = $m['d'];
        });

        /* --------------------------------------------------------------- medidas */

        $num = '(?<a>\d+(?:[.,]\d+)?)';
        $mm = '(?:\s*(?:mm|milimetros?))?';
        $rango = function (array $m) use (&$b) {
            $a = Texto::decimal($m['a']);
            $z = isset($m['z']) && $m['z'] !== '' ? Texto::decimal($m['z']) : $a;
            $b['diametro_desde'] = min($a, $z);
            $b['diametro_hasta'] = max($a, $z);
        };
        $consumir("/\\bdiametros?\\s*(?:de\\s+|entre\\s+)?{$num}\\s*(?:a|y|-|hasta)\\s*(?<z>\\d+(?:[.,]\\d+)?){$mm}/", $rango)
            || $consumir("/\\bdiametros?\\s*(?:de\\s+|entre\\s+)?{$num}{$mm}/", $rango)
            || $consumir("/\\b(?:de|entre)\\s+{$num}\\s*(?:a|y|-)\\s*(?<z>\\d+(?:[.,]\\d+)?)\\s*mm\\b/", $rango);
        $consumir("/\\b(?:mayor(?:es)?|mas grandes?)\\s+(?:a|de|que)\\s+{$num}\\s*mm\\b/", function (array $m) use (&$b) {
            $b['diametro_desde'] = Texto::decimal($m['a']);
        });
        $consumir("/\\b(?:menor(?:es)?|mas chic[oa]s?)\\s+(?:a|de|que)\\s+{$num}\\s*mm\\b/", function (array $m) use (&$b) {
            $b['diametro_hasta'] = Texto::decimal($m['a']);
        });
        $consumir('/\b(?:no cotizad[oa]s?|lo que no se cotizo|los que no se cotizaron)\b/', function () use (&$b) {
            $b['incluir_no_cotizadas'] = true;
        });

        /* -------------------------------------------- rubro, provincia y localidad */

        $consumir('/\b(?:del\s+)?rubro\s+(?:de\s+)?(?<r>[a-z0-9][a-z0-9\s\-\.\/]*?)(?=\s+(?:que|con|de|en|sin|y|desde|hasta|para|cuyo|cuyos)\b|$)/', function (array $m) use (&$b) {
            $b['rubro'] = $m['r'];
        });

        foreach ($this->catalogo->provinciasEn($w) as $nombre) {
            $b['provincia'] = $nombre;
            $consumir('/\b'.preg_quote($nombre, '/').'\b/', fn () => null);
            break;
        }
        foreach ($this->catalogo->localidadesEn($w) as $nombre) {
            // Una localidad se reconoce cuando se la nombra con "de", "en", "zona": "empresas de Rosario".
            if ($consumir('/\b(?:de|en|del|zona|por|cerca de)\s+(?:la\s+|el\s+)?'.preg_quote($nombre, '/').'\b/', fn () => null)) {
                $b['localidad'] = $nombre;
                break;
            }
        }

        /* ------------------------------------------------ qué se quiere saber */

        $preguntaPrecio = $consumir('/\b(?:cuanto|cuanta plata|a cuanto|a que precio|precios?|importes?)\b/', fn () => null);
        $preguntaQuien = $consumir('/\b(?:a que empresas?|a quien(?:es)?|quien(?:es)?|que clientes|que empresas?|cuales empresas)\b/', fn () => null);

        /* ------------------------------------------------- forma y material */

        $this->forma($w, $b);
        $otrosMateriales = $this->material($w, $b);
        $this->especificacionesDelMaterial($w, $b);

        /* ------------------------------------------------------------- la vista */

        $explicita = $vista;
        $vista ??= match (true) {
            isset($b['sin_cotizar_meses']) || isset($b['con_whatsapp']) || isset($b['con_mail']) => 'empresas',
            $preguntaPrecio && (isset($b['material']) || isset($b['forma'])) => 'renglones',
            $preguntaQuien => 'empresas',
            isset($b['sin_respuesta']) || isset($b['por_vencer_dias']) || isset($b['compra']) || isset($b['orden_de_compra'])
                || isset($b['estado']) || isset($b['tipo']) || isset($b['moneda']) => 'consultas',
            isset($b['material']) || isset($b['forma']) || isset($b['diametro_desde']) || isset($b['diametro_hasta']) || isset($b['incluir_no_cotizadas']) => 'renglones',
            // Sólo se dijo cuándo: lo que se movió en ese período.
            isset($b['desde']) || isset($b['hasta']) => 'consultas',
            default => null,
        };

        // "Cerradas": de una cotización es un estado; de una empresa, que dejó de existir.
        if ($cerrado) {
            $vista === 'consultas' ? $b['estado'] = Consulta::CERRADA : $b['activa'] = 'inactivas';
        }

        if (($vistaFinal = $explicita ?? $vista) !== null) {
            $b['vista'] = $vistaFinal;
        }

        /* -------------------------------------------------- lo que quedó sin entender */

        [$propios, $restos, $numeros] = $this->sobrantes($original, $w);

        if ($numeros !== []) {
            $b['texto'] = trim(($b['texto'] ?? '').' '.implode(' ', $numeros));
        }

        if ($propios !== []) {
            // Un nombre propio es una empresa: en la lista de empresas se busca como texto; en las demás, por el nombre.
            if (($b['vista'] ?? 'empresas') === 'empresas') {
                $b['texto'] = trim(($b['texto'] ?? '').' '.implode(' ', $propios));
            } else {
                $b['empresa'] = array_shift($propios);
                $restos = [...$restos, ...$propios];
            }
        }

        foreach ($otrosMateriales as $material) {
            $restos[] = $material;
        }

        $entendio = array_diff_key($b, ['vista' => 1]) !== [] || $propios !== [];

        return ['borrador' => $b, 'restos' => $restos, 'entendio' => $entendio];
    }

    /* ----------------------------------------------------------------- piezas */

    /** La vista que nombra el primer sustantivo de la frase, si hay alguno. */
    private function vistaPorLosSustantivos(string $w): ?string
    {
        $sustantivos = [
            'comprobantes' => '/\b(?:facturas?|remitos?|comprobantes?|notas? de (?:credito|debito))\b/',
            'consultas' => '/\b(?:cotizaciones|cotizacion|pedidos?|presupuestos?|observaciones|borradores?|ventas?|vendid[oa]s?)\b/',
            'renglones' => '/\b(?:renglon(?:es)?|items?|lineas?|materiales cotizados?)\b/',
            'empresas' => '/\b(?:empresas?|clientes?|proveedor(?:es)?|razon(?:es)? sociales?|fichas?)\b/',
        ];

        $primera = null;
        $vista = null;

        foreach ($sustantivos as $nombre => $regex) {
            if (preg_match($regex, $w, $m, PREG_OFFSET_CAPTURE) && ($primera === null || $m[0][1] < $primera)) {
                $primera = $m[0][1];
                $vista = $nombre;
            }
        }

        return $vista;
    }

    /**
     * Período: "este año", "el mes pasado", "en octubre", "últimos 6 meses",
     * "desde el 1/10 hasta el 15/10", "del 1 al 15 de octubre".
     *
     * @param  array<string, mixed>  $b
     */
    private function periodo(string &$w, callable $consumir, array &$b, CarbonImmutable $hoy): void
    {
        $meses = implode('|', array_keys(Texto::MESES));
        $fecha = "(?:\\d{1,2}[\\/\\-.]\\d{1,2}(?:[\\/\\-.]\\d{2,4})?|\\d{1,2} de (?:{$meses})(?: de \\d{4})?)";
        $poner = function (?array $p) use (&$b) {
            if ($p) {
                $b['desde'] = $p['desde'];
                $b['hasta'] = $p['hasta'];
            }
        };

        // "del 1 al 15 de octubre [de 2025]"
        if ($consumir("/\\bdel\\s+(?<a>\\d{1,2})\\s+al\\s+(?<z>\\d{1,2})\\s+de\\s+(?<m>{$meses})(?:\\s+(?:de|del)\\s+(?<y>\\d{4}))?\\b/", function (array $m) use ($hoy, $poner) {
            $mes = Texto::MESES[$m['m']];
            $anio = ($m['y'] ?? '') !== '' ? (int) $m['y'] : ($mes > $hoy->month ? $hoy->year - 1 : $hoy->year);
            $desde = Texto::fecha("{$m['a']}/{$mes}/{$anio}", $hoy);
            $hasta = Texto::fecha("{$m['z']}/{$mes}/{$anio}", $hoy);
            $desde && $hasta && $poner(Periodo::fechas($desde, $hasta));
        })) {
            return;
        }

        // "desde el 1/10 hasta el 15/10", "entre el 1/10 y el 15/10"
        if ($consumir("/\\b(?:desde|del|entre)(?: el)?\\s+(?<a>{$fecha})\\s+(?:hasta|al|y|a)(?: el)?\\s+(?<z>{$fecha})\\b/", function (array $m) use ($hoy, $poner) {
            $desde = Texto::fecha($m['a'], $hoy);
            $hasta = Texto::fecha($m['z'], $hoy);
            $desde && $hasta && $poner(Periodo::fechas($desde, $hasta));
        })) {
            return;
        }

        $consumir("/\\bdesde(?: el)?\\s+(?<a>{$fecha})\\b/", function (array $m) use (&$b, $hoy) {
            ($f = Texto::fecha($m['a'], $hoy)) && $b['desde'] = $f->toDateString();
        });
        $consumir("/\\bhasta(?: el)?\\s+(?<a>{$fecha})\\b/", function (array $m) use (&$b, $hoy) {
            ($f = Texto::fecha($m['a'], $hoy)) && $b['hasta'] = $f->toDateString();
        });

        if (isset($b['desde']) || isset($b['hasta'])) {
            return;
        }

        // "últimos 6 meses", "el último año", "última semana"
        if ($consumir('/\bultim[oa]s?\s+(?:'.self::N.'\s+)?(?<u>dias?|semanas?|mes(?:es)?|trimestres?|anos?)\b/', function (array $m) use ($hoy, $poner) {
            $n = ($m['n'] ?? '') !== '' ? Texto::numero($m['n']) : 1;
            // Un trimestre son tres meses.
            if (str_starts_with($m['u'], 'trimestre') && $n) {
                $n *= 3;
                $m['u'] = 'mes';
            }
            $unidad = match (true) {
                str_starts_with($m['u'], 'dia') => 'dia',
                str_starts_with($m['u'], 'semana') => 'semana',
                str_starts_with($m['u'], 'mes') => 'mes',
                default => 'anio',
            };
            $n && $poner(Periodo::ultimos($n, $unidad, $hoy));
        })) {
            return;
        }

        $nombrados = [
            '/\besta semana\b/' => 'esta_semana', '/\b(?:la )?semana pasada\b/' => 'semana_pasada',
            '/\beste mes\b|\ben el mes\b/' => 'este_mes', '/\b(?:el )?mes pasado\b|\bel mes anterior\b/' => 'mes_pasado',
            '/\beste ano\b|\ben lo que va del ano\b|\bdurante el ano\b|\ben el ano\b/' => 'este_anio',
            '/\b(?:el )?ano pasado\b|\bel ano anterior\b/' => 'anio_pasado',
            '/\bayer\b/' => 'ayer', '/\bhoy\b/' => 'hoy',
        ];

        foreach ($nombrados as $regex => $nombre) {
            if ($consumir($regex, fn () => null)) {
                $poner(Periodo::de($nombre, $hoy));

                return;
            }
        }

        // "en octubre", "de marzo de 2025"
        if ($consumir("/\\b(?:(?:en|de|del|durante|mes de)\\s+)?(?<m>{$meses})(?:\\s+(?:de|del)\\s+(?<y>\\d{4}))?\\b/", function (array $m) use ($hoy, $poner) {
            $poner(Periodo::mes(Texto::MESES[$m['m']], ($m['y'] ?? '') !== '' ? (int) $m['y'] : null, $hoy));
        })) {
            return;
        }

        // "en 2025"
        $consumir('/\b(?:en|del|de|durante)\s+(?<y>20\d{2})\b/', function (array $m) use ($hoy, $poner) {
            $poner(Periodo::anio((int) $m['y'], $hoy));
        });
    }

    /**
     * La forma que se nombra ("barras", "caño", "chapa"). Se saca de la frase.
     *
     * @param  array<string, mixed>  $b
     */
    private function forma(string &$w, array &$b): void
    {
        $palabras = $w === '' ? [] : explode(' ', $w);

        for ($i = 0; $i < count($palabras); $i++) {
            foreach ([2, 1] as $largo) {
                if ($i + $largo > count($palabras)) {
                    continue;
                }

                $frase = implode(' ', array_slice($palabras, $i, $largo));

                if (! $this->esRelleno($frase) && $this->catalogo->forma($frase) !== null) {
                    $b['forma'] = $frase;
                    array_splice($palabras, $i, $largo);
                    $w = implode(' ', $palabras);

                    return;
                }
            }
        }
    }

    /**
     * El material que se nombra: el más largo que figure en el catálogo (nombre o alias).
     * Devuelve los otros materiales nombrados, que esta búsqueda no usa.
     *
     * @param  array<string, mixed>  $b
     * @return list<string>
     */
    private function material(string &$w, array &$b): array
    {
        $indice = $this->catalogo->materiales();
        $palabras = $w === '' ? [] : explode(' ', $w);
        $otros = [];

        for ($i = 0; $i < count($palabras); $i++) {
            for ($largo = min(4, count($palabras) - $i); $largo >= 1; $largo--) {
                $frase = implode(' ', array_slice($palabras, $i, $largo));

                if (! isset($indice[$frase]) || $this->esRelleno($frase)) {
                    continue;
                }

                if (! isset($b['material'])) {
                    $b['material'] = $indice[$frase];
                } else {
                    $otros[] = $indice[$frase];
                }

                array_splice($palabras, $i, $largo);
                $i--;

                break;
            }
        }

        $w = implode(' ', $palabras);

        return array_values(array_unique($otros));
    }

    /**
     * "316L", "c276", "2205": un código de aleación suelto, que figura en el nombre de algún material,
     * es el material (más preciso que la familia: "acero inoxidable 316L" busca 316L).
     *
     * @param  array<string, mixed>  $b
     */
    private function especificacionesDelMaterial(string &$w, array &$b): void
    {
        $indice = array_keys($this->catalogo->materiales());
        $quedan = [];

        foreach ($w === '' ? [] : explode(' ', $w) as $token) {
            $esCodigo = preg_match('/^[a-z]*\d[a-z0-9\-]*$/', $token) && (preg_match('/[a-z]/', $token) || strlen($token) >= 3);
            $figura = $esCodigo && array_filter($indice, fn ($clave) => str_contains(" {$clave} ", " {$token} ")) !== [];

            if ($figura) {
                $b['material'] = $token;

                continue;
            }

            $quedan[] = $token;
        }

        $w = implode(' ', $quedan);
    }

    private function esRelleno(string $frase): bool
    {
        foreach (explode(' ', $frase) as $palabra) {
            if (! in_array($palabra, self::RELLENO, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lo que quedó de la frase: los nombres propios (empresas) y las palabras sueltas que no se entendieron.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function sobrantes(string $original, string $w): array
    {
        $quedan = array_count_values(array_filter(explode(' ', $w)));
        $propios = [];
        $numeros = [];
        $restos = [];
        $actual = [];
        $cerrarNombre = function () use (&$propios, &$actual) {
            $actual !== [] && $propios[] = implode(' ', $actual);
            $actual = [];
        };

        foreach (array_values(array_filter(preg_split('/\s+/u', trim($original)) ?: [])) as $i => $token) {
            $norm = Texto::normalizar($token);

            if ($norm === '' || ($quedan[$norm] ?? 0) === 0) {
                $cerrarNombre();

                continue;
            }

            $quedan[$norm]--;

            if ($this->esRelleno($norm) || preg_match('/^(?:cotiz|cotic|mostr|busc|dam[eoa]|list|decim|traem|quier|necesit|hic|hiz|hech|teng|pas[eoa]|mand|envi|carg|emit|vend|ten[eg]|compr|pid[ie]|trabaj|usan|estan|queda|figur|aparec)/', $norm)) {
                $cerrarNombre();

                continue;
            }

            $limpio = trim($token, " \t\n\r\0\x0B.,;:!?¿¡\"'()");
            $mayuscula = $limpio !== '' && mb_strtoupper(mb_substr($limpio, 0, 1)) === mb_substr($limpio, 0, 1) && preg_match('/\p{L}/u', mb_substr($limpio, 0, 1));
            // Un número solo es un número (de una orden, de un comprobante), no el nombre de una empresa.
            if (ctype_digit($limpio)) {
                $cerrarNombre();
                strlen($limpio) >= 4 ? $numeros[] = $limpio : $restos[] = $limpio;

                continue;
            }

            // La primera palabra de la frase va con mayúscula siempre: sólo cuenta si está toda en mayúsculas o tiene números.
            $esNombre = $mayuscula && ($i > 0 || $limpio === mb_strtoupper($limpio)) || preg_match('/\d/', $limpio);

            if ($esNombre) {
                $actual[] = $limpio;
            } else {
                $cerrarNombre();
                $restos[] = $limpio;
            }
        }
        $cerrarNombre();

        return [$propios, array_values(array_unique($restos)), $numeros];
    }
}

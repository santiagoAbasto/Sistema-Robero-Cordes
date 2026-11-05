<?php

namespace App\Services\Cordes;

use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Material;
use App\Models\User;
use App\Services\Buscador\Catalogo;
use App\Services\Buscador\Filtros;
use App\Services\Buscador\Texto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Contesta con el código: ejecuta las consultas de un PLAN (ver Entiende) y arma la respuesta completa —texto, tablas,
 * atajos, opciones y sugerencias— sin IA.
 *
 * Todo lo que dice sale de lo que devolvieron las herramientas (las mismas que usa la IA, de solo lectura y con permisos),
 * así que no puede inventar: no hay nada que "comprobar", porque no pasó por ninguna boca que pueda equivocarse.
 * Cuando falta un dato para contestar (¿de qué material?, ¿qué medida?) lo pide con botones, con opciones sacadas de los
 * datos (los materiales más cotizados con densidad conocida, los schedules que existen para ese diámetro).
 *
 * Devuelve null sólo si no puede hacer nada útil: entonces lo intenta la IA.
 */
final class Redactor
{
    private readonly Herramientas $h;

    private readonly Respuesta $respuesta;

    /** @var list<string> */
    private array $pasos = [];

    public function __construct(private readonly User $usuario, private readonly Catalogo $catalogo)
    {
        $this->h = new Herramientas($usuario, $catalogo);
        $this->respuesta = new Respuesta($catalogo);
    }

    /**
     * @param  array{intencion: string, datos: array<string, mixed>}  $plan
     * @return array<string, mixed>|null
     */
    public function redactar(array $plan): ?array
    {
        $this->pasos = [];
        $d = $plan['datos'];

        $r = match ($plan['intencion']) {
            'ayuda' => $this->ayuda(),
            'convertir' => $this->convertir($d),
            'calcular' => $this->calcular($d),
            'cotizacion' => $this->cotizacion($d),
            'peso' => $this->peso($d),
            'cano' => $this->cano($d),
            'formula' => $this->formula($d),
            'precio' => $this->precio($d),
            'evolucion' => $this->evolucion($d),
            'material' => $this->material($d),
            'empresa' => $this->empresa($d),
            'ranking' => $this->ranking($d),
            'catalogo' => $this->catalogoDe($d),
            'agrupar' => $this->agrupar($d),
            default => null,
        };

        return $r === null ? null : $r + ['pasos' => $this->pasos];
    }

    /* ======================================================================= base */

    /** @param  array<string, mixed>  $sobre */
    private function resp(array $sobre): array
    {
        return $sobre + [
            'fuente' => 'codigo', 'mensaje' => null, 'verificada' => true, 'aviso' => null, 'pedido' => null, 'borrador' => null,
            'tablas' => [], 'atajos' => [], 'opciones' => null, 'seguir' => [], 'no_entendi' => [], 'pasos' => [], 'analisis' => null,
        ];
    }

    /** @param  array<string, mixed>  $args */
    private function consultar(string $herramienta, array $args): array
    {
        $this->pasos[] = $this->h->etiqueta($herramienta, $args);

        return $this->h->ejecutar($herramienta, $args);
    }

    /** @return array<string, mixed> */
    private function ruta(string $etiqueta, string $icono, string $ruta): array
    {
        return ['etiqueta' => $etiqueta, 'icono' => $icono, 'accion' => ['tipo' => 'ruta', 'ruta' => $ruta]];
    }

    /**
     * @param  array<string, string>  $pedido
     * @return array<string, mixed>
     */
    private function ver(string $etiqueta, array $pedido): array
    {
        return ['etiqueta' => $etiqueta, 'icono' => 'lista', 'accion' => ['tipo' => 'pedido', 'pedido' => $pedido]];
    }

    /** @return array<string, mixed> */
    private function preguntar(string $etiqueta, string $texto, string $icono = 'pregunta'): array
    {
        return ['etiqueta' => $etiqueta, 'icono' => $icono, 'accion' => ['tipo' => 'preguntar', 'texto' => $texto]];
    }

    /** @return list<array<string, mixed>> */
    private function tablas(array $resultado, int $max = 2): array
    {
        return array_map(fn ($t) => $this->respuesta->tabla($t), array_slice($resultado['tablas'] ?? [], 0, $max));
    }

    /** «una» o «un» según cómo se llama la forma: una BARRA, una CHAPA, un CAÑO, un TUBO. */
    private function un(string $nombre): string
    {
        $primera = preg_split('/[\s\/]+/', trim(Texto::normalizar($nombre)))[0] ?? '';

        return str_ends_with($primera, 'a') ? 'una' : 'un';
    }

    private function n(float|int|null $v, int $dec = 2): string
    {
        return $v === null ? '—' : number_format((float) $v, $dec, ',', '.');
    }

    /** Un número sin ceros de más: 38,1 y no 38,10. */
    private function m(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, ',', '.'), '0'), ',');
    }

    private function fecha(?string $iso): string
    {
        return $iso && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $x) ? "{$x[3]}/{$x[2]}/{$x[1]}" : '—';
    }

    /** @param  array<string, mixed>  $datos */
    private function conAyuda(string $mensaje, array $datos = []): array
    {
        return $this->resp(['mensaje' => $mensaje, 'seguir' => array_slice($this->ejemplos(), 0, 3)] + $datos);
    }

    /** @return list<string> */
    private function ejemplos(): array
    {
        return [
            '¿cuánto pesa una barra de monel de 38,1 x 145 mm?', '¿a cuánto cotizamos titanio grado 2 en chapa?', 'tabla de caños schedule 40',
            'fórmula de la barra redonda', 'contame de una empresa', 'cuántos mm son 1 1/2 pulgadas', '¿qué empresas más compran monel?',
        ];
    }

    /** Los materiales que más se cotizaron y de los que se sabe la densidad: lo que se ofrece cuando falta elegir uno. */
    private function materialesFrecuentes(int $limite = 6): array
    {
        return Cache::remember("cordes.materiales_frecuentes.{$limite}", 600, fn () => DB::table('consulta_lineas as l')
            ->join('materiales as m', 'm.id', '=', 'l.material_id')
            ->where('m.activo', true)->where('m.densidad', '>', 0)
            ->selectRaw('m.nombre, count(*) n')->groupBy('m.nombre')->orderByDesc('n')->limit($limite)->pluck('nombre')->all());
    }

    /* ====================================================================== ayuda */

    private function ayuda(): array
    {
        return $this->resp([
            'mensaje' => "¡Hola! Soy **Cordes**. Contestá con los datos del sistema, nunca de memoria. Preguntame, por ejemplo:\n"
                ."- **Pesos**: de una barra, chapa, caño, con las medidas que quieras (también con largos aleatorios).\n"
                ."- **Precios**: a cuánto se cotizó un material, por moneda y unidad.\n"
                ."- **Caños y schedules**: diámetros, paredes, kilos por metro.\n"
                ."- **Fórmulas y medidas** de cada forma, y datos de un **material**.\n"
                ."- **Empresas**: ficha, teléfono, mail, última cotización.\n"
                .'- **Cotizaciones** por su número, **listas** y **rankings**, y conversiones o cuentas.',
            'seguir' => array_slice($this->ejemplos(), 0, 5),
        ]);
    }

    /* =============================================================== conversiones */

    private function convertir(array $d): array
    {
        $r = $this->consultar('convertir', $d);

        if (isset($r['error'])) {
            return $this->conAyuda($r['error']);
        }

        // El valor tal como lo escribió la persona («1 1/2»), no el decimal que salió de leerlo.
        $texto = trim((string) ($d['valor'] ?? $r['valor'])).' '.$r['de'];

        return $this->resp([
            'mensaje' => "**{$texto} = {$this->m((float) $r['resultado'])} {$r['a']}**",
            'seguir' => ["¿Y {$this->m((float) $r['resultado'])} {$r['a']} a {$r['de']}?"],
        ]);
    }

    private function calcular(array $d): array
    {
        $r = $this->consultar('calcular', $d);

        if (isset($r['error'])) {
            return $this->conAyuda($r['error']);
        }

        return $this->resp(['mensaje' => '**'.($d['legible'] ?? $d['expresion'])." = {$this->n((float) $r['resultado'], fmod((float) $r['resultado'], 1) === 0.0 ? 0 : 2)}**"]);
    }

    /* ================================================================= cotización */

    private function cotizacion(array $d): array
    {
        $r = $this->consultar('cotizacion', ['numero' => $d['numero']]);

        if (isset($r['error'])) {
            return $this->conAyuda("No encontré la cotización **{$d['numero']}**. Revisá el número; puede estar con su revisión («R1»).");
        }

        $ve = $this->usuario->veImportes();
        $lineas = $r['renglones'] ?? [];
        $total = $ve && $r['total'] !== null ? ' Total: **'.$this->n($r['total'])." {$r['moneda']}**." : '';
        $filas = array_map(fn ($l) => array_values(array_filter([
            $l['descripcion'], $l['cantidad'] ?? '—', $l['unidad'] ?? '—', $ve ? (isset($l['precio_unitario']) ? $this->n($l['precio_unitario']) : '—') : null, $ve ? (isset($l['importe']) ? $this->n($l['importe']) : '—') : null,
        ], fn ($x) => $x !== null)), $lineas);

        return $this->resp([
            'mensaje' => "La **{$r['numero']}** es de **{$r['empresa']['nombre']}**, del {$this->fecha($r['fecha'])}, y está **{$r['estado']}**. Tiene ".count($lineas).' '.(count($lineas) === 1 ? 'renglón' : 'renglones').".{$total}",
            'tablas' => $filas === [] ? [] : [['titulo' => 'Renglones', 'columnas' => array_values(array_filter(['Descripción', 'Cant.', 'Unidad', $ve ? 'Precio' : null, $ve ? 'Importe' : null])), 'filas' => $filas, 'acciones' => array_fill(0, count($filas), [])]],
            'atajos' => [$this->ruta('Abrir la cotización', 'documento', "/consultas/{$r['id']}"), $this->ruta("Abrir la ficha de {$r['empresa']['nombre']}", 'empresa', "/empresas/{$r['empresa']['id']}")],
            'seguir' => ["contame de la empresa {$r['empresa']['nombre']} (id {$r['empresa']['id']})"],
        ]);
    }

    /* ======================================================================== peso */

    private function peso(array $d): array
    {
        if ($d['es_cano']) {
            return $this->pesoDeCano($d);
        }

        $texto = (string) $d['texto'];

        if (! $d['forma']) {
            $formas = Forma::query()->where('activo', true)->whereNotNull('expresion')->orderBy('orden')->limit(8)->pluck('nombre')->all();

            return $this->resp([
                'mensaje' => 'Para calcular el peso necesito saber **qué forma** es: barra, chapa, caño…',
                'opciones' => ['pregunta' => '¿Qué forma es?', 'opciones' => array_map(fn ($f) => ['etiqueta' => $f, 'enviar' => rtrim($texto, '?').' en '.mb_strtolower($f)], $formas)],
            ]);
        }

        $forma = Forma::query()->where('nombre', $d['forma'])->first();

        if (! $forma || ! $forma->tieneCalculoAutomatico()) {
            return $this->resp([
                'mensaje' => "La forma **{$d['forma']}** no tiene una fórmula de peso cargada, así que no puedo calcularlo sin inventar. Si querés, se la puede cargar en *Formas y fórmulas*.",
                'atajos' => [$this->ruta('Ir a Formas y fórmulas', 'lista', '/config/formas')],
            ]);
        }

        if (! $d['material']) {
            return $this->resp([
                'mensaje' => "Para calcular el peso de una **{$forma->nombre}** necesito saber **de qué material** es (cada uno tiene su densidad).",
                'opciones' => ['pregunta' => '¿De qué material?', 'opciones' => array_map(fn ($m) => ['etiqueta' => $m, 'enviar' => rtrim($texto, '?').' de '.$m], $this->materialesFrecuentes())],
            ]);
        }

        // Qué medidas pide y en qué orden se escriben: «Diámetro × Largo».
        $campos = collect($forma->camposDelCalculo())->where('tipo', 'numero')->keyBy('clave');
        $orden = array_values(array_intersect($forma->ordenEnQueSeEscriben(), $campos->keys()->all()));
        $faltan = array_values(array_diff($orden, array_keys($d['medidas'])));
        $etiquetas = implode(' × ', array_map(fn ($k) => $campos[$k]['label'], $orden));

        // Sólo falta el largo: se da el peso por metro, que es lo que se puede decir sin inventar.
        // (por metro sólo tiene sentido en lo que se vende por largo; una chapa no.)
        if ($faltan === ['length'] && count($orden) > 1 && ! preg_match('/^(?:CHAPA|PLACA|LAMINA|FLEJE)/', Texto::normalizar($forma->nombre) === '' ? '' : strtoupper(Texto::normalizar($forma->nombre)))) {
            $d['medidas']['length'] = ['valor' => 1, 'unidad' => 'm'];
            $porMetro = true;
        } elseif ($faltan !== []) {
            return $this->resp([
                'mensaje' => "Para {$this->un($forma->nombre)} **{$forma->nombre}** necesito: **{$etiquetas}**. Escribilo así: «cuánto pesa {$this->un($forma->nombre)} {$forma->nombre} de {$d['material']} de ".implode(' x ', array_map(fn ($k) => match ($k) {
                    'length' => '1000', 'width' => '1000', default => '20'
                }, $orden)).' mm».',
                'seguir' => [rtrim($texto, '?').' de 20 x 1000 mm'],
                // Marca interna: puede ser que la medida esté escrita de una forma que el código no lee (con palabras, rara); la IA lo intenta antes de pedirla.
                'necesita_medidas' => true,
            ]);
        } else {
            $porMetro = false;
        }

        $variable = $d['largo_variable'] ?? null;
        $casos = $variable
            ? [['Largo mínimo', $variable['min']], ['Largo promedio', $variable['promedio']], ['Largo máximo', $variable['max']]]
            : [['', null]];
        $resultados = [];

        foreach ($casos as [$etiqueta, $largo]) {
            $medidas = $d['medidas'];
            $variable && $largo !== null && $medidas['length'] = ['valor' => $largo, 'unidad' => $variable['unidad']];
            $r = $this->consultar('calcular_peso', [
                'material' => $d['material'], 'forma' => $forma->nombre, 'piezas' => $d['piezas'] ?? 1,
                'medidas' => array_map(fn ($k) => ['clave' => $k, 'valor' => $medidas[$k]['valor'], 'unidad' => $medidas[$k]['unidad']], array_keys($medidas)),
            ]);

            if (! ($r['ok'] ?? false)) {
                return $this->pesoNoSePudo($r, $texto, (string) $d['material'], $forma);
            }

            $resultados[] = [$etiqueta, $largo, $r];
        }

        [, , $r] = $resultados[$variable ? 1 : 0];
        $asumidas = $d['asumidas'] ?? [];
        $piezas = (float) ($d['piezas'] ?? 1);
        $medidasTexto = $this->medidasEnTexto($d['medidas'], $orden, $variable, $porMetro);
        $material = $r['material'];
        $nombreForma = mb_strtolower($forma->nombre);
        $femenina = str_ends_with(Texto::normalizar($nombreForma), 'a');
        $encabezado = ($piezas > 1 ? $this->m($piezas).' ' : ($femenina ? 'Una ' : 'Un '))."**{$nombreForma}** de **{$material}** de {$medidasTexto}";

        $frase = match (true) {
            $porMetro => "{$encabezado} pesa **{$this->n($r['peso_por_pieza_kg'], 4)} kg por metro**. Decime el largo y te doy el total.",
            $variable !== null => "{$encabezado}: con el largo promedio ({$this->m($variable['promedio'])} {$variable['unidad']}) pesa **{$this->n($r['peso_por_pieza_kg'], 4)} kg**".($piezas > 1 ? " cada una y **{$this->n($r['peso_total_kg'], 4)} kg** en total" : '').'. El sistema calcula el peso con el largo promedio.',
            $piezas > 1 => "{$encabezado}: cada pieza pesa **{$this->n($r['peso_por_pieza_kg'], 4)} kg** y las {$this->m($piezas)} pesan **{$this->n($r['peso_total_kg'], 4)} kg**.",
            default => "{$encabezado} pesa **{$this->n($r['peso_total_kg'], 4)} kg**.",
        };

        $filas = [
            ['Material', $material], ['Densidad', $this->n($r['densidad_g_cm3'], 4).' g/cm³'], ['Forma', $forma->nombre],
            ['Fórmula del volumen (cm³)', $this->formulaLegible((string) $r['formula_del_volumen_cm3'], $forma)],
            ['Volumen por pieza', $this->n($r['volumen_por_pieza_cm3'], 4).' cm³'], ['Peso por pieza', $this->n($r['peso_por_pieza_kg'], 4).' kg'],
        ];
        $piezas > 1 && $filas[] = ['Piezas', $this->m($piezas)] and $filas[] = ['Peso total', $this->n($r['peso_total_kg'], 4).' kg'];
        $tablas = [['titulo' => 'Cómo se calculó', 'columnas' => ['Dato', 'Valor'], 'filas' => $filas, 'acciones' => array_fill(0, count($filas), [])]];

        if ($variable) {
            $tablas[] = ['titulo' => 'Según el largo', 'columnas' => ['Largo', 'Peso por pieza (kg)', 'Peso total (kg)'], 'filas' => array_map(
                fn ($x) => [$x[0].' · '.$this->m((float) $x[1]).' '.$variable['unidad'], $this->n($x[2]['peso_por_pieza_kg'], 4), $this->n($x[2]['peso_total_kg'], 4)], $resultados,
            ), 'acciones' => array_fill(0, 3, [])];
        }

        $supuestos = [];

        foreach ($asumidas as $k => $u) {
            if (isset($d['medidas'][$k]) && ! ($k === 'length' && ($variable || $porMetro))) {
                $supuestos[] = 'tomé el '.mb_strtolower((string) ($campos[$k]['label'] ?? $k)).' como **'.$this->m((float) $d['medidas'][$k]['valor']).' '.$u.'** (sin unidad, un '.($k === 'length' ? 'largo así de chico' : 'valor así').' va en '.($u === 'm' ? 'metros' : $u).')';
            }
        }

        $supuestos !== [] && $frase .= "\n*Ojo: ".implode('; ', $supuestos).'. Si era otra unidad, decímelo y lo recalculo.*';

        $otros = array_values(array_filter($this->materialesFrecuentes(4), fn ($m) => Texto::normalizar($m) !== Texto::normalizar($material)));
        $original = (string) $d['texto'];
        $clave = $d['material_texto'] ?? null;
        $conMaterial = fn (string $nuevo) => $clave && stripos($original, $clave) !== false
            ? preg_replace('/'.preg_quote($clave, '/').'/i', $nuevo, $original, 1)
            : rtrim($original, '?').' de '.$nuevo;

        return $this->resp([
            'mensaje' => $frase,
            // Interno (lo usa Descompone para comparar y sumar sin volver a leer el texto); Cordes lo saca antes de contestar.
            '_calculo' => [
                'descripcion' => "{$nombreForma} de {$material}".($medidasTexto !== '' ? " de {$medidasTexto}" : ''), 'material' => $material, 'forma' => $forma->nombre,
                'kg_pieza' => (float) $r['peso_por_pieza_kg'], 'kg_total' => (float) $r['peso_total_kg'], 'piezas' => $piezas, 'por_metro' => $porMetro,
            ],
            'tablas' => $tablas,
            'atajos' => [$this->preguntar("¿A cuánto se cotizó {$material}?", "a cuánto cotizamos {$material} en ".mb_strtolower($forma->nombre), 'grafico'), $this->preguntar('Fórmula de esta forma', 'fórmula de '.mb_strtolower($forma->nombre), 'documento')],
            'seguir' => array_slice(array_merge(
                $piezas <= 1 && ! $variable ? [rtrim($original, '?').' x 10 piezas'] : [],
                array_map(fn ($m) => (string) $conMaterial($m), array_slice($otros, 0, 2)),
            ), 0, 3),
        ]);
    }

    /** @param  array<string, mixed>  $r */
    private function pesoNoSePudo(array $r, string $texto, string $material, Forma $forma): array
    {
        if (! empty($r['parecidos'])) {
            return $this->resp([
                'mensaje' => "No encontré el material **{$material}** en el catálogo. ¿Era alguno de estos?",
                'opciones' => ['pregunta' => '¿Cuál?', 'opciones' => array_map(fn ($m) => ['etiqueta' => $m, 'enviar' => rtrim($texto, '?').' — material '.$m], $r['parecidos'])],
            ]);
        }

        if (! empty($r['materiales_parecidos_con_densidad'])) {
            $nombres = array_map(fn ($s) => trim(preg_replace('/\s*\(.*$/', '', (string) $s) ?? (string) $s), $r['materiales_parecidos_con_densidad']);

            return $this->resp([
                'mensaje' => "Del **{$r['material']}** no tengo cargada la densidad, así que no puedo calcular el peso sin inventarla. Estos materiales parecidos sí la tienen:",
                'opciones' => ['pregunta' => '¿Calculo con otro?', 'opciones' => array_map(fn ($n) => ['etiqueta' => $n, 'enviar' => rtrim($texto, '?').' — material '.$n], array_slice($nombres, 0, 6))],
            ]);
        }

        return $this->resp(['mensaje' => 'No pude calcular el peso: '.($r['motivo'] ?? $r['error'] ?? 'falta un dato').'.']);
    }

    /** «38,1 mm × 6 m»: en el orden en que se escribe; con una sola unidad va al final, con varias cada número lleva la suya. */
    private function medidasEnTexto(array $medidas, array $orden, ?array $variable, bool $porMetro): string
    {
        $partes = [];
        $unidades = [];

        foreach ($orden as $k) {
            if (! isset($medidas[$k]) || ($k === 'length' && ($porMetro || $variable))) {
                continue;
            }

            $unidades[$k] = $medidas[$k]['unidad'];
            $partes[$k] = $this->m((float) $medidas[$k]['valor']);
        }

        $uno = count(array_unique($unidades)) <= 1;
        $texto = $uno
            ? implode(' × ', $partes).($unidades !== [] ? ' '.reset($unidades) : '')
            : implode(' × ', array_map(fn ($k, $v) => "{$v} {$unidades[$k]}", array_keys($partes), $partes));

        return $variable
            ? trim(($texto !== '' ? $texto.', ' : '')."largo variable de {$this->m($variable['min'])} a {$this->m($variable['max'])} {$variable['unidad']}")
            : $texto;
    }

    private function formulaLegible(string $expresion, Forma $forma): string
    {
        $t = $expresion;

        foreach ($forma->camposDelCalculo() as $c) {
            $t = preg_replace('/\b'.preg_quote($c['clave'], '/').'\b/', $c['label'], $t) ?? $t;
        }

        $t = preg_replace('/pow\(\s*([^,()]+?)\s*,\s*2\s*\)/', '$1²', $t) ?? $t;
        $t = preg_replace('/\bpi\b/', 'π', $t) ?? $t;

        // Los decimales como se escriben acá: 0,57735 y no 0.57735.
        $t = preg_replace('/(\d)\.(\d)/', '$1,$2', $t) ?? $t;

        return trim(str_replace(['*', '/'], ['×', '÷'], $t));
    }

    /* ======================================================================== caños */

    private function pesoDeCano(array $d): array
    {
        $c = $d['cano'];
        $texto = (string) $d['texto'];

        if ($c['nominal'] === null) {
            return $this->resp([
                'mensaje' => 'Para el peso de un caño necesito su **diámetro nominal** (en pulgadas o DN) y el **schedule**.',
                'opciones' => ['pregunta' => '¿Qué diámetro?', 'opciones' => array_map(fn ($n) => ['etiqueta' => $n.'"', 'enviar' => rtrim($texto, '?').' de '.$n.' pulgadas'], ['1/2', '1', '2', '3', '4', '6'])],
            ]);
        }

        if ($c['schedule'] === null) {
            return $this->pedirSchedule($c['nominal'], $texto);
        }

        if (! $d['material']) {
            return $this->resp([
                'mensaje' => "Para el peso del caño de **{$c['nominal']}** SCH **{$c['schedule']}** necesito **el material**.",
                'opciones' => ['pregunta' => '¿De qué material?', 'opciones' => array_map(fn ($m) => ['etiqueta' => $m, 'enviar' => rtrim($texto, '?').' de '.$m], $this->materialesFrecuentes())],
            ]);
        }

        $t = $this->consultar('canos', ['nominal' => $c['nominal'], 'schedule' => $c['schedule'], 'material' => $d['material']]);

        if (isset($t['error'])) {
            return $this->pedirSchedule($c['nominal'], $texto, $t['schedules_de_ese_diametro'] ?? []);
        }

        $fila = $t['canos'][0];

        if (! isset($fila['kg_por_metro'])) {
            return $this->resp(['mensaje' => "Del **{$t['material']}** no tengo cargada la densidad, así que no puedo calcular los kilos por metro sin inventarlos.", 'tablas' => $this->tablas($t)]);
        }

        $linea = "El caño de **{$c['nominal']}** SCH **{$c['schedule']}** de **{$t['material']}** (Ø exterior {$this->m($fila['diametro_exterior_mm'])} mm, pared {$this->m($fila['pared_mm'])} mm) pesa **{$this->n($fila['kg_por_metro'], 3)} kg por metro**.";

        if ($c['metros'] === null) {
            return $this->resp([
                'mensaje' => $linea.' Decime el largo y te doy el total.',
                '_calculo' => ['descripcion' => "caño de {$c['nominal']} SCH {$c['schedule']} de {$t['material']}", 'material' => $t['material'], 'forma' => $d['forma'] ?? 'CAÑO',
                    'kg_pieza' => (float) $fila['kg_por_metro'], 'kg_total' => (float) $fila['kg_por_metro'], 'piezas' => 1.0, 'por_metro' => true],
                'tablas' => $this->tablas($t),
                'seguir' => [rtrim($texto, '?').' de 6 metros'],
            ]);
        }

        $piezas = max(1.0, (float) ($d['piezas'] ?? 1));
        $p = $this->consultar('calcular_peso', ['material' => $d['material'], 'forma' => $d['forma'] ?? 'CAÑO', 'cano_id' => $fila['id'], 'piezas' => $piezas, 'medidas' => [['clave' => 'length', 'valor' => $c['metros'], 'unidad' => 'm']]]);

        if (! ($p['ok'] ?? false)) {
            return $this->resp(['mensaje' => $linea, 'tablas' => $this->tablas($t)]);
        }

        $resultado = $piezas > 1
            ? " Cada caño de **{$this->m($c['metros'])} m** pesa **{$this->n($p['peso_por_pieza_kg'], 4)} kg** y los **{$this->m($piezas, 0)} caños** pesan **{$this->n($p['peso_total_kg'], 4)} kg**."
            : " De **{$this->m($c['metros'])} m** pesa **{$this->n($p['peso_total_kg'], 4)} kg**.";

        return $this->resp([
            'mensaje' => $linea.$resultado,
            '_calculo' => ['descripcion' => "caño de {$c['nominal']} SCH {$c['schedule']} de {$t['material']} de {$this->m($c['metros'])} m", 'material' => $t['material'], 'forma' => $d['forma'] ?? 'CAÑO',
                'kg_pieza' => (float) $p['peso_por_pieza_kg'], 'kg_total' => (float) $p['peso_total_kg'], 'piezas' => $piezas, 'por_metro' => false],
            'tablas' => $this->tablas($t),
            'seguir' => [rtrim($texto, '?').' sch 80', "tabla de caños schedule {$c['schedule']}"],
        ]);
    }

    /** @param  list<string>  $existentes */
    private function pedirSchedule(string $nominal, string $texto, array $existentes = []): array
    {
        $t = $this->consultar('canos', ['nominal' => $nominal]);
        $schedules = $existentes ?: array_values(array_unique(array_column($t['canos'] ?? [], 'schedule')));

        if ($schedules === []) {
            return $this->resp(['mensaje' => "No encontré caños de **{$nominal}** en la tabla."]);
        }

        return $this->resp([
            'mensaje' => "Para el caño de **{$nominal}** hay estos schedules. Elegí uno:",
            'tablas' => $this->tablas($t),
            'opciones' => ['pregunta' => '¿Qué schedule?', 'opciones' => array_map(fn ($s) => ['etiqueta' => "SCH {$s}", 'enviar' => rtrim($texto, '?').' sch '.$s], array_slice($schedules, 0, 8))],
        ]);
    }

    private function cano(array $d): array
    {
        $texto = (string) $d['texto'];
        $nominal = $d['nominal'];
        $schedule = $d['schedule'];

        if ($nominal === null && $schedule === null) {
            return $this->resp([
                'mensaje' => 'La tabla de caños tiene los **diámetros nominales** de 1/4" a 36" y estos **schedules**. ¿Cuál querés ver?',
                'opciones' => ['pregunta' => '¿Qué schedule?', 'opciones' => array_map(fn ($s) => ['etiqueta' => "SCH {$s}", 'enviar' => "tabla de caños schedule {$s}"], ['5S', '10S', '40', '80', 'STD', 'XS'])],
            ]);
        }

        $t = $this->consultar('canos', ['nominal' => $nominal, 'schedule' => $schedule, 'material' => $d['material']]);

        if (isset($t['error'])) {
            return $nominal !== null ? $this->pedirSchedule($nominal, $texto, $t['schedules_de_ese_diametro'] ?? []) : $this->conAyuda($t['error']);
        }

        $f = $t['canos'];
        $mensaje = match (true) {
            $nominal !== null && $schedule !== null && count($f) === 1 => "El caño de **{$nominal}** SCH **{$schedule}** tiene Ø exterior **{$this->m($f[0]['diametro_exterior_mm'])} mm**, pared **{$this->m($f[0]['pared_mm'])} mm** y Ø interior **{$this->m($f[0]['diametro_interior_mm'])} mm**."
                .(isset($f[0]['kg_por_metro']) ? " En **{$t['material']}** pesa **{$this->n($f[0]['kg_por_metro'], 3)} kg por metro**." : ''),
            $nominal !== null && $schedule === null => "Para **{$nominal}** hay **".count($f).' schedules** en la tabla. Los muestro de menor a mayor pared.',
            default => "En **SCH {$schedule}** hay **".count($f).'** diámetros'.(count($f) >= 40 ? ' (muestro los primeros 40)' : '').'.',
        };

        return $this->resp([
            'mensaje' => $mensaje,
            'tablas' => $this->tablas($t),
            'seguir' => array_values(array_filter([
                $nominal !== null && $schedule !== null ? "cuánto pesa un caño de {$nominal} sch {$schedule} de monel de 6 metros" : null,
                $nominal !== null && $schedule === null ? "cuánto pesa un caño de {$nominal} sch 40 de monel de 6 metros" : null,
                $schedule !== null && $nominal === null ? 'tabla de caños schedule 80' : null,
            ])),
        ]);
    }

    /* ===================================================================== catálogo */

    /** «Qué materiales hay de titanio», «qué formas hay»: lo que tiene el catálogo, con sus datos. */
    private function catalogoDe(array $d): array
    {
        $this->pasos[] = 'Mirando el catálogo…';

        if ($d['que'] === 'formas') {
            $formas = Forma::query()->where('activo', true)->orderBy('orden')->orderBy('nombre')->get();
            $calculables = $formas->filter(fn (Forma $f) => filled($f->expresion) || $f->usa_cano);
            $filas = $formas->take(40)->map(function (Forma $f) {
                $campos = collect($f->camposDelCalculo())->where('tipo', 'numero')->keyBy('clave');
                $orden = array_values(array_intersect($f->ordenEnQueSeEscriben(), $campos->keys()->all())) ?: $campos->keys()->all();

                return [$f->nombre, $orden === [] ? '—' : implode(' × ', array_map(fn ($k) => $campos[$k]['label'], $orden)), filled($f->expresion) || $f->usa_cano ? 'Sí' : 'No'];
            })->values()->all();

            return $this->resp([
                'mensaje' => "Hay **{$formas->count()}** formas activas; en **{$calculables->count()}** se puede calcular el peso con su fórmula.".($formas->count() > 40 ? ' Muestro las primeras 40.' : ''),
                'tablas' => [['titulo' => 'Formas', 'columnas' => ['Forma', 'Medidas (como se escriben)', 'Calcula el peso'], 'filas' => $filas,
                    'acciones' => array_map(fn ($f) => [], $filas)]],
                'seguir' => ['fórmula de la barra redonda', 'cuánto pesa una chapa de monel de 3 x 1000 x 2000'],
            ]);
        }

        $termino = $d['termino'] ?? null;
        $q = Material::query()->where('activo', true);

        if ($termino === null || $termino === '') {
            $familias = Material::query()->where('activo', true)->whereNotNull('familia')
                ->selectRaw('familia, count(*) as n, sum(case when densidad > 0 then 1 else 0 end) as con_densidad')->groupBy('familia')->orderByDesc('n')->limit(20)->get();

            return $this->resp([
                'mensaje' => 'Hay **'.$this->n(Material::query()->where('activo', true)->count(), 0).'** materiales en el catálogo, en estas familias. Elegí una para ver sus materiales:',
                'tablas' => [['titulo' => 'Familias de materiales', 'columnas' => ['Familia', 'Materiales', 'Con densidad cargada'], 'filas' => $familias->map(fn ($f) => [$f->familia, (int) $f->n, (int) $f->con_densidad])->all(), 'acciones' => $familias->map(fn () => [])->all()]],
                'opciones' => ['pregunta' => '¿De qué familia?', 'opciones' => $familias->take(6)->map(fn ($f) => ['etiqueta' => $f->familia, 'enviar' => 'qué materiales hay de '.$f->familia])->all()],
            ]);
        }

        $t = Texto::normalizar($termino);
        $q->where(fn ($w) => $w->where('nombre', 'like', "%{$t}%")->orWhere('familia', 'like', "%{$t}%")->orWhereHas('alias', fn ($a) => $a->where('alias', 'like', "%{$t}%")));
        $total = (clone $q)->count();
        $materiales = $q->orderByRaw('densidad is null')->orderBy('nombre')->limit(30)->get();

        if ($materiales->isEmpty()) {
            return $this->resp(['mensaje' => "No hay materiales que coincidan con **{$termino}** en el catálogo.", 'seguir' => ['qué materiales hay']]);
        }

        $veces = DB::table('consulta_lineas')->whereIn('material_id', $materiales->pluck('id'))->selectRaw('material_id, count(*) n')->groupBy('material_id')->pluck('n', 'material_id');
        $filas = $materiales->map(fn ($m) => [$m->nombre, $m->familia ?: '—', $m->densidad && (float) $m->densidad > 0 ? $this->n((float) $m->densidad, 4) : '—', $m->uns ?: '—', (int) ($veces[$m->id] ?? 0)])->all();

        return $this->resp([
            'mensaje' => "Hay **{$this->n($total, 0)}** materiales que coinciden con «{$termino}»".($total > 30 ? ' (muestro 30)' : '').'. Los que tienen densidad cargada permiten calcular el peso.',
            'tablas' => [['titulo' => "Materiales: {$termino}", 'columnas' => ['Material', 'Familia', 'Densidad (g/cm³)', 'UNS', 'Cotizado'], 'filas' => $filas, 'acciones' => array_map(fn () => [], $filas)]],
            'seguir' => array_values(array_filter([$materiales->first() ? "a cuánto cotizamos {$materiales->first()->nombre}" : null, $materiales->first() ? "cuánto pesa una barra de {$materiales->first()->nombre} de 20 x 1000" : null])),
        ]);
    }

    /* ====================================================================== fórmula */

    private function formula(array $d): array
    {
        if (! $d['forma']) {
            $formas = Forma::query()->where('activo', true)->whereNotNull('expresion')->orderBy('orden')->limit(8)->pluck('nombre')->all();

            return $this->resp([
                'mensaje' => '¿De qué forma querés ver la fórmula?',
                'opciones' => ['pregunta' => '¿Qué forma?', 'opciones' => array_map(fn ($f) => ['etiqueta' => $f, 'enviar' => 'fórmula de '.mb_strtolower($f)], $formas)],
            ]);
        }

        $r = $this->consultar('formas', ['texto' => $d['forma']]);
        $f = $r['formas'][0] ?? null;

        if (! $f) {
            return $this->conAyuda("No encontré la forma **{$d['forma']}**.");
        }

        $forma = Forma::query()->find($f['id']);
        $etiquetas = collect($f['medidas'])->where('tipo', 'numero')->pluck('nombre', 'clave');
        $escritas = array_values(array_intersect($forma->ordenEnQueSeEscriben(), $etiquetas->keys()->all()));
        $medidas = implode(' × ', array_map(fn ($k) => $etiquetas[$k], $escritas ?: $etiquetas->keys()->all()));

        if (! $f['calcula_peso'] && ! $f['usa_cano']) {
            return $this->resp(['mensaje' => $medidas === ''
                ? "La **{$f['nombre']}** no tiene medidas ni fórmula de peso cargadas: no se calcula sola. Se pueden cargar en *Formas y fórmulas*."
                : "La **{$f['nombre']}** lleva estas medidas: **{$medidas}**, pero no tiene fórmula de peso cargada: no se calcula sola.",
                'atajos' => [$this->ruta('Ir a Formas y fórmulas', 'lista', '/config/formas')]]);
        }

        $legible = $f['formula_del_volumen_cm3'] ? $this->formulaLegible($f['formula_del_volumen_cm3'], $forma) : 'se toma del caño de la tabla (diámetro exterior y pared)';

        return $this->resp([
            'mensaje' => "Para {$this->un($f['nombre'])} **{$f['nombre']}** se miden: **{$medidas}**.\nEl volumen de una pieza (en cm³, con las medidas en mm) es: **{$legible}**.\nY el peso = volumen × densidad del material ÷ 1000.",
            'seguir' => ["cuánto pesa {$this->un($f['nombre'])} {$f['nombre']} de monel"],
        ]);
    }

    /* ===================================================================== material */

    private function material(array $d): array
    {
        $r = $this->consultar('material', ['texto' => $d['material']]);

        if (isset($r['error'])) {
            return $this->resp([
                'mensaje' => $r['error'],
                'opciones' => ! empty($r['parecidos']) ? ['pregunta' => '¿Era alguno de estos?', 'opciones' => array_map(fn ($m) => ['etiqueta' => $m, 'enviar' => 'datos del material '.$m], $r['parecidos'])] : null,
            ]);
        }

        $m = $r['materiales'][0];
        $dens = $m['densidad_g_cm3'] !== null ? "**{$this->n($m['densidad_g_cm3'], 4)} g/cm³**" : 'no está cargada';
        $mensaje = match ($d['aspecto']) {
            'densidad' => "La densidad del **{$m['nombre']}** es ".($m['densidad_g_cm3'] !== null ? $dens.'.' : 'no está cargada en el sistema, así que no puedo darte el dato ni calcular pesos de ese material.'),
            'normas' => "El **{$m['nombre']}**: UNS **".($m['uns'] ?: '—').'**, W.Nr **'.($m['w_nr'] ?: '—').'**.',
            'alias' => "El **{$m['nombre']}** también se escribe: ".($m['alias'] ? implode(', ', array_map(fn ($a) => "**{$a}**", $m['alias'])) : 'no tiene otros nombres cargados').'.',
            default => "El **{$m['nombre']}** es de la familia **".($m['familia'] ?: '—')."**. Densidad: {$dens}; UNS **".($m['uns'] ?: '—').'**; W.Nr **'.($m['w_nr'] ?: '—')."**. Se cotizó en **{$m['renglones_cotizados']}** renglones.",
        };

        $filas = array_map(fn ($x) => [$x['nombre'], $x['familia'] ?? '—', $x['densidad_g_cm3'] !== null ? $this->n($x['densidad_g_cm3'], 4) : '—', $x['uns'] ?: '—', $x['renglones_cotizados']], $r['materiales']);

        return $this->resp([
            'mensaje' => $mensaje.(count($r['materiales']) > 1 ? ' Hay otros parecidos en la tabla.' : ''),
            'tablas' => count($filas) > 1 ? [['titulo' => 'Materiales que coinciden', 'columnas' => ['Material', 'Familia', 'Densidad (g/cm³)', 'UNS', 'Cotizado'], 'filas' => $filas, 'acciones' => array_fill(0, count($filas), [])]] : [],
            'atajos' => [$this->preguntar("¿A cuánto se cotizó {$m['nombre']}?", "a cuánto cotizamos {$m['nombre']}", 'grafico')],
            'seguir' => $m['densidad_g_cm3'] !== null ? ["cuánto pesa una barra de {$m['nombre']} de 20 x 1000 mm"] : [],
        ]);
    }

    /* ======================================================================= precio */

    /** @param  array<string, mixed>  $d */
    private function evolucion(array $d): array
    {
        if (! $this->usuario->veImportes()) {
            return $this->resp(['mensaje' => 'No tenés permiso para ver precios ni importes. Si lo necesitás, pedile a un administrador que lo habilite en *Quién ve qué*.']);
        }

        $f = $d['filtros'];
        $texto = (string) $d['texto'];
        $por = $d['por'] === 'mes' ? 'mes' : 'anio';
        $empresa = null;

        if (! empty($d['empresa'])) {
            $e = $this->empresaPorNombre((string) $d['empresa'], $texto);

            if (isset($e['respuesta'])) {
                return $e['respuesta'];
            }

            $empresa = $e['empresa'];
            $f['empresa_id'] = $empresa->id;
        }

        if (array_intersect_key($f, array_flip(['material', 'material_id', 'forma', 'forma_id', 'empresa_id', 'diametro_desde', 'diametro_hasta'])) === []) {
            return $this->resp([
                'mensaje' => 'Decime **de qué** querés ver cómo se movió el precio: un material, una forma o una empresa.',
                'opciones' => ['pregunta' => '¿De qué material?', 'opciones' => array_map(fn ($m) => ['etiqueta' => $m, 'enviar' => "cómo viene el precio de {$m} por año"], $this->materialesFrecuentes())],
            ]);
        }

        $r = $this->consultar('evolucion_de_precios', ['filtros' => $f, 'por' => $por]);

        if (isset($r['error'])) {
            return $this->conAyuda($r['error']);
        }

        $de = trim(implode(' ', array_filter([$f['material'] ?? null, isset($f['forma']) ? 'en '.$f['forma'] : null, $empresa ? 'a '.$empresa->nombre : null]))) ?: 'eso';
        $pedido = $r['filtros_aplicados'] ?? [];

        if ($r['periodos'] === []) {
            return $this->resp(['mensaje' => "No encontré precios cotizados de **{$de}** para ver cómo se movieron.", 'pedido' => $pedido ?: null]);
        }

        // Cada moneda y unidad por separado (nunca se mezclan), de lo más viejo a lo más nuevo, y la variación entre la primera y la última.
        $grupos = collect($r['periodos'])->groupBy(fn ($p) => $p['moneda'].'|'.$p['unidad'])->sortByDesc(fn ($g) => $g->sum('renglones'))->take(2);
        $bloques = [];

        foreach ($grupos as $g) {
            $ordenados = $g->sortBy('periodo')->values()->take(-8);
            $primero = $ordenados->first();
            $ultimo = $ordenados->last();
            $u = $primero['unidad'] !== '—' ? " por {$primero['unidad']}" : '';
            $lineas = $ordenados->map(fn ($p) => "- **{$p['periodo']}**: promedio **{$this->n($p['promedio'])}** (entre **{$this->n($p['minimo'])}** y **{$this->n($p['maximo'])}**, {$p['renglones']} ".($p['renglones'] === 1 ? 'renglón' : 'renglones').')')->implode("\n");
            $variacion = '';

            if ($ordenados->count() >= 2 && $primero['promedio'] > 0) {
                $pct = ($ultimo['promedio'] - $primero['promedio']) / $primero['promedio'] * 100;
                $variacion = "\nDel **{$primero['periodo']}** al **{$ultimo['periodo']}** el promedio ".($pct >= 0 ? 'subió' : 'bajó').' **'.$this->n(abs($pct), 1).' %**.';
            }

            $bloques[] = "En **{$primero['moneda']}**{$u}:\n{$lineas}{$variacion}";
        }

        return $this->resp([
            'mensaje' => "Así se movió el precio de **{$de}** (promedio por ".($por === 'mes' ? 'mes' : 'año')."):\n".implode("\n\n", $bloques)."\n*El promedio junta todo lo cotizado con ese filtro, medidas distintas incluidas: para comparar de verdad, acotá por forma o medida.*".(count($grupos) < collect($r['periodos'])->groupBy(fn ($p) => $p['moneda'].'|'.$p['unidad'])->count() ? "\nHay más monedas y unidades en la tabla: no se mezclan." : ''),
            'tablas' => $this->tablas($r),
            'pedido' => $pedido ?: null,
            'atajos' => [$this->ruta('Ver el reporte por material', 'grafico', '/reportes?r=material')],
            'seguir' => array_values(array_filter([
                $por === 'anio' ? (preg_match('/por\s+a(?:ñ|n)o|por\s+anio/iu', $texto) ? preg_replace('/por\s+a(?:ñ|n)o|por\s+anio/iu', 'por mes', rtrim($texto, '?')) : rtrim($texto, '?').' por mes') : null,
                isset($f['material']) ? 'a cuánto cotizamos '.$f['material'] : null,
                isset($f['material']) && ! $empresa ? 'qué empresas más compran '.$f['material'] : null,
            ])),
        ]);
    }

    private function precio(array $d): array
    {
        if (! $this->usuario->veImportes()) {
            return $this->resp(['mensaje' => 'No tenés permiso para ver precios ni importes. Si lo necesitás, pedile a un administrador que lo habilite en *Quién ve qué*.']);
        }

        $f = $d['filtros'];
        $texto = (string) $d['texto'];
        $empresa = null;

        if (! empty($d['empresa'])) {
            $e = $this->empresaPorNombre((string) $d['empresa'], $texto);

            if (isset($e['respuesta'])) {
                return $e['respuesta'];
            }

            $empresa = $e['empresa'];
            $f['empresa_id'] = $empresa->id;
        }

        $hay = array_intersect_key($f, array_flip(['material', 'material_id', 'forma', 'forma_id', 'empresa_id', 'diametro_desde', 'diametro_hasta']));

        if ($hay === []) {
            return $this->resp([
                'mensaje' => 'Decime **de qué** querés saber el precio: un material, una forma o una empresa.',
                'opciones' => ['pregunta' => '¿De qué material?', 'opciones' => array_map(fn ($m) => ['etiqueta' => $m, 'enviar' => "a cuánto cotizamos {$m}"], $this->materialesFrecuentes())],
            ]);
        }

        // Una empresa sola, sin material ni forma: lo que se le cotizó en total.
        if ($empresa && array_diff_key($hay, ['empresa_id' => 1]) === []) {
            return $this->empresaDeLaPregunta($empresa, 'importes', $texto);
        }

        $r = $this->consultar('precios', ['filtros' => $f]);

        if (isset($r['error'])) {
            return $this->conAyuda($r['error']);
        }

        $pedido = $r['filtros_aplicados'] ?? [];
        $de = trim(implode(' ', array_filter([$f['material'] ?? null, isset($f['forma']) ? 'en '.$f['forma'] : null, $empresa ? 'a '.$empresa->nombre : null]))) ?: 'eso';

        if ($r['estadisticas'] === []) {
            return $this->resp(['mensaje' => "No encontré precios cotizados de **{$de}**.".(isset($f['desde']) ? ' Probá con otro período.' : ''), 'pedido' => $pedido ?: null]);
        }

        $lineas = [];

        foreach (array_slice($r['estadisticas'], 0, 3) as $e) {
            $u = $e['unidad'] !== '—' ? " por {$e['unidad']}" : ' (sin unidad cargada)';
            $lineas[] = "- En **{$e['moneda']}**{$u}: entre **{$this->n($e['minimo'])}** y **{$this->n($e['maximo'])}**; promedio **{$this->n($e['promedio'])}**"
                .($e['ultimo_precio'] !== null ? "; el último fue **{$this->n($e['ultimo_precio'])}** ({$this->fecha($e['ultima_fecha'])})" : '').' — '.$e['renglones'].' '.($e['renglones'] === 1 ? 'renglón' : 'renglones').'.';
        }

        // «último precio de…»: primero lo último que se cotizó, puntual; después las estadísticas.
        $ultima = '';

        if (preg_match('/\bultim[oa]s?\b/', Texto::normalizar($texto)) && ($u = ($r['ultimos'][0] ?? null)) !== null) {
            $ultima = "Lo último que se cotizó fue **{$this->n($u['precio'])}** en **{$u['moneda']}**".($u['unidad'] ? " por {$u['unidad']}" : '').' el '.$this->fecha($u['fecha'])." (a **{$u['empresa']}**: {$u['descripcion']}).\n";
        }

        $periodo = isset($f['desde']) ? ' entre el '.$this->fecha($f['desde']).' y el '.$this->fecha($f['hasta'] ?? $f['desde']) : '';
        $mensaje = $ultima."**{$de}** se cotizó en **".$this->n($r['renglones'], 0).'** '.($r['renglones'] === 1 ? 'renglón' : 'renglones')."{$periodo}:\n".implode("\n", $lineas);

        if (count($r['estadisticas']) > 3) {
            $mensaje .= "\nHay más monedas y unidades en la tabla: no se mezclan.";
        }

        return $this->resp([
            'mensaje' => $mensaje,
            'tablas' => $this->tablas($r),
            'pedido' => $pedido ?: null,
            'atajos' => [$this->ruta('Ver el reporte por material', 'grafico', '/reportes?r=material')],
            'seguir' => array_values(array_filter([
                ! isset($f['desde']) ? rtrim($texto, '?').' este año' : null,
                ! isset($f['moneda']) ? rtrim($texto, '?').' en dólares' : null,
                isset($f['material']) && ! $empresa ? 'qué empresas más compran '.$f['material'] : null,
            ])),
        ]);
    }

    /* ====================================================================== empresa */

    /**
     * Encuentra la empresa de la que se habla, o pide que se elija.
     *
     * @return array{empresa?: Empresa, respuesta?: array<string, mixed>}
     */
    private function empresaPorNombre(string $nombre, string $textoOriginal): array
    {
        if (preg_match('/^id:(\d+)$/', $nombre, $m)) {
            $e = Empresa::query()->find((int) $m[1]);

            return $e ? ['empresa' => $e] : ['respuesta' => $this->conAyuda('No encontré esa empresa.')];
        }

        $this->pasos[] = "Buscando la empresa «{$nombre}»…";
        $c = $this->h->empresasPorNombre($nombre, 6);

        if ($c->isEmpty()) {
            $parecidas = $this->empresasParecidas($nombre);

            return ['respuesta' => $this->resp([
                'mensaje' => "No encontré una empresa que se llame **{$nombre}**.".($parecidas ? ' ¿Será alguna de estas?' : ' Revisá cómo está escrito.'),
                'opciones' => $parecidas ? ['pregunta' => '¿Cuál?', 'opciones' => array_map(fn ($n) => ['etiqueta' => $n, 'enviar' => str_ireplace($nombre, $n, $textoOriginal)], $parecidas)] : null,
            ])];
        }

        $norm = Texto::normalizar($nombre);
        $exacta = $c->first(fn ($e) => Texto::normalizar($e->nombre) === $norm);

        if ($c->count() === 1 || $exacta) {
            $e = $exacta ?? $c->first();

            return ['empresa' => $e, 'parcial' => ! $exacta && ! str_starts_with(Texto::normalizar($e->nombre), $norm)];
        }

        $opciones = $c->take(6)->map(fn ($e) => [
            'etiqueta' => $e->nombre.(($e->localidad?->nombre ?? $e->provincia?->nombre) ? ' · '.($e->localidad?->nombre ?? $e->provincia?->nombre) : '').($e->activa ? '' : ' (inactiva)'),
            'enviar' => preg_replace('/'.preg_quote($nombre, '/').'/i', "{$e->nombre} (id {$e->id})", $textoOriginal, 1) ?? $textoOriginal,
        ])->all();

        return ['respuesta' => $this->resp([
            'mensaje' => "Hay **{$c->count()}** empresas que se llaman parecido a «{$nombre}». ¿Cuál es?",
            'opciones' => ['pregunta' => '¿De cuál empresa?', 'opciones' => $opciones],
        ])];
    }

    /** @return list<string> */
    private function empresasParecidas(string $nombre): array
    {
        $norm = Texto::normalizar($nombre);
        $nombres = Cache::remember('cordes.nombres_de_empresas', 1800, fn () => Empresa::query()->pluck('nombre')->all());
        $puntajes = [];

        foreach ($nombres as $n) {
            $nn = Texto::normalizar($n);
            $d = levenshtein(mb_substr($norm, 0, 40), mb_substr($nn, 0, 40));

            if ($d <= max(2, (int) floor(mb_strlen($norm) / 3))) {
                $puntajes[$n] = $d;
            }
        }

        asort($puntajes);

        return array_slice(array_keys($puntajes), 0, 5);
    }

    private function empresa(array $d): array
    {
        $e = $this->empresaPorNombre((string) $d['nombre'], (string) $d['texto']);

        if (isset($e['respuesta'])) {
            return $e['respuesta'];
        }

        $r = $this->empresaDeLaPregunta($e['empresa'], (string) $d['aspecto'], (string) $d['texto']);

        // Una sola empresa coincide, pero sólo en parte con lo escrito: se dice, por si era otra.
        if ($e['parcial'] ?? false) {
            $r['mensaje'] = "Encontré una sola empresa que coincide con «{$d['nombre']}»:\n".$r['mensaje'];
        }

        return $r;
    }

    private function empresaDeLaPregunta(Empresa $e, string $aspecto, string $texto): array
    {
        $r = $this->consultar('empresa', ['id' => $e->id]);

        if (isset($r['error'])) {
            return $this->conAyuda($r['error']);
        }

        $ve = $this->usuario->veImportes();
        $lugar = $r['ubicacion'] ? " ({$r['ubicacion']})" : '';
        $activa = $r['activa'] ? '' : ' Está **inactiva** (dada de baja).';
        $contactos = array_map(fn ($c) => '- **'.$c['nombre'].'**'.($c['cargo'] ? " · {$c['cargo']}" : '').($c['medios'] ? ': '.implode(' · ', $c['medios']) : '').($c['principal'] ? ' (principal)' : ''), $r['contactos']);

        $mensaje = match ($aspecto) {
            'contacto' => $contactos === []
                ? "**{$r['nombre']}** no tiene contactos cargados."
                : "Contactos de **{$r['nombre']}**:\n".implode("\n", $contactos),
            'ultima' => $r['ultima_cotizacion']
                ? "A **{$r['nombre']}** se le cotizó por última vez el **{$this->fecha($r['ultima_cotizacion'])}**. En total tiene **{$r['cotizaciones_firmes']}** ".($r['cotizaciones_firmes'] === 1 ? 'cotización' : 'cotizaciones').' (la primera, el '.$this->fecha($r['primera_cotizacion']).').'
                : "A **{$r['nombre']}** todavía no se le cotizó nada.",
            'importes' => $ve
                ? ($r['cotizado_por_moneda'] ?? []) === [] ? "A **{$r['nombre']}** no se le cotizó nada con importes." : "A **{$r['nombre']}** se le cotizó: ".implode(' · ', array_map(fn ($c) => "**{$this->n($c['importe'])} {$c['moneda']}**", $r['cotizado_por_moneda'])).' ('.$r['cotizaciones_firmes'].' cotizaciones firmes).'
                : 'No tenés permiso para ver importes.',
            'datos' => "**{$r['nombre']}**{$lugar}. CUIT: **".($r['cuit'] ?: '—').'**. IVA: **'.($r['condicion_iva'] ?: '—').'**. Pago: **'.($r['condicion_pago'] ?: '—').'**.'.$activa,
            default => "**{$r['nombre']}**{$lugar}".($r['rubro'] ? ", {$r['rubro']}" : '').".{$activa} Tiene **{$r['cotizaciones_firmes']}** ".($r['cotizaciones_firmes'] === 1 ? 'cotización' : 'cotizaciones')
                .($r['ultima_cotizacion'] ? ", la última el **{$this->fecha($r['ultima_cotizacion'])}**" : '').'.'
                .($r['materiales_mas_cotizados'] ? "\nLo que más se le cotizó: ".implode(', ', array_map(fn ($m) => preg_replace('/:\s*(\d+) renglones/', ' ($1)', $m), array_slice($r['materiales_mas_cotizados'], 0, 4))).'.' : '')
                .($contactos !== [] ? "\n".implode("\n", array_slice($contactos, 0, 3)) : ''),
        };

        $id = $e->id;
        $atajos = array_values(array_filter([
            $this->ruta('Abrir la ficha', 'empresa', "/empresas/{$id}"),
            $r['activa'] ? $this->ruta('Cotizar', 'mas', "/empresas/{$id}/agregar") : null,
            $this->ver('Ver sus cotizaciones', ['grano' => 'consultas', 'empresa_id' => (string) $id]),
            $this->ver('Ver lo que se le cotizó', ['grano' => 'renglones', 'empresa_id' => (string) $id]),
        ]));

        return $this->resp([
            'mensaje' => $mensaje,
            'atajos' => $atajos,
            'seguir' => array_values(array_filter([
                $aspecto !== 'contacto' ? "teléfono y mail de {$r['nombre']} (id {$id})" : null,
                $aspecto !== 'ultima' ? "cuándo fue la última cotización a {$r['nombre']} (id {$id})" : null,
                $ve && $aspecto !== 'importes' ? "cuánto le cotizamos a {$r['nombre']} (id {$id})" : null,
            ])),
        ]);
    }

    /* =========================================================== ranking y agrupar */

    private function ranking(array $d): array
    {
        $por = $d['por'];
        $vista = $por === 'material' || $por === 'forma' ? 'renglones' : 'consultas';
        $filtros = $d['filtros'];
        $vendidas = $d['medida'] === 'vendidas';
        $vendidas && $filtros['compra'] = 'vendida';

        $r = $this->consultar('agrupar', ['vista' => $vista, 'filtros' => $filtros, 'por' => $por, 'limite' => 12]);

        if (isset($r['error'])) {
            return $this->conAyuda($r['error']);
        }

        // «Sin dato» no es un material ni una forma: son renglones del sistema viejo que no se pudieron reconocer.
        $sinDato = collect($r['grupos'])->first(fn ($g) => $g['etiqueta'] === 'Sin dato');
        $grupos = array_values(array_filter($r['grupos'], fn ($g) => $g['etiqueta'] !== 'Sin dato'));
        $texto = (string) $d['texto'];

        if ($grupos === []) {
            return $this->resp([
                'mensaje' => $vendidas
                    ? 'Todavía no hay ventas registradas con esos filtros: el sistema viejo no guardó qué se vendió, así que lo vendido cuenta desde que se usa este sistema.'
                    : 'No encontré datos para armar ese ranking con esos filtros.',
                'pedido' => $r['filtros_aplicados'] ?: null,
                'seguir' => $vendidas ? [str_ireplace(['compran', 'compraron', 'vendidas', 'vendimos'], 'cotizamos', $texto)] : [],
            ]);
        }

        $nombres = ['empresa' => 'Las empresas', 'material' => 'Los materiales', 'forma' => 'Las formas', 'usuario' => 'Los vendedores'][$por] ?? 'Los primeros';
        $que = $vendidas ? 'compradas' : 'cotizaciones';
        $top = array_slice($grupos, 0, 3);
        $mensaje = "{$nombres} con más {$que}: ".implode(', ', array_map(fn ($g, $i) => ($i + 1).'. **'.$g['etiqueta'].'** ('.$this->n($g['cantidad'], 0).')', $top, array_keys($top))).'.';
        $sinDato && $mensaje .= ' *No cuento '.$this->n($sinDato['cantidad'], 0).' renglones del sistema viejo cuyo material no se pudo reconocer.*';

        $tablas = $this->tablas($r);
        $tablas === [] || $tablas[0] = $this->respuesta->tabla(['titulo' => $tablas[0]['titulo'], 'columnas' => $tablas[0]['columnas'], 'filas' => array_map(fn ($g) => [$g['etiqueta'], $g['cantidad']], $grupos),
            'acciones' => array_map(fn ($g) => $g['filtro'] ? [['tipo' => 'pedido', 'pedido' => $g['filtro'] + ($r['filtros_aplicados'] ?? [])]] : [], $grupos)]);

        return $this->resp([
            'mensaje' => $mensaje,
            'tablas' => $tablas,
            'pedido' => $r['filtros_aplicados'] ?: null,
            'seguir' => array_values(array_filter([! isset($filtros['desde']) ? rtrim($texto, '?').' este año' : null, 'cuántas cotizaciones por mes'])),
        ]);
    }

    private function agrupar(array $d): array
    {
        $r = $this->consultar('agrupar', ['vista' => $d['vista'], 'filtros' => $d['filtros'], 'por' => $d['por'], 'limite' => $d['por'] === 'mes' ? 24 : 12]);

        if (isset($r['error'])) {
            return $this->conAyuda($r['error']);
        }

        if ($r['grupos'] === []) {
            return $this->resp(['mensaje' => 'No encontré nada para repartir con esos filtros.', 'pedido' => $r['filtros_aplicados'] ?: null]);
        }

        $cosa = ['empresas' => 'empresas', 'consultas' => 'cotizaciones y pedidos', 'renglones' => 'renglones', 'comprobantes' => 'facturas y remitos'][$r['vista']];
        $max = collect($r['grupos'])->sortByDesc('cantidad')->first();
        $mensaje = 'Hay **'.$this->n($r['total'], 0)."** {$cosa}, repartidas por **{$d['por']}**. El que más tiene es **{$max['etiqueta']}** ({$this->n($max['cantidad'], 0)}).";

        if ($r['vista'] === 'empresas' && preg_match('/cotizaci|pedido|presupuesto|renglon|factura/', Texto::normalizar((string) ($d['texto'] ?? '')))) {
            $mensaje .= " *Por {$d['por']} sólo puedo contar empresas, no cotizaciones.*";
        }

        return $this->resp([
            'mensaje' => $mensaje,
            'tablas' => $this->tablas($r),
            'pedido' => $r['filtros_aplicados'] ?: null,
        ]);
    }

    /** Útil para pruebas y para quien llama: el pedido saneado. */
    public static function sanear(array $p): array
    {
        return Filtros::sanear($p);
    }
}

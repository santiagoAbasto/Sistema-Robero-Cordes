<?php

namespace App\Services\Cordes;

use App\Models\User;
use App\Services\Buscador\Catalogo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El ciclo de la IA con herramientas: la persona pregunta, la IA decide qué consultar, el sistema ejecuta las
 * consultas (Herramientas) y se las devuelve, hasta que la IA responde.
 *
 * Lo que lo hace seguro:
 *  · La IA sólo puede pedir herramientas de solo lectura, que respetan los permisos de la persona.
 *  · Todo lo que dice se comprueba con el Verificador: una cifra o un nombre que no salió de una herramienta
 *    se le devuelve para que lo corrija. Si no puede, Respuesta no muestra su texto.
 *  · Tope de pasos y de tiempo, y cada paso se avisa (para que la pantalla muestre qué se está mirando).
 *
 * No decide nada por sí mismo: devuelve lo que dijo la IA y los datos que consultó. Respuesta lo valida.
 */
final class Agente
{
    public const MAX_PASOS = 8;

    /** Cuántas consultas puede pedir de una vez (en paralelo) en un mismo paso. */
    public const MAX_JUNTAS = 6;

    public const SEGUNDOS = 55;

    public const MAX_CORRECCIONES = 2;

    public function __construct(private readonly User $usuario, private readonly ?Catalogo $catalogo = null) {}

    public static function estaConfigurada(): bool
    {
        return filled(config('services.openai.key'));
    }

    /**
     * @param  list<array{rol: string, texto: string}>  $historial  las últimas vueltas de la conversación
     * @param  array<string, mixed>  $contexto  `filtros` y `vista` de la búsqueda anterior; `pista` de las reglas
     * @param  (callable(string): void)|null  $alPaso  se llama con lo que se está haciendo, en castellano
     * @return array{modelo: array<string, mixed>, tablas: array<string, array<string, mixed>>, verificacion: array{ok: bool, sin_comprobar: list<string>},
     *               pasos: list<string>, herramientas: list<string>, tokens: int, milisegundos: int, ultimas_tablas: list<string>, pedido_de_herramientas: ?array<string, string>, no_entendi_de_herramientas: list<string>}
     *
     * @throws \RuntimeException con el motivo, en criollo, si la IA no pudo
     */
    public function responder(string $texto, array $historial = [], array $contexto = [], ?callable $alPaso = null): array
    {
        $inicio = microtime(true);
        $catalogo = $this->catalogo ?? new Catalogo;
        $herramientas = new Herramientas($this->usuario, $catalogo);
        $verificador = new Verificador;
        $verificador->agregar($texto);

        $mensajes = [['role' => 'system', 'content' => Conocimiento::prompt($this->usuario, $catalogo)]];

        foreach (array_slice($historial, -8) as $h) {
            $verificador->agregar((string) ($h['texto'] ?? ''));
            $mensajes[] = ['role' => ($h['rol'] ?? '') === 'cordes' ? 'assistant' : 'user', 'content' => mb_substr((string) ($h['texto'] ?? ''), 0, 1500)];
        }

        $mensajes[] = ['role' => 'user', 'content' => $this->preguntaConContexto($texto, $contexto)];

        $tablas = [];
        $respondidas = [];
        $pasos = [];
        $usadas = [];
        $tokens = 0;
        $correcciones = 0;
        $ultimasTablas = [];
        $ambiguas = [];
        $ultimoPedido = null;
        $noEntendi = [];
        $final = null;
        $verificacion = ['ok' => true, 'sin_comprobar' => []];

        for ($paso = 0; $paso < self::MAX_PASOS; $paso++) {
            $this->haySiTiempo($inicio);
            $m = $this->llamar($mensajes, $herramientas->definiciones(), $tokens);
            $mensajes[] = array_filter(['role' => 'assistant', 'content' => $m['content'] ?? null, 'tool_calls' => $m['tool_calls'] ?? null], fn ($v) => $v !== null);

            if (! empty($m['tool_calls'])) {
                $ultimasTablas = [];

                foreach (array_values($m['tool_calls']) as $n => $llamada) {
                    $nombre = (string) data_get($llamada, 'function.name');
                    $args = json_decode((string) data_get($llamada, 'function.arguments', '{}'), true);
                    $args = is_array($args) ? $args : [];

                    // Demasiadas a la vez: se contestan las primeras y a las demás se les pide que esperen (cada llamada necesita su respuesta).
                    if ($n >= self::MAX_JUNTAS) {
                        $mensajes[] = ['role' => 'tool', 'tool_call_id' => (string) ($llamada['id'] ?? ''), 'content' => json_encode(['error' => 'Demasiadas consultas juntas: hacé ésta en el próximo paso.'])];

                        continue;
                    }

                    // La misma consulta con los mismos datos no se repite: se le devuelve lo que ya salió.
                    $clave = $nombre.'|'.json_encode($args, JSON_UNESCAPED_UNICODE);

                    if (isset($respondidas[$clave])) {
                        $mensajes[] = ['role' => 'tool', 'tool_call_id' => (string) ($llamada['id'] ?? ''), 'content' => $respondidas[$clave]];

                        continue;
                    }

                    $etiqueta = $herramientas->etiqueta($nombre, $args);
                    $pasos[] = $etiqueta;
                    $usadas[] = $nombre;
                    $alPaso && $alPaso($etiqueta);

                    $resultado = $herramientas->ejecutar($nombre, $args);

                    foreach ($resultado['tablas'] ?? [] as $i => $tabla) {
                        $ref = 't'.(count($tablas) + 1);
                        $resultado['tablas'][$i]['ref'] = $ref;
                        $tablas[$ref] = $tabla;
                        $ultimasTablas[] = $ref;
                    }

                    // Si dos empresas se llaman parecido, la pregunta de cuál es se arma acá: no depende de que la IA se acuerde.
                    foreach ($resultado['candidatas'] ?? [] as $c) {
                        $ambiguas[] = ['etiqueta' => $c['nombre'].(filled($c['localidad'] ?? null) || filled($c['provincia'] ?? null) ? ' · '.($c['localidad'] ?? $c['provincia']) : ''),
                            'enviar' => 'Contame de la empresa '.$c['nombre'].' (id '.$c['id'].')'];
                    }

                    // La última búsqueda que consultó es lo que corresponde mostrar abajo, aunque la IA no lo haya dicho.
                    if (in_array($nombre, ['buscar', 'agrupar', 'precios', 'evolucion_de_precios'], true) && ! isset($resultado['error']) && is_array($resultado['filtros_aplicados'] ?? null)) {
                        $ultimoPedido = $resultado['filtros_aplicados'];
                        $noEntendi = array_merge($noEntendi, array_map('strval', (array) ($resultado['no_entendi'] ?? [])));
                    }

                    $verificador->agregar($resultado);
                    $contenido = json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                    $respondidas[$clave] = $contenido;
                    $mensajes[] = ['role' => 'tool', 'tool_call_id' => (string) ($llamada['id'] ?? ''), 'content' => $contenido];
                }

                continue;
            }

            $final = $this->leerJson((string) ($m['content'] ?? ''));
            $malos = $verificador->sinComprobar($this->textoAComprobar($final));

            if ($malos === []) {
                $verificacion = ['ok' => true, 'sin_comprobar' => []];
                break;
            }

            if ($correcciones >= self::MAX_CORRECCIONES) {
                $verificacion = ['ok' => false, 'sin_comprobar' => $malos];
                break;
            }

            $correcciones++;
            $pasos[] = 'Comprobando las cifras contra los datos…';
            $alPaso && $alPaso('Comprobando las cifras contra los datos…');
            $mensajes[] = ['role' => 'user', 'content' => 'VERIFICACIÓN DEL SISTEMA: estas cifras o nombres de tu respuesta NO salen de ninguna herramienta ni de lo que escribió la persona: '
                .json_encode($malos, JSON_UNESCAPED_UNICODE).'. Corregí: usá sólo lo que devolvieron las herramientas (llamá una si te falta el dato; para cuentas usá `calcular`) o sacá esas partes. Devolvé el JSON completo de nuevo.'];
            $final = null;
        }

        if ($final === null) {
            // Se acabaron los pasos: se le pide que cierre con lo que tiene, sin herramientas.
            $this->haySiTiempo($inicio);
            $mensajes[] = ['role' => 'user', 'content' => 'Se acabó el tiempo de consulta. Respondé AHORA con el JSON final usando sólo lo que ya consultaste; si falta algo, decilo.'];
            $m = $this->llamar($mensajes, [], $tokens);
            $final = $this->leerJson((string) ($m['content'] ?? ''));
            $malos = $verificador->sinComprobar($this->textoAComprobar($final));
            $verificacion = ['ok' => $malos === [], 'sin_comprobar' => $malos];
        }

        if (count($ambiguas) >= 2 && empty($final['opciones'])) {
            $final['opciones'] = ['pregunta' => '¿De cuál empresa?', 'opciones' => array_slice($ambiguas, 0, 6)];
        }

        $milisegundos = (int) ((microtime(true) - $inicio) * 1000);

        // Lo que se guarda para auditar: qué se preguntó, qué consultó y cuánto costó. Nada de los datos.
        Log::info('Cordes IA', ['usuario' => $this->usuario->id, 'pregunta' => mb_substr($texto, 0, 200), 'herramientas' => $usadas, 'pasos' => count($pasos),
            'correcciones' => $correcciones, 'verificada' => $verificacion['ok'], 'sin_comprobar' => $verificacion['sin_comprobar'], 'tokens' => $tokens, 'ms' => $milisegundos]);

        return ['modelo' => $final, 'tablas' => $tablas, 'verificacion' => $verificacion, 'pasos' => $pasos, 'herramientas' => $usadas,
            'tokens' => $tokens, 'milisegundos' => $milisegundos, 'ultimas_tablas' => $ultimasTablas, 'pedido_de_herramientas' => $ultimoPedido, 'no_entendi_de_herramientas' => array_values(array_unique($noEntendi))];
    }

    /** @param  array<string, mixed>  $contexto */
    private function preguntaConContexto(string $texto, array $contexto): string
    {
        $partes = [$texto];

        if (! empty($contexto['filtros']) || ! empty($contexto['vista'])) {
            $partes[] = '[Lo que la persona está viendo ahora en el Buscador (de la respuesta anterior): '
                .json_encode(['vista' => $contexto['vista'] ?? null, 'filtros' => $contexto['filtros'] ?? new \stdClass], JSON_UNESCAPED_UNICODE).'. Si la pregunta sigue ese hilo ("y de Córdoba", "ahora en dólares"), partí de esos filtros y cambiá sólo lo que pide.]';
        }

        if (! empty($contexto['pista'])) {
            $partes[] = '[Pista del sistema, puede estar incompleta o equivocada: '.json_encode($contexto['pista'], JSON_UNESCAPED_UNICODE).']';
        }

        return implode("\n\n", $partes);
    }

    /**
     * @param  list<array<string, mixed>>  $mensajes
     * @param  list<array<string, mixed>>  $herramientas
     * @return array<string, mixed> el mensaje de la IA
     */
    private function llamar(array $mensajes, array $herramientas, int &$tokens): array
    {
        $modelos = array_values(array_unique(array_filter([config('services.openai.modelo_cordes'), config('services.openai.model')])));
        $ultimo = null;

        foreach ($modelos as $modelo) {
            $cuerpo = ['model' => $modelo, 'temperature' => 0.1, 'max_tokens' => 1600, 'messages' => $mensajes]
                + ($herramientas !== [] ? ['tools' => $herramientas, 'tool_choice' => 'auto'] : ['response_format' => ['type' => 'json_object']]);

            $r = Http::withToken((string) config('services.openai.key'))
                ->timeout(min(35, (int) config('services.openai.timeout', 30)))
                ->post(rtrim((string) config('services.openai.url'), '/').'/chat/completions', $cuerpo);

            if ($r->successful()) {
                $tokens += (int) data_get($r->json(), 'usage.total_tokens', 0);

                return (array) data_get($r->json(), 'choices.0.message', []);
            }

            Log::warning('OpenAI respondio '.$r->status().' a Cordes IA ('.$modelo.'): '.mb_substr($r->body(), 0, 300));
            $ultimo = $r->status();

            // Un modelo que esta cuenta no tiene: se prueba con el de siempre. Cualquier otro error, no.
            if (! in_array($r->status(), [400, 403, 404], true)) {
                break;
            }
        }

        throw new \RuntimeException(match (true) {
            in_array($ultimo, [401, 403], true) => 'La credencial de la IA no sirve o venció',
            $ultimo === 429 => 'La cuenta de la IA no tiene crédito o llegó al límite de consultas',
            $ultimo >= 500 => 'El servicio de IA no está respondiendo',
            default => 'La IA no pudo contestar',
        });
    }

    private function haySiTiempo(float $inicio): void
    {
        if (microtime(true) - $inicio > self::SEGUNDOS) {
            throw new \RuntimeException('La consulta tardó demasiado');
        }
    }

    /** @return array<string, mixed> */
    private function leerJson(string $contenido): array
    {
        $limpio = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($contenido)) ?? '');
        $d = json_decode($limpio, true);

        if (! is_array($d) && preg_match('/\{.*\}/s', $limpio, $m)) {
            $d = json_decode($m[0], true);
        }

        // Si contestó con texto suelto, es el mensaje: igual se verifica.
        return is_array($d) ? $d : ['mensaje' => mb_substr($limpio, 0, 1500)];
    }

    /** Lo que dice la IA y que se le muestra a la persona: eso es lo que se comprueba. */
    private function textoAComprobar(array $final): string
    {
        $partes = [(string) ($final['mensaje'] ?? '')];

        foreach ((array) ($final['atajos'] ?? []) as $a) {
            is_array($a) && $partes[] = (string) ($a['etiqueta'] ?? '');
        }

        if (is_array($final['opciones'] ?? null)) {
            $partes[] = (string) ($final['opciones']['pregunta'] ?? '');
        }

        return implode("\n", $partes);
    }
}

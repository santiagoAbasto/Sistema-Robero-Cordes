<?php

namespace App\Services\Cordes;

use App\Models\User;
use App\Services\Buscador\Catalogo;
use App\Services\Buscador\Filtros;
use App\Services\Buscador\Interprete;
use App\Services\Buscador\InterpretePorReglas;
use App\Services\Buscador\ResolverDePedido;
use App\Services\Buscador\Texto;
use Illuminate\Support\Facades\Log;

/**
 * Cordes IA: una pregunta, una respuesta que se puede confiar. Siempre hay respuesta.
 *
 * El orden, de lo más rápido a lo más poderoso:
 *
 *  1. EL MOTOR DE CÓDIGO (Entiende + Redactor): lo que se entiende con el catálogo y los datos se contesta al instante, sin IA.
 *  2. UNA LISTA que las reglas entienden por completo ("cotizaciones sin respuesta de este mes"): al instante, con cifras y atajos.
 *  3. LA IA (Agente), automáticamente, para lo complejo: preguntas que juntan varias cosas, frases raras, preguntas abiertas. Cada cifra
 *     que dice se comprueba (Verificador).
 *  4. Si la IA no está, no contesta o no se puede comprobar: EL CÓDIGO LO RESUELVE IGUAL (Descompone: parte la pregunta, le pasa cada parte
 *     al motor y arma la comparación, el total o las dos respuestas). Y lo que ni así se entiende se busca como texto en todo el sistema,
 *     con cifras por vista y botones: nunca se queda sin respuesta, y nunca dice "no sé, necesito la IA".
 *
 * Sólo lee. Respeta quién ve qué.
 */
final class Cordes
{
    /** Preguntas que piden SABER algo (un dato, una cuenta), no una lista: las reglas no las pueden contestar. */
    private const PIDE_SABER = '/\b(?:cuanto (?:pesa|pesan|cuesta|sale|vale|le cotizamos|les cotizamos|cotizamos|vendimos|facturamos|mide|da|es)|pesa|peso|pesos? de|kilos|densidad|formula|que es|que significa|'
        .'diferencia|compar\w*|promedio|mediana|minimo|maximo|mas barat\w*|mas car\w*|precios?|a cuanto|ultima vez|cuando fue|quien (?:compra|compro|es)|ranking|mejor(?:es)? cliente\w*|'
        .'convert\w*|pasar a|equivale|norma|uns|telefono|mail de|correo de|contacto|direccion|cuit de|datos de|explic\w*|como se calcula|cuantos? (?:mm|pulgadas|metros|kg)|'
        .'que (?:schedule|material|forma)|sirve|conviene|recomend\w*|cual es|cuales son|hay (?:alguna|algun)|salen? de|sacar de|se sacan|entran? en|caben?|rendimiento|aprovech\w*|merma|desperdicio|nesting|cuantas? piezas|cuantos? cortes)\b/';

    public function __construct(private readonly User $usuario) {}

    /**
     * @param  list<array{rol: string, texto: string}>  $historial
     * @param  array{vista?: ?string, filtros?: array<string, mixed>}  $contexto
     * @param  (callable(string): void)|null  $alPaso
     * @return array<string, mixed>
     */
    public function responder(string $texto, array $historial = [], array $contexto = [], ?callable $alPaso = null): array
    {
        $texto = trim(preg_replace('/\s+/', ' ', mb_substr($texto, 0, 400)) ?? '');

        try {
            return $this->conForma($this->camino($texto, $historial, $contexto, $alPaso), $texto);
        } catch (\Throwable $e) {
            // Pase lo que pase por dentro (una base que no contesta, un error nuestro), la persona recibe una respuesta útil.
            report($e);

            return $this->conForma($this->ultimoRecurso($texto), $texto);
        }
    }

    /**
     * @param  list<array{rol: string, texto: string}>  $historial
     * @param  array<string, mixed>  $contexto
     * @return array<string, mixed>
     */
    private function camino(string $texto, array $historial, array $contexto, ?callable $alPaso): array
    {
        $catalogo = new Catalogo;
        $reglas = (new InterpretePorReglas($catalogo))->interpretar($texto);
        $palabras = count(array_filter(explode(' ', $texto)));
        $pideSaber = (bool) preg_match(self::PIDE_SABER, Texto::normalizar($texto));
        $esSeguimiento = $historial !== [] || ! empty($contexto['filtros']);
        $compuesta = null;
        $plan = null;
        $porCodigo = null;

        try {
            $entiende = new Entiende($catalogo);
            $compuesta = $entiende->compuesta($texto);

            // 1. EL CÓDIGO PRIMERO: entiende qué se pregunta con los datos del sistema y contesta, sin IA, al instante.
            $plan = $entiende->plan($texto, $reglas);
            $alPaso && $plan && $alPaso('Entendí: '.$plan['intencion'].'…');
            $porCodigo = $plan ? (new Redactor($this->usuario, $catalogo))->redactar($plan) : null;
        } catch (\Throwable $e) {
            report($e);
        }

        // Si el código entendió la pregunta pero no pudo leer las medidas, y hay IA, ella lo intenta primero (puede estar escrito raro).
        $respaldo = null;

        if ($porCodigo !== null) {
            $faltaLeerMedidas = (bool) ($porCodigo['necesita_medidas'] ?? false);

            if (! ($faltaLeerMedidas && Agente::estaConfigurada() && $compuesta === null && $palabras > 2)) {
                return $this->conAnalisis($porCodigo);
            }

            $respaldo = $porCodigo;
        }

        // 2. UNA LISTA que las reglas entienden por completo (y que no es un seguimiento de otra cosa).
        $esLista = $reglas['entendio'] && $reglas['restos'] === [] && ! $pideSaber && ! $esSeguimiento && $compuesta === null;

        // 3. LA IA, sola y de entrada, para lo complejo que el código no resuelve de una.
        if (Agente::estaConfigurada() && ! $esLista && $palabras > 2) {
            try {
                $a = (new Agente($this->usuario, $catalogo))->responder($texto, $historial, $contexto + ['pista' => $this->pista($reglas, $plan, $compuesta)], $alPaso);
                $deLaIA = (new Respuesta($catalogo))->desdeLaIA($a);

                // Una respuesta que la IA dio y se pudo comprobar, se muestra. Si no se pudo comprobar, o salió vacía, manda el código.
                if ($this->sirve($deLaIA)) {
                    return $this->conAnalisis($deLaIA);
                }

                return $this->conAnalisis($this->delCodigo($texto, $catalogo, $alPaso) ?? $respaldo ?? $deLaIA);
            } catch (\Throwable $e) {
                Log::warning('Cordes IA no pudo usar la IA: '.$e->getMessage());
            }
        }

        // 4. SIN IA (o la IA no pudo): el código lo resuelve con todos los datos que tiene.
        if ($respaldo !== null) {
            return $this->conAnalisis($respaldo);
        }

        if (! $esLista) {
            $delCodigo = $this->delCodigo($texto, $catalogo, $alPaso);

            if ($delCodigo !== null) {
                return $this->conAnalisis($delCodigo);
            }
        }

        return $this->conAnalisis($this->conReglas($texto, $contexto, $pideSaber));
    }

    /** ¿Se puede mostrar tal cual? Comprobada y con algo para ver. */
    private function sirve(array $r): bool
    {
        return ($r['verificada'] ?? false) && (filled($r['mensaje'] ?? null) || ! empty($r['tablas']) || ! empty($r['pedido']) || ! empty($r['opciones']));
    }

    /** Lo que resuelve el código partiendo la pregunta (comparar, sumar, varias preguntas en una); null si no se puede partir. */
    private function delCodigo(string $texto, Catalogo $catalogo, ?callable $alPaso): ?array
    {
        try {
            return (new Descompone($this->usuario, $catalogo, $alPaso))->resolver($texto);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Lo que el código alcanzó a entender, para que la IA no parta de cero: qué filtros, qué tipo de pregunta y con qué datos.
     *
     * @param  array{borrador: array<string, mixed>, restos: list<string>, entendio: bool}  $reglas
     * @param  array{intencion: string, datos: array<string, mixed>}|null  $plan
     * @return array<string, mixed>|null
     */
    private function pista(array $reglas, ?array $plan = null, ?string $compuesta = null): ?array
    {
        $deReglas = $reglas['entendio']
            ? array_filter(['filtros' => array_diff_key($reglas['borrador'], ['vista' => 1]) ?: null, 'vista' => $reglas['borrador']['vista'] ?? null, 'sin_entender' => $reglas['restos'] ?: null])
            : [];

        $datos = $plan === null ? [] : array_filter($plan['datos'], fn ($v) => is_scalar($v) && $v !== '' && $v !== false);
        $delMotor = $plan === null ? [] : ['el_motor_reconocio' => ['tipo_de_pregunta' => $plan['intencion']] + ($datos !== [] ? ['datos' => $datos] : [])];
        $aparte = $compuesta === null ? [] : ['la_pregunta_junta_varias_cosas' => $compuesta.': dividila en pasos y resolvé cada uno por separado'];

        return ($deReglas + $delMotor + $aparte) ?: null;
    }

    /**
     * La búsqueda por reglas, o —si no se entendió como pregunta— por texto en todo el sistema. Siempre devuelve algo para ver.
     *
     * @param  array{vista?: ?string, filtros?: array<string, mixed>}  $contexto
     * @return array<string, mixed>
     */
    private function conReglas(string $texto, array $contexto, bool $pideSaber): array
    {
        $r = (new Interprete)->interpretar($texto, conIA: false);
        $pedido = $r['pedido'];
        $mensaje = null;
        $busquedaPorTexto = $r['mensaje'] !== null;

        // «y de Córdoba», «ahora en dólares»: sobre lo que se estaba viendo, cambiando sólo eso.
        if (! empty($contexto['filtros']) && preg_match('/^\s*(?:y|ahora|pero|solo|solamente|tambien|ademas|de\s+esos|de\s+esas|en\s+cambio|mejor)\b/', Texto::normalizar($texto))) {
            $nuevo = (new ResolverDePedido(new Catalogo))->resolver(['vista' => $contexto['vista'] ?? null] + $contexto['filtros'] + $this->borradorDe($texto));
            $pedido = Filtros::sanear(array_replace($nuevo['pedido'], array_diff_key($r['pedido'], ['q' => 1])));
            $busquedaPorTexto = false;
        }

        if ($busquedaPorTexto) {
            $mensaje = 'Busqué «'.mb_substr($texto, 0, 80).'» en todo el sistema. Abajo está lo que encontré; si querías otra cosa, probá con una de estas:';
        } elseif ($pideSaber) {
            $mensaje = 'Esto es lo que encontré con lo que escribiste. Para un dato puntual, una de estas preguntas sirve:';
        }

        return [
            'fuente' => 'reglas', 'mensaje' => $mensaje, 'verificada' => true, 'aviso' => $r['aviso'], 'pedido' => $pedido,
            'borrador' => null, 'tablas' => [], 'atajos' => [], 'opciones' => null,
            'seguir' => $mensaje !== null ? array_slice(self::EJEMPLOS, 0, 4) : [],
            'no_entendi' => $r['no_entendi'], 'pasos' => [], 'analisis' => null,
        ];
    }

    /**
     * Lo que se hace si algo falló por dentro: una búsqueda por texto, que no depende de nada de lo demás.
     *
     * @return array<string, mixed>
     */
    private function ultimoRecurso(string $texto): array
    {
        return [
            'fuente' => 'reglas', 'mensaje' => 'Busqué «'.mb_substr($texto, 0, 80).'» en todo el sistema. Abajo está lo que encontré; si querías otra cosa, probá con una de estas:',
            'verificada' => true, 'aviso' => null, 'pedido' => ['q' => mb_substr($texto, 0, 200)], 'borrador' => null, 'tablas' => [], 'atajos' => [],
            'opciones' => null, 'seguir' => array_slice(self::EJEMPLOS, 0, 4), 'no_entendi' => [], 'pasos' => [], 'analisis' => null,
        ];
    }

    private const EJEMPLOS = [
        '¿cuánto pesa una barra de monel de 38,1 x 145 mm?', '¿a cuánto cotizamos titanio grado 2 en chapa?', 'tabla de caños schedule 40', 'contame de una empresa',
    ];

    /** @return array<string, mixed> el borrador que entienden las reglas, sin lo que sobra */
    private function borradorDe(string $texto): array
    {
        $b = (new InterpretePorReglas(new Catalogo))->interpretar($texto)['borrador'];
        unset($b['vista'], $b['texto']);

        return $b;
    }

    /**
     * Las cifras, los atajos y los "sin este filtro hay N" salen del código, sobre el pedido final.
     *
     * Una lista (la respuesta ES la cuenta) lleva adentro lo básico, que es rápido. En todo lo demás (un precio, un peso, una comparación) la
     * respuesta sale al instante y la pantalla pide el análisis completo aparte (POST /api/cordes/analizar) y lo suma cuando llega: así nadie espera
     * diez pasadas sobre 230.000 renglones para ver un precio.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function conAnalisis(array $r): array
    {
        if (($r['pedido'] ?? null) !== null && ($r['verificada'] ?? true) && ($r['fuente'] ?? '') === 'reglas') {
            try {
                $r['analisis'] = (new Analista($this->usuario))->basico($r['pedido']);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $r;
    }

    /**
     * La forma que espera la pantalla, siempre completa, sin lo interno, y con algo para ver: una respuesta vacía no sale nunca.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function conForma(array $r, string $texto): array
    {
        $r = array_filter($r, fn ($v, $k) => ! str_starts_with((string) $k, '_') && $k !== 'necesita_medidas', ARRAY_FILTER_USE_BOTH);
        $r += [
            'fuente' => 'codigo', 'mensaje' => null, 'verificada' => true, 'aviso' => null, 'pedido' => null, 'borrador' => null,
            'tablas' => [], 'atajos' => [], 'opciones' => null, 'seguir' => [], 'no_entendi' => [], 'pasos' => [], 'analisis' => null,
        ];

        // Todo lo que sale pasa por la misma barrera, venga del motor, del descompositor o de la IA: la pantalla recibe siempre lo mismo y válido.
        $barrera = new Respuesta(new Catalogo);
        $r['fuente'] = in_array($r['fuente'], ['ia', 'reglas', 'codigo'], true) ? $r['fuente'] : 'codigo';
        $r['mensaje'] = filled($r['mensaje']) ? $barrera->texto((string) $r['mensaje'], 3600) : null;
        $r['verificada'] = (bool) $r['verificada'];
        $r['aviso'] = filled($r['aviso']) ? $barrera->texto((string) $r['aviso'], 300) : null;
        $r['pedido'] = is_array($r['pedido']) && ($p = Filtros::sanear($r['pedido'])) !== [] ? $p : null;
        $r['tablas'] = array_map(fn ($t) => $barrera->tabla((array) $t), array_slice(array_values((array) $r['tablas']), 0, 3));
        $r['atajos'] = $barrera->atajosArmados((array) $r['atajos']);
        $r['opciones'] = $barrera->opcionesValidas($r['opciones']);
        $r['seguir'] = $barrera->seguirValidos($r['seguir']);
        $r['no_entendi'] = array_values(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 80), array_filter((array) $r['no_entendi'], 'is_scalar')), fn ($x) => $x !== ''));
        $r['pasos'] = array_values(array_map(fn ($x) => mb_substr((string) $x, 0, 160), array_filter(array_slice((array) $r['pasos'], 0, 14), 'is_scalar')));

        if (! filled($r['mensaje']) && empty($r['tablas']) && empty($r['pedido']) && empty($r['opciones']) && $r['analisis'] === null) {
            $r['mensaje'] = 'Busqué «'.mb_substr($texto, 0, 80).'» y no encontré nada con eso. Probá con otras palabras o con una de estas:';
            $r['seguir'] = $r['seguir'] !== [] ? $r['seguir'] : array_slice(self::EJEMPLOS, 0, 4);
        }

        return $r;
    }
}

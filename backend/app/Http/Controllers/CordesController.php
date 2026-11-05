<?php

namespace App\Http\Controllers;

use App\Services\Buscador\Filtros;
use App\Services\Cordes\Analista;
use App\Services\Cordes\Cordes;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cordes IA: preguntas con las palabras de cada uno, contestadas con los datos del sistema.
 * La lógica está en App\Services\Cordes.
 */
class CordesController extends Controller
{
    /**
     * POST /api/cordes/preguntar. Con `stream` contesta de a poco —una línea de JSON por cada paso, y la
     * última con la respuesta— para que la pantalla muestre qué se está mirando mientras se trabaja.
     */
    public function preguntar(Request $request)
    {
        $d = $request->validate([
            'texto' => ['required', 'string', 'min:2', 'max:400'],
            'historial' => ['nullable', 'array', 'max:12'],
            'historial.*.rol' => ['required', 'in:usuario,cordes'],
            'historial.*.texto' => ['required', 'string', 'max:1500'],
            'contexto' => ['nullable', 'array'],
            'contexto.vista' => ['nullable', 'in:'.implode(',', Filtros::GRANOS)],
            'contexto.filtros' => ['nullable', 'array'],
            'stream' => ['nullable', 'boolean'],
        ]);

        // Una pregunta con la IA puede tardar más que los 30 segundos de PHP: se le da el tiempo del tope del agente y un poco más.
        set_time_limit(90);

        $cordes = new Cordes($request->user());
        $historial = $d['historial'] ?? [];
        $contexto = $d['contexto'] ?? [];

        if (! $request->boolean('stream')) {
            return response()->json($cordes->responder($d['texto'], $historial, $contexto));
        }

        return new StreamedResponse(function () use ($cordes, $d, $historial, $contexto) {
            $emitir = function (array $evento): void {
                echo json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";

                if (ob_get_level() > 0) {
                    @ob_flush();
                }

                flush();
            };

            try {
                $respuesta = $cordes->responder($d['texto'], $historial, $contexto, fn (string $paso) => $emitir(['tipo' => 'paso', 'texto' => $paso]));
                $emitir(['tipo' => 'fin', 'respuesta' => $respuesta]);
            } catch (\Throwable $e) {
                report($e);
                $emitir(['tipo' => 'error', 'mensaje' => 'No pude contestar ahora. Probá de nuevo, o buscá con los filtros de abajo.']);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            // Que ningún intermediario junte las líneas: cada paso tiene que llegar cuando ocurre.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** POST /api/cordes/analizar — las cifras y los atajos de una búsqueda ya armada (un clic en un atajo). */
    public function analizar(Request $request)
    {
        $d = $request->validate(['pedido' => ['required', 'array']]);

        $limpio = Filtros::sanear(array_diff_key($d['pedido'], ['orden' => 1]));

        return response()->json(['analisis' => (new Analista($request->user()))->analizar($limpio)]);
    }
}

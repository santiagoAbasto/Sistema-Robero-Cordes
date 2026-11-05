<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Services\FusionDeEmpresas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Combinar dos fichas de una misma empresa, desde la pantalla.
 *
 * Pedido de Roberto (04-11-2026): "buscando un cliente vemos que tenemos
 * dos, porque el nombre pre-migración no era exactamente igual… ¿cómo podríamos
 * hacer para combinar las fichas?". La lógica es la misma del comando
 * empresas:fusionar-duplicadas (App\Services\FusionDeEmpresas): se elige la
 * ficha que queda, se le pasa todo lo de la otra y la otra queda archivada.
 * No se borra nada.
 */
class EmpresaFusionController extends Controller
{
    public function __construct(private FusionDeEmpresas $fusion) {}

    /** Las fichas que parecen ser la misma empresa que esta. */
    public function parecidas(Request $request, Empresa $empresa): JsonResponse
    {
        $puede = $request->user()?->puedeArchivar() ?? false;

        return response()->json([
            // Si no puede combinar, la pantalla ni se lo ofrece: no hace falta ni buscarlas.
            'puede_combinar' => $puede,
            'data' => $puede
                ? $this->fusion->parecidas($empresa)->map(fn (Empresa $o) => $this->fusion->resumen($o))->values()
                : [],
        ]);
    }

    /** Las dos fichas lado a lado, y cuál conviene dejar. */
    public function comparar(Request $request, Empresa $empresa, Empresa $otra): JsonResponse
    {
        $this->soloSiPuede($request);
        $this->soloDistintasYActivas($empresa, $otra);

        return response()->json([
            'esta' => $this->fusion->resumen($empresa),
            'otra' => $this->fusion->resumen($otra),
            // La que más cotizaciones tiene (la misma regla del comando).
            'recomendada' => $this->fusion->elegirSobreviviente(collect([$empresa, $otra]))->id,
        ]);
    }

    /** Combina. `queda` dice cuál de las dos sigue viva: "esta" o "otra". */
    public function combinar(Request $request, Empresa $empresa): JsonResponse
    {
        $this->soloSiPuede($request);

        $datos = $request->validate([
            'otra_id' => ['required', 'integer', 'exists:empresas,id'],
            'queda' => ['required', 'in:esta,otra'],
        ], [
            'otra_id.required' => 'Elegí con qué ficha se combina.',
            'queda.required' => 'Elegí cuál de las dos fichas queda.',
        ]);

        $otra = Empresa::findOrFail($datos['otra_id']);
        $this->soloDistintasYActivas($empresa, $otra);

        [$queda, $sobra] = $datos['queda'] === 'esta' ? [$empresa, $otra] : [$otra, $empresa];

        // Una sola transacción: o se pasa todo o no se toca nada.
        $resultado = DB::transaction(fn () => $this->fusion->fusionar($queda, collect([$sobra])));

        return response()->json([
            'empresa_id' => $queda->id,
            'resumen' => $resultado,
            'mensaje' => sprintf(
                'Listo: las fichas quedaron en una sola, «%s». Se pasaron %d consultas y %d contactos. La otra ficha quedó archivada: no se borró nada.',
                $queda->fresh()->nombre,
                $resultado['consultas'],
                $resultado['contactos'],
            ),
        ]);
    }

    private function soloSiPuede(Request $request): void
    {
        abort_unless(
            $request->user()?->puedeArchivar() ?? false,
            403,
            'No tenés permiso para combinar fichas. Pedíselo a un administrador (se da en "Quién ve qué", casilla "Archiva").',
        );
    }

    private function soloDistintasYActivas(Empresa $a, Empresa $b): void
    {
        abort_if($a->is($b), 422, 'Tenés que elegir otra ficha: no se puede combinar una consigo misma.');
        abort_unless(
            $a->activa && $b->activa,
            422,
            'Las dos fichas tienen que estar activas. Si una está inactiva, reactivala primero desde su ficha.',
        );
    }
}

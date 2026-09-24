<?php

namespace App\Http\Controllers;

use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Lo que hay que seguir y lo que ya termino.
 *
 * Las dos cosas salen de la misma pregunta —que paso con cada cotizacion— y
 * por eso viven juntas: la campanita es el ahora y el reporte es el balance.
 *
 * No hay tabla de notificaciones. Un aviso de "esta por vencer" no es un
 * hecho que haya que guardar: es una consecuencia de la fecha, y guardarlo
 * significaria mantenerlo sincronizado cada vez que alguien cambia la validez
 * o cierra la cotizacion. Se calcula al momento de preguntar y siempre dice
 * la verdad.
 */
class SeguimientoController extends Controller
{
    /** Dentro de cuantos dias cuenta como "por vencer" para el aviso. */
    private const AVISO_DIAS = 7;

    /**
     * Lo que necesita atencion ahora. Es lo que muestra la campanita.
     *
     * Trae los conteos para el numerito y las primeras de cada grupo para el
     * desplegable, no la lista entera: quien quiera verlas todas entra a la
     * pantalla de seguimiento.
     */
    public function pendientes()
    {
        $porVencer = Consulta::cotizaciones()->porVencer(self::AVISO_DIAS);
        $vencidas = Consulta::cotizaciones()->sinRespuesta();

        return [
            'por_vencer' => [
                'dias' => self::AVISO_DIAS,
                'cuantas' => (clone $porVencer)->count(),
                'primeras' => $this->resumir((clone $porVencer)->orderBy('vence_el')),
            ],
            'vencidas_sin_cerrar' => [
                'cuantas' => (clone $vencidas)->count(),
                'primeras' => $this->resumir((clone $vencidas)->orderBy('vence_el')),
            ],
        ];
    }

    /**
     * El balance del periodo: que se perdio y por que.
     *
     * La fecha de generacion viaja con el reporte a proposito. Un reporte
     * impreso sin fecha no se puede comparar con otro ni discutir en una
     * reunion: nadie sabe si mira lo de hoy o lo de la semana pasada.
     */
    public function reporte(Request $request)
    {
        $datos = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        $desde = isset($datos['desde']) ? Carbon::parse($datos['desde']) : now()->startOfYear();
        $hasta = isset($datos['hasta']) ? Carbon::parse($datos['hasta']) : now();

        /*
          whereDate y no whereBetween: la columna guarda una fecha pero se
          compara como texto, asi que un "hasta" de 2026-09-30 dejaba afuera
          todo lo cargado ese mismo dia. Es como lo hace el resto del sistema.
        */
        $enElPeriodo = fn () => Consulta::cotizaciones()
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString());

        $cerradas = $enElPeriodo()->cerradas()->withCount(['observaciones', 'impresiones'])->get();

        return [
            // Quien lee el reporte tiene que saber de cuando es.
            'generado_el' => now()->toIso8601String(),
            'periodo' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],

            'abiertas' => [
                'total' => $enElPeriodo()->whereNotIn('estado', [...Consulta::ESTADOS_DE_CIERRE, 'Vendida'])->count(),
                'por_vencer' => (clone $enElPeriodo())->porVencer(self::AVISO_DIAS)->count(),
                'vencidas_sin_cerrar' => (clone $enElPeriodo())->sinRespuesta()->count(),
            ],

            'cerradas' => [
                'total' => $cerradas->count(),
                // Separadas por motivo: son dos finales distintos y se miran
                // distinto. Una vencida se puede volver a levantar.
                'por_motivo' => collect(Consulta::ESTADOS_DE_CIERRE)
                    ->map(fn (string $estado) => [
                        'motivo' => $estado,
                        'cuantas' => $cerradas->where('estado', $estado)->count(),
                        'importe' => round((float) $cerradas->where('estado', $estado)->sum('total'), 2),
                    ])->values(),
            ],

            'vendidas' => [
                'total' => $enElPeriodo()->where('estado', 'Vendida')->count(),
            ],

            /*
              Cuantas se cerraron sin que nadie las hubiera tocado nunca. Es el
              numero que duele: no se perdieron por precio, se perdieron por
              no llamar. Va aparte del promedio justamente por eso.
            */
            'seguimiento' => [
                'sin_ningun_seguimiento' => $cerradas
                    ->filter(fn ($c) => $c->observaciones_count + $c->impresiones_count === 0)
                    ->count(),
                'promedio_por_cerrada' => $cerradas->isEmpty() ? 0 : round(
                    $cerradas->sum(fn ($c) => $c->observaciones_count + $c->impresiones_count) / $cerradas->count(),
                    1,
                ),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function resumir($consultas): array
    {
        return $consultas->with('empresa')->limit(5)->get()
            ->map(fn (Consulta $c) => [
                'id' => $c->id,
                'empresa' => $c->empresa?->nombre,
                'vence_el' => $c->vence_el?->toDateString(),
                'dias_para_vencer' => $c->dias_para_vencer,
            ])->all();
    }
}

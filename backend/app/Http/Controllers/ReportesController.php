<?php

namespace App\Http\Controllers;

use App\Services\PlanillaXlsx;
use App\Services\Reportes\FiltrosDeReporte;
use App\Services\Reportes\Reporte;
use App\Services\Reportes\ReporteDeComprobantes;
use App\Services\Reportes\ReporteDeEmpresas;
use App\Services\Reportes\ReporteDeMateriales;
use App\Services\Reportes\ReporteDeRanking;
use App\Services\Reportes\ReporteDeVencimientos;
use Illuminate\Http\Request;

/**
 * Los reportes: por material, por empresa (o por año), compras y comprobantes, ranking y vencimientos.
 * Todos con la misma forma de respuesta y el mismo Excel (ver App\Services\Reportes\Reporte).
 * El balance de seguimiento, que es anterior, sigue en SeguimientoController.
 */
class ReportesController extends Controller
{
    /** @var array<string, class-string<Reporte>> */
    public const REPORTES = [
        'material' => ReporteDeMateriales::class,
        'empresa' => ReporteDeEmpresas::class,
        'comprobantes' => ReporteDeComprobantes::class,
        'ranking' => ReporteDeRanking::class,
        'vencimientos' => ReporteDeVencimientos::class,
    ];

    public function show(Request $request, string $reporte)
    {
        return response()->json($this->reporte($request, $reporte)->generar());
    }

    public function exportar(Request $request, string $reporte, PlanillaXlsx $planilla)
    {
        $r = $this->reporte($request, $reporte);
        $excel = $r->paraExcel();

        $periodo = ($request->query('desde') ?: 'inicio').'_'.($request->query('hasta') ?: now()->toDateString());
        $nombre = 'reporte-'.$r->clave().'-'.preg_replace('/[^0-9A-Za-z_-]/', '', $periodo).'.xlsx';

        return response($planilla->escribir([$excel['titulos'], ...$excel['filas']], mb_substr($r->titulo(), 0, 30)), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
        ]);
    }

    private function reporte(Request $request, string $reporte): Reporte
    {
        abort_unless(isset(self::REPORTES[$reporte]), 404);
        $clase = self::REPORTES[$reporte];

        return new $clase($request->user(), FiltrosDeReporte::desdeElPedido($request));
    }
}

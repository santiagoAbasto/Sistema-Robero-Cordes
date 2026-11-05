<?php

namespace App\Http\Controllers;

use App\Services\Buscador\Buscador;
use App\Services\Buscador\Filtros;
use App\Services\Buscador\Interprete;
use App\Services\PlanillaXlsx;
use Illuminate\Http\Request;

/**
 * El buscador único: empresas, cotizaciones, renglones y comprobantes con los
 * mismos filtros. La lógica está en App\Services\Buscador\Buscador.
 */
class BuscadorController extends Controller
{
    /** GET /api/buscar — una página de resultados, con su resumen. */
    public function buscar(Request $request)
    {
        return response()->json((new Buscador($request->user()))->pagina(Filtros::desde($request)));
    }

    /**
     * POST /api/buscar/interpretar — Cordes IA: una pregunta con las palabras de cada uno,
     * traducida a un pedido del Buscador. No busca nada: la pantalla muestra lo que entendió
     * y recién entonces hace la búsqueda.
     */
    public function interpretar(Request $request)
    {
        $datos = $request->validate(['texto' => ['required', 'string', 'min:2', 'max:400']]);

        return response()->json((new Interprete)->interpretar($datos['texto']));
    }

    /** GET /api/buscar/exportar — lo mismo, pero todo el resultado en un Excel. */
    public function exportar(Request $request, PlanillaXlsx $planilla)
    {
        $filtros = Filtros::desde($request);
        $r = (new Buscador($request->user()))->paraExcel($filtros);

        return response($planilla->escribir([$r['titulos'], ...$r['filas']], ucfirst($filtros->grano)), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="buscador-'.$filtros->grano.'-'.now()->format('Y-m-d').'.xlsx"',
        ]);
    }
}

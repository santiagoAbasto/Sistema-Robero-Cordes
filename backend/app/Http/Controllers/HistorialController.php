<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\HistorialCambio;
use Illuminate\Http\Request;

/** Control de cambios: qué cambió, quién y qué decía antes. */
class HistorialController extends Controller
{
    public function deEmpresa(Request $request, Empresa $empresa)
    {
        $cambios = HistorialCambio::query()
            ->where('empresa_id', $empresa->id)
            ->when($request->query('desde'), fn ($q, $v) => $q->whereDate('fecha', '>=', $v))
            ->when($request->query('hasta'), fn ($q, $v) => $q->whereDate('fecha', '<=', $v))
            ->when($request->query('usuario_id'), fn ($q, $v) => $q->where('usuario_id', $v))
            ->when($request->query('accion'), fn ($q, $v) => $q->where('accion', $v))
            ->when($request->query('tabla'), fn ($q, $v) => $q->where('tabla', $v))
            ->with('usuario')
            ->orderByDesc('fecha')->orderByDesc('id')
            ->paginate((int) $request->query('por_pagina', 50));

        return response()->json([
            'data' => $cambios->getCollection()->map(fn ($c) => [
                'id' => $c->id,
                'fecha' => $c->fecha?->format('Y-m-d H:i'),
                // Sin usuario = lo hizo el sistema (por ejemplo, un vencimiento).
                'quien' => $c->usuario?->initials ?? 'el sistema',
                'tabla' => $c->tabla,
                'que_cambio' => $this->descripcion($c),
                'antes' => $c->valor_anterior,
                'ahora' => $c->valor_nuevo,
                'accion' => $c->accion,
            ]),
            'meta' => [
                'current_page' => $cambios->currentPage(),
                'last_page' => $cambios->lastPage(),
                'total' => $cambios->total(),
                'per_page' => $cambios->perPage(),
            ],
        ]);
    }

    private function descripcion(HistorialCambio $c): string
    {
        $tablas = [
            'empresas' => 'Empresa',
            'contactos' => 'Contacto',
            'contacto_medios' => 'Medio de contacto',
            'razones_sociales' => 'Razon social',
            'empresa_campos' => 'Condicion de trabajo',
            'empresa_relacion' => 'Relacion',
            'consultas' => 'Cotizacion',
            'consulta_lineas' => 'Linea de cotizacion',
            'observaciones' => 'Observacion',
        ];

        $donde = $tablas[$c->tabla] ?? $c->tabla;

        return $c->campo ? "{$donde} · {$c->campo}" : $donde;
    }
}

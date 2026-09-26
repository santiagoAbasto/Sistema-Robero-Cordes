<?php

namespace App\Http\Controllers;

use App\Models\Consulta;
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

    /**
     * Las revisiones de una cotizacion, no su log.
     *
     * "Cambie una cantidad, guarde e imprimi, no encuentro donde figura el
     * historial de cambios ni las revisiones. Lo encontre en la ficha de la
     * empresa; deberia haber un historial dentro de cada cotizacion. Queremos
     * que muestre informacion de revisiones, no logs paso a paso."
     *
     * Dos cosas distintas, y las dos estaban mal:
     *
     * 1. El historial solo se podia pedir por empresa. Una empresa con cien
     *    cotizaciones devolvia el revuelto de las cien.
     * 2. Lo que devolvia era una fila por campo. Cambiar tres precios y
     *    guardar daba tres renglones sueltos, y para saber que paso en ese
     *    guardado habia que leerlos y juntarlos con la vista.
     *
     * Una REVISION es un guardado: todo lo que cambio la misma persona en el
     * mismo momento, con una linea que lo resume y el detalle adentro para
     * quien lo quiera abrir.
     */
    public function revisionesDeConsulta(Request $request, Consulta $consulta)
    {
        $lineas = $consulta->lineas()->pluck('id');
        $observaciones = $consulta->observaciones()->pluck('id');

        $cambios = HistorialCambio::query()
            ->where(function ($q) use ($consulta, $lineas, $observaciones) {
                $q->where(fn ($q) => $q->where('tabla', 'consultas')->where('registro_id', $consulta->id))
                    ->orWhere(fn ($q) => $q->where('tabla', 'consulta_lineas')->whereIn('registro_id', $lineas))
                    ->orWhere(fn ($q) => $q->where('tabla', 'observaciones')->whereIn('registro_id', $observaciones));
            })
            ->with('usuario')
            ->orderBy('fecha')->orderBy('id')
            ->get();

        /*
          Un guardado escribe todas sus filas con la misma fecha y el mismo
          usuario: eso es lo que las junta. La fecha va al segundo, que es lo
          que guarda la columna.
        */
        $revisiones = $cambios
            ->groupBy(fn ($c) => $c->fecha?->format('Y-m-d H:i:s').'|'.($c->usuario_id ?? 0))
            ->values()
            ->map(function ($grupo, $i) {
                $primero = $grupo->first();

                return [
                    'numero' => $i + 1,
                    'fecha' => $primero->fecha?->format('Y-m-d H:i'),
                    'quien' => $primero->usuario?->name ?? 'el sistema',
                    'iniciales' => $primero->usuario?->initials ?? '—',
                    'que_paso' => $this->resumenDeRevision($grupo),
                    'cambios' => $grupo
                        // Las altas no tienen "antes": no aportan nada al detalle.
                        ->filter(fn ($c) => filled($c->campo))
                        ->map(fn ($c) => [
                            'que' => $this->descripcion($c),
                            'antes' => $c->valor_anterior,
                            'ahora' => $c->valor_nuevo,
                        ])
                        ->values(),
                ];
            });

        return response()->json([
            // La ultima primero: es la que se quiere ver al abrir.
            'revisiones' => $revisiones->reverse()->values(),
            'total' => $revisiones->count(),
        ]);
    }

    /**
     * Una linea que diga que paso en ese guardado.
     *
     * Es la diferencia entre una revision y un log: "Cambio el precio de 2
     * lineas" en vez de tres renglones con nombres de columnas.
     *
     * @param  \Illuminate\Support\Collection<int, HistorialCambio>  $grupo
     */
    private function resumenDeRevision($grupo): string
    {
        if ($grupo->contains(fn ($c) => $c->accion === 'Impresion')) {
            $via = $grupo->firstWhere('accion', 'Impresion')?->valor_nuevo;

            return $via ? "Se imprimio: {$via}" : 'Se imprimio';
        }

        if ($grupo->contains(fn ($c) => $c->accion === 'Alta' && $c->tabla === 'consultas')) {
            $conLineas = $grupo->where('tabla', 'consulta_lineas')->count();

            return $conLineas > 0
                ? "Se cargo la cotizacion, con {$conLineas} ".($conLineas === 1 ? 'linea' : 'lineas')
                : 'Se cargo la cotizacion';
        }

        $lineasNuevas = $grupo->where('accion', 'Alta')->where('tabla', 'consulta_lineas')->count();
        $campos = $grupo->filter(fn ($c) => filled($c->campo));

        $partes = [];

        if ($campos->isNotEmpty()) {
            $nombres = $campos->pluck('campo')->unique()->values();
            $cuantas = $campos->where('tabla', 'consulta_lineas')->pluck('registro_id')->unique()->count();

            $que = $nombres->take(3)->implode(', ').($nombres->count() > 3 ? ' y '.($nombres->count() - 3).' mas' : '');

            $partes[] = $cuantas > 1
                ? "Cambio {$que} en {$cuantas} lineas"
                : 'Cambio '.$que;
        }

        if ($lineasNuevas > 0) {
            $partes[] = "agrego {$lineasNuevas} ".($lineasNuevas === 1 ? 'linea' : 'lineas');
        }

        return $partes === [] ? 'Se guardo sin cambios' : ucfirst(implode(' y ', $partes));
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

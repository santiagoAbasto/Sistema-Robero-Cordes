<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConsultaResource;
use App\Models\Consulta;
use Illuminate\Http\Request;

class ConsultaController extends Controller
{
    private const RELACIONES = [
        'empresa', 'contacto', 'razonSocial', 'usuario', 'moneda',
        'lineas.material', 'lineas.forma', 'lineas.unidadVenta', 'lineas.opciones.material',
        'lineas.unidadFactura', 'lineas.unidadPedida',
        'condiciones', 'observaciones.usuario',
        'impresiones.contacto', 'impresiones.usuario',
        'copiadaDe.empresa',
    ];

    /**
     * Consultas por fecha y Busq. Consultas p/Cond.
     * Busca cotizaciones, no empresas: se llega por el material.
     */
    public function index(Request $request)
    {
        $consultas = Consulta::query()
            ->when($request->query('tipo'), fn ($q, $v) => $q->where('tipo', $v))
            ->when($request->query('estado'), fn ($q, $v) => $q->where('estado', $v))
            ->when($request->query('empresa_id'), fn ($q, $v) => $q->where('empresa_id', $v))
            ->when($request->query('usuario_id'), fn ($q, $v) => $q->where('usuario_id', $v))
            ->when($request->query('moneda_id'), fn ($q, $v) => $q->where('moneda_id', $v))
            ->when($request->query('desde'), fn ($q, $v) => $q->whereDate('fecha', '>=', $v))
            ->when($request->query('hasta'), fn ($q, $v) => $q->whereDate('fecha', '<=', $v))
            ->when($request->query('material_id'), fn ($q, $v) => $q->whereHas(
                'lineas', fn ($l) => $l->where('material_id', $v)
            ))
            ->when($request->query('forma_id'), fn ($q, $v) => $q->whereHas(
                'lineas', fn ($l) => $l->where('forma_id', $v)
            ))
            // "todas las barras de entre 30 y 40 mm": el diámetro es un número.
            ->when($request->query('diametro_desde'), fn ($q, $v) => $q->whereHas(
                'lineas', fn ($l) => $l->where('diametro_mm', '>=', $v)
            ))
            ->when($request->query('diametro_hasta'), fn ($q, $v) => $q->whereHas(
                'lineas', fn ($l) => $l->where('diametro_mm', '<=', $v)
            ))
            ->when($request->boolean('sin_respuesta'), fn ($q) => $q->sinRespuesta())
            // Las dos listas de la pantalla de seguimiento.
            ->when($request->filled('por_vencer'), fn ($q) => $q->porVencer((int) $request->query('por_vencer')))
            ->when($request->boolean('cerradas'), fn ($q) => $q->cerradas())
            // Cuantas veces se le hizo seguimiento: las notas del hilo y los
            // envios de la hoja. Se cuentan en la consulta y no recorriendo
            // las relaciones, que serian dos consultas mas por fila.
            ->withCount(['observaciones', 'impresiones'])
            ->when(! $request->boolean('incluir_borradores'), fn ($q) => $q->firmes())
            ->with(self::RELACIONES)
            ->orderByDesc('fecha')->orderByDesc('id')
            ->paginate((int) $request->query('por_pagina', 25));

        return ConsultaResource::collection($consultas);
    }

    public function show(Consulta $consulta)
    {
        return new ConsultaResource($consulta->load(self::RELACIONES));
    }

    /**
     * Las cotizaciones relacionadas por cómo se generó ésta.
     * No es por parecido: es porque una salió de la otra.
     */
    public function relacionadas(Consulta $consulta)
    {
        $familia = $consulta->familia();

        $resumen = fn ($c) => $c ? [
            'id' => $c->id,
            'empresa' => $c->empresa?->nombre,
            'empresa_id' => $c->empresa_id,
            'fecha' => $c->fecha?->format('Y-m-d'),
            'estado' => $c->estado,
            'quien' => $c->usuario?->initials,
            'total' => (float) $c->lineas->where('quitada', false)->sum('importe'),
            'lineas' => $c->lineas->where('quitada', false)->count(),
        ] : null;

        return response()->json([
            'madre' => $resumen($familia['madre']),
            'hermanas' => $familia['hermanas']->map($resumen)->values(),
            'hijas' => $familia['hijas']->map($resumen)->values(),
        ]);
    }

    /** Los borradores que salieron de una cotización copiada a otras empresas. */
    public function borradores(Consulta $consulta)
    {
        $borradores = $consulta->copias()
            ->with(self::RELACIONES)
            ->orderBy('id')
            ->get();

        return ConsultaResource::collection($borradores);
    }
}

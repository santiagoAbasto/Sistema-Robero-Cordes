<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConsultaResource;
use App\Models\Consulta;
use Illuminate\Http\Request;

class ConsultaController extends Controller
{
    private const RELACIONES = [
        'empresa', 'contacto', 'razonSocial', 'usuario', 'moneda',
        'lineas.material', 'lineas.forma', 'lineas.unidadVenta',
        'lineas.unidadFactura', 'lineas.unidadPedida',
        'condiciones', 'observaciones.usuario',
        'impresiones.contacto', 'impresiones.usuario',
        'copiadaDe.empresa',
        // Quien la emitio: sale en el aviso de "emitida como 2026-0001 R0".
        'emisor',
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
            // Los borradores se esconden por defecto (firmes), salvo que se
            // pidan a propósito: con incluir_borradores, o filtrando
            // justamente por estado=Borrador —si no, pedirlos devolvía 0, que
            // es lo que mostraba el acceso "Borradores" del dashboard.
            ->when(
                ! $request->boolean('incluir_borradores') && $request->query('estado') !== 'Borrador',
                fn ($q) => $q->firmes(),
            )
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
            'total' => (float) $c->lineas->filter(fn ($l) => $l->cuentaParaElTotal())->sum('importe'),
            'lineas' => $c->lineas->where('quitada', false)->count(),
        ] : null;

        return response()->json([
            'madre' => $resumen($familia['madre']),
            'hermanas' => $familia['hermanas']->map($resumen)->values(),
            'hijas' => $familia['hijas']->map($resumen)->values(),
        ]);
    }

    /**
     * La papelera: los borradores descartados que todavía se pueden restaurar.
     *
     * Primero se barren los que ya pasaron los 30 días —esos se borran para
     * siempre, acá, porque Railway no corre un cron que lo haga solo—. Lo que
     * queda se muestra con quién lo descartó y cuántos días le quedan.
     */
    public function eliminados(Request $request)
    {
        abort_unless(
            $request->user()?->role === 'Administrador',
            403,
            'Solo un administrador puede ver la papelera.',
        );

        $limite = now()->subDays(Consulta::DIAS_EN_PAPELERA);

        // Los vencidos se van de verdad (con sus líneas, por el cascade).
        Consulta::onlyTrashed()->where('deleted_at', '<', $limite)->get()
            ->each(fn (Consulta $c) => $c->forceDelete());

        $papelera = Consulta::onlyTrashed()
            ->where('deleted_at', '>=', $limite)
            ->with(['empresa:id,nombre', 'eliminadaPor:id,name', 'lineas:id,consulta_id,importe,quitada,alternativa_de_id'])
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (Consulta $c) => [
                'id' => $c->id,
                'empresa' => $c->empresa?->nombre,
                'empresa_id' => $c->empresa_id,
                'fecha' => $c->fecha?->toDateString(),
                'total' => round($c->total, 2),
                'eliminada_por' => $c->eliminadaPor?->name,
                'eliminada_el' => $c->deleted_at?->toIso8601String(),
                // Cuántos días le quedan en la papelera antes de borrarse solo.
                'dias_restantes' => max(0, Consulta::DIAS_EN_PAPELERA - (int) $c->deleted_at->startOfDay()->diffInDays(now()->startOfDay())),
            ]);

        return response()->json(['data' => $papelera]);
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

    /**
     * Todas las revisiones de una cotizacion, de la R0 a la ultima.
     *
     * "Ver todas las versiones que sufrio la cotizacion". Vuelve una lista
     * corta —numero, cuando, quien, cuanto— y no las cotizaciones enteras:
     * es para elegir cual abrir, no para leerlas todas juntas.
     */
    public function versiones(Consulta $consulta)
    {
        return $consulta->versiones()
            ->with('emisor:id,name', 'usuario:id,name', 'lineas:id,consulta_id,importe,quitada,alternativa_de_id')
            ->get()
            ->map(fn (Consulta $v) => [
                'id' => $v->id,
                'numero' => $v->numeroConRevision(),
                'revision' => (int) $v->revision,
                'emitida' => $v->estaEmitida(),
                'emitida_el' => $v->emitida_el?->toIso8601String(),
                'emitida_por' => $v->emisor?->name,
                'cargada_por' => $v->usuario?->name,
                'fecha' => $v->fecha?->toDateString(),
                'total' => $v->total,
                'estado' => $v->estado,
            ]);
    }
}

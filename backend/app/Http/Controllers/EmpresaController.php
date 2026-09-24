<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmpresaListaResource;
use App\Http\Resources\EmpresaResource;
use App\Models\Empresa;
use Illuminate\Http\Request;

class EmpresaController extends Controller
{
    /**
     * Cons./Modif. Registros y Busq. Registros p/Cond.
     *
     * El buscador de arriba encuentra por nombre, CUIT, código ISIS, por el
     * teléfono o el mail de cualquiera de sus contactos, y por el material
     * de cualquiera de sus cotizaciones. Por eso ya no hace falta la pantalla
     * de buscar por teléfono.
     */
    public function index(Request $request)
    {
        $empresas = Empresa::query()
            ->activas()
            ->buscar($request->query('buscar'))
            ->conRelacion($request->query('relacion'))
            ->when($request->query('rubro_id'), fn ($q, $v) => $q->where('rubro_id', $v))
            ->when($request->query('localidad_id'), fn ($q, $v) => $q->where('localidad_id', $v))
            ->when($request->query('provincia_id'), fn ($q, $v) => $q->where('provincia_id', $v))
            // Texto dentro de la observación de la empresa.
            ->when(
                $request->query('observacion'),
                fn ($q, $v) => $q->where('observacion_general', 'like', "%{$v}%")
            )
            // Empresas a las que les cotizamos tal material.
            ->when($request->query('material_id'), fn ($q, $v) => $q->whereHas(
                'consultas.lineas',
                fn ($l) => $l->where('material_id', $v)
            ))
            // Hace más de N meses que no se les cotiza.
            ->when($request->query('sin_cotizar_meses'), fn ($q, $v) => $q->whereDoesntHave(
                'consultas',
                fn ($c) => $c->where('tipo', 'Cotizacion')
                    ->whereDate('fecha', '>=', now()->subMonths((int) $v))
            ))
            ->when($request->boolean('con_whatsapp'), fn ($q) => $q->whereHas(
                'contactos.medios.tipoMedio',
                fn ($t) => $t->where('nombre', 'WhatsApp')
            ))
            ->when($request->boolean('con_mail'), fn ($q) => $q->whereHas(
                'contactos.medios.tipoMedio',
                fn ($t) => $t->where('nombre', 'Mail')
            ))
            ->with(['relaciones', 'contactos', 'razonesSociales', 'localidad', 'provincia', 'rubro'])
            // Los borradores no cuentan: todavia no figuran en el historial.
            ->withCount(['consultas as consultas_count' => fn ($q) => $q->where('tipo', 'Cotizacion')
                ->where('estado', '!=', 'Borrador')])
            ->orderBy('nombre')
            ->paginate((int) $request->query('por_pagina', 25));

        return EmpresaListaResource::collection($empresas);
    }

    /** La ficha completa. */
    public function show(Empresa $empresa)
    {
        $empresa->load([
            'relaciones',
            'enlaces',
            'contactos.medios.tipoMedio',
            'razonesSociales.provinciaSede',
            'campos',
            'localidad', 'provincia', 'pais', 'rubro',
            'consultas.lineas.material',
            'consultas.lineas.forma',
            'consultas.lineas.unidadVenta',
            'consultas.lineas.unidadFactura',
            'consultas.lineas.unidadPedida',
            'consultas.contacto',
            'consultas.usuario',
            'consultas.moneda',
            'consultas.observaciones.usuario',
        ]);

        return new EmpresaResource($empresa);
    }
}

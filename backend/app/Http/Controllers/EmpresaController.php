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
        $buscar = trim((string) $request->query('buscar'));

        $empresas = Empresa::query()
            // Las que cerraron no salen salvo que se pidan (?inactivas=1): se reactivan desde su ficha.
            ->when(
                $request->boolean('inactivas'),
                fn ($q) => $q->where('activa', false),
                fn ($q) => $q->activas()
            )
            ->buscar($buscar)
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
            // Con búsqueda, primero las que coinciden por NOMBRE (empieza con,
            // después contiene); las que sólo matchean por un contacto, una
            // razón social o una línea de cotización van al final. Sin esto,
            // buscar una empresa por su nombre la enterraba detrás de cualquier
            // cotización cuyo material contuviera ese texto, a varias páginas.
            ->when($buscar !== '', fn ($q) => $q->orderByRaw(
                'CASE WHEN nombre LIKE ? THEN 1 WHEN nombre LIKE ? THEN 2 ELSE 3 END',
                [$buscar.'%', '%'.$buscar.'%']
            ))
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
            'consultas.comprobantes',
            'consultas.contacto',
            'consultas.usuario',
            'consultas.moneda',
            'consultas.observaciones.usuario',
        ]);

        return new EmpresaResource($empresa);
    }
}

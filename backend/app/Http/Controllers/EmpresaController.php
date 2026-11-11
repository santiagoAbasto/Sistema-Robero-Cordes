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
                // Con ?con_inactivas=1 salen todas, cada una marcada como inactiva.
                fn ($q) => $request->boolean('con_inactivas') ? $q : $q->activas()
            )
            ->buscar($buscar)
            // Un grupo de empresas por número (?ids=3,7,12): las últimas que se abrieron, para ver cómo están HOY.
            ->when($request->has('ids'), fn ($q) => $q->whereIn('id', collect(explode(',', (string) $request->query('ids')))
                ->map(fn ($id) => (int) trim($id))
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->take(25)
                ->all()))
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
            // Las inactivas (si se pidieron) van siempre al final, aunque
            // coincidan mejor o les toque antes por el abecedario.
            ->orderByDesc('activa')
            // Con búsqueda, primero las que coinciden por NOMBRE; las que sólo
            // matchean por un contacto, una razón social o una línea de
            // cotización van al final. Sin esto, buscar una empresa por su
            // nombre la enterraba detrás de cualquier cotización cuyo material
            // contuviera ese texto, a varias páginas.
            ->when($buscar !== '', fn ($q) => $this->ordenarPorRelevancia($q, $buscar))
            ->orderBy('nombre')
            ->paginate((int) $request->query('por_pagina', 25));

        return EmpresaListaResource::collection($empresas);
    }

    /**
     * Del nombre que más se parece al que menos:
     *   0 el nombre igual · 1 empieza con lo buscado · 2 cada palabra buscada
     *   empieza una palabra del nombre · 3 lo contiene tal cual · 4 contiene
     *   cada palabra · 5 sólo matchea por otra cosa.
     *
     * El 2 es el que faltaba: buscando "rada", MECANIZADOS RADA (la palabra
     * entera) quedaba detrás de ESTRADA, PERFORADA o INTEGRADA, que sólo la
     * contienen por dentro, y el buscador de arriba muestra pocas (Roberto,
     * 11-11-2026).
     */
    private function ordenarPorRelevancia($consulta, string $buscar)
    {
        $palabras = preg_split('/\s+/u', $buscar, -1, PREG_SPLIT_NO_EMPTY) ?: [$buscar];

        $empiezaPalabra = implode(' AND ', array_fill(0, count($palabras), '(nombre LIKE ? OR nombre LIKE ?)'));
        $contiene = implode(' AND ', array_fill(0, count($palabras), 'nombre LIKE ?'));

        $datos = [$buscar, $buscar.'%'];
        foreach ($palabras as $palabra) {
            array_push($datos, $palabra.'%', '% '.$palabra.'%');
        }
        $datos[] = '%'.$buscar.'%';
        foreach ($palabras as $palabra) {
            $datos[] = '%'.$palabra.'%';
        }

        return $consulta->orderByRaw(
            "CASE WHEN nombre = ? THEN 0 WHEN nombre LIKE ? THEN 1 WHEN {$empiezaPalabra} THEN 2 WHEN nombre LIKE ? THEN 3 WHEN {$contiene} THEN 4 ELSE 5 END",
            $datos,
        );
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

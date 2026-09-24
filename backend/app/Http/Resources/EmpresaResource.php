<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La ficha completa: los datos, los contactos, a quién se le factura,
 * las condiciones de trabajo y el historial separado en tres.
 */
class EmpresaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'codigo_indice' => $this->codigo_indice,
            'codigo_isis' => $this->codigo_isis,
            'cuit' => $this->cuit,
            'direccion' => $this->direccion,
            'localidad' => $this->localidad?->nombre,
            'localidad_id' => $this->localidad_id,
            'provincia' => $this->provincia?->nombre,
            'provincia_id' => $this->provincia_id,
            'pais' => $this->pais?->nombre,
            'pais_id' => $this->pais_id,
            'codigo_postal' => $this->codigo_postal,
            'rubro' => $this->rubro?->nombre,
            'rubro_id' => $this->rubro_id,
            'activa' => $this->activa,
            'visible_para' => $this->visible_para,

            // Una empresa puede ser cliente y proveedor a la vez.
            'relaciones' => $this->relaciones->map(fn ($r) => [
                'id' => $r->id,
                'relacion' => $r->relacion,
                'desde' => $r->desde?->format('Y-m-d'),
                'activa' => $r->activa,
            ])->values(),

            // Web, redes y mapa: se cargan una vez y quedan clickeables.
            'enlaces' => $this->enlaces->map(fn ($e) => [
                'id' => $e->id,
                'tipo' => $e->tipo,
                'url' => $e->url,
                'url_completa' => $e->url_completa,
                'etiqueta' => $e->etiqueta,
            ])->values(),

            // Se arma con la direccion cargada: no hace falta pegar el link.
            'mapa' => $this->direccion
                ? 'https://www.google.com/maps/search/?api=1&query='.urlencode(implode(', ', array_filter([
                    $this->direccion,
                    $this->localidad?->nombre,
                    $this->provincia?->nombre,
                    $this->pais?->nombre,
                ])))
                : null,

            // La que se ve siempre arriba de la ficha.
            'observacion_general' => $this->observacion_general,

            'contactos' => ContactoResource::collection($this->contactos),

            'razones_sociales' => $this->razonesSociales->map(fn ($r) => [
                'id' => $r->id,
                'razon_social' => $r->razon_social,
                'cuit' => $r->cuit,
                'condicion_iva' => $r->condicion_iva,
                'iibb_condicion' => $r->iibb_condicion,
                'iibb_provincia_sede' => $r->provinciaSede?->nombre,
                'iibb_numero' => $r->iibb_numero,
                'inicio_actividades' => $r->inicio_actividades?->format('Y-m-d'),
                'direccion_fiscal' => $r->direccion_fiscal,
                'localidad' => $r->localidad,
                'habitual' => $r->habitual,
                'activa' => $r->activa,
            ])->values(),

            // Los campos que arma cada empresa: tipo de pago, flete, cómo lo retira.
            'campos' => $this->campos->map(fn ($c) => [
                'id' => $c->id,
                'titulo' => $c->titulo,
                'valor' => $c->valor,
                'usar_al_cotizar' => $c->usar_al_cotizar,
            ])->values(),

            // El historial va separado en tres secciones, como en la ficha.
            'cotizaciones' => ConsultaResource::collection(
                $this->consultas->where('tipo', 'Cotizacion')->where('estado', '!=', 'Borrador')->values()
            ),
            'pedidos' => ConsultaResource::collection(
                $this->consultas->where('tipo', 'Pedido')->values()
            ),
            'observaciones_empresa' => ConsultaResource::collection(
                $this->consultas->where('tipo', 'Observacion')->values()
            ),
        ];
    }
}

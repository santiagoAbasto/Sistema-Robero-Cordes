<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Una cotización, pedido u observación con todo lo que cuelga. */
class ConsultaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'fecha' => $this->fecha?->format('Y-m-d'),
            'validez_dias' => $this->validez_dias,
            'vence_el' => $this->vence_el?->format('Y-m-d'),
            'esta_vencida' => $this->esta_vencida,
            'estado' => $this->estado,
            'dias_para_vencer' => $this->dias_para_vencer,

            // Cuantas veces se le siguio el rastro: notas del hilo y envios de
            // la hoja. Solo viaja en las listas, que es donde se pide contado.
            'seguimientos' => $this->when(
                $this->observaciones_count !== null,
                fn () => (int) $this->observaciones_count + (int) $this->impresiones_count,
            ),

            'empresa' => $this->whenLoaded('empresa', fn () => [
                'id' => $this->empresa->id,
                'nombre' => $this->empresa->nombre,
            ]),
            'contacto' => $this->whenLoaded('contacto', fn () => $this->contacto ? [
                'id' => $this->contacto->id,
                'nombre' => $this->contacto->nombre,
                'sector' => $this->contacto->sector,
            ] : null),
            'razon_social' => $this->whenLoaded('razonSocial', fn () => $this->razonSocial?->razon_social),
            'quien_lo_hizo' => $this->whenLoaded('usuario', fn () => $this->usuario?->initials),
            'moneda' => $this->whenLoaded('moneda', fn () => $this->moneda?->nombre),
            'tipo_cambio' => $this->tipo_cambio,

            'ajuste_dif_cambio' => $this->ajuste_dif_cambio,
            'ajuste_dif_cambio_detalle' => $this->ajuste_dif_cambio_detalle,

            'nro_factura' => $this->nro_factura,
            'id_sistema' => $this->id_sistema,
            'condicion_pago' => $this->condicion_pago,
            'lista_precios' => $this->lista_precios,

            // La NOTA y las observaciones son de uso interno: nunca se imprimen.
            'nota' => $this->nota,
            'texto' => $this->texto,

            'solicitud' => $this->solicitud_via || $this->solicitud_texto ? [
                'via' => $this->solicitud_via,
                'fecha' => $this->solicitud_fecha?->format('Y-m-d'),
                'texto' => $this->solicitud_texto,
            ] : null,

            'copiada_de' => $this->whenLoaded('copiadaDe', fn () => $this->copiadaDe ? [
                'id' => $this->copiadaDe->id,
                'empresa' => $this->copiadaDe->empresa?->nombre,
                'fecha' => $this->copiadaDe->fecha?->format('Y-m-d'),
            ] : null),

            'total' => $this->total,
            'total_kilos' => $this->total_kilos,
            'lineas_iguales_a_lo_pedido' => $this->lineas_iguales_a_lo_pedido,

            'lineas' => ConsultaLineaResource::collection($this->whenLoaded('lineas')),
            'juego_condiciones' => $this->juego_condiciones,
            'condiciones' => $this->whenLoaded('condiciones', fn () => $this->condiciones->map(fn ($c) => [
                'id' => $c->id, 'titulo' => $c->titulo, 'texto' => $c->texto,
                'imprime' => $c->imprime, 'origen' => $c->origen,
            ])),
            'observaciones' => $this->whenLoaded('observaciones', fn () => $this->observaciones->map(fn ($o) => [
                'id' => $o->id,
                'numero' => $o->numero,
                'fecha' => $o->fecha?->format('Y-m-d H:i'),
                'quien' => $o->usuario?->initials,
                'texto' => $o->texto,
            ])),
            'impresiones' => $this->whenLoaded('impresiones', fn () => $this->impresiones->map(fn ($i) => [
                'id' => $i->id,
                'fecha' => $i->fecha?->format('Y-m-d H:i'),
                'nombre_en_pdf' => $i->nombre_en_pdf,
                'contacto' => $i->contacto?->nombre,
                'telefono' => $i->telefono,
                'mail' => $i->mail,
                'via' => $i->via,
                'quien' => $i->usuario?->initials,
                'vencida_al_mandar' => $i->vencida_al_mandar,
            ])),
        ];
    }
}

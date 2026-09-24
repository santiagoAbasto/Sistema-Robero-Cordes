<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsultaLineaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orden' => $this->orden,

            // lo que se cotiza
            'material' => $this->material?->nombre,
            'material_id' => $this->material_id,
            'forma' => $this->forma?->nombre,
            'forma_id' => $this->forma_id,
            'dimensiones' => $this->dimensiones,
            'descripcion' => $this->descripcion,
            'codigo_cliente' => $this->codigo_cliente,
            'item_cliente' => $this->item_cliente,
            'nota' => $this->nota,
            'diametro_mm' => $this->diametro_mm,
            'espesor_mm' => $this->espesor_mm,
            'ancho_mm' => $this->ancho_mm,
            'largo_mm' => $this->largo_mm,

            // el peso, con la foto de como se saco
            'peso_kg' => $this->peso_kg,
            'calculo' => $this->calculo,

            // el check
            'igual_a_lo_pedido' => $this->igual_a_lo_pedido,
            'motivo_cambio' => $this->motivo_cambio,
            'pedido' => $this->igual_a_lo_pedido ? null : [
                'material' => $this->pedido_material,
                'forma' => $this->pedido_forma,
                'dimensiones' => $this->pedido_dimensiones,
                'cantidad' => $this->cantidad_pedida,
                'unidad' => $this->unidadPedida?->codigo,
                // El id ademas del codigo: la pantalla necesita el id para
                // dejar elegida la unidad, igual que en la unidad de venta.
                'unidad_id' => $this->unidad_pedida_id,
                'texto' => $this->pedido_texto,
            ],

            // cantidades y unidades
            'cantidad' => $this->cantidad,
            'unidad' => $this->unidadVenta?->codigo,
            'unidad_factura' => $this->unidadFactura?->codigo,
            // Los ids ademas del codigo: el codigo es para mostrar y el id es
            // lo que elige el desplegable al abrir la cotizacion. Sin ellos la
            // unidad volvia vacia, y al guardar de nuevo se borraba.
            'unidad_venta_id' => $this->unidad_venta_id,
            'unidad_factura_id' => $this->unidad_factura_id,
            'factor_conversion' => $this->factor_conversion,
            'factor_calculado' => $this->factor_calculado,
            // "calculadora" o "manual": lo decide el servidor por la operacion
            // que se uso, no comparando el valor con el de la formula.
            'origen_factor' => $this->origen_factor,
            // Cuando el factor se cargo a mano, quien y cuando. Un peso a mano
            // que termina en una factura tiene que tener un responsable.
            'factor_cargado_por' => $this->factorCargadoPor?->iniciales
                ?? $this->factorCargadoPor?->name,
            'factor_cargado_el' => $this->factor_cargado_el?->toDateTimeString(),
            'cantidad_facturar' => $this->cantidad_facturar,
            'cambia_de_unidad' => $this->cambiaDeUnidad(),

            'precio_unitario' => $this->precio_unitario,
            'precio_por_kilo' => $this->precio_por_kilo,
            'importe' => $this->importe,

            'aprox' => $this->aprox,
            'idem' => $this->idem,
            'quitada' => $this->quitada,

            'desde_stock' => $this->desde_stock,
            'deposito' => $this->deposito,
            'colada' => $this->colada,

            // Las alternativas: el mismo item cotizado de otra manera. La base
            // es la que cuenta para el total; las demas son opciones.
            'opciones' => $this->whenLoaded('opciones', fn () => $this->opciones->map(fn ($o) => [
                'id' => $o->id,
                'etiqueta' => $o->etiqueta,
                'tipo' => $o->tipo,
                'es_base' => $o->es_base,
                'cantidad' => $o->cantidad,
                'precio_unitario' => $o->precio_unitario,
                'precio_por_kilo' => $o->precio_por_kilo,
                'plazo_dias' => $o->plazo_dias,
                'material_id' => $o->material_id,
                'material' => $o->material?->nombre,
                'descripcion' => $o->descripcion,
                'nota' => $o->nota,
                // Ya resueltas contra la linea, para no repetir la cuenta.
                'cantidad_final' => $o->laCantidad(),
                'precio_final' => $o->elPrecio(),
                'importe' => $o->importe,
            ]), []),
        ];
    }
}

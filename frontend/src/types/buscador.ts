import type { EmpresaLista } from './indice'

/* ---------------------------------------------------------------------------
   El buscador único (GET /api/buscar): cuatro vistas del mismo dato.
--------------------------------------------------------------------------- */

export type Grano = 'empresas' | 'consultas' | 'renglones' | 'comprobantes'

/** La empresa de una fila: `activa` es lo que decide si se pinta de rojo. */
export interface EmpresaDeFila {
  id: number
  nombre: string
  activa: boolean
}

export interface FilaEmpresa extends EmpresaLista {
  ultima_cotizacion: string | null
}

export interface FilaConsulta {
  id: number
  tipo: string
  estado: string
  fecha: string | null
  numero: string | null
  emitida: boolean
  empresa: EmpresaDeFila
  moneda: string | null
  usuario: string | null
  vence_el: string | null
  esta_vencida: boolean
  solicitud_nro: string | null
  nro_factura: string | null
  nro_remito: string | null
  nro_pedido_interno: string | null
  comprobantes: string[]
  cantidad_de_lineas: number
  detalle: string[]
  compra: { parcial: boolean; compradas: number; total: number; importe?: number | null } | null
  /** null cuando quien mira no ve importes. */
  total: number | null
}

export interface ConsultaDeFila {
  id: number
  numero: string | null
  tipo: string
  estado: string
  fecha: string | null
  emitida: boolean
}

export interface FilaRenglon {
  id: number
  consulta: ConsultaDeFila
  empresa: EmpresaDeFila
  moneda: string | null
  es_alternativa: boolean
  descripcion: string | null
  material: string | null
  pedido_material: string | null
  forma: string | null
  diametro_mm: number | null
  espesor_mm: number | null
  ancho_mm: number | null
  largo_mm: number | null
  cantidad: number | null
  unidad: string | null
  precio_unitario: number | null
  importe: number | null
  comprada: boolean
  no_cotizada: boolean
  item_cliente: string | null
  codigo_cliente: string | null
}

export interface FilaComprobante {
  id: number
  tipo: string
  numero: string | null
  fecha: string | null
  importe: number | null
  nota: string | null
  moneda: string | null
  consulta: ConsultaDeFila & { solicitud_nro: string | null }
  empresa: EmpresaDeFila
}

export interface ImporteDeMoneda {
  moneda: string | null
  importe: number
  cotizaciones?: number
  renglones?: number
  comprobantes?: number
}

export interface ResumenDelBuscador {
  activas?: number
  inactivas?: number
  /** PHP manda [] y no {} cuando no hay nada: se lee con Object.entries. */
  por_estado?: Record<string, number>
  por_tipo?: Record<string, number>
  importe_por_moneda?: ImporteDeMoneda[]
}

export interface RespuestaDelBuscador<T> {
  data: T[]
  meta: { grano: Grano; current_page: number; last_page: number; per_page: number; total: number }
  resumen: ResumenDelBuscador
  permisos: { ve_importes: boolean }
}

/**
 * Lo que se pide: nombre de filtro → valor, con los mismos nombres que el
 * servidor. La dirección de la pantalla ES el pedido, así que se puede volver
 * con el botón de atrás, guardar una búsqueda o mandarla por mail.
 */
export type Pedido = Record<string, string>

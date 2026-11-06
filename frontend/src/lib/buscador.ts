import api from './api'
import { fecha as fmtFecha, hoy } from './indice'
import type { Catalogos } from '../types/indice'
import type { Grano, Pedido, RespuestaDelBuscador } from '../types/buscador'

/* ---------------------------------------------------------------------------
   El buscador único: lo que se pide, cómo se guarda en la dirección y cómo se
   dice en castellano.

   Los filtros se llaman igual que en el servidor (app/Services/Buscador/
   Filtros.php): la dirección de la pantalla es el pedido, sin traducciones
   en el medio que se puedan desacomodar.
--------------------------------------------------------------------------- */

export const GRANOS: { clave: Grano; titulo: string; ayuda: string }[] = [
  { clave: 'empresas', titulo: 'Empresas', ayuda: 'Una fila por empresa: para entrar a su ficha' },
  { clave: 'consultas', titulo: 'Cotizaciones y pedidos', ayuda: 'Una fila por cotización, pedido u observación' },
  { clave: 'renglones', titulo: 'Material cotizado', ayuda: 'Una fila por material cotizado: a quién y cuánto' },
  { clave: 'comprobantes', titulo: 'Facturas y remitos', ayuda: 'Una fila por factura, remito o nota de crédito' },
]

/** Los cuatro grupos de filtros: de la empresa, de la cotización, del material y del comprobante. */
export type Grupo = 'empresa' | 'cotizacion' | 'material' | 'comprobante'

export const GRUPOS: { clave: Grupo; titulo: string; ayuda: string }[] = [
  { clave: 'empresa', titulo: 'De la empresa', ayuda: 'Quién es, dónde está, cómo se la contacta' },
  { clave: 'cotizacion', titulo: 'De la cotización', ayuda: 'Cuándo, quién la hizo, cómo terminó' },
  { clave: 'material', titulo: 'Del material', ayuda: 'Qué material, qué forma, qué medida' },
  { clave: 'comprobante', titulo: 'De la factura o el remito', ayuda: 'Número, tipo y fecha' },
]

/** Qué grupo se mira primero según lo que se está buscando. */
export const GRUPO_DE_LA_VISTA: Record<Grano, Grupo> = {
  empresas: 'empresa',
  consultas: 'cotizacion',
  renglones: 'material',
  comprobantes: 'comprobante',
}

/** Cada filtro, a qué grupo pertenece. */
const GRUPO_DEL_FILTRO: Record<string, Grupo> = {
  empresa_id: 'empresa', empresa: 'empresa', relacion: 'empresa', rubro_id: 'empresa', provincia_id: 'empresa',
  localidad_id: 'empresa', activa: 'empresa', con_whatsapp: 'empresa', con_mail: 'empresa',
  sin_cotizar_meses: 'empresa', condicion_iva: 'empresa', condicion_pago: 'empresa', condicion: 'empresa',
  observacion: 'empresa', condicion_id: 'empresa', opcion_id: 'empresa',

  tipo: 'cotizacion', estado: 'cotizacion', usuario_id: 'cotizacion', moneda_id: 'cotizacion',
  desde: 'cotizacion', hasta: 'cotizacion', fecha_de: 'cotizacion', compra: 'cotizacion',
  orden_de_compra: 'cotizacion', incluir_borradores: 'cotizacion', sin_respuesta: 'cotizacion',
  por_vencer: 'cotizacion',

  material_id: 'material', material: 'material', forma_id: 'material', diametro_desde: 'material',
  diametro_hasta: 'material', incluir_no_cotizadas: 'material',

  comprobante: 'comprobante', tipo_comprobante: 'comprobante',
}

/** Los que no son filtros: qué vista, qué texto, qué página, en qué orden. */
const NO_FILTROS = ['grano', 'q', 'pagina', 'orden']

export const CLAVES_DE_FILTRO = Object.keys(GRUPO_DEL_FILTRO)

export function grupoDelFiltro(clave: string): Grupo | undefined {
  return GRUPO_DEL_FILTRO[clave]
}

/* ---------------------------------------------------------------- el pedido */

/** El pedido que dice la dirección: sólo lo que el buscador conoce, el resto se ignora. */
export function pedidoDeLaUrl(params: URLSearchParams): Pedido {
  const pedido: Pedido = {}

  for (const clave of [...NO_FILTROS, ...CLAVES_DE_FILTRO]) {
    const valor = params.get(clave)
    if (valor !== null && valor !== '') pedido[clave] = valor
  }

  return pedido
}

export function grano(pedido: Pedido): Grano {
  const g = pedido.grano
  return g === 'consultas' || g === 'renglones' || g === 'comprobantes' ? g : 'empresas'
}

/** La dirección de una búsqueda: sirve para links, atajos y redirecciones. */
export function enlaceAlBuscador(pedido: Pedido): string {
  const params = new URLSearchParams()
  for (const [clave, valor] of Object.entries(pedido)) if (valor) params.set(clave, valor)
  const texto = params.toString()

  return texto ? `/buscador?${texto}` : '/buscador'
}

/**
 * Lo que se manda al servidor. Si lo escrito en "material" es exactamente un
 * material del catálogo, se pide por su número: así también se encuentran los
 * renglones del sistema viejo que lo nombran con otra grafía (el alias).
 */
export function parametrosDelPedido(
  pedido: Pedido,
  materiales: Catalogos['materiales'] = [],
  extra: Record<string, string> = {},
): Record<string, string> {
  const salida: Record<string, string> = { ...pedido, ...extra }

  if (salida.material && !salida.material_id) {
    const escrito = salida.material.trim().toLowerCase()
    const exacto = materiales.find((m) => m.nombre.toLowerCase() === escrito)

    // Sólo si nadie más lo contiene: "Monel" es un material, pero también la familia de Monel 400,
    // Monel K500…, y pedir sólo el número dejaría afuera a los demás.
    const otros = exacto ? materiales.filter((m) => m.id !== exacto.id && m.nombre.toLowerCase().includes(escrito)) : []

    if (exacto && otros.length === 0) {
      salida.material_id = String(exacto.id)
      delete salida.material
    }
  }

  return salida
}

export async function buscar<T>(
  pedido: Pedido,
  materiales?: Catalogos['materiales'],
): Promise<RespuestaDelBuscador<T>> {
  const { data } = await api.get<RespuestaDelBuscador<T>>('/buscar', {
    params: parametrosDelPedido(pedido, materiales),
  })

  return data
}

/** Todo el resultado (no sólo la página) en una planilla de Excel. */
export async function bajarExcel(pedido: Pedido, materiales?: Catalogos['materiales']): Promise<void> {
  const params = parametrosDelPedido(pedido, materiales)
  delete params.pagina

  try {
    const { data } = await api.get<Blob>('/buscar/exportar', { params, responseType: 'blob' })
    const url = URL.createObjectURL(data)
    const enlace = document.createElement('a')
    enlace.href = url
    enlace.download = `buscador-${grano(pedido)}-${hoy()}.xlsx`
    enlace.click()
    URL.revokeObjectURL(url)
  } catch (err) {
    throw new Error(await mensajeDelExcel(err))
  }
}

/** Con responseType blob el error también llega como archivo: hay que abrirlo para leerlo. */
async function mensajeDelExcel(err: unknown): Promise<string> {
  const respuesta = (err as { response?: { data?: unknown } }).response?.data

  if (respuesta instanceof Blob) {
    try {
      const { message } = JSON.parse(await respuesta.text()) as { message?: string }
      if (message) return message
    } catch {
      /* no era un JSON: cae al mensaje de siempre */
    }
  }

  return 'No pudimos preparar el Excel. Probá de nuevo.'
}

/* ------------------------------------------------------- filtros en castellano */

const FECHAS_DE: Record<string, string> = {
  cotizacion: 'de la cotización',
  factura: 'de la factura',
  remito: 'del remito',
  pedido: 'del pedido',
  comprobante: 'de la factura o el remito',
}

const COMPRAS: Record<string, string> = {
  vendida: 'Vendidas (cualquier compra)',
  parcial: 'Compra parcial',
  total: 'Compra total',
}

export interface FiltroActivo {
  /** Qué claves se sacan del pedido al quitarlo. */
  claves: string[]
  texto: string
  grupo: Grupo
}

/** Los filtros que están puestos, dichos como los diría una persona. */
export function filtrosActivos(pedido: Pedido, c: Catalogos | null): FiltroActivo[] {
  const lista: FiltroActivo[] = []
  const agregar = (claves: string[], texto: string) =>
    lista.push({ claves, texto, grupo: GRUPO_DEL_FILTRO[claves[0]] })
  const v = (k: string) => pedido[k]
  const nombre = (lista_: { id: number; nombre: string }[] | undefined, id: string) =>
    lista_?.find((x) => String(x.id) === id)?.nombre ?? '…'

  if (v('empresa_id')) agregar(['empresa_id'], 'Una sola empresa')
  if (v('empresa')) agregar(['empresa'], `Empresa: ${v('empresa')}`)
  if (v('relacion')) agregar(['relacion'], `Relación: ${v('relacion')}`)
  if (v('rubro_id')) agregar(['rubro_id'], `Rubro: ${nombre(c?.rubros, v('rubro_id'))}`)
  if (v('provincia_id')) agregar(['provincia_id'], `Provincia: ${nombre(c?.provincias, v('provincia_id'))}`)
  if (v('localidad_id')) agregar(['localidad_id'], `Localidad: ${nombre(c?.localidades, v('localidad_id'))}`)
  if (v('activa') === 'activas') agregar(['activa'], 'Sólo las activas')
  if (v('activa') === 'inactivas') agregar(['activa'], 'Sólo las inactivas')
  if (v('con_whatsapp')) agregar(['con_whatsapp'], 'Con WhatsApp')
  if (v('con_mail')) agregar(['con_mail'], 'Con mail')
  if (v('sin_cotizar_meses')) agregar(['sin_cotizar_meses'], `Sin cotizarles hace más de ${v('sin_cotizar_meses')} meses`)
  if (v('condicion_iva')) agregar(['condicion_iva'], `IVA: ${v('condicion_iva')}`)
  if (v('condicion_pago')) agregar(['condicion_pago'], `Pago: ${v('condicion_pago')}`)
  if (v('condicion')) agregar(['condicion'], `Condición: ${v('condicion')}`)
  if (v('condicion_id') || v('opcion_id')) {
    const tipo = c?.condiciones_de_la_ficha?.find((t) => String(t.id) === v('condicion_id'))
    const dato = (tipo ? tipo.opciones : (c?.condiciones_de_la_ficha ?? []).flatMap((t) => t.opciones)).find((o) => String(o.id) === v('opcion_id'))
    agregar(
      ['condicion_id', 'opcion_id'],
      `Ficha: ${tipo?.nombre ?? '…'}${v('opcion_id') ? ` = ${dato?.texto ?? '…'}` : ''}`,
    )
  }
  if (v('observacion')) agregar(['observacion'], `Observación: ${v('observacion')}`)

  if (v('tipo')) agregar(['tipo'], `Tipo: ${v('tipo')}`)
  if (v('estado')) agregar(['estado'], `Estado: ${v('estado')}`)
  if (v('usuario_id')) {
    const u = c?.usuarios?.find((x) => String(x.id) === v('usuario_id'))
    agregar(['usuario_id'], `La hizo: ${u?.iniciales ?? u?.name ?? '…'}`)
  }
  if (v('moneda_id')) agregar(['moneda_id'], `Moneda: ${nombre(c?.monedas, v('moneda_id'))}`)
  if (v('desde')) agregar(['desde'], `Desde el ${fmtFecha(v('desde'))}`)
  if (v('hasta')) agregar(['hasta'], `Hasta el ${fmtFecha(v('hasta'))}`)
  if (v('fecha_de')) agregar(['fecha_de'], `Fecha ${FECHAS_DE[v('fecha_de')] ?? v('fecha_de')}`)
  if (v('compra')) agregar(['compra'], COMPRAS[v('compra')] ?? v('compra'))
  if (v('orden_de_compra')) agregar(['orden_de_compra'], `Orden de compra: ${v('orden_de_compra')}`)
  if (v('sin_respuesta')) agregar(['sin_respuesta'], 'Sin respuesta')
  if (v('por_vencer')) agregar(['por_vencer'], `Vencen en ${v('por_vencer')} días`)
  if (v('incluir_borradores')) agregar(['incluir_borradores'], 'Con los borradores')

  if (v('material_id')) {
    agregar(['material_id'], `Material: ${c?.materiales?.find((m) => String(m.id) === v('material_id'))?.nombre ?? '…'}`)
  }
  if (v('material')) agregar(['material'], `Material: ${v('material')}`)
  if (v('forma_id')) agregar(['forma_id'], `Forma: ${c?.formas?.find((f) => String(f.id) === v('forma_id'))?.nombre ?? '…'}`)
  if (v('diametro_desde')) agregar(['diametro_desde'], `Diámetro desde ${v('diametro_desde')} mm`)
  if (v('diametro_hasta')) agregar(['diametro_hasta'], `Diámetro hasta ${v('diametro_hasta')} mm`)
  if (v('incluir_no_cotizadas')) agregar(['incluir_no_cotizadas'], 'Con lo que no se cotizó')

  if (v('comprobante')) agregar(['comprobante'], `Comprobante: ${v('comprobante')}`)
  if (v('tipo_comprobante')) agregar(['tipo_comprobante'], `Tipo de comprobante: ${v('tipo_comprobante')}`)

  return lista
}

/* ------------------------------------------------------------------- atajos */

export interface Atajo {
  texto: string
  ayuda: string
  pedido: () => Pedido
}

/**
 * Las preguntas que más se hacen, a un clic. Cada atajo arma el pedido entero:
 * no se mezcla con lo que estuviera puesto antes.
 */
export const ATAJOS: Atajo[] = [
  {
    texto: 'Hace más de 6 meses que no les cotizo',
    ayuda: 'Empresas activas a las que no se les cotizó en los últimos 6 meses',
    pedido: () => ({ grano: 'empresas', sin_cotizar_meses: '6', activa: 'activas' }),
  },
  {
    texto: 'Cotizaciones sin respuesta',
    ayuda: 'Pasó la validez y nadie contestó',
    pedido: () => ({ grano: 'consultas', sin_respuesta: '1' }),
  },
  {
    texto: 'Vencen esta semana',
    ayuda: 'Cotizaciones abiertas que vencen en los próximos 7 días',
    pedido: () => ({ grano: 'consultas', por_vencer: '7' }),
  },
  {
    texto: 'Ventas con compra parcial',
    ayuda: 'El cliente compró algunos ítems, no todos',
    pedido: () => ({ grano: 'consultas', compra: 'parcial' }),
  },
  {
    texto: 'Lo cotizado en el último año',
    ayuda: 'Todas las cotizaciones y pedidos del último año',
    pedido: () => ({ grano: 'consultas', desde: hoy(-365), hasta: hoy() }),
  },
  {
    texto: 'Borradores sin emitir',
    ayuda: 'Lo que quedó a medias',
    pedido: () => ({ grano: 'consultas', estado: 'Sin emitir' }),
  },
]

/** Cuántas cosas hay puestas para filtrar (el texto no cuenta: tiene su propia caja). */
export function cantidadDeFiltros(pedido: Pedido): number {
  return CLAVES_DE_FILTRO.filter((k) => pedido[k]).length
}

/* ----------------------------------------------------------------- Cordes IA */

/** Lo que entendió Cordes, dicho en etiquetas: qué quiere ver, el texto y cada filtro. */
export function loQueEntendio(pedido: Pedido, c: Catalogos | null): string[] {
  const vista = GRANOS.find((g) => g.clave === grano(pedido))?.titulo ?? 'Empresas'

  return [
    `Ver: ${vista}`,
    ...(pedido.q ? [`Texto: «${pedido.q}»`] : []),
    ...filtrosActivos(pedido, c).map((f) => f.texto),
  ]
}

/** El pedido sin lo que no cambia la búsqueda (la página y el orden): sirve para saber si sigue siendo el mismo. */
export function claveDelPedido(params: URLSearchParams | Pedido): string {
  const copia = new URLSearchParams(params instanceof URLSearchParams ? params : Object.entries(params))
  copia.delete('pagina')
  copia.delete('orden')
  copia.sort()

  return copia.toString()
}

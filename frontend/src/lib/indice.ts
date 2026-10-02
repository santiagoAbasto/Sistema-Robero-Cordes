import { useCallback, useEffect, useRef, useState } from 'react'
import api from './api'
import type { Catalogos, Consulta, Empresa, EmpresaLista, Paginado } from '../types/indice'

/* ---------------------------------------------------------------------------
   Acceso a los datos del Índice Telefónico.
--------------------------------------------------------------------------- */

export interface FiltrosEmpresas {
  /** Laravel lee la pagina de "page": no cambiarle el nombre. */
  page?: number
  buscar?: string
  relacion?: string
  rubro_id?: number | ''
  localidad_id?: number | ''
  provincia_id?: number | ''
  observacion?: string
  material_id?: number | ''
  sin_cotizar_meses?: number | ''
  con_whatsapp?: boolean
  con_mail?: boolean
}

function limpiar<T extends object>(params: T): Record<string, string> {
  const salida: Record<string, string> = {}

  for (const [clave, valor] of Object.entries(params)) {
    if (valor === undefined || valor === null || valor === '' || valor === false) continue
    salida[clave] = String(valor)
  }

  return salida
}

export async function buscarEmpresas(filtros: FiltrosEmpresas) {
  const { data } = await api.get<Paginado<EmpresaLista>>('/empresas', {
    params: limpiar(filtros),
  })

  return data
}

export async function traerEmpresa(id: number | string) {
  const { data } = await api.get<{ data: Empresa }>(`/empresas/${id}`)

  return data.data
}

export async function traerConsulta(id: number | string) {
  const { data } = await api.get<{ data: Consulta }>(`/consultas/${id}`)

  return data.data
}

export async function traerBorradores(id: number | string) {
  const { data } = await api.get<{ data: Consulta[] }>(`/consultas/${id}/borradores`)

  return data.data
}

export interface FiltrosConsultas {
  tipo?: string
  estado?: string
  empresa_id?: number | ''
  usuario_id?: number | ''
  material_id?: number | ''
  forma_id?: number | ''
  desde?: string
  hasta?: string
  diametro_desde?: number | ''
  diametro_hasta?: number | ''
  sin_respuesta?: boolean
  /** Laravel lee la pagina de "page": no cambiarle el nombre. */
  page?: number
  /** Vencen dentro de tantos dias y siguen abiertas. */
  por_vencer?: number | ''
  /** Solo las que terminaron: declinadas o vencidas. */
  cerradas?: boolean
}

export async function buscarConsultas(filtros: FiltrosConsultas) {
  const { data } = await api.get<Paginado<Consulta>>('/consultas', {
    params: limpiar(filtros),
  })

  return data
}

/* ---------------------------------------------------------------- seguimiento */

export interface Pendiente {
  id: number
  empresa: string | null
  vence_el: string | null
  dias_para_vencer: number | null
}

export interface Pendientes {
  por_vencer: { dias: number; cuantas: number; primeras: Pendiente[] }
  vencidas_sin_cerrar: { cuantas: number; primeras: Pendiente[] }
}

/** Lo que necesita atención ahora. Se calcula al preguntar: no hay tabla. */
export async function traerPendientes(): Promise<Pendientes> {
  const { data } = await api.get<Pendientes>('/seguimiento/pendientes')

  return data
}

export interface ReporteSeguimiento {
  generado_el: string
  periodo: { desde: string; hasta: string }
  abiertas: { total: number; por_vencer: number; vencidas_sin_cerrar: number }
  cerradas: {
    total: number
    por_motivo: { motivo: string; cuantas: number; importe: number }[]
  }
  vendidas: { total: number }
  seguimiento: { sin_ningun_seguimiento: number; promedio_por_cerrada: number }
}

export async function traerReporteSeguimiento(periodo: { desde?: string; hasta?: string }) {
  const { data } = await api.get<ReporteSeguimiento>('/reportes/seguimiento', {
    params: limpiar(periodo),
  })

  return data
}

/* ------------------------------------------------------------------ catálogos */

let cacheCatalogos: Catalogos | null = null

export async function traerCatalogos(): Promise<Catalogos> {
  if (cacheCatalogos) return cacheCatalogos

  const { data } = await api.get<Catalogos>('/catalogos')
  cacheCatalogos = data

  return data
}

/**
 * Que el próximo pedido traiga el catálogo de nuevo: el buscador de
 * direcciones agregó una localidad y la ficha, al modificarla, no la tenía.
 */
export function olvidarCatalogos() {
  cacheCatalogos = null
}

export function useCatalogos() {
  const [catalogos, setCatalogos] = useState<Catalogos | null>(cacheCatalogos)

  useEffect(() => {
    if (cacheCatalogos) return
    let vivo = true
    traerCatalogos().then((c) => vivo && setCatalogos(c)).catch(() => {})

    return () => {
      vivo = false
    }
  }, [])

  return catalogos
}

/* ------------------------------------------------------------------- hooks */

interface EstadoCarga<T> {
  datos: T | null
  cargando: boolean
  error: string | null
}

/** Carga algo una vez y vuelve a cargarlo cuando cambian las dependencias. */
export function useCarga<T>(cargar: () => Promise<T>, deps: unknown[]): EstadoCarga<T> & { recargar: () => void } {
  const [estado, setEstado] = useState<EstadoCarga<T>>({ datos: null, cargando: true, error: null })
  const [tick, setTick] = useState(0)
  const fn = useRef(cargar)
  fn.current = cargar

  useEffect(() => {
    let vivo = true
    setEstado((e) => ({ ...e, cargando: true, error: null }))

    fn.current()
      .then((datos) => vivo && setEstado({ datos, cargando: false, error: null }))
      .catch((err) => {
        if (!vivo) return
        const mensaje =
          err?.response?.status === 404
            ? 'No encontramos ese registro.'
            : 'No pudimos traer los datos. Probá de nuevo.'
        setEstado({ datos: null, cargando: false, error: mensaje })
      })

    return () => {
      vivo = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick])

  return { ...estado, recargar: () => setTick((t) => t + 1) }
}

/** Espera a que dejen de tipear antes de buscar. */
export function useDebounce<T>(valor: T, ms = 300): T {
  const [diferido, setDiferido] = useState(valor)

  useEffect(() => {
    const id = setTimeout(() => setDiferido(valor), ms)

    return () => clearTimeout(id)
  }, [valor, ms])

  return diferido
}

/* --------------------------------------------------- el pie de un mail */

/** Lo que se pudo leer del pie de un mail. Todo puede venir vacío. */
export interface DatosDeLaFirma {
  empresa: string | null
  contacto: string | null
  cargo: string | null
  mail: string | null
  telefono: string | null
  web: string | null
  direccion: string | null
  codigo_postal: string | null
  pais_id: number | null
  provincia_id: number | null
  localidad_id: number | null
  localidad_nombre?: string | null
  /** Que es el telefono: Celular, WhatsApp o Telefono. Lo dice su rotulo. */
  tipo_telefono?: string | null
  /** La web y las redes que trae: se guardan como enlaces de la ficha. */
  enlaces?: EnlaceLeido[]
}

export interface EnlaceLeido {
  tipo: string
  url: string
}

type FirmaLeida = { datos: DatosDeLaFirma; mensaje: string; texto: string; con_ia: boolean }

/** El texto pegado, o la imagen de la firma: esa la copia la IA y se lee con las mismas reglas. */
export async function leerFirmaDeMail(entrada: string | File) {
  const { data } =
    typeof entrada === 'string'
      ? await api.post<FirmaLeida>('/empresas/leer-firma', { texto: entrada })
      : await api.postForm<FirmaLeida>('/empresas/leer-firma', { imagen: entrada })

  return data
}

/**
 * La imagen pegada con Ctrl+V, si lo pegado es SOLO una imagen. Si tambien
 * trae texto (un mail copiado con su logo) gana el texto, como siempre.
 */
export function imagenPegada(e: React.ClipboardEvent): File | undefined {
  if (e.clipboardData.getData('text/plain').trim()) return undefined

  return Array.from(e.clipboardData.files).find((f) => f.type.startsWith('image/'))
}

/* ------------------------------------------------------------- revisiones */

/** Un guardado de la cotización: todo lo que cambió una persona de una vez. */
export interface Revision {
  numero: number
  fecha: string | null
  quien: string
  iniciales: string
  /** Una línea que dice qué pasó, para no tener que leer el detalle. */
  que_paso: string
  cambios: { que: string; antes: string | null; ahora: string | null }[]
}

export async function traerRevisiones(consultaId: number) {
  const { data } = await api.get<{ revisiones: Revision[]; total: number }>(
    `/consultas/${consultaId}/revisiones`,
  )

  return data
}

/* ------------------------------------------------------- últimas empresas vistas */

const VISTAS_KEY = 'cordes_ultimas_empresas'
const MAX_VISTAS = 6

export interface EmpresaVista {
  id: number
  nombre: string
  relaciones: string[]
  localidad: string | null
}

export function leerUltimasVistas(): EmpresaVista[] {
  try {
    const crudo = localStorage.getItem(VISTAS_KEY)

    return crudo ? (JSON.parse(crudo) as EmpresaVista[]) : []
  } catch {
    return []
  }
}

export function anotarVisita(empresa: EmpresaVista): void {
  try {
    const previas = leerUltimasVistas().filter((e) => e.id !== empresa.id)
    localStorage.setItem(VISTAS_KEY, JSON.stringify([empresa, ...previas].slice(0, MAX_VISTAS)))
  } catch {
    /* si el navegador no deja guardar, no pasa nada: es una comodidad */
  }
}

export function useUltimasVistas() {
  const [vistas, setVistas] = useState<EmpresaVista[]>([])

  useEffect(() => setVistas(leerUltimasVistas()), [])

  return vistas
}

/* ------------------------------------------------------------------ formato */

/** 1458.72 -> "1.458,72" */
export function plata(valor: string | number | null | undefined): string {
  if (valor === null || valor === undefined || valor === '') return '—'
  const numero = typeof valor === 'string' ? Number(valor) : valor
  if (Number.isNaN(numero)) return '—'

  return numero.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** Cantidades: sin decimales cuando es entero. */
export function cantidad(valor: string | number | null | undefined): string {
  if (valor === null || valor === undefined || valor === '') return '—'
  const numero = typeof valor === 'string' ? Number(valor) : valor
  if (Number.isNaN(numero)) return '—'

  return numero.toLocaleString('es-AR', {
    minimumFractionDigits: Number.isInteger(numero) ? 0 : 2,
    maximumFractionDigits: 2,
  })
}

/**
 * La fecha de hoy en Argentina, "2026-10-01". Corrida en días si hace falta.
 *
 * toISOString da la de Greenwich: después de las 21 h ya era mañana, y una
 * cotización cargada a la noche salía con la fecha del día siguiente.
 */
export function hoy(dias = 0): string {
  return new Date(Date.now() + dias * 864e5).toLocaleDateString('sv-SE', {
    timeZone: 'America/Argentina/Buenos_Aires',
  })
}

/** "2026-07-20" -> "20/07/2026" */
export function fecha(valor: string | null | undefined): string {
  if (!valor) return '—'
  const soloFecha = valor.slice(0, 10)
  const [a, m, d] = soloFecha.split('-')
  if (!a || !m || !d) return valor

  return `${d}/${m}/${a}`
}

/** "2026-07-20 12:15" -> "20/07/2026 12:15" */
export function fechaHora(valor: string | null | undefined): string {
  if (!valor) return '—'
  const [f, h] = valor.split(' ')

  return h ? `${fecha(f)} ${h}` : fecha(f)
}

export const useCallbackEstable = useCallback

/* ---------------------------------------------------------------------------
   Escritura. Todo lo que se guarda queda registrado en el control de cambios.
--------------------------------------------------------------------------- */

export interface DatosEmpresa {
  nombre: string
  codigo_indice?: string | null
  codigo_isis?: string | null
  cuit?: string | null
  direccion?: string | null
  localidad_id?: number | null
  provincia_id?: number | null
  pais_id?: number | null
  codigo_postal?: string | null
  rubro_id?: number | null
  observacion_general?: string | null
  relaciones?: string[]
  /** Su primer contacto: se guarda junto con la empresa y nace principal. */
  contacto?: DatosContacto | null
  /** La web y las redes leídas del mail, como enlaces de la ficha. */
  enlaces?: EnlaceLeido[]
}

export async function crearEmpresa(datos: DatosEmpresa) {
  const { data } = await api.post<{ data: Empresa }>('/empresas', datos)

  return data.data
}

export async function actualizarEmpresa(id: number, datos: DatosEmpresa) {
  const { data } = await api.put<{ data: Empresa }>(`/empresas/${id}`, datos)

  return data.data
}

export async function archivarEmpresa(id: number) {
  await api.post(`/empresas/${id}/archivar`)
}

export interface DatosContacto {
  nombre: string
  sector?: string | null
  cargo?: string | null
  principal?: boolean
  observacion?: string | null
  medios: { tipo_medio_id: number; valor: string; principal?: boolean; nota?: string | null }[]
}

export async function guardarContacto(empresaId: number, datos: DatosContacto, contactoId?: number) {
  const url = contactoId
    ? `/empresas/${empresaId}/contactos/${contactoId}`
    : `/empresas/${empresaId}/contactos`
  const { data } = contactoId ? await api.put(url, datos) : await api.post(url, datos)

  // Si ya habia alguien con ese nombre, el servidor le suma lo nuevo y lo dice.
  return data as { id: number; mensaje: string; ya_estaba?: boolean }
}

export async function archivarContacto(id: number) {
  await api.post(`/contactos/${id}/archivar`)
}

export interface DatosRazonSocial {
  razon_social: string
  cuit: string
  condicion_iva?: string | null
  iibb_condicion?: string | null
  iibb_provincia_sede_id?: number | null
  iibb_numero?: string | null
  inicio_actividades?: string | null
  direccion_fiscal?: string | null
  localidad?: string | null
  habitual?: boolean
}

export async function guardarRazonSocial(
  empresaId: number,
  datos: DatosRazonSocial,
  razonId?: number,
) {
  const url = razonId
    ? `/empresas/${empresaId}/razones-sociales/${razonId}`
    : `/empresas/${empresaId}/razones-sociales`
  const { data } = razonId ? await api.put(url, datos) : await api.post(url, datos)

  return data
}

export async function archivarRazonSocial(id: number) {
  await api.post(`/razones-sociales/${id}/archivar`)
}

export interface DatosCampo {
  titulo: string
  valor?: string | null
  usar_al_cotizar?: boolean
}

export async function guardarCampo(empresaId: number, datos: DatosCampo, campoId?: number) {
  const url = campoId ? `/empresas/${empresaId}/campos/${campoId}` : `/empresas/${empresaId}/campos`
  const { data } = campoId ? await api.put(url, datos) : await api.post(url, datos)

  return data
}

export async function borrarCampo(id: number) {
  await api.delete(`/campos/${id}`)
}

/* ------------------------------------------------------------- consultas */

export interface DatosLinea {
  descripcion: string
  /** Como el cliente reconoce el item: su codigo y el numero de su pedido. */
  codigo_cliente?: string | null
  item_cliente?: string | null
  /** Datos extra del articulo: plano, posicion, tratamiento. Sale impresa. */
  nota?: string | null
  material_id?: number | null
  /**
   * El material que pidieron y todavia no esta en el catalogo.
   *
   * Viaja el nombre escrito, no un id: el servidor lo da de alta al guardar,
   * sin densidad. Va junto con material_id en null.
   */
  material_nuevo?: string | null
  forma_id?: number | null
  dimensiones?: string | null
  diametro_mm?: number | null
  espesor_mm?: number | null
  ancho_mm?: number | null
  largo_mm?: number | null
  /**
   * Largo variable: las barras y los caños no vienen todos del mismo largo.
   *
   * Cargados los dos extremos, el peso y el factor se calculan con el promedio
   * y el rango sale impreso. Sin los dos, el largo es uno solo como siempre.
   */
  largo_min_mm?: number | null
  largo_max_mm?: number | null
  /** Medida y peso con tolerancia: sale "(aprox.)" en la hoja. */
  aprox?: boolean
  /** "idem al anterior": se imprime asi en vez de repetir la descripcion. */
  idem?: boolean
  cantidad?: number | null
  unidad_venta_id?: number | null
  unidad_factura_id?: number | null
  factor_conversion?: number | null
  precio_unitario?: number | null
  precio_por_kilo?: number | null
  igual_a_lo_pedido?: boolean
  motivo_cambio?: string | null
  pedido_material?: string | null
  pedido_forma?: string | null
  pedido_dimensiones?: string | null
  /**
   * Las medidas de lo pedido, campo por campo. `pedido_dimensiones` sigue
   * siendo el texto que sale impreso: se arma a partir de éstas.
   */
  pedido_medidas?: Record<string, { valor: string; unidad: string }> | null
  cantidad_pedida?: number | null
  unidad_pedida_id?: number | null
  desde_stock?: boolean
  deposito?: string | null
  colada?: string | null
  quitada?: boolean

  /**
   * La calculadora de peso: van las medidas como se cargaron, no el resultado.
   * El peso lo rehace el servidor y lo guarda junto con la densidad y la
   * formula que uso, para que corregir una densidad mañana no cambie lo que se
   * cotizo hoy.
   */
  calc_medidas?: Record<string, { valor: string; unidad: string }> | null
  calc_piezas?: number | null
  calc_cano_id?: number | null

  /**
   * Alternativas: el mismo ítem cotizado de otra manera. Cada una pisa solo lo
   * que cambia; lo que va en null lo hereda de la línea.
   */
  opciones?: DatosOpcion[]

  /** El id de la línea que ya existía, para que el servidor la empareje. */
  id?: number | null
  /**
   * "Usar estos kilos": se le pide al servidor que saque el factor y lo
   * aplique. Es una operación, no un valor — el navegador pide, no decide de
   * dónde vino el número.
   */
  aplicar_calculo_al_factor?: boolean
}

export interface DatosOpcion {
  etiqueta: string
  tipo: string
  es_base: boolean
  cantidad?: number | null
  precio_unitario?: number | null
  precio_por_kilo?: number | null
  plazo_dias?: number | null
  material_id?: number | null
  descripcion?: string | null
  nota?: string | null
}

export interface DatosConsulta {
  tipo: string
  fecha: string
  validez_dias?: number
  contacto_id?: number | null
  razon_social_id?: number | null
  moneda_id?: number | null
  tipo_cambio?: number | null
  ajuste_dif_cambio?: boolean
  ajuste_dif_cambio_detalle?: string | null
  nro_factura?: string | null
  id_sistema?: string | null
  condicion_pago?: string | null
  lista_precios?: string | null
  nota?: string | null
  texto?: string | null
  estado?: string
  solicitud_via?: string | null
  solicitud_fecha?: string | null
  solicitud_texto?: string | null
  lineas?: DatosLinea[]
  juego_condiciones?: string | null
  condiciones?: { titulo?: string | null; texto: string; imprime?: boolean; origen?: string }[]
}

export async function crearConsulta(empresaId: number, datos: DatosConsulta) {
  const { data } = await api.post<{ data: Consulta }>(`/empresas/${empresaId}/consultas`, datos)

  return data.data
}

export async function actualizarConsulta(id: number, datos: DatosConsulta) {
  const { data } = await api.put<{ data: Consulta }>(`/consultas/${id}`, datos)

  return data.data
}

export async function descartarConsulta(id: number) {
  await api.delete(`/consultas/${id}`)
}

export async function confirmarConsulta(id: number) {
  const { data } = await api.post<{ data: Consulta }>(`/consultas/${id}/confirmar`)

  return data.data
}

/* ------------------------------------------------------------ revisiones */

/** La emite: le da numero y revision (2026-0001 R0) y la congela. */
export async function emitirConsulta(id: number) {
  const { data } = await api.post<{ data: Consulta }>(`/consultas/${id}/emitir`)

  return data.data
}

/**
 * Hace una revision de una emitida: un borrador nuevo con todo lo de la base.
 *
 * Si ya hay una revision a medio hacer de la misma cotizacion, el servidor
 * contesta 409 con su id: se abre esa en vez de crear otra.
 */
export async function nuevaRevision(id: number): Promise<number> {
  try {
    const { data } = await api.post<{ data: Consulta }>(`/consultas/${id}/revision`)

    return data.data.id
  } catch (err) {
    const abierta = (err as { response?: { status?: number; data?: { id?: number } } }).response

    if (abierta?.status === 409 && abierta.data?.id) return abierta.data.id

    throw err
  }
}

/** Una de las versiones de una cotizacion, para elegir cual abrir. */
export interface VersionDeCotizacion {
  id: number
  numero: string | null
  revision: number
  emitida: boolean
  emitida_el: string | null
  emitida_por: string | null
  cargada_por: string | null
  fecha: string | null
  total: number
  estado: string
}

export async function versionesDe(id: number) {
  const { data } = await api.get<VersionDeCotizacion[]>(`/consultas/${id}/versiones`)

  return data
}

export async function copiarConsulta(id: number, empresas: number[]) {
  const { data } = await api.post<{ mensaje: string; borradores: { data: Consulta[] } }>(
    `/consultas/${id}/copiar`,
    { empresas },
  )

  return data
}

export async function agregarObservacion(consultaId: number, texto: string) {
  const { data } = await api.post(`/consultas/${consultaId}/observaciones`, { texto })

  return data
}

export async function borrarObservacion(id: number) {
  await api.delete(`/observaciones/${id}`)
}

export interface DatosImpresion {
  nombre_en_pdf: string
  contacto_id?: number | null
  telefono?: string | null
  mail?: string | null
  via: string
  incluye_importes?: boolean
  incluye_nota?: boolean
}

export async function registrarImpresion(consultaId: number, datos: DatosImpresion) {
  const { data } = await api.post(`/consultas/${consultaId}/impresiones`, datos)

  return data
}

/* ------------------------------------------------------- control de cambios */

export interface Cambio {
  id: number
  fecha: string
  quien: string
  tabla: string
  que_cambio: string
  antes: string | null
  ahora: string | null
  accion: string
}

export async function traerHistorial(
  empresaId: number | string,
  filtros: Record<string, string> = {},
) {
  const { data } = await api.get<Paginado<Cambio>>(`/empresas/${empresaId}/historial`, {
    params: limpiar(filtros),
  })

  return data
}

/** Saca el mensaje de error que devolvió la API, para mostrarlo tal cual. */
export function mensajeDeError(err: unknown, porDefecto = 'No pudimos guardar. Probá de nuevo.'): string {
  const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }

  // Sin respuesta no hubo rechazo: no llegamos al servidor. Decirlo aparte
  // evita ponerse a revisar la pantalla cuando lo que falta es el backend.
  if (!e?.response) {
    return 'No se pudo conectar con el servidor. Fijate que este levantado y probá de nuevo.'
  }

  const errores = e?.response?.data?.errors

  if (errores) {
    const primero = Object.values(errores)[0]
    if (primero?.[0]) return primero[0]
  }

  return e?.response?.data?.message ?? porDefecto
}

/* ------------------------------------------------- enlaces de la empresa */

export interface DatosEnlace {
  tipo: string
  url: string
  etiqueta?: string | null
}

export async function guardarEnlace(empresaId: number, datos: DatosEnlace, enlaceId?: number) {
  const url = enlaceId
    ? `/empresas/${empresaId}/enlaces/${enlaceId}`
    : `/empresas/${empresaId}/enlaces`
  const { data } = enlaceId ? await api.put(url, datos) : await api.post(url, datos)

  return data
}

export async function borrarEnlace(id: number) {
  await api.delete(`/enlaces/${id}`)
}

/* --------------------------------------- cotizaciones relacionadas y precarga */

export async function traerRelacionadas(consultaId: number | string) {
  const { data } = await api.get<import('../types/indice').Relacionadas>(
    `/consultas/${consultaId}/relacionadas`,
  )

  return data
}

/** Pega el texto del cliente y devuelve las líneas propuestas. */
export async function interpretarTexto(texto: string) {
  const { data } = await api.post<{
    lineas: import('../types/indice').LineaInterpretada[]
    sin_reconocer: string[]
    con_ia: boolean
    aviso: string | null
    repetidas: string[]
    mensaje: string
  }>('/consultas/interpretar', { texto })

  return data
}

/**
 * Trae la hoja de una cotización y la pone delante de la persona.
 *
 * La pestaña se abre en el mismo momento del clic, ANTES de guardar: si se
 * abriera después —cuando el servidor ya contestó— el navegador la trataría
 * como ventana emergente y la bloquearía.
 *
 * Y si igual la bloqueó, la hoja se descarga. Lo que no puede pasar es que
 * alguien apriete "Guardar e imprimir" y no reciba nada.
 *
 * Se pide con axios y no con un link directo para que el token viaje en el
 * encabezado y no en la dirección. El servidor deja registrado que se mandó.
 */
/**
 * La hoja de una cotización para mirarla en la pantalla, sin mandarla.
 *
 * Es la misma que recibe el cliente, pero con registrar=0: mirarla no es
 * imprimirla, y si cada vistazo quedara anotado el historial de impresiones
 * diría que se le mandó veinte veces. Devuelve una dirección local que hay que
 * liberar con URL.revokeObjectURL cuando ya no se muestra.
 */
export async function urlDeLaHojaPrevia(id: number): Promise<string> {
  const { data } = await api.get(`/consultas/${id}/pdf`, {
    responseType: 'blob',
    params: { registrar: 0 },
  })

  return URL.createObjectURL(new Blob([data], { type: 'application/pdf' }))
}

export async function traerLaHoja(
  id: number,
  pestana: Window | null,
  nombre: string,
  via = 'PDF',
): Promise<'pestana' | 'descarga'> {
  const { data } = await api.get(`/consultas/${id}/pdf`, {
    responseType: 'blob',
    params: { via },
  })

  const url = URL.createObjectURL(new Blob([data], { type: 'application/pdf' }))

  // El navegador la libera cuando ya la usó.
  setTimeout(() => URL.revokeObjectURL(url), 60_000)

  if (pestana && !pestana.closed) {
    pestana.location.href = url

    return 'pestana'
  }

  const a = document.createElement('a')
  a.href = url
  a.download = `${nombre}.pdf`
  a.click()

  return 'descarga'
}

import api from './api'

/* ---------------------------------------------------------------------------
   Administracion de formas.

   Todo lo que tiene que ver con normalizar y validar formulas se le pregunta al
   servidor: es el mismo codigo que despues las guarda, asi que lo que se ve
   probando es exactamente lo que va a quedar.
--------------------------------------------------------------------------- */

export interface CampoDeForma {
  clave: string
  label: string
}

export interface FormaAdmin {
  id: number
  /** El nombre corto y estable: "barra_redonda". El visible puede cambiar. */
  clave: string
  nombre: string
  campos: CampoDeForma[]
  expresion: string | null
  usa_cano: boolean
  activo: boolean
  orden: number
  medidas_habituales: string | null
  medidas_necesarias: string | null
  /** Cuantas lineas ya cotizadas la usan. No se recalculan: es para saber el alcance. */
  lineas_cotizadas: number
  materiales_que_la_usan: number
  /**
   * Con historial la clave queda congelada: es lo que identifica a la forma, y
   * cambiarla dejaría las cotizaciones viejas apuntando a algo que ya no
   * existe. Renombrarla es una migración, no una edición de pantalla.
   */
  clave_editable: boolean
  /**
   * Cómo está la cuenta de esta forma:
   *  · valida      — se resuelve y todas sus medidas están declaradas
   *  · sin_formula — no tiene cuenta: el peso se carga a mano
   *  · invalida    — tiene cuenta pero no se puede resolver
   */
  estado_formula: 'valida' | 'sin_formula' | 'invalida'
  problema_formula: string | null
}

export interface ListadoDeFormas {
  medidas_posibles: CampoDeForma[]
  unidades: string[]
  formas: FormaAdmin[]
}

export interface PruebaDeFormula {
  formula: string
  ok: boolean
  error: string | null
  variables: string[]
  /** Medidas que nombra la formula pero la forma no declara. Impide guardar. */
  sin_declarar: string[]
  valores_usados: Record<string, number>
  volumen_cm3: number | null
}

export interface DatosDeForma {
  nombre: string
  /** Si va vacía, el servidor la arma del nombre. */
  clave?: string | null
  orden?: number | null
  campos: CampoDeForma[]
  expresion: string | null
  usa_cano: boolean
  activo: boolean
  medidas_habituales?: string | null
  medidas_necesarias?: string | null
}

export interface ResultadoDeBorrado {
  /** Falso cuando tenía historial: en ese caso quedó desactivada. */
  borrada: boolean
  mensaje: string
}

/**
 * Borrar una forma.
 *
 * Si ya tiene cotizaciones el servidor no la borra: la desactiva y lo dice.
 * Esa decisión la toma el servidor, no esta pantalla.
 */
export async function borrarForma(id: number) {
  const { data } = await api.delete<ResultadoDeBorrado>(`/formas/${id}`)

  return data
}

export async function traerFormas() {
  const { data } = await api.get<ListadoDeFormas>('/formas')

  return data
}

export async function probarFormula(cuerpo: {
  expresion: string
  campos: CampoDeForma[]
  valores: Record<string, number>
}) {
  const { data } = await api.post<PruebaDeFormula>('/formas/probar', cuerpo)

  return data
}

export async function guardarForma(datos: DatosDeForma, id?: number) {
  const { data } = id
    ? await api.put<FormaAdmin>(`/formas/${id}`, datos)
    : await api.post<FormaAdmin>('/formas', datos)

  return data
}

export async function ordenarFormas(ids: number[]) {
  await api.post('/formas/orden', { ids })
}

/* ------------------------------------------- lo que se propone al cotizar */

export interface SugerenciaDeTransporte {
  etiqueta: string
  tipo: string
  /** El plazo que se usó la última vez. Null si no hay historial. */
  plazo_dias: number | null
  veces: number
}

export interface SugerenciasDeAlternativas {
  transporte: SugerenciaDeTransporte[]
  tipos: string[]
}

/**
 * Se pide una sola vez por sesión y la comparten todas las líneas: si no, una
 * cotización de diez ítems haría diez pedidos iguales.
 */
let pedido: Promise<SugerenciasDeAlternativas> | null = null

export function traerSugerencias(): Promise<SugerenciasDeAlternativas> {
  pedido ??= api
    .get<SugerenciasDeAlternativas>('/alternativas/sugerencias')
    .then((r) => r.data)
    .catch(() => ({ transporte: [], tipos: [] }))

  return pedido
}

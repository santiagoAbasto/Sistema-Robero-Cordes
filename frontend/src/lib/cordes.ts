import api, { getToken, setToken } from './api'
import type { Pedido } from '../types/buscador'
import type { AnalisisDeCordes, RespuestaDeCordes, Turno } from '../types/cordes'

/** La respuesta siempre completa: si al servidor le faltara una parte, la pantalla igual se arma (listas vacías, no errores). */
function completa(r: Partial<RespuestaDeCordes>): RespuestaDeCordes {
  return {
    fuente: r.fuente ?? 'codigo',
    mensaje: r.mensaje ?? null,
    verificada: r.verificada ?? true,
    aviso: r.aviso ?? null,
    pedido: r.pedido ?? null,
    borrador: r.borrador ?? null,
    tablas: (r.tablas ?? []).map((t) => ({ ...t, columnas: t.columnas ?? [], filas: t.filas ?? [], acciones: t.acciones ?? [] })),
    atajos: r.atajos ?? [],
    opciones: r.opciones ?? null,
    seguir: r.seguir ?? [],
    no_entendi: r.no_entendi ?? [],
    pasos: r.pasos ?? [],
    analisis: r.analisis ?? null,
  }
}

/** Un error con algo que decirle a la persona. */
export class ErrorDeCordes extends Error {
  readonly tipo: 'limite' | 'sesion' | 'general'

  constructor(mensaje: string, tipo: 'limite' | 'sesion' | 'general' = 'general') {
    super(mensaje)
    this.tipo = tipo
  }
}

export interface ContextoDeCordes {
  vista: string | null
  filtros: Record<string, string | number | boolean>
}

/** Cuánto de cada vuelta se manda: una respuesta larga (la evolución de precios) no tiene que ocupar todo el hilo. */
const TOPE_DE_UNA_VUELTA = 1200

/** Lo que se le manda de la conversación: las últimas vueltas, sólo con su texto. */
export function historialParaCordes(turnos: Turno[]): { rol: 'usuario' | 'cordes'; texto: string }[] {
  const salida: { rol: 'usuario' | 'cordes'; texto: string }[] = []

  for (const t of turnos) {
    if (t.rol === 'persona') {
      salida.push({ rol: 'usuario', texto: t.texto })
    } else if (t.rol === 'cordes') {
      const texto = t.respuesta.verificada ? (t.respuesta.mensaje ?? t.respuesta.analisis?.titulo ?? '') : ''
      if (texto) salida.push({ rol: 'cordes', texto: texto.length > TOPE_DE_UNA_VUELTA ? `${texto.slice(0, TOPE_DE_UNA_VUELTA)}…` : texto })
    }
  }

  return salida.slice(-8)
}

/**
 * Le pregunta a Cordes y va avisando qué está mirando.
 *
 * El servidor contesta de a poco —una línea de JSON por paso y la última con la respuesta—, así que
 * la pantalla muestra qué se está consultando mientras se trabaja, en vez de un "pensando…" mudo.
 */
export async function preguntarEnVivo(
  cuerpo: { texto: string; historial: { rol: 'usuario' | 'cordes'; texto: string }[]; contexto?: ContextoDeCordes },
  alPaso: (texto: string) => void,
  senal?: AbortSignal,
): Promise<RespuestaDeCordes> {
  const token = getToken()
  let respuesta: Response

  try {
    respuesta = await fetch(`${String(api.defaults.baseURL).replace(/\/$/, '')}/cordes/preguntar`, {
      method: 'POST',
      signal: senal,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/x-ndjson',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify({ ...cuerpo, stream: true }),
    })
  } catch (e) {
    if ((e as Error).name === 'AbortError') throw e
    throw new ErrorDeCordes('No pude conectarme. Revisá tu conexión y probá de nuevo.')
  }

  if (respuesta.status === 401) {
    setToken(null)
    throw new ErrorDeCordes('Tu sesión venció. Volvé a entrar.', 'sesion')
  }

  if (respuesta.status === 429) {
    throw new ErrorDeCordes('Me preguntaron demasiado seguido. Esperá un minutito y probá de nuevo.', 'limite')
  }

  if (!respuesta.ok) {
    // Con el historial recortado, un 422 solo puede ser una pregunta demasiado larga (el servidor acepta 400 caracteres).
    throw new ErrorDeCordes(
      respuesta.status === 422
        ? cuerpo.texto.length > 400
          ? 'La pregunta es muy larga: acortala un poco y probá de nuevo.'
          : 'No pude entender el pedido. Probá de nuevo, o buscá con los filtros de abajo.'
        : 'No pude pensar ahora. Probá de nuevo, o buscá con los filtros de abajo.',
    )
  }

  let final: RespuestaDeCordes | null = null

  const leer = (linea: string) => {
    if (!linea.trim()) return
    let evento: { tipo?: string; texto?: string; mensaje?: string; respuesta?: RespuestaDeCordes }
    try {
      evento = JSON.parse(linea)
    } catch {
      return
    }
    if (evento.tipo === 'paso' && evento.texto) alPaso(evento.texto)
    if (evento.tipo === 'fin' && evento.respuesta) final = completa(evento.respuesta)
    if (evento.tipo === 'error') throw new ErrorDeCordes(evento.mensaje ?? 'No pude contestar ahora.')
  }

  const lector = respuesta.body?.getReader()

  if (lector) {
    const decodificador = new TextDecoder()
    let resto = ''

    for (;;) {
      const { done, value } = await lector.read()
      if (done) break
      resto += decodificador.decode(value, { stream: true })
      const lineas = resto.split('\n')
      resto = lineas.pop() ?? ''
      lineas.forEach(leer)
    }

    leer(resto)
  } else {
    // Un navegador sin lectura de a poco: llega todo junto, pero llega.
    ;(await respuesta.text()).split('\n').forEach(leer)
  }

  if (!final) throw new ErrorDeCordes('No pude terminar de pensar. Probá de nuevo.')

  return final
}

/** Las cifras y atajos de una búsqueda ya armada (cuando se toca un atajo del propio Cordes). */
export async function analizarPedido(pedido: Pedido): Promise<AnalisisDeCordes> {
  const { data } = await api.post<{ analisis: AnalisisDeCordes }>('/cordes/analizar', { pedido })

  return data.analisis
}

/** Qué se puede preguntar: ejemplos que enseñan hasta dónde llega. (Sin nombres de clientes: el repo es público.) */
export const EJEMPLOS_PARA_CORDES = [
  '¿cuánto pesa una barra de monel de 38,1 x 145 mm?',
  '¿a cuánto cotizamos titanio grado 2 este año?',
  'clientes de Córdoba que no cotizo hace más de un año',
  'cuántos kilos pesa un caño de 2 pulgadas schedule 40 de inoxidable de 6 metros',
  'cotizaciones sin respuesta de este mes',
]

import { useSyncExternalStore } from 'react'
import type { Turno } from '../types/cordes'
import { ErrorDeCordes, historialParaCordes, preguntarEnVivo, type ContextoDeCordes } from './cordes'

/* ---------------------------------------------------------------------------
   La conversación con Cordes, FUERA de la pantalla.

   Por qué afuera: si la conversación viviera en el componente, irse a otra
   pantalla —abrir una ficha, una cotización— la perdería, y una pregunta que
   estaba pensando se abortaría. Con una consulta de la IA eso es plata y
   tiempo tirados. Acá la consulta sigue aunque la pantalla se cierre, la
   respuesta queda guardada y al volver con "atrás" está todo como se dejó:
   la conversación, hasta dónde se había bajado y lo que estaba pensando.

   Se guarda en sessionStorage (dura lo que la solapa) y se borra al cerrar
   sesión: tiene datos de clientes.
--------------------------------------------------------------------------- */

const CLAVE = 'cordes.conversacion.v2'

interface Estado {
  turnos: Turno[]
  trabajando: boolean
  pasos: string[]
  /** El turno que se está "escribiendo" (sólo si la pantalla estaba abierta cuando llegó). */
  escribiendoId: number | null
  /** Hasta dónde estaba bajada la conversación. */
  scroll: number
  /** El último turno que la persona tuvo delante: lo que llegó después se muestra al volver. */
  visto: number
}

function cargar(): Estado {
  try {
    const guardado = JSON.parse(sessionStorage.getItem(CLAVE) ?? 'null') as Partial<Estado> | null
    const turnos = Array.isArray(guardado?.turnos) ? (guardado.turnos as Turno[]).filter((t) => t && typeof t.id === 'number' && typeof t.rol === 'string').slice(-14) : []
    const ultimo = turnos.at(-1)

    // Si se recargó la página en medio de una pregunta, esa consulta se perdió: se avisa y se ofrece repetirla.
    if (ultimo?.rol === 'persona') {
      turnos.push({ id: ultimo.id + 1, rol: 'error', texto: 'La pregunta quedó sin contestar porque se cerró la pantalla.', pregunta: ultimo.texto })
    }

    return { turnos, trabajando: false, pasos: [], escribiendoId: null, scroll: typeof guardado?.scroll === 'number' ? guardado.scroll : 0, visto: typeof guardado?.visto === 'number' ? guardado.visto : 0 }
  } catch {
    return { turnos: [], trabajando: false, pasos: [], escribiendoId: null, scroll: 0, visto: 0 }
  }
}

let estado: Estado = cargar()
let idSiguiente = estado.turnos.reduce((m, t) => Math.max(m, t.id), 0) + 1
let control: AbortController | null = null
let pantallas = 0
const oyentes = new Set<() => void>()

function guardar() {
  try {
    sessionStorage.setItem(CLAVE, JSON.stringify({ turnos: estado.turnos.slice(-14), scroll: estado.scroll, visto: estado.visto }))
  } catch {
    /* sin lugar para guardar: se sigue igual */
  }
}

function poner(cambios: Partial<Estado>, persistir = true) {
  estado = { ...estado, ...cambios }
  if (persistir) guardar()
  oyentes.forEach((o) => o())
}

const suscribir = (o: () => void) => {
  oyentes.add(o)

  return () => oyentes.delete(o)
}

export function useConversacion(): Estado {
  return useSyncExternalStore(suscribir, () => estado)
}

/** La pantalla avisa que está abierta: sólo entonces tiene sentido "escribir" una respuesta que llega. */
export function pantallaAbierta(): () => void {
  pantallas++

  return () => {
    pantallas--
  }
}

export function agregarTurno<T extends Turno extends infer U ? (U extends { id: number } ? Omit<U, 'id'> : never) : never>(t: T): Turno {
  const turno = { ...t, id: idSiguiente++ } as Turno
  poner({ turnos: [...estado.turnos, turno] })

  return turno
}

export function actualizarTurno(id: number, cambios: Partial<Turno>) {
  poner({ turnos: estado.turnos.map((t) => (t.id === id ? ({ ...t, ...cambios } as Turno) : t)) })
}

export function trabajando(si: boolean) {
  poner({ trabajando: si, pasos: si ? estado.pasos : [] }, false)
}

export function escribiendo(id: number | null) {
  poner({ escribiendoId: id }, false)
}

export function guardarScroll(n: number) {
  estado = { ...estado, scroll: n }
  guardar()
}

/** La persona tuvo delante hasta este turno. */
export function marcarVisto(id: number) {
  if (id === estado.visto) return
  estado = { ...estado, visto: id }
  guardar()
}

/** Empezar de nuevo: se corta lo que estaba pensando. */
export function reiniciar() {
  control?.abort()
  control = null
  poner({ turnos: [], trabajando: false, pasos: [], escribiendoId: null, scroll: 0, visto: 0 })
}

/** Al cerrar sesión: nada de esto tiene que quedar. */
export function olvidarConversacion() {
  reiniciar()

  try {
    sessionStorage.removeItem(CLAVE)
  } catch {
    /* nada */
  }
}

/**
 * Le pregunta a Cordes. La consulta NO depende de la pantalla: sigue si se la cierra, y la respuesta queda guardada.
 * Devuelve cuando terminó (de una forma u otra).
 */
export async function preguntar(texto: string, contexto?: ContextoDeCordes): Promise<void> {
  const limpio = texto.trim()
  if (limpio.length < 2 || estado.trabajando) return

  control?.abort()
  const este = new AbortController()
  control = este

  const antes = historialParaCordes(estado.turnos)
  agregarTurno({ rol: 'persona', texto: limpio })
  poner({ trabajando: true, pasos: [] }, false)

  try {
    const respuesta = await preguntarEnVivo(
      { texto: limpio, historial: antes, contexto },
      (p) => poner({ pasos: [...estado.pasos, p] }, false),
      este.signal,
    )
    const turno = agregarTurno({ rol: 'cordes', respuesta, pregunta: limpio, aplicado: false })
    // Si la pantalla se cerró mientras pensaba, la respuesta ya está completa: no se la "escribe" al volver.
    poner({ escribiendoId: pantallas > 0 ? turno.id : null }, false)
  } catch (e) {
    if ((e as Error).name === 'AbortError') return
    agregarTurno({ rol: 'error', texto: e instanceof ErrorDeCordes ? e.message : 'No pude pensar ahora. Probá de nuevo, o buscá con los filtros de abajo.', pregunta: limpio })
  } finally {
    if (control === este) {
      control = null
      poner({ trabajando: false, pasos: [] }, false)
    }
  }
}

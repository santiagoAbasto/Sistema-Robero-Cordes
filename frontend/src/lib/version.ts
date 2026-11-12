import { useSyncExternalStore } from 'react'
import api from './api'

/* ---------------------------------------------------------------------------
   Avisar que hay una versión nueva, sin pisarle lo que está escribiendo.

   El sistema es una sola pantalla: el navegador baja el JavaScript una vez y
   lo sigue ejecutando todo el día. Si se instala una versión a las once, el
   que tenía la pestaña abierta desde las nueve sigue con la vieja hasta que
   cierre o recargue, y no tiene cómo enterarse.

   Eso ya costó dos veces: el 06-11-2026 el botón de borradores seguía
   llevando a la pantalla vieja con la versión nueva instalada, y el 11-11 no
   se sabía si el arreglo del buscador no andaba o si se estaba mirando el JS
   anterior. Se disfraza de "el arreglo no funciona", que es lo caro.

   El index.html ya se sirve con no-cache (ver routes/web.php), así que quien
   recarga o entra de cero recibe lo nuevo. Esto es para el que YA lo tiene
   abierto.

   La regla: recarga sola, pero NUNCA encima de algo a medio escribir. Si hay
   una cotización sin guardar o el cursor está dentro de un casillero, espera
   y vuelve a fijarse. Perder una cotización cargada a mano es mucho peor que
   seguir diez minutos con la versión anterior.
--------------------------------------------------------------------------- */

/** El commit con el que se compiló ESTA pantalla. Lo pone el build. */
export const COMMIT_DE_LA_PANTALLA = String(
  (import.meta.env as Record<string, unknown>).VITE_APP_COMMIT ?? '',
).slice(0, 7)

/** Cada cuánto se le pregunta al servidor qué versión está sirviendo. */
const CADA = 5 * 60 * 1000

/** Lo que se espera antes de recargar, para que no sea de golpe. */
const GRACIA = 8000

interface Estado {
  /** El servidor tiene otra versión que la de esta pantalla. */
  hayVersionNueva: boolean
  /** La persona cerró el aviso: no se recarga sola hasta que vuelva a entrar. */
  pospuesto: boolean
  /** Segundos que faltan para recargar, o null si todavía no corresponde. */
  faltan: number | null
}

let estado: Estado = { hayVersionNueva: false, pospuesto: false, faltan: null }
const oyentes = new Set<() => void>()

function avisar(cambio: Partial<Estado>) {
  estado = { ...estado, ...cambio }
  oyentes.forEach((f) => f())
}

/* ------------------------------------------------- el trabajo sin guardar */

/**
 * Las pantallas que tienen algo a medio cargar se anotan acá.
 *
 * Hoy la única es el editor de cotizaciones, que es donde se pierde trabajo
 * de verdad. Cualquier otra que llegue a tener un formulario largo se suma
 * llamando a `avisarTrabajoSinGuardar` con su propia clave.
 */
const sinGuardar = new Set<string>()

export function avisarTrabajoSinGuardar(clave: string, hay: boolean): void {
  if (hay) sinGuardar.add(clave)
  else sinGuardar.delete(clave)
}

/**
 * Si el cursor está dentro de un casillero, alguien está escribiendo.
 *
 * Cubre las pantallas que no se anotan: una ficha nueva, un contacto, una
 * nota. No hace falta que cada formulario avise.
 */
function estanEscribiendo(): boolean {
  const foco = document.activeElement as HTMLElement | null

  if (!foco) return false

  return (
    ['INPUT', 'TEXTAREA', 'SELECT'].includes(foco.tagName) || foco.isContentEditable === true
  )
}

/** Recargar ahora sería seguro: no hay nada a medio escribir. */
export function sePuedeRecargar(): boolean {
  return sinGuardar.size === 0 && !estanEscribiendo()
}

/* ----------------------------------------------------------- la vigilancia */

let mirando = false
let cuentaRegresiva: ReturnType<typeof setInterval> | null = null

function recargar() {
  // replace y no reload: si la versión nueva cambió las direcciones, se
  // vuelve a pedir la pantalla entera en vez de repetir la misma petición.
  window.location.reload()
}

/**
 * Arranca la cuenta regresiva, o la corta si dejó de ser seguro.
 *
 * Se revisa en cada segundo y no una sola vez: la persona puede ponerse a
 * escribir justo mientras corre, y entonces la recarga se cancela.
 */
function revisarSiRecargar() {
  if (!estado.hayVersionNueva || estado.pospuesto) return

  if (!sePuedeRecargar()) {
    if (cuentaRegresiva) {
      clearInterval(cuentaRegresiva)
      cuentaRegresiva = null
      avisar({ faltan: null })
    }

    return
  }

  if (cuentaRegresiva) return

  avisar({ faltan: Math.round(GRACIA / 1000) })

  cuentaRegresiva = setInterval(() => {
    if (!sePuedeRecargar() || estado.pospuesto) {
      clearInterval(cuentaRegresiva!)
      cuentaRegresiva = null
      avisar({ faltan: null })

      return
    }

    const quedan = (estado.faltan ?? 1) - 1

    if (quedan <= 0) {
      clearInterval(cuentaRegresiva!)
      cuentaRegresiva = null
      recargar()

      return
    }

    avisar({ faltan: quedan })
  }, 1000)
}

async function preguntarAlServidor() {
  // Sin commit sellado en el build no hay con qué comparar: en desarrollo,
  // donde Vite recarga solo, esto no tiene nada que hacer.
  if (!COMMIT_DE_LA_PANTALLA) return

  try {
    const { data } = await api.get<{ commit?: string }>('/version')
    const delServidor = String(data?.commit ?? '').slice(0, 7)

    if (delServidor && delServidor !== 'local' && delServidor !== COMMIT_DE_LA_PANTALLA) {
      avisar({ hayVersionNueva: true })
    }
  } catch {
    // Sin respuesta no se concluye nada: puede ser la red, no una versión.
  }
}

/** Empieza a vigilar. Se llama una sola vez, desde el marco de la app. */
export function vigilarLaVersion(): void {
  if (mirando || !COMMIT_DE_LA_PANTALLA) return

  mirando = true

  preguntarAlServidor()
  setInterval(preguntarAlServidor, CADA)

  // Al volver a la pestaña: es cuando más probable es que haya pasado algo.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') preguntarAlServidor()
  })

  // Y se revisa seguido si ya se puede recargar: el momento llega cuando
  // guardan la cotización o sacan el cursor del casillero.
  setInterval(revisarSiRecargar, 1000)
}

export function posponer(): void {
  if (cuentaRegresiva) {
    clearInterval(cuentaRegresiva)
    cuentaRegresiva = null
  }

  avisar({ pospuesto: true, faltan: null })
}

export function actualizarAhora(): void {
  recargar()
}

/* ------------------------------------------------------------- para React */

export function useVersion(): Estado {
  return useSyncExternalStore(
    (f) => {
      oyentes.add(f)

      return () => oyentes.delete(f)
    },
    () => estado,
    () => estado,
  )
}

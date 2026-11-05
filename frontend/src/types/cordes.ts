import type { Grano, Pedido } from './buscador'

/* ---------------------------------------------------------------------------
   Lo que contesta Cordes IA (POST /api/cordes/preguntar).

   Todo lo que se muestra ya viene validado por el servidor: las tablas son las
   que devolvió una herramienta, los atajos apuntan a cosas que existen y las
   cifras del análisis las calculó el código, no la IA.
--------------------------------------------------------------------------- */

export interface TablaDeCordes {
  titulo: string
  columnas: string[]
  filas: string[][]
  /** Los botones de cada fila (abrir la ficha, la cotización, cotizar, ver sólo estas): uno por cosa que se puede abrir. */
  acciones?: AtajoDeCordes[][]
}

export type AccionDeAtajo =
  | { tipo: 'pedido'; pedido: Pedido }
  | { tipo: 'ruta'; ruta: string }
  | { tipo: 'excel'; pedido: Pedido }
  | { tipo: 'preguntar'; texto: string }
  /** Baja hasta la lista completa del Buscador. */
  | { tipo: 'bajar' }

export type IconoDeAtajo = 'lista' | 'empresa' | 'mas' | 'documento' | 'grafico' | 'excel' | 'pregunta'

export interface AtajoDeCordes {
  etiqueta: string
  icono: IconoDeAtajo
  accion: AccionDeAtajo
}

export interface OpcionesDeCordes {
  pregunta: string
  opciones: { etiqueta: string; enviar: string }[]
}

export interface AnalisisDeCordes {
  vista: Grano
  total: number
  titulo: string
  nivel: 'vacio' | 'pocos' | 'normal' | 'muchos'
  cifras: { etiqueta: string; valor: string }[]
  /** Por dónde se reparte; cada ítem afina la búsqueda con un clic. */
  destacados: { titulo: string; items: { etiqueta: string; cantidad: number; porcentaje: number; pedido: Pedido }[] }[]
  /** Lo mismo, visto desde otra vista, con su cuenta. */
  vistas: { vista: Grano; titulo: string; cantidad: number; pedido: Pedido }[]
  /** Cuando no hay nada: qué filtro sobra. */
  relajar: { etiqueta: string; cantidad: number; pedido: Pedido }[]
  /** false: sólo lo básico (cuántas hay y cuánto suman); el reparto y las vistas se piden aparte y llegan después. */
  completo?: boolean
}

export interface RespuestaDeCordes {
  /** "codigo": lo entendió y contestó el sistema; "ia": razonó la inteligencia artificial; "reglas": una lista rápida. */
  fuente: 'ia' | 'reglas' | 'codigo'
  mensaje: string | null
  /** false si no se pudieron comprobar las cifras del texto: entonces no se muestra su texto. */
  verificada: boolean
  aviso: string | null
  /** La búsqueda que corresponde mostrar en el Buscador (si la hay). */
  pedido: Pedido | null
  /** Lo que hay que mandar de vuelta para que "y de Córdoba" entienda de qué se habla. */
  borrador: { vista: Grano | null; filtros: Record<string, string | number | boolean> } | null
  tablas: TablaDeCordes[]
  atajos: AtajoDeCordes[]
  opciones: OpcionesDeCordes | null
  seguir: string[]
  no_entendi: string[]
  /** Qué miró para contestar. */
  pasos: string[]
  analisis: AnalisisDeCordes | null
}

/** Una vuelta de la conversación. */
export type Turno =
  | { id: number; rol: 'persona'; texto: string }
  | { id: number; rol: 'cordes'; respuesta: RespuestaDeCordes; pregunta: string; /** ya se mostró su búsqueda en el Buscador */ aplicado?: boolean }
  | { id: number; rol: 'error'; texto: string; pregunta: string }

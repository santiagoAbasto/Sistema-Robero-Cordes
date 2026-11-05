import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { AnimatePresence, motion, useReducedMotion } from 'motion/react'
import { ArrowUp, RotateCcw, Sparkles } from 'lucide-react'
import { Card, Chip, cx } from '../../components/ui'
import CordesBot, { type EstadoDelRobot } from '../../components/CordesBot'
import { bajarExcel, claveDelPedido, loQueEntendio } from '../../lib/buscador'
import { analizarPedido, EJEMPLOS_PARA_CORDES, type ContextoDeCordes } from '../../lib/cordes'
import { actualizarTurno, agregarTurno, escribiendo, guardarScroll, marcarVisto, pantallaAbierta, preguntar as preguntarAlAsistente, reiniciar, trabajando as ponerTrabajando, useConversacion } from '../../lib/cordesStore'
import type { Pedido } from '../../types/buscador'
import type { Catalogos } from '../../types/indice'
import type { AtajoDeCordes, RespuestaDeCordes, Turno } from '../../types/cordes'
import { Atajo, BarraDe, Cifra, ComoLoSupo, Numero, Pensando, Sugerencia, TablaViva, TextoVivo } from './cordes/piezas'
import './CordesIA.css'

/* ---------------------------------------------------------------------------
   Cordes IA: un asistente que conoce el sistema.

   Se le pregunta con las palabras de cada uno —un precio, un peso, una
   empresa, una lista— y contesta con datos del sistema, nunca de memoria.
   El propio sistema entiende y contesta casi todo (y por eso es instantáneo
   y no cuesta); la inteligencia artificial interviene sólo en lo que el
   código no logra interpretar, y cada cifra suya se comprueba.

   Es una conversación que sobrevive a navegar: los resultados traen botones
   para abrir la ficha o la cotización, y al volver con "atrás" todo sigue
   donde estaba, sin repetir ninguna consulta (ver lib/cordesStore).
--------------------------------------------------------------------------- */

/** Lo que la persona está viendo en el Buscador, con los nombres que entiende el asistente. */
function contextoDelPedido(pedido: Pedido): ContextoDeCordes | undefined {
  const { grano: vista, q, por_vencer: porVencer, pagina: _pagina, orden: _orden, por_pagina: _porPagina, ...resto } = pedido
  const filtros: ContextoDeCordes['filtros'] = { ...resto }
  if (q) filtros.texto = q
  if (porVencer) filtros.por_vencer_dias = Number(porVencer)

  return Object.keys(filtros).length > 0 ? { vista: vista || 'empresas', filtros } : undefined
}

export default function CordesIA({
  aplicar,
  pedidoActual,
  catalogos,
}: {
  /** Pone la búsqueda que armó Cordes en el Buscador. */
  aplicar: (pedido: Pedido) => void
  /** Lo que se está viendo ahora: es el contexto de lo que se pregunte. */
  pedidoActual: Pedido
  catalogos: Catalogos | null
}) {
  const navegar = useNavigate()
  const reducir = useReducedMotion()
  const { turnos, trabajando, pasos, escribiendoId, scroll, visto } = useConversacion()
  const [pregunta, setPregunta] = useState('')
  const hilo = useRef<HTMLDivElement>(null)
  const campo = useRef<HTMLInputElement>(null)
  const largoAnterior = useRef(turnos.length)

  const claveActual = claveDelPedido(pedidoActual)
  const iaConectada = catalogos?.ia_activa ?? false
  const ultimo = turnos.at(-1)

  // Mientras la pantalla está abierta, una respuesta que llega se "escribe"; si no, ya está completa al volver.
  useEffect(() => pantallaAbierta(), [])

  // Al volver (con «atrás», por ejemplo), la conversación está donde se la dejó; si mientras tanto llegó una respuesta, se baja hasta ella.
  useLayoutEffect(() => {
    if (!hilo.current) return
    const llegoAlgo = (ultimo?.id ?? 0) > visto
    hilo.current.scrollTop = llegoAlgo ? hilo.current.scrollHeight : scroll
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Lo que se ve en pantalla queda como "visto".
  useEffect(() => {
    if (ultimo) marcarVisto(ultimo.id)
  }, [ultimo])

  // Una respuesta que llegó con la pantalla cerrada muestra su búsqueda al volver (una sola vez).
  useEffect(() => {
    if (ultimo?.rol === 'cordes' && ultimo.respuesta.pedido && !ultimo.aplicado) {
      actualizarTurno(ultimo.id, { aplicado: true })
      aplicar(ultimo.respuesta.pedido)
    }
  }, [ultimo, aplicar])

  // Siempre se ve lo último (pero no al abrir la pantalla: ahí manda donde se la había dejado).
  useEffect(() => {
    if (turnos.length > largoAnterior.current || trabajando) {
      hilo.current?.scrollTo({ top: hilo.current.scrollHeight, behavior: reducir ? 'auto' : 'smooth' })
    }

    largoAnterior.current = turnos.length
  }, [turnos.length, trabajando, pasos.length, reducir])

  const preguntar = useCallback(
    async (texto: string) => {
      setPregunta('')
      await preguntarAlAsistente(texto, contextoDelPedido(pedidoActual))
      campo.current?.focus()
    },
    [pedidoActual],
  )

  /** Un atajo es una acción con un clic: ver, abrir, bajar, o seguir preguntando. */
  const elegir = useCallback(
    async (a: AtajoDeCordes) => {
      const { accion } = a

      if (accion.tipo === 'preguntar') return preguntar(accion.texto)

      if (accion.tipo === 'ruta') return navegar(accion.ruta)

      if (accion.tipo === 'bajar') {
        return document.getElementById('resultados-del-buscador')?.scrollIntoView({ behavior: reducir ? 'auto' : 'smooth', block: 'start' })
      }

      if (accion.tipo === 'excel') {
        try {
          await bajarExcel(accion.pedido, catalogos?.materiales)
        } catch (e) {
          agregarTurno({ rol: 'error', texto: e instanceof Error ? e.message : 'No pude preparar el Excel.', pregunta: a.etiqueta })
        }

        return
      }

      // Cambiar la búsqueda: se muestra en el Buscador y Cordes comenta lo que hay.
      agregarTurno({ rol: 'persona', texto: a.etiqueta })
      aplicar(accion.pedido)
      ponerTrabajando(true)

      try {
        const analisis = await analizarPedido(accion.pedido)
        const respuesta: RespuestaDeCordes = {
          fuente: 'reglas', mensaje: null, verificada: true, aviso: null, pedido: accion.pedido, borrador: null,
          tablas: [], atajos: [], opciones: null, seguir: [], no_entendi: [], pasos: [], analisis,
        }
        const turno = agregarTurno({ rol: 'cordes', respuesta, pregunta: a.etiqueta, aplicado: true })
        escribiendo(turno.id)
      } catch {
        agregarTurno({ rol: 'error', texto: 'La búsqueda quedó armada abajo, pero no pude comentarla.', pregunta: a.etiqueta })
      } finally {
        ponerTrabajando(false)
      }
    },
    [aplicar, catalogos?.materiales, navegar, preguntar, reducir],
  )

  function alEnviar(e: FormEvent) {
    e.preventDefault()
    void preguntar(pregunta)
  }

  function alTeclear(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Escape') setPregunta('')
    // Flecha arriba con el campo vacío: vuelve la última pregunta, para repetirla o corregirla.
    if (e.key === 'ArrowUp' && pregunta === '') {
      const anterior = [...turnos].reverse().find((t) => t.rol === 'persona')
      if (anterior?.rol === 'persona') {
        e.preventDefault()
        setPregunta(anterior.texto)
      }
    }
  }

  function empezarDeNuevo() {
    reiniciar()
    campo.current?.focus()
  }

  /* ------------------------------------------------------------ el robot */

  const vigente = ultimo?.rol === 'cordes' && ultimo.respuesta.pedido !== null && claveDelPedido(ultimo.respuesta.pedido) === claveActual
  const estado: EstadoDelRobot = useMemo(() => {
    if (trabajando) return 'pensando'
    if (!ultimo || ultimo.rol === 'persona') return 'reposo'
    if (ultimo.rol === 'error') return 'confundido'
    const r = ultimo.respuesta
    if (r.opciones) return 'preguntando'
    if (!r.verificada || (r.analisis?.total === 0 && !r.mensaje)) return 'confundido'

    return 'contento'
  }, [trabajando, ultimo])

  const vacio = turnos.length === 0

  return (
    <Card className="overflow-hidden border-[#e4dcf7] bg-gradient-to-br from-[#f8f5ff] via-white to-white">
      <div className="flex flex-col gap-4 p-[22px] sm:flex-row sm:gap-6">
        <div className="mx-auto shrink-0 sm:sticky sm:top-4 sm:mx-0 sm:self-start">
          <CordesBot estado={estado} tamano={vacio ? 132 : 112} />
        </div>

        <div className="flex min-w-0 flex-1 flex-col gap-3.5">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h2 className="whitespace-nowrap text-[16px] font-semibold tracking-[-0.01em] text-ink">Cordes IA</h2>
            <span
              className="inline-flex items-center gap-1.5 rounded-full bg-ia px-[8px] py-[3px] text-[9.5px] font-bold leading-none tracking-[0.38px] text-white"
              title={iaConectada ? 'Contesta él mismo casi todo; con la inteligencia artificial entiende además cualquier frase y razona preguntas complejas' : 'Contesta con los datos del sistema, sin inteligencia artificial'}
            >
              {iaConectada && <span className="h-1.5 w-1.5 rounded-full bg-[#86efac] shadow-[0_0_0_3px_rgba(134,239,172,0.25)]" aria-hidden />}
              {iaConectada ? 'IA' : 'SISTEMA'}
            </span>
            {!vacio && (
              <button
                type="button"
                onClick={empezarDeNuevo}
                className="ml-auto inline-flex items-center gap-1.5 whitespace-nowrap rounded-md px-2 py-1 text-[11.5px] font-medium text-slate-500 outline-none transition-colors hover:bg-white hover:text-ink focus-visible:ring-2 focus-visible:ring-ia/40"
              >
                <RotateCcw size={13} strokeWidth={2.2} /> Empezar de nuevo
              </button>
            )}
          </div>

          {/* La conversación */}
          <div
            ref={hilo}
            onScroll={(e) => guardarScroll(e.currentTarget.scrollTop)}
            className={cx('flex flex-col gap-3 overflow-y-auto overscroll-contain pr-1 [scrollbar-width:thin]', vacio ? '' : 'max-h-[560px] min-h-[96px]')}
            aria-live="polite"
            aria-label="Conversación con Cordes"
          >
            {vacio && !trabajando && <Saludo iaConectada={iaConectada} />}

            {turnos.map((t, i) => (
              <Vuelta
                key={t.id}
                turno={t}
                esUltimo={i === turnos.length - 1}
                escribir={t.id === escribiendoId}
                alTerminarDeEscribir={() => escribiendo(null)}
                vigente={vigente && i === turnos.length - 1}
                catalogos={catalogos}
                ocupado={trabajando}
                alElegir={elegir}
                alPreguntar={(texto) => void preguntar(texto)}
                alVolverAMostrar={(p) => aplicar(p)}
              />
            ))}

            <AnimatePresence>
              {trabajando && (
                <motion.div
                  key="pensando"
                  initial={reducir ? false : { opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, y: -4 }}
                  transition={{ duration: 0.2 }}
                  className="max-w-[560px] rounded-2xl rounded-tl-md border border-[#e9e2f8] bg-white px-4 py-3 shadow-[0_1px_2px_rgba(76,29,149,0.06),0_8px_24px_-12px_rgba(76,29,149,0.18)]"
                >
                  <Pensando pasos={pasos} />
                </motion.div>
              )}
            </AnimatePresence>
          </div>

          {/* El campo */}
          <form onSubmit={alEnviar} className="flex flex-col gap-2.5">
            <label htmlFor="pregunta-a-cordes" className="sr-only">
              Preguntale a Cordes
            </label>
            <div className={cx('rounded-[13px] p-[1.5px] transition-shadow', trabajando ? 'cordes-borde-vivo' : 'bg-[#d9ccf5] focus-within:bg-ia focus-within:shadow-[0_0_0_4px_#ede6fc]')}>
              <div className="relative flex items-center rounded-xl bg-white">
                <Sparkles size={17} strokeWidth={2} className="pointer-events-none absolute left-3.5 text-ia" />
                <input
                  id="pregunta-a-cordes"
                  ref={campo}
                  value={pregunta}
                  onChange={(e) => setPregunta(e.target.value)}
                  onKeyDown={alTeclear}
                  placeholder={vacio ? 'Preguntale a Cordes: ¿cuánto pesa una barra de monel de 38,1 x 145 mm?' : 'Seguí preguntando: «y del año pasado», «ahora en dólares», «contame de la primera»…'}
                  maxLength={400}
                  // Con una conversación en curso no se roba el foco: al volver con «atrás» la pantalla no tiene que saltar.
                  autoFocus={vacio}
                  autoComplete="off"
                  className="h-12 w-full rounded-xl bg-transparent pl-11 pr-14 text-[14.5px] text-ink outline-none placeholder:text-faint"
                />
                <button
                  type="submit"
                  disabled={trabajando || pregunta.trim().length < 2}
                  aria-label="Preguntar"
                  className="absolute right-2 grid h-8 w-8 place-items-center rounded-lg bg-ia text-white shadow-[0_2px_6px_rgba(124,58,237,0.35)] outline-none transition-all hover:bg-[#6d28d9] focus-visible:ring-2 focus-visible:ring-ia/50 disabled:scale-95 disabled:opacity-40 disabled:shadow-none"
                >
                  <ArrowUp size={17} strokeWidth={2.6} />
                </button>
              </div>
            </div>

            {vacio && (
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-[11px] font-medium text-slate-500">Probá con:</span>
                {EJEMPLOS_PARA_CORDES.map((ejemplo, i) => (
                  <Sugerencia key={ejemplo} texto={ejemplo} demora={0.05 * i} deshabilitado={trabajando} alElegir={(t) => void preguntar(t)} />
                ))}
              </div>
            )}
          </form>
        </div>
      </div>
    </Card>
  )
}

/* ---------------------------------------------------------------- saludo */

function Saludo({ iaConectada }: { iaConectada: boolean }) {
  return (
    <div className="max-w-[600px] rounded-2xl rounded-tl-md border border-[#e9e2f8] bg-white px-4 py-3 shadow-[0_1px_2px_rgba(76,29,149,0.06)]">
      <p className="text-[14px] font-medium text-ink">¡Hola! Soy Cordes. Preguntame lo que quieras del sistema.</p>
      <p className="mt-1 text-[12.5px] leading-relaxed text-muted">
        Pesos de barras, chapas y caños, precios, tablas de schedule, fórmulas, materiales, empresas y cotizaciones. Todo sale de los datos del sistema, nunca de memoria
        {iaConectada ? ', y si la pregunta es rara o compleja, la inteligencia artificial me ayuda a entenderla y cada cifra suya se comprueba.' : '.'}
      </p>
    </div>
  )
}

/* ---------------------------------------------------------------- una vuelta */

function Vuelta({
  turno,
  esUltimo,
  escribir,
  alTerminarDeEscribir,
  vigente,
  catalogos,
  ocupado,
  alElegir,
  alPreguntar,
  alVolverAMostrar,
}: {
  turno: Turno
  esUltimo: boolean
  escribir: boolean
  alTerminarDeEscribir: () => void
  vigente: boolean
  catalogos: Catalogos | null
  ocupado: boolean
  alElegir: (a: AtajoDeCordes) => void
  alPreguntar: (texto: string) => void
  alVolverAMostrar: (p: Pedido) => void
}) {
  const reducir = useReducedMotion()
  const entrada = reducir ? false : { opacity: 0, y: 10, scale: 0.985 }

  if (turno.rol === 'persona') {
    return (
      <motion.div initial={entrada} animate={{ opacity: 1, y: 0, scale: 1 }} transition={{ type: 'spring', stiffness: 420, damping: 30 }} className="flex justify-end">
        <p className="max-w-[80%] rounded-2xl rounded-br-md bg-gradient-to-br from-[#7c3aed] to-[#6d28d9] px-4 py-2.5 text-[14px] leading-snug text-white shadow-[0_4px_14px_rgba(124,58,237,0.28)]">
          {turno.texto}
        </p>
      </motion.div>
    )
  }

  if (turno.rol === 'error') {
    return (
      <motion.div initial={entrada} animate={{ opacity: 1, y: 0, scale: 1 }} className="max-w-[600px] rounded-2xl rounded-tl-md border border-[#f3d9d0] bg-[#fffaf8] px-4 py-3">
        <p className="text-[13.5px] text-[#8a3b22]">{turno.texto}</p>
        {esUltimo && turno.pregunta.length > 1 && (
          <button
            type="button"
            disabled={ocupado}
            onClick={() => alPreguntar(turno.pregunta)}
            className="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-[#e8c9bd] bg-white px-3 py-1.5 text-[12px] font-semibold text-[#8a3b22] outline-none transition-colors hover:bg-[#fff3ee] focus-visible:ring-2 focus-visible:ring-[#d97757]/40 disabled:opacity-50"
          >
            <RotateCcw size={13} strokeWidth={2.2} /> Probar de nuevo
          </button>
        )}
      </motion.div>
    )
  }

  return (
    <motion.div
      initial={entrada}
      animate={{ opacity: 1, y: 0, scale: 1 }}
      transition={{ type: 'spring', stiffness: 380, damping: 32 }}
      className={cx(
        'flex max-w-[760px] flex-col gap-3 rounded-2xl rounded-tl-md border border-[#e9e2f8] bg-white px-4 py-3.5',
        'shadow-[0_1px_2px_rgba(76,29,149,0.06),0_8px_24px_-12px_rgba(76,29,149,0.18)]',
        escribir && 'cordes-destello',
      )}
    >
      <RespuestaVista
        r={turno.respuesta}
        pregunta={turno.pregunta}
        esUltimo={esUltimo}
        escribir={escribir}
        alTerminarDeEscribir={alTerminarDeEscribir}
        vigente={vigente}
        catalogos={catalogos}
        ocupado={ocupado}
        alElegir={alElegir}
        alPreguntar={alPreguntar}
        alVolverAMostrar={alVolverAMostrar}
      />
    </motion.div>
  )
}

function RespuestaVista({
  r,
  pregunta,
  esUltimo,
  escribir,
  alTerminarDeEscribir,
  vigente,
  catalogos,
  ocupado,
  alElegir,
  alPreguntar,
  alVolverAMostrar,
}: {
  r: RespuestaDeCordes
  pregunta: string
  esUltimo: boolean
  escribir: boolean
  alTerminarDeEscribir: () => void
  vigente: boolean
  catalogos: Catalogos | null
  ocupado: boolean
  alElegir: (a: AtajoDeCordes) => void
  alPreguntar: (texto: string) => void
  alVolverAMostrar: (p: Pedido) => void
}) {
  const a = r.analisis
  const entendio = vigente && r.pedido ? loQueEntendio(r.pedido, catalogos) : []

  // Los atajos: los que propuso Cordes, y los que salen de los números (cambiar de vista, qué filtro sobra, Excel, bajar a la lista).
  const atajos = useMemo<AtajoDeCordes[]>(() => {
    const deNumeros: AtajoDeCordes[] = [
      ...(a?.relajar ?? []).map((x) => ({ etiqueta: x.etiqueta, icono: 'lista' as const, accion: { tipo: 'pedido' as const, pedido: x.pedido } })),
      ...(a?.vistas ?? []).map((x) => ({ etiqueta: x.titulo, icono: 'lista' as const, accion: { tipo: 'pedido' as const, pedido: x.pedido } })),
    ]
    const hayExcel = r.atajos.some((x) => x.accion.tipo === 'excel')
    const excel: AtajoDeCordes[] =
      a && a.total > 0 && a.total <= 10000 && r.pedido && !hayExcel
        ? [{ etiqueta: 'Bajar a Excel', icono: 'excel', accion: { tipo: 'excel', pedido: r.pedido } }]
        : []
    const lista: AtajoDeCordes[] = a && a.total > 0 && vigente ? [{ etiqueta: `Ver la lista completa (${a.total.toLocaleString('es-AR')})`, icono: 'lista', accion: { tipo: 'bajar' } }] : []

    return [...r.atajos, ...lista, ...deNumeros, ...excel].slice(0, 9)
  }, [a, r.atajos, r.pedido, vigente])

  const hayTexto = Boolean(r.mensaje)
  // Si la búsqueda de esta respuesta ya no es la que se ve (se cambiaron los filtros), se puede volver a mostrar con un clic.
  const sePuedeVolverAMostrar = esUltimo && r.pedido !== null && !vigente

  return (
    <>
      {r.aviso && <p className="text-[11.5px] leading-snug text-muted">{r.aviso}</p>}

      {hayTexto ? (
        <TextoVivo texto={r.mensaje ?? ''} escribir={escribir} alTerminar={alTerminarDeEscribir} />
      ) : a ? (
        <p className="text-[15px] font-semibold text-ink" aria-live="polite">
          {a.total === 0 ? (
            a.titulo
          ) : (
            <>
              Encontré <Numero valor={a.total} className="text-[22px] text-ia" /> {a.titulo.replace(/^Encontré [\d.]+ /, '')}
            </>
          )}
        </p>
      ) : null}

      {/* Lo que entendió, para poder corregirlo */}
      {entendio.length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="text-[11px] font-medium text-slate-500">Busqué:</span>
          {entendio.map((e, i) => (
            <motion.span key={e} initial={{ opacity: 0, scale: 0.9 }} animate={{ opacity: 1, scale: 1 }} transition={{ delay: 0.04 * i, type: 'spring', stiffness: 500, damping: 26 }}>
              <Chip tono="violeta">{e}</Chip>
            </motion.span>
          ))}
        </div>
      )}

      {r.no_entendi.length > 0 && (
        <p className="text-[12.5px] text-warning-ink">
          No pude entender: {r.no_entendi.map((x) => `«${x}»`).join(', ')}. Si hace falta, afinalo con los filtros.
        </p>
      )}

      {r.tablas.map((t, i) => (
        <TablaViva key={`${t.titulo}-${i}`} tabla={t} alElegir={alElegir} ocupado={ocupado} />
      ))}

      {/* Las cifras y por dónde se reparte: las calcula el código sobre lo que se ve en la lista */}
      {a && a.total > 0 && (a.cifras.length > 0 || a.destacados.length > 0) && (
        <div className="flex flex-col gap-3">
          {a.cifras.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {a.cifras.map((c) => (
                <Cifra key={c.etiqueta} etiqueta={c.etiqueta} valor={c.valor} />
              ))}
            </div>
          )}
          {a.destacados.length > 0 && (
            <div className="grid gap-3 sm:grid-cols-2">
              {a.destacados.map((d, di) => (
                <div key={d.titulo} className="rounded-xl border border-[#efeaf9] bg-[#fcfbff] p-3">
                  <p className="mb-2 text-[11px] font-semibold uppercase tracking-[0.05em] text-slate-500">{d.titulo}</p>
                  <ul className="flex flex-col gap-1.5">
                    {d.items.map((it, ii) => (
                      <li key={it.etiqueta}>
                        <button
                          type="button"
                          disabled={ocupado}
                          onClick={() => alElegir({ etiqueta: `Sólo ${it.etiqueta}`, icono: 'lista', accion: { tipo: 'pedido', pedido: it.pedido } })}
                          title={`Quedarme sólo con: ${it.etiqueta}`}
                          className="group flex w-full flex-col gap-1 rounded-md px-1.5 py-1 text-left outline-none transition-colors hover:bg-[#f3eefe] focus-visible:ring-2 focus-visible:ring-ia/40 disabled:opacity-60"
                        >
                          <span className="flex items-baseline justify-between gap-2 text-[12.5px]">
                            <span className="truncate font-medium text-ink group-hover:text-[#4c2a9c]">{it.etiqueta}</span>
                            <span className="shrink-0 tabular-nums text-slate-500">
                              {it.cantidad.toLocaleString('es-AR')} · {it.porcentaje}%
                            </span>
                          </span>
                          <BarraDe porcentaje={it.porcentaje} demora={0.1 + 0.05 * (di * 3 + ii)} />
                        </button>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Opciones: cuando no es una sola cosa, que elija con un clic */}
      {r.opciones && esUltimo && (
        <div className="rounded-xl border border-[#e0d4f7] bg-[#f8f5ff] p-3">
          <p className="mb-2 text-[13px] font-semibold text-[#4c2a9c]">{r.opciones.pregunta}</p>
          <div className="flex flex-wrap gap-2">
            {r.opciones.opciones.map((o, i) => (
              <motion.button
                key={o.etiqueta}
                type="button"
                disabled={ocupado}
                onClick={() => alPreguntar(o.enviar)}
                initial={{ opacity: 0, y: 6 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.05 * i }}
                whileTap={{ scale: 0.97 }}
                className="rounded-lg border border-[#d6c8f3] bg-white px-3.5 py-2 text-left text-[13px] font-semibold text-[#3b1f86] shadow-[0_1px_2px_rgba(76,29,149,0.08)] outline-none transition-colors hover:border-ia hover:bg-[#f3eefe] focus-visible:ring-2 focus-visible:ring-ia/40 disabled:opacity-50"
              >
                {o.etiqueta}
              </motion.button>
            ))}
          </div>
        </div>
      )}

      {esUltimo && !r.verificada && (
        <button
          type="button"
          disabled={ocupado}
          onClick={() => alPreguntar(pregunta)}
          className="inline-flex w-fit items-center gap-1.5 rounded-lg border border-[#d6c8f3] bg-white px-3 py-1.5 text-[12px] font-semibold text-[#4c2a9c] outline-none transition-colors hover:bg-[#f3eefe] focus-visible:ring-2 focus-visible:ring-ia/40 disabled:opacity-50"
        >
          <RotateCcw size={13} strokeWidth={2.2} /> Preguntarlo de nuevo
        </button>
      )}

      {esUltimo && (atajos.length > 0 || sePuedeVolverAMostrar) && (
        <div className="flex flex-wrap gap-2">
          {sePuedeVolverAMostrar && r.pedido && (
            <Atajo
              atajo={{ etiqueta: 'Volver a mostrar esta búsqueda', icono: 'lista', accion: { tipo: 'pedido', pedido: r.pedido } }}
              alElegir={() => r.pedido && alVolverAMostrar(r.pedido)}
              deshabilitado={ocupado}
            />
          )}
          {atajos.map((x, i) => (
            <Atajo key={`${x.etiqueta}-${i}`} atajo={x} alElegir={alElegir} demora={0.04 * i} deshabilitado={ocupado} />
          ))}
        </div>
      )}

      {esUltimo && r.seguir.length > 0 && (
        <div className="flex flex-col gap-1.5">
          <span className="text-[11px] font-medium text-slate-500">Podés seguir con:</span>
          <div className="flex flex-wrap gap-2">
            {r.seguir.map((s, i) => (
              <Sugerencia key={s} texto={s} demora={0.05 * i} deshabilitado={ocupado} alElegir={alPreguntar} />
            ))}
          </div>
        </div>
      )}

      {r.fuente !== 'reglas' && <ComoLoSupo pasos={r.pasos} verificada={r.verificada} fuente={r.fuente} />}
    </>
  )
}

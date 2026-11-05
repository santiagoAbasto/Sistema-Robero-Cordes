import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { animate, AnimatePresence, motion, useReducedMotion } from 'motion/react'
import {
  BarChart3,
  Building2,
  Check,
  ChevronDown,
  Copy,
  CornerDownRight,
  FileSpreadsheet,
  FileText,
  List,
  Plus,
} from 'lucide-react'
import { cx } from '../../../components/ui'
import type { AtajoDeCordes, IconoDeAtajo, TablaDeCordes } from '../../../types/cordes'
import '../CordesIA.css'

/* ---------------------------------------------------------------------------
   Las piezas con las que Cordes arma su respuesta. Cada una cuida lo mismo:
   que se lea fácil, que se mueva con sentido (y nada más) y que quien tenga
   "reducir movimiento" activado vea todo quieto y completo.
--------------------------------------------------------------------------- */

/* ------------------------------------------------------------- texto vivo */

/** *cursiva* (una sola estrella, para las aclaraciones: «Entendí: …», «Ojo: …»). */
function conCursivas(texto: string, clave: string): ReactNode[] {
  return texto.split(/\*([^*\n]+)\*/).map((parte, i) =>
    i % 2 === 1 ? (
      <em key={`${clave}-c${i}`} className="text-[12.5px] italic text-muted">
        {parte}
      </em>
    ) : (
      <span key={`${clave}-c${i}`}>{parte}</span>
    ),
  )
}

/** **negrita** (aunque quede sin cerrar mientras se escribe), *cursiva* y nada más: el texto nunca es HTML. */
function enLinea(texto: string, clave: string): ReactNode[] {
  return texto.split('**').map((parte, i) =>
    i % 2 === 1 ? (
      <strong key={`${clave}-${i}`} className="font-semibold text-ink">
        {parte}
      </strong>
    ) : (
      <span key={`${clave}-${i}`}>{conCursivas(parte, `${clave}-${i}`)}</span>
    ),
  )
}

/** Párrafos, listas con «- » o «1. » y títulos con «#», como los escribe Cordes. */
function formatear(texto: string): ReactNode {
  const bloques: ReactNode[] = []
  let lista: { ordenada: boolean; items: string[] } | null = null

  const cerrarLista = () => {
    if (!lista) return
    const { ordenada, items } = lista
    const Etiqueta = ordenada ? 'ol' : 'ul'
    bloques.push(
      <Etiqueta
        key={`l${bloques.length}`}
        className={cx('my-1.5 flex flex-col gap-1 pl-5 text-[13.5px] leading-[1.55] text-[#3f3a52]', ordenada ? 'list-decimal' : 'list-disc marker:text-ia')}
      >
        {items.map((it, i) => (
          <li key={i}>{enLinea(it, `li${bloques.length}-${i}`)}</li>
        ))}
      </Etiqueta>,
    )
    lista = null
  }

  texto.split('\n').forEach((crudo, n) => {
    const linea = crudo.trim()
    const viñeta = /^[-•*]\s+(.*)$/.exec(linea)
    const numero = /^\d+[.)]\s+(.*)$/.exec(linea)

    if (viñeta || numero) {
      const ordenada = Boolean(numero)
      if (lista && lista.ordenada !== ordenada) cerrarLista()
      lista ??= { ordenada, items: [] }
      lista.items.push((viñeta ?? numero)![1])

      return
    }

    cerrarLista()

    if (linea === '') return

    const titulo = /^#{1,4}\s+(.*)$/.exec(linea)
    bloques.push(
      titulo ? (
        <p key={`t${n}`} className="mt-2 text-[13px] font-semibold text-ink first:mt-0">
          {enLinea(titulo[1], `t${n}`)}
        </p>
      ) : (
        <p key={`p${n}`} className="text-[14px] leading-[1.6] text-[#2e2a40]">
          {enLinea(linea, `p${n}`)}
        </p>
      ),
    )
  })

  cerrarLista()

  return <div className="flex flex-col gap-1.5">{bloques}</div>
}

/**
 * El mensaje de Cordes. Si es el último, se va escribiendo (rápido: un párrafo en medio segundo) y un
 * clic lo muestra entero. Con "reducir movimiento", aparece completo.
 */
export function TextoVivo({ texto, escribir, alTerminar }: { texto: string; escribir: boolean; alTerminar?: () => void }) {
  const reducir = useReducedMotion()
  const animado = escribir && !reducir
  const [n, setN] = useState(animado ? 0 : texto.length)
  const terminar = useRef(alTerminar)
  terminar.current = alTerminar

  useEffect(() => {
    if (!animado) {
      setN(texto.length)

      return
    }

    let cuadro = 0
    let hecho = 0
    let ultimo = performance.now()

    const paso = (t: number) => {
      hecho = Math.min(texto.length, hecho + (t - ultimo) * 0.55)
      ultimo = t
      setN(Math.floor(hecho))

      if (hecho < texto.length) cuadro = requestAnimationFrame(paso)
      else terminar.current?.()
    }

    cuadro = requestAnimationFrame(paso)

    return () => cancelAnimationFrame(cuadro)
  }, [texto, animado])

  const escribiendo = n < texto.length

  return (
    <div onClick={() => escribiendo && setN(texto.length)} className={escribiendo ? 'cursor-pointer' : undefined}>
      {formatear(texto.slice(0, n))}
      {escribiendo && <span className="cordes-cursor" aria-hidden />}
    </div>
  )
}

/* ------------------------------------------------------------------ cifras */

/** Un número entero que cuenta hasta su valor. */
export function Numero({ valor, className }: { valor: number; className?: string }) {
  const reducir = useReducedMotion()
  const ref = useRef<HTMLSpanElement>(null)

  useEffect(() => {
    const el = ref.current
    if (!el || reducir) return

    const control = animate(0, valor, {
      duration: Math.min(1.1, 0.35 + Math.log10(valor + 1) * 0.22),
      ease: [0.16, 1, 0.3, 1],
      onUpdate: (v) => {
        el.textContent = Math.round(v).toLocaleString('es-AR')
      },
    })

    return () => control.stop()
  }, [valor, reducir])

  return (
    <span ref={ref} className={cx('tabular-nums', className)}>
      {valor.toLocaleString('es-AR')}
    </span>
  )
}

/** Una cifra con su rótulo: los enteros cuentan; los importes ya vienen formateados. */
export function Cifra({ etiqueta, valor }: { etiqueta: string; valor: string }) {
  const entero = /^\d+$/.test(valor)

  return (
    <div className="flex min-w-[96px] flex-col rounded-xl border border-[#ece6fa] bg-white/80 px-3 py-2 shadow-[0_1px_0_rgba(76,29,149,0.04)]">
      <span className="text-[10.5px] font-medium uppercase tracking-[0.04em] text-slate-500">{etiqueta}</span>
      <span className="mt-0.5 text-[17px] font-semibold leading-tight text-ink">
        {entero ? <Numero valor={Number(valor)} /> : <span className="tabular-nums">{valor}</span>}
      </span>
    </div>
  )
}

/** Una barra que crece desde cero hasta su porcentaje. */
export function BarraDe({ porcentaje, demora = 0 }: { porcentaje: number; demora?: number }) {
  const reducir = useReducedMotion()

  return (
    <div className="h-1.5 w-full overflow-hidden rounded-full bg-[#efeaf9]">
      <motion.div
        className="h-full rounded-full bg-gradient-to-r from-[#a78bfa] to-[#7c3aed]"
        initial={{ width: reducir ? `${porcentaje}%` : 0 }}
        animate={{ width: `${Math.max(3, porcentaje)}%` }}
        transition={{ duration: 0.7, delay: demora, ease: [0.16, 1, 0.3, 1] }}
      />
    </div>
  )
}

/* ------------------------------------------------------------------- tabla */

/** Una celda que parece número se alinea a la derecha, para poder comparar de un vistazo. */
const esNumerica = (v: string) => /^[−-]?[\d.]+(?:,\d+)?(?:\s?%)?$/.test(v.trim())

export function TablaViva({ tabla, alElegir, ocupado }: { tabla: TablaDeCordes; alElegir: (a: AtajoDeCordes) => void; ocupado?: boolean }) {
  const reducir = useReducedMotion()
  const [todas, setTodas] = useState(false)
  const [copiada, setCopiada] = useState(false)
  const visibles = todas ? tabla.filas : tabla.filas.slice(0, 8)
  // Si alguna fila trae botones (abrir la ficha, la cotización, cotizar, ver sólo estas), hay una columna para ellos.
  const hayBotones = (tabla.acciones ?? []).some((f) => f.length > 0)

  async function copiar() {
    const tsv = [tabla.columnas, ...tabla.filas].map((f) => f.join('\t')).join('\n')
    try {
      await navigator.clipboard.writeText(tsv)
      setCopiada(true)
      setTimeout(() => setCopiada(false), 1600)
    } catch {
      /* sin permiso para copiar: no pasa nada */
    }
  }

  return (
    <div className="overflow-hidden rounded-xl border border-[#e9e2f8] bg-white">
      <div className="flex items-center justify-between gap-3 border-b border-[#f0ebfa] bg-[#faf8ff] px-3.5 py-2">
        <span className="truncate text-[12px] font-semibold text-[#4c3a73]">{tabla.titulo}</span>
        <button
          type="button"
          onClick={copiar}
          title="Copiar la tabla para pegarla en Excel"
          className="inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-[11px] font-medium text-slate-500 outline-none transition-colors hover:bg-white hover:text-ink focus-visible:ring-2 focus-visible:ring-ia/40"
        >
          {copiada ? <Check size={13} strokeWidth={2.4} className="text-success" /> : <Copy size={13} strokeWidth={2} />}
          {copiada ? 'Copiada' : 'Copiar'}
        </button>
      </div>
      <div className="max-h-[300px] overflow-auto">
        <table className="w-full border-collapse text-left text-[12.5px]">
          <thead className="sticky top-0 z-10 bg-white">
            <tr>
              {tabla.columnas.map((c, i) => (
                <th key={i} className="whitespace-nowrap border-b border-[#f0ebfa] px-3.5 py-2 text-[10.5px] font-semibold uppercase tracking-[0.04em] text-slate-500">
                  {c}
                </th>
              ))}
              {hayBotones && <th className="border-b border-[#f0ebfa] px-2 py-2" aria-label="Abrir" />}
            </tr>
          </thead>
          <tbody>
            {visibles.map((fila, i) => (
              <motion.tr
                key={i}
                initial={reducir ? false : { opacity: 0, y: 4 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: Math.min(i, 10) * 0.03, duration: 0.25 }}
                className="border-b border-[#f6f3fc] last:border-0 hover:bg-[#faf8ff]"
              >
                {fila.map((celda, j) => (
                  <td key={j} className={cx('px-3.5 py-2 text-[#2e2a40]', esNumerica(celda) ? 'whitespace-nowrap text-right tabular-nums' : 'max-w-[320px]')}>
                    {celda}
                  </td>
                ))}
                {hayBotones && (
                  <td className="whitespace-nowrap px-2 py-1.5 text-right">
                    <span className="inline-flex gap-1">
                      {(tabla.acciones?.[i] ?? []).map((a) => (
                        <BotonDeFila key={a.etiqueta} atajo={a} alElegir={alElegir} deshabilitado={ocupado} />
                      ))}
                    </span>
                  </td>
                )}
              </motion.tr>
            ))}
          </tbody>
        </table>
      </div>
      {tabla.filas.length > 8 && (
        <button
          type="button"
          onClick={() => setTodas((t) => !t)}
          className="flex w-full items-center justify-center gap-1 border-t border-[#f0ebfa] bg-[#faf8ff] py-1.5 text-[11.5px] font-semibold text-ia outline-none transition-colors hover:bg-[#f3eefe] focus-visible:ring-2 focus-visible:ring-ia/40"
        >
          {todas ? 'Ver menos' : `Ver las ${tabla.filas.length} filas`}
          <ChevronDown size={14} strokeWidth={2.4} className={cx('transition-transform', todas && 'rotate-180')} />
        </button>
      )}
    </div>
  )
}

/* ------------------------------------------------------------------ atajos */

const ICONOS: Record<IconoDeAtajo, typeof List> = {
  lista: List,
  empresa: Building2,
  mas: Plus,
  documento: FileText,
  grafico: BarChart3,
  excel: FileSpreadsheet,
  pregunta: CornerDownRight,
}

/** El botón de una fila: un enlace de verdad si lleva a otra pantalla (se puede abrir en otra pestaña), o un botón si cambia la búsqueda. */
function BotonDeFila({ atajo, alElegir, deshabilitado }: { atajo: AtajoDeCordes; alElegir: (a: AtajoDeCordes) => void; deshabilitado?: boolean }) {
  const Icono = ICONOS[atajo.icono] ?? List
  const clase = 'inline-flex h-7 items-center gap-1 rounded-md border border-[#e4dcf7] bg-white px-2 text-[11px] font-semibold text-[#4c2a9c] outline-none transition-colors hover:border-ia/50 hover:bg-[#f6f1ff] focus-visible:ring-2 focus-visible:ring-ia/40 disabled:opacity-50'
  const contenido = (
    <>
      <Icono size={13} strokeWidth={2.2} className="shrink-0 text-ia" />
      <span className="hidden sm:inline">{atajo.etiqueta}</span>
    </>
  )

  return atajo.accion.tipo === 'ruta' ? (
    <Link to={atajo.accion.ruta} title={atajo.etiqueta} aria-label={atajo.etiqueta} className={clase}>
      {contenido}
    </Link>
  ) : (
    <button type="button" title={atajo.etiqueta} aria-label={atajo.etiqueta} disabled={deshabilitado} onClick={() => alElegir(atajo)} className={clase}>
      {contenido}
    </button>
  )
}

export function Atajo({
  atajo,
  alElegir,
  demora = 0,
  deshabilitado,
}: {
  atajo: AtajoDeCordes
  alElegir: (a: AtajoDeCordes) => void
  demora?: number
  deshabilitado?: boolean
}) {
  const reducir = useReducedMotion()
  const Icono = ICONOS[atajo.icono] ?? List
  const clase = cx(
    'inline-flex max-w-full items-center gap-1.5 rounded-full border border-[#ddd2f7] bg-white px-3 py-1.5 text-[12px] font-semibold text-[#4c2a9c] outline-none',
    'shadow-[0_1px_2px_rgba(76,29,149,0.06)] transition-colors hover:border-ia/50 hover:bg-[#f6f1ff] focus-visible:ring-2 focus-visible:ring-ia/40 disabled:opacity-50',
  )

  // A otra pantalla: un enlace de verdad (clic del medio o Ctrl+clic lo abren en otra pestaña).
  if (atajo.accion.tipo === 'ruta') {
    return (
      <Link to={atajo.accion.ruta} className={clase}>
        <Icono size={14} strokeWidth={2.2} className="shrink-0 text-ia" />
        <span className="truncate">{atajo.etiqueta}</span>
      </Link>
    )
  }

  return (
    <motion.button
      type="button"
      disabled={deshabilitado}
      onClick={() => alElegir(atajo)}
      initial={reducir ? false : { opacity: 0, y: 6, scale: 0.96 }}
      animate={{ opacity: 1, y: 0, scale: 1 }}
      transition={{ delay: demora, type: 'spring', stiffness: 420, damping: 28 }}
      whileHover={reducir ? undefined : { y: -1.5 }}
      whileTap={reducir ? undefined : { scale: 0.97 }}
      className={clase}
    >
      <Icono size={14} strokeWidth={2.2} className="shrink-0 text-ia" />
      <span className="truncate">{atajo.etiqueta}</span>
    </motion.button>
  )
}

/** Una sugerencia para seguir hablando: se manda como pregunta. */
export function Sugerencia({ texto, alElegir, demora = 0, deshabilitado }: { texto: string; alElegir: (t: string) => void; demora?: number; deshabilitado?: boolean }) {
  const reducir = useReducedMotion()

  return (
    <motion.button
      type="button"
      disabled={deshabilitado}
      onClick={() => alElegir(texto)}
      initial={reducir ? false : { opacity: 0, y: 4 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ delay: demora, duration: 0.25 }}
      className="rounded-full border border-dashed border-[#d3c6f2] px-3 py-1.5 text-left text-[11.5px] font-medium text-[#6b5a8f] outline-none transition-colors hover:border-solid hover:border-ia/50 hover:bg-[#f8f5ff] hover:text-[#4c2a9c] focus-visible:ring-2 focus-visible:ring-ia/40 disabled:opacity-50"
    >
      {texto}
    </motion.button>
  )
}

/* ------------------------------------------------------------------ pensando */

/**
 * Lo que Cordes está haciendo, en vivo: cada consulta que hace aparece cuando la hace, y la anterior
 * queda tildada. Así se ve que trabaja (y en qué), en lugar de un "pensando…" sin explicación.
 */
export function Pensando({ pasos }: { pasos: string[] }) {
  const reducir = useReducedMotion()
  const todos = ['Leyendo tu pregunta…', ...pasos]

  return (
    <div className="flex flex-col gap-2" role="status" aria-live="polite">
      <ul className="flex flex-col gap-1.5">
        <AnimatePresence initial={false}>
          {todos.map((p, i) => {
            const actual = i === todos.length - 1

            return (
              <motion.li
                key={`${i}-${p}`}
                initial={reducir ? false : { opacity: 0, x: -6 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.22 }}
                className={cx('flex items-center gap-2 text-[13px]', actual ? 'font-medium text-ink' : 'text-slate-500')}
              >
                <span className="grid h-4 w-4 shrink-0 place-items-center">
                  {actual ? (
                    <span className="cordes-latido" aria-hidden />
                  ) : (
                    <motion.span initial={reducir ? false : { scale: 0 }} animate={{ scale: 1 }} transition={{ type: 'spring', stiffness: 500, damping: 20 }}>
                      <Check size={14} strokeWidth={2.8} className="text-success" />
                    </motion.span>
                  )}
                </span>
                {p}
              </motion.li>
            )
          })}
        </AnimatePresence>
      </ul>
      <div className="cordes-brillo mt-0.5 h-1.5 w-full max-w-[260px] rounded-full" aria-hidden />
    </div>
  )
}

/** Cómo lo averiguó: se abre para ver qué se consultó (confianza = poder mirar). */
export function ComoLoSupo({ pasos, verificada, fuente }: { pasos: string[]; verificada: boolean; fuente: 'ia' | 'reglas' | 'codigo' }) {
  const [abierto, setAbierto] = useState(false)
  const consultas = useMemo(() => pasos.filter((p) => !p.startsWith('Comprobando')), [pasos])

  if (consultas.length === 0 && !verificada) return null

  return (
    <div className="text-[11.5px] text-slate-500">
      <button
        type="button"
        onClick={() => setAbierto((a) => !a)}
        aria-expanded={abierto}
        className="inline-flex items-center gap-1.5 rounded-md py-0.5 outline-none transition-colors hover:text-ink focus-visible:ring-2 focus-visible:ring-ia/40"
      >
        {verificada && <Check size={13} strokeWidth={2.8} className="text-success" />}
        <span>
          {verificada ? (fuente === 'codigo' ? 'Calculado con los datos del sistema' : 'Cifras comprobadas con los datos del sistema') : 'Cómo lo averigüé'}
          {consultas.length > 0 && ` · ${consultas.length} ${consultas.length === 1 ? 'consulta' : 'consultas'}`}
        </span>
        {consultas.length > 0 && <ChevronDown size={13} strokeWidth={2.4} className={cx('transition-transform', abierto && 'rotate-180')} />}
      </button>
      <AnimatePresence initial={false}>
        {abierto && consultas.length > 0 && (
          <motion.ul
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="overflow-hidden"
          >
            {consultas.map((p, i) => (
              <li key={i} className="py-0.5 pl-5 before:mr-1.5 before:text-ia before:content-['›']">
                {p.replace(/…$/, '')}
              </li>
            ))}
          </motion.ul>
        )}
      </AnimatePresence>
    </div>
  )
}

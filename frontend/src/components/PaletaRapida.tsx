import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AnimatePresence, motion } from 'motion/react'
import { Building2, CornerDownLeft, Search } from 'lucide-react'
import { NAV_ROUTES } from '../data/navigation'
import { useUltimasVistas } from '../lib/indice'

/* ---------------------------------------------------------------------------
   Ir a cualquier lado escribiendo, sin recorrer el menú.

   El menú tiene ocho secciones y cincuenta entradas: encontrar "Formas y
   fórmulas" es bajar, abrir Configuración y leer. Acá se escribe "form" y se
   entra con Enter.

   Se busca por el nombre de la entrada Y por el de su sección, así que "cotiz"
   trae todo lo de Cotizaciones aunque la palabra no esté en cada etiqueta.
--------------------------------------------------------------------------- */

/** Sin acentos y en minúscula: "fórmulas" tiene que encontrarse con "formulas". */
function plano(t: string): string {
  return t.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
}

export default function PaletaRapida({
  abierta,
  onCerrar,
}: {
  abierta: boolean
  onCerrar: () => void
}) {
  const navigate = useNavigate()
  const [texto, setTexto] = useState('')
  const [activo, setActivo] = useState(0)
  const campo = useRef<HTMLInputElement>(null)
  const ultimas = useUltimasVistas()

  const resultados = useMemo(() => {
    const q = plano(texto.trim())

    if (!q) return NAV_ROUTES.slice(0, 8)

    return NAV_ROUTES.filter((d) => plano(`${d.parent ?? ''} ${d.label}`).includes(q)).slice(0, 8)
  }, [texto])

  const empresas = useMemo(() => {
    const q = plano(texto.trim())

    if (!q) return ultimas.slice(0, 4)

    return ultimas.filter((e) => plano(e.nombre).includes(q)).slice(0, 4)
  }, [texto, ultimas])

  const todo = useMemo(
    () => [
      ...empresas.map((e) => ({ tipo: 'empresa' as const, label: e.nombre, to: `/empresas/${e.id}` })),
      ...resultados.map((d) => ({
        tipo: 'menu' as const,
        label: d.label,
        seccion: d.parent ?? '',
        to: d.to,
      })),
    ],
    [empresas, resultados],
  )

  useEffect(() => {
    if (abierta) {
      setTexto('')
      setActivo(0)
      // El foco va después del montaje, si no el input todavía no existe.
      requestAnimationFrame(() => campo.current?.focus())
    }
  }, [abierta])

  useEffect(() => setActivo(0), [texto])

  function teclas(e: React.KeyboardEvent) {
    if (e.key === 'Escape') return onCerrar()

    if (e.key === 'ArrowDown') {
      e.preventDefault()

      return setActivo((i) => (todo.length ? (i + 1) % todo.length : 0))
    }

    if (e.key === 'ArrowUp') {
      e.preventDefault()

      return setActivo((i) => (todo.length ? (i - 1 + todo.length) % todo.length : 0))
    }

    if (e.key === 'Enter' && todo[activo]) {
      e.preventDefault()
      navigate(todo[activo].to)
      onCerrar()
    }
  }

  return (
    <AnimatePresence>
      {abierta && (
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.12 }}
          onClick={onCerrar}
          className="fixed inset-0 z-[60] flex items-start justify-center bg-black/25 px-4 pt-[12vh]"
        >
          <motion.div
            initial={{ opacity: 0, y: -10, scale: 0.98 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -10, scale: 0.98 }}
            transition={{ duration: 0.14 }}
            onClick={(e) => e.stopPropagation()}
            role="dialog"
            aria-label="Ir a"
            className="w-full max-w-[560px] overflow-hidden rounded-[14px] border border-line bg-white shadow-2xl"
          >
            <label className="flex items-center gap-2.5 border-b border-line px-4 py-3">
              <Search size={17} strokeWidth={2} className="shrink-0 text-faint" />
              <input
                ref={campo}
                value={texto}
                onChange={(e) => setTexto(e.target.value)}
                onKeyDown={teclas}
                placeholder="Ir a una pantalla o a una empresa…"
                className="w-full min-w-0 bg-transparent text-[14px] text-ink placeholder:text-faint focus:outline-none"
              />
              <kbd className="shrink-0 rounded-md border border-line bg-app px-1.5 py-0.5 text-[10.5px] text-faint">
                esc
              </kbd>
            </label>

            {todo.length === 0 ? (
              <p className="px-4 py-8 text-center text-[13px] text-muted">
                Nada con “{texto}”.
              </p>
            ) : (
              <div className="max-h-[360px] overflow-y-auto py-1.5">
                {todo.map((item, i) => (
                  <button
                    key={`${item.tipo}-${item.to}-${i}`}
                    type="button"
                    onMouseEnter={() => setActivo(i)}
                    onClick={() => {
                      navigate(item.to)
                      onCerrar()
                    }}
                    className={`flex w-full items-center gap-2.5 px-4 py-2 text-left transition-colors ${
                      i === activo ? 'bg-brand-50' : 'hover:bg-app'
                    }`}
                  >
                    {item.tipo === 'empresa' && (
                      <Building2 size={14} strokeWidth={2} className="shrink-0 text-brand-600" />
                    )}

                    <span className="min-w-0 flex-1 truncate text-[13.5px] text-ink">
                      {item.label}
                    </span>

                    {item.tipo === 'menu' && item.seccion && (
                      <span className="shrink-0 text-[11.5px] text-faint">{item.seccion}</span>
                    )}
                    {item.tipo === 'empresa' && (
                      <span className="shrink-0 text-[11.5px] text-faint">visitada</span>
                    )}

                    {i === activo && (
                      <CornerDownLeft size={13} strokeWidth={2} className="shrink-0 text-brand-600" />
                    )}
                  </button>
                ))}
              </div>
            )}
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  )
}

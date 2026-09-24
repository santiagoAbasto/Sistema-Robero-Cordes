import { ChevronLeft, ChevronRight } from 'lucide-react'

/* ---------------------------------------------------------------------------
   Moverse entre las páginas de una lista.

   El servidor venía paginando de a 25 desde siempre y ninguna pantalla dibujaba
   los controles: de las 968 empresas sólo se veía la primera página y las otras
   943 no había forma de alcanzarlas. No era un problema de scroll, era data
   inaccesible.

   Se muestra solo cuando hay más de una página: en una lista corta no aparece
   nada y no estorba.
--------------------------------------------------------------------------- */

export default function Paginador({
  pagina,
  paginas,
  total,
  porPagina,
  onIr,
  que = 'resultados',
}: {
  pagina: number
  paginas: number
  total: number
  porPagina: number
  onIr: (pagina: number) => void
  /** Cómo se llama lo que se está listando: "empresas", "cotizaciones". */
  que?: string
}) {
  if (paginas <= 1) return null

  const desde = (pagina - 1) * porPagina + 1
  const hasta = Math.min(pagina * porPagina, total)

  return (
    <div className="flex flex-wrap items-center gap-3 border-t border-line bg-app px-[22px] py-2.5">
      <span className="text-[11.5px] text-muted">
        {desde}–{hasta} de {total.toLocaleString('es-AR')} {que}
      </span>

      <div className="ml-auto flex items-center gap-1.5">
        <Flecha lado="izq" disponible={pagina > 1} onClick={() => onIr(pagina - 1)} />

        {ventana(pagina, paginas).map((p, i) =>
          p === null ? (
            <span key={`hueco-${i}`} className="px-1 text-[12px] text-faint">
              …
            </span>
          ) : (
            <button
              key={p}
              type="button"
              onClick={() => onIr(p)}
              aria-current={p === pagina ? 'page' : undefined}
              className={`h-7 min-w-[28px] rounded-md border px-1.5 text-[12px] font-semibold transition-colors ${
                p === pagina
                  ? 'border-brand bg-brand text-white'
                  : 'border-line-strong bg-white text-slate-600 hover:bg-app'
              }`}
            >
              {p}
            </button>
          ),
        )}

        <Flecha lado="der" disponible={pagina < paginas} onClick={() => onIr(pagina + 1)} />
      </div>
    </div>
  )
}

function Flecha({
  lado,
  disponible,
  onClick,
}: {
  lado: 'izq' | 'der'
  disponible: boolean
  onClick: () => void
}) {
  const Icono = lado === 'izq' ? ChevronLeft : ChevronRight

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={!disponible}
      aria-label={lado === 'izq' ? 'Página anterior' : 'Página siguiente'}
      className="grid h-7 w-7 place-items-center rounded-md border border-line-strong bg-white text-slate-600 transition-colors hover:bg-app disabled:cursor-default disabled:opacity-40 disabled:hover:bg-white"
    >
      <Icono size={15} strokeWidth={2.2} />
    </button>
  )
}

/**
 * Los números a mostrar: siempre la primera, la última y las de al lado.
 *
 * Con 39 páginas no se pueden dibujar los 39 botones. null es el "…".
 */
function ventana(pagina: number, paginas: number): (number | null)[] {
  if (paginas <= 7) {
    return Array.from({ length: paginas }, (_, i) => i + 1)
  }

  const cerca = [pagina - 1, pagina, pagina + 1].filter((p) => p > 1 && p < paginas)
  const salida: (number | null)[] = [1]

  if (cerca[0] > 2) salida.push(null)
  salida.push(...cerca)
  if (cerca[cerca.length - 1] < paginas - 1) salida.push(null)
  salida.push(paginas)

  return salida
}

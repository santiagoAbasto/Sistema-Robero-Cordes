import { Link } from 'react-router-dom'
import { versionesDe, useCarga, fecha, plata } from '../../lib/indice'

/* ---------------------------------------------------------------------------
   Las versiones de una cotización, como solapas: R0, R1, R2.

   "Cuando entramos a una cotización, que podamos ver las versiones, más
   organizada, las solapas y ver entre una y otra". Cada versión es su propia
   cotización —la emitida quedó como se mandó—, así que cada solapa abre la
   suya. La que se está mirando queda marcada.

   Con una sola versión no hay entre qué elegir, y no se muestra.
--------------------------------------------------------------------------- */

export default function Versiones({ consultaId }: { consultaId: number }) {
  const { datos } = useCarga(() => versionesDe(consultaId), [consultaId])

  if (!datos || datos.length < 2) return null

  return (
    <nav aria-label="Versiones de la cotización" className="flex flex-wrap items-center gap-2">
      <span className="mr-1 text-[11px] font-semibold uppercase tracking-wide text-muted">
        Versiones
      </span>

      {datos.map((v) => {
        const actual = v.id === consultaId
        const etiqueta = v.emitida ? `R${v.revision}` : 'Borrador'

        return (
          <Link
            key={v.id}
            to={`/consultas/${v.id}`}
            aria-current={actual ? 'page' : undefined}
            className={`flex flex-col rounded-[9px] border px-3 py-1.5 text-left transition-colors ${
              actual
                ? 'border-brand bg-brand text-white'
                : 'border-line-strong bg-white text-slate-700 hover:bg-app'
            }`}
          >
            <span className="text-[12.5px] font-semibold">{etiqueta}</span>
            <span className={`text-[10.5px] tabular-nums ${actual ? 'text-white/80' : 'text-muted'}`}>
              {v.emitida && v.emitida_el ? fecha(v.emitida_el) : 'sin emitir'}
              {v.total > 0 && ` · ${plata(v.total)}`}
            </span>
          </Link>
        )
      })}
    </nav>
  )
}

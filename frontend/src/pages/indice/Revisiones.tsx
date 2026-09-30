import { useState } from 'react'
import { History, ChevronRight } from 'lucide-react'
import { Card, CardHeader, Chip, NotaPie } from '../../components/ui'
import { traerRevisiones, useCarga } from '../../lib/indice'
import type { Revision } from '../../lib/indice'

/* ---------------------------------------------------------------------------
   Las revisiones de esta cotización.

   Antes esto sólo se podía ver desde la ficha de la empresa, revuelto con el
   de las otras cien cotizaciones, y llegaba una fila por campo cambiado:
   corregir tres precios y guardar daba tres renglones sueltos.

   Una entrada es UN GUARDADO: lo que cambió la misma persona en el mismo
   momento, con una línea que dice qué pasó.

   Se llama "cambios" y no "revisiones" porque revisión ya es otra cosa: la R0,
   R1, R2 del número de cotización. Cada versión tiene sus propios cambios,
   porque cada una es su propia cotización. El detalle —qué decía antes y qué
   dice ahora— está adentro, para quien lo necesite.
--------------------------------------------------------------------------- */

export default function Revisiones({ consultaId }: { consultaId: number }) {
  const { datos, cargando } = useCarga(() => traerRevisiones(consultaId), [consultaId])
  const [abierta, setAbierta] = useState<number | null>(null)

  if (cargando || !datos || datos.total === 0) return null

  return (
    <Card className="overflow-hidden">
      <CardHeader
        titulo="Cambios de esta version"
        cuenta={datos.total}
        chips={<Chip tono="neutro">uso interno</Chip>}
        ayuda="Cada renglón es un guardado: quién lo hizo, cuándo y qué cambió. La última arriba."
      />

      <ul className="border-t border-[#eef2f6]">
        {datos.revisiones.map((r) => (
          <Fila
            key={r.numero}
            revision={r}
            abierta={abierta === r.numero}
            onAbrir={() => setAbierta(abierta === r.numero ? null : r.numero)}
          />
        ))}
      </ul>

      <NotaPie>
        No se imprime. Es para saber qué se le mandó al cliente y qué se cambió después.
      </NotaPie>
    </Card>
  )
}

function Fila({
  revision,
  abierta,
  onAbrir,
}: {
  revision: Revision
  abierta: boolean
  onAbrir: () => void
}) {
  const tieneDetalle = revision.cambios.length > 0

  return (
    <li className="border-b border-[#eef2f6]">
      <div className="flex flex-wrap items-center gap-2.5 px-[22px] py-3">
        <span className="grid h-6 min-w-[26px] place-items-center rounded-md bg-slate-100 px-1.5 text-[11px] font-bold text-slate-500">
          {revision.numero}
        </span>

        <History size={13} strokeWidth={2.2} className="text-faint" />
        <span className="text-[12.5px] font-medium text-ink">{revision.que_paso}</span>

        <div className="ml-auto flex items-center gap-2.5">
          <span className="text-[11.5px] text-muted">{revision.fecha}</span>
          <span className="text-[11px] text-[#c3cdd6]">·</span>
          <span className="text-[11.5px] text-muted">{revision.quien}</span>

          {tieneDetalle && (
            <button
              type="button"
              onClick={onAbrir}
              className="flex items-center gap-1 text-[11.5px] font-semibold text-brand-600 hover:text-brand"
            >
              <ChevronRight
                size={13}
                strokeWidth={2.4}
                className={`transition-transform ${abierta ? 'rotate-90' : ''}`}
              />
              {abierta ? 'Cerrar' : 'Ver el detalle'}
            </button>
          )}
        </div>
      </div>

      {abierta && (
        <div className="border-t border-[#eef2f6] bg-[#f8fafc] px-[22px] py-3">
          <table className="w-full text-[11.5px]">
            <thead>
              <tr className="text-[10px] font-semibold uppercase tracking-wide text-faint">
                <th className="pb-1.5 text-left">Que</th>
                <th className="pb-1.5 text-left">Decia</th>
                <th className="pb-1.5 text-left">Dice ahora</th>
              </tr>
            </thead>
            <tbody>
              {revision.cambios.map((c, i) => (
                <tr key={i} className="align-top">
                  <td className="py-1 pr-4 text-muted">{c.que}</td>
                  <td className="py-1 pr-4 text-[#9aa7b4] line-through">{c.antes ?? '—'}</td>
                  <td className="py-1 font-medium text-ink">{c.ahora ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </li>
  )
}

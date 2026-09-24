import { Link } from 'react-router-dom'
import { AlarmClock, ArrowRight, CalendarX2, Check } from 'lucide-react'
import { traerPendientes, useCarga } from '../lib/indice'
import type { Pendiente } from '../lib/indice'

/* ---------------------------------------------------------------------------
   Lo que hay que hacer hoy, arriba de todo.

   Va antes que cualquier número del dashboard: los KPIs cuentan cómo viene el
   mes, esto dice a quién llamar antes de que se caiga. Es lo único de esta
   pantalla sobre lo que se puede actuar ahora mismo.

   Sale del mismo endpoint que la campanita: se calcula al preguntar, no hay
   nada guardado que pueda quedar desactualizado.
--------------------------------------------------------------------------- */

export default function NecesitaAtencion() {
  const { datos, cargando } = useCarga(() => traerPendientes(), [])

  if (cargando || !datos) return null

  const { por_vencer: porVencer, vencidas_sin_cerrar: vencidas } = datos
  const total = porVencer.cuantas + vencidas.cuantas

  // Al día no se muestra como un cero: se dice, y ocupa una línea.
  if (total === 0) {
    return (
      <div className="flex items-center gap-2.5 rounded-card border border-[#cdebd8] bg-[#f4fbf6] px-5 py-3">
        <Check size={16} strokeWidth={2.4} className="shrink-0 text-success-ink" />
        <span className="text-[13px] text-success-ink">
          Al día: no hay cotizaciones por vencer ni vencidas sin cerrar.
        </span>
      </div>
    )
  }

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Panel
        icono={AlarmClock}
        tono="ambar"
        titulo={`Vencen dentro de ${porVencer.dias} días`}
        pie="Todavía se salvan con un llamado."
        cuantas={porVencer.cuantas}
        items={porVencer.primeras}
      />
      <Panel
        icono={CalendarX2}
        tono="rojo"
        titulo="Vencidas sin cerrar"
        pie="Cerralas diciendo qué pasó: ese motivo sirve para volver a cotizar."
        cuantas={vencidas.cuantas}
        items={vencidas.primeras}
      />
    </div>
  )
}

const TONOS = {
  ambar: {
    caja: 'border-[#f3d9a6] bg-[#fff8ee]',
    texto: 'text-warning-ink',
  },
  rojo: {
    caja: 'border-[#f1c9c0] bg-[#fdf3f1]',
    texto: 'text-danger',
  },
} as const

function Panel({
  icono: Icono,
  tono,
  titulo,
  pie,
  cuantas,
  items,
}: {
  icono: typeof AlarmClock
  tono: keyof typeof TONOS
  titulo: string
  pie: string
  cuantas: number
  items: Pendiente[]
}) {
  const c = TONOS[tono]

  // Un panel en cero no se dibuja: el otro ocupa el ancho y no hay ruido.
  if (cuantas === 0) return null

  return (
    <div className={`flex flex-col rounded-card border ${c.caja}`}>
      <div className="flex items-center gap-2.5 px-5 pb-2.5 pt-4">
        <Icono size={16} strokeWidth={2.2} className={`shrink-0 ${c.texto}`} />
        <span className="text-[13.5px] font-semibold text-ink">{titulo}</span>
        <span className={`ml-auto text-[20px] font-bold leading-none tabular-nums ${c.texto}`}>
          {cuantas}
        </span>
      </div>

      <div className="flex flex-col">
        {items.map((p) => (
          <Link
            key={p.id}
            to={`/consultas/${p.id}`}
            className="flex items-baseline gap-3 px-5 py-1.5 transition-opacity hover:opacity-70"
          >
            <span className="min-w-0 flex-1 truncate text-[13px] text-ink">
              {p.empresa ?? 'Sin empresa'}
            </span>
            <span className={`shrink-0 text-[11.5px] font-medium ${c.texto}`}>
              {cuando(p)}
            </span>
          </Link>
        ))}
      </div>

      <div className="mt-auto flex items-center justify-between gap-3 px-5 pb-3.5 pt-2.5">
        <span className="text-[11.5px] text-muted">{pie}</span>
        <Link
          to="/cotizaciones/seguimiento"
          className="flex shrink-0 items-center gap-1 text-[12.5px] font-semibold text-brand hover:text-brand-600"
        >
          Ver todas <ArrowRight size={14} strokeWidth={2.2} />
        </Link>
      </div>
    </div>
  )
}

function cuando(p: Pendiente): string {
  const d = p.dias_para_vencer

  if (d === null) return ''
  if (d < 0) return `hace ${Math.abs(d)} ${Math.abs(d) === 1 ? 'día' : 'días'}`
  if (d === 0) return 'vence hoy'

  return `en ${d} ${d === 1 ? 'día' : 'días'}`
}

import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { AnimatePresence, motion } from 'motion/react'
import { AlarmClock, Bell, CalendarX2 } from 'lucide-react'
import { traerPendientes, useCarga } from '../lib/indice'
import type { Pendiente } from '../lib/indice'

/* ---------------------------------------------------------------------------
   Lo que necesita atención ahora.

   No hay tabla de notificaciones ni nada que marcar como leído: un aviso de
   "está por vencer" no es un hecho que haya pasado, es una consecuencia de la
   fecha. Se pregunta y se calcula. Cuando la cotización se cierra o se le
   cambia la validez, el aviso desaparece solo — no hay nada que sincronizar.
--------------------------------------------------------------------------- */

export default function Campanita() {
  const [abierta, setAbierta] = useState(false)
  const caja = useRef<HTMLDivElement>(null)

  // Se refresca al abrir: es barato y evita mostrar un número viejo.
  const { datos, recargar } = useCarga(() => traerPendientes(), [])

  useEffect(() => {
    function fuera(e: MouseEvent) {
      if (caja.current && !caja.current.contains(e.target as Node)) setAbierta(false)
    }
    document.addEventListener('mousedown', fuera)

    return () => document.removeEventListener('mousedown', fuera)
  }, [])

  const porVencer = datos?.por_vencer
  const vencidas = datos?.vencidas_sin_cerrar
  const cuantas = (porVencer?.cuantas ?? 0) + (vencidas?.cuantas ?? 0)

  return (
    <div className="relative" ref={caja}>
      <button
        type="button"
        onClick={() => {
          setAbierta((a) => !a)
          if (!abierta) recargar()
        }}
        aria-label={cuantas > 0 ? `${cuantas} cotizaciones necesitan atención` : 'Sin avisos'}
        className="relative grid h-10 w-10 place-items-center rounded-lg text-faint transition-colors hover:bg-app hover:text-ink"
      >
        <Bell size={19} strokeWidth={1.9} />
        {cuantas > 0 && (
          <span className="absolute -right-0.5 -top-0.5 grid h-[18px] min-w-[18px] place-items-center rounded-full bg-danger px-1 text-[10px] font-bold text-white ring-2 ring-white">
            {cuantas > 99 ? '99+' : cuantas}
          </span>
        )}
      </button>

      <AnimatePresence>
        {abierta && (
          <motion.div
            initial={{ opacity: 0, y: -6 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -6 }}
            transition={{ duration: 0.14 }}
            className="absolute right-0 z-50 mt-2 w-[330px] overflow-hidden rounded-[12px] border border-line bg-white shadow-xl"
          >
            <div className="border-b border-line px-4 py-2.5">
              <span className="text-[12px] font-semibold text-ink">Necesita atención</span>
            </div>

            {cuantas === 0 ? (
              <p className="px-4 py-6 text-center text-[12.5px] text-muted">
                No hay cotizaciones por vencer ni vencidas sin cerrar.
              </p>
            ) : (
              <div className="max-h-[380px] overflow-y-auto">
                <Grupo
                  icono={AlarmClock}
                  tono="ambar"
                  titulo={`Vencen dentro de ${porVencer?.dias ?? 7} días`}
                  cuantas={porVencer?.cuantas ?? 0}
                  items={porVencer?.primeras ?? []}
                  onIr={() => setAbierta(false)}
                />
                <Grupo
                  icono={CalendarX2}
                  tono="rojo"
                  titulo="Vencidas sin cerrar"
                  cuantas={vencidas?.cuantas ?? 0}
                  items={vencidas?.primeras ?? []}
                  onIr={() => setAbierta(false)}
                />
              </div>
            )}

            <Link
              to="/cotizaciones/seguimiento"
              onClick={() => setAbierta(false)}
              className="block border-t border-line bg-app px-4 py-2.5 text-center text-[12px] font-semibold text-brand-600 transition-colors hover:bg-[#eef4fa]"
            >
              Ver todas
            </Link>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}

function Grupo({
  icono: Icono,
  tono,
  titulo,
  cuantas,
  items,
  onIr,
}: {
  icono: typeof AlarmClock
  tono: 'ambar' | 'rojo'
  titulo: string
  cuantas: number
  items: Pendiente[]
  onIr: () => void
}) {
  if (cuantas === 0) return null

  const color = tono === 'rojo' ? 'text-danger' : 'text-warning-ink'

  return (
    <div className="border-b border-line last:border-b-0">
      <div className="flex items-center gap-2 px-4 pb-1.5 pt-3">
        <Icono size={13} strokeWidth={2.2} className={color} />
        <span className="text-[11px] font-semibold uppercase tracking-wide text-muted">
          {titulo}
        </span>
        <span className={`ml-auto text-[12px] font-bold ${color}`}>{cuantas}</span>
      </div>

      {items.map((p) => (
        <Link
          key={p.id}
          to={`/consultas/${p.id}`}
          onClick={onIr}
          className="flex items-baseline gap-2 px-4 py-1.5 transition-colors hover:bg-app"
        >
          <span className="min-w-0 flex-1 truncate text-[12.5px] text-ink">
            {p.empresa ?? 'Sin empresa'}
          </span>
          <span className={`shrink-0 text-[11px] ${color}`}>{cuandoVence(p)}</span>
        </Link>
      ))}

      {cuantas > items.length && (
        <p className="px-4 pb-2 pt-0.5 text-[11px] text-faint">
          y {cuantas - items.length} más
        </p>
      )}
    </div>
  )
}

function cuandoVence(p: Pendiente): string {
  const d = p.dias_para_vencer

  if (d === null) return ''
  if (d < 0) return `hace ${Math.abs(d)} ${Math.abs(d) === 1 ? 'día' : 'días'}`
  if (d === 0) return 'hoy'

  return `en ${d} ${d === 1 ? 'día' : 'días'}`
}

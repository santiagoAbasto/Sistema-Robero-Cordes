import { Link } from 'react-router-dom'
import {
  ArrowRight,
  Briefcase,
  CalendarRange,
  FileText,
  Inbox,
  Plus,
  Printer,
  Search,
  TrendingDown,
  TrendingUp,
  Users,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import api from '../lib/api'
import { fecha as fmtFecha, plata, useCarga } from '../lib/indice'
import { Cargando } from '../components/ui'
import NecesitaAtencion from '../components/NecesitaAtencion'

/* ---------------------------------------------------------------------------
   Pantalla de inicio.

   Todo lo que se muestra sale de lo que hay cargado. Donde todavía no hay
   datos —las compras, por ejemplo— no se inventa un número: se muestra el
   cuadro vacío con el motivo.
--------------------------------------------------------------------------- */

interface Resumen {
  periodo: string
  totales: {
    empresas: number
    empresas_nuevas: number
    clientes: number
    proveedores: number
    cotizaciones_mes: number
    cotizaciones_mes_pasado: number
  }
  ultimas: {
    id: number
    empresa_id: number
    empresa: string | null
    fecha: string | null
    tipo: string
    estado: string
    moneda: string | null
    moneda_base: string | null
    total: number
  }[]
  materiales: { nombre: string; veces: number }[]
}

const ESTADOS: Record<string, { dot: string; bg: string; fg: string }> = {
  Borrador: { dot: 'bg-[#94a3b8]', bg: 'bg-[#f1f5f9]', fg: 'text-[#64748b]' },
  Confirmada: { dot: 'bg-brand', bg: 'bg-brand-50', fg: 'text-brand-700' },
  Vendida: { dot: 'bg-success', bg: 'bg-success-bg', fg: 'text-success-ink' },
}

const QUICK: { label: string; to: string; icon: LucideIcon; fg: string }[] = [
  { label: 'Nueva empresa', to: '/empresas/nueva', icon: Briefcase, fg: 'text-[#7c3aed]' },
  { label: 'Buscar cotizacion', to: '/consultas/condicion', icon: Search, fg: 'text-brand' },
  { label: 'Consultas por fecha', to: '/consultas/fecha', icon: CalendarRange, fg: 'text-[#0d9488]' },
  { label: 'Imprimir', to: '/imprimir', icon: Printer, fg: 'text-[#d97706]' },
]

function EstadoPill({ estado }: { estado: string }) {
  const s = ESTADOS[estado] ?? ESTADOS.Borrador

  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full ${s.bg} px-2.5 py-1 text-[11.5px] font-semibold ${s.fg}`}
    >
      <span className={`h-1.5 w-1.5 rounded-full ${s.dot}`} />
      {estado}
    </span>
  )
}

/** Una tarjeta de arriba. El pie dice algo cierto o no dice nada. */
function Kpi({
  label,
  valor,
  icon: Icon,
  bg,
  fg,
  pie,
}: {
  label: string
  valor: number
  icon: LucideIcon
  bg: string
  fg: string
  pie?: React.ReactNode
}) {
  return (
    <div className="rounded-card border border-line bg-white p-5 shadow-[var(--shadow-card)]">
      <span className={`grid h-10 w-10 place-items-center rounded-[10px] ${bg} ${fg}`}>
        <Icon size={20} strokeWidth={2} />
      </span>
      <p className="mt-3.5 text-[13px] font-medium text-muted">{label}</p>
      <p className="mt-1 text-[28px] font-bold leading-none tracking-tight text-ink">
        {valor.toLocaleString('es-AR')}
      </p>
      <p className="mt-2.5 flex items-center gap-1 text-[12px] text-faint">{pie ?? ' '}</p>
    </div>
  )
}

/** La comparación con el mes pasado, solo cuando hay con qué comparar. */
function Variacion({ ahora, antes }: { ahora: number; antes: number }) {
  if (antes === 0) {
    return <span className="text-faint">{ahora === 0 ? 'sin movimiento' : 'primer mes con datos'}</span>
  }

  const pct = Math.round(((ahora - antes) / antes) * 100)
  const subio = pct >= 0
  const Icono = subio ? TrendingUp : TrendingDown

  return (
    <>
      <Icono size={14} strokeWidth={2.4} className={subio ? 'text-success' : 'text-danger'} />
      <span className={`font-semibold ${subio ? 'text-success-ink' : 'text-danger'}`}>
        {subio ? '+' : ''}
        {pct}%
      </span>
      <span className="text-faint">vs. mes pasado ({antes})</span>
    </>
  )
}

export default function Dashboard() {
  const { datos, cargando } = useCarga(
    () => api.get<Resumen>('/resumen').then((r) => r.data),
    [],
  )

  if (cargando) return <Cargando texto="Armando el resumen…" />
  if (!datos) return null

  const { totales, ultimas, materiales } = datos
  const masCotizado = materiales[0]?.veces ?? 1

  return (
    <div className="flex flex-col gap-6">
      {/* encabezado */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-[26px] font-bold tracking-tight text-ink">Dashboard</h1>
          <p className="mt-1 text-[14px] text-muted">
            Resumen de actividad comercial · {datos.periodo}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2.5">
          <Link
            to="/empresas/nueva"
            className="flex items-center gap-2 rounded-lg border border-line-strong bg-white px-4 py-2.5 text-[13.5px] font-semibold text-ink transition-colors hover:bg-app"
          >
            <Plus size={17} strokeWidth={2.2} /> Nueva empresa
          </Link>
          <Link
            to="/empresas/registros"
            className="flex items-center gap-2 rounded-lg bg-brand px-4 py-2.5 text-[13.5px] font-semibold text-white shadow-[0_2px_8px_rgba(0,125,195,0.3)] transition-colors hover:bg-brand-600"
          >
            <Plus size={17} strokeWidth={2.2} /> Nueva cotización
          </Link>
        </div>
      </div>

      {/*
        Lo que hay que hacer hoy va antes que los números del mes: los KPIs
        cuentan cómo viene, esto dice a quién llamar antes de que se caiga.
      */}
      <NecesitaAtencion />

      {/* tarjetas */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi
          label="Empresas registradas"
          valor={totales.empresas}
          icon={Briefcase}
          bg="bg-brand-50"
          fg="text-brand"
          pie={
            totales.empresas_nuevas > 0
              ? `${totales.empresas_nuevas} ${totales.empresas_nuevas === 1 ? 'nueva' : 'nuevas'} este mes`
              : 'sin altas este mes'
          }
        />
        <Kpi
          label="Clientes"
          valor={totales.clientes}
          icon={Users}
          bg="bg-[#f3effd]"
          fg="text-[#7c3aed]"
          pie={`de ${totales.empresas} empresas`}
        />
        <Kpi
          label="Proveedores"
          valor={totales.proveedores}
          icon={Inbox}
          bg="bg-[#e6f7f4]"
          fg="text-[#0d9488]"
          pie={`de ${totales.empresas} empresas`}
        />
        <Kpi
          label="Cotizaciones del mes"
          valor={totales.cotizaciones_mes}
          icon={FileText}
          bg="bg-[#fff4e5]"
          fg="text-[#d97706]"
          pie={
            <Variacion ahora={totales.cotizaciones_mes} antes={totales.cotizaciones_mes_pasado} />
          }
        />
      </div>

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {/* últimas cotizaciones */}
        <div className="rounded-card border border-line bg-white shadow-[var(--shadow-card)] lg:col-span-2">
          <div className="flex items-start justify-between gap-4 px-6 pb-4 pt-5">
            <div>
              <h2 className="text-[16px] font-semibold text-ink">Últimas cotizaciones</h2>
              <p className="mt-0.5 text-[12.5px] text-faint">Lo último que se cargó</p>
            </div>
            <Link
              to="/consultas/fecha"
              className="flex items-center gap-1 text-[13px] font-semibold text-brand hover:text-brand-600"
            >
              Ver todas <ArrowRight size={15} strokeWidth={2.2} />
            </Link>
          </div>

          {ultimas.length === 0 ? (
            <p className="border-t border-line px-6 py-8 text-center text-[13px] text-faint">
              Todavía no hay cotizaciones cargadas.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <div className="min-w-[600px]">
                <div className="grid grid-cols-[1.8fr_0.8fr_0.9fr_1fr_1fr] gap-2 border-y border-line bg-app/60 px-6 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-faint">
                  <span>Empresa</span>
                  <span>Fecha</span>
                  <span>Moneda</span>
                  <span className="text-right">Total</span>
                  <span className="text-right">Estado</span>
                </div>
                {ultimas.map((c, i) => (
                  <Link
                    key={c.id}
                    to={`/consultas/${c.id}`}
                    className={`grid grid-cols-[1.8fr_0.8fr_0.9fr_1fr_1fr] items-center gap-2 px-6 py-[13px] transition-colors hover:bg-app ${
                      i < ultimas.length - 1 ? 'border-b border-line' : ''
                    }`}
                  >
                    <span className="truncate text-[13px] font-semibold text-ink">{c.empresa}</span>
                    <span className="text-[13px] text-muted">{fmtFecha(c.fecha)}</span>
                    <span className="text-[12.5px] text-muted">{c.moneda_base ?? '—'}</span>
                    <span className="text-right text-[13px] font-semibold text-ink">
                      {plata(c.total)}
                    </span>
                    <span className="flex justify-end">
                      <EstadoPill estado={c.estado} />
                    </span>
                  </Link>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* columna derecha */}
        <div className="flex flex-col gap-5">
          <div className="rounded-card border border-line bg-white p-6 shadow-[var(--shadow-card)]">
            <h2 className="text-[16px] font-semibold text-ink">Accesos rápidos</h2>
            <div className="mt-4 grid grid-cols-2 gap-3">
              {QUICK.map((q) => {
                const Icon = q.icon

                return (
                  <Link
                    key={q.label}
                    to={q.to}
                    className="flex flex-col gap-3 rounded-xl border border-line bg-white p-4 transition-colors hover:border-brand-200 hover:bg-brand-50"
                  >
                    <Icon size={20} strokeWidth={2} className={q.fg} />
                    <span className="text-[13px] font-medium text-ink">{q.label}</span>
                  </Link>
                )
              })}
            </div>
          </div>

          <div className="rounded-card border border-line bg-white p-6 shadow-[var(--shadow-card)]">
            <h2 className="text-[16px] font-semibold text-ink">Materiales más cotizados</h2>
            <p className="mt-0.5 text-[12.5px] text-faint">Últimos 30 días</p>

            {materiales.length === 0 ? (
              <p className="mt-5 text-[12.5px] leading-relaxed text-faint">
                No hay cotizaciones en los últimos 30 días. Cuando se carguen, acá aparece qué
                material se pidió más.
              </p>
            ) : (
              <div className="mt-4 flex flex-col gap-3.5">
                {materiales.map((m) => (
                  <div key={m.nombre}>
                    <div className="flex items-center justify-between gap-3">
                      <span className="truncate text-[13px] font-medium text-ink">{m.nombre}</span>
                      <span className="text-[13px] font-semibold text-muted">{m.veces}</span>
                    </div>
                    <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-line">
                      <div
                        className="h-full rounded-full bg-brand"
                        style={{ width: `${(m.veces / masCotizado) * 100}%` }}
                      />
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}

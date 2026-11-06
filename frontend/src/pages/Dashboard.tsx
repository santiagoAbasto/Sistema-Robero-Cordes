import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../lib/auth'
import {
  ArrowRight,
  BarChart3,
  Briefcase,
  CalendarClock,
  CalendarRange,
  CheckCircle2,
  FileText,
  Inbox,
  MessageCircleQuestion,
  Plus,
  Printer,
  RefreshCw,
  Search,
  Sparkles,
  Trash2,
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
    borradores: number
    borradores_eliminados: number
    vendidas: number
  }
  ultimas: {
    id: number
    empresa_id: number
    empresa: string | null
    fecha: string | null
    tipo: string
    estado: string
    numero_con_revision: string | null
    emitida: boolean
    /** N° de pedido del cliente (portal, licitación, orden de compra). */
    solicitud_nro: string | null
    compra: { parcial: boolean; compradas: number; total: number } | null
    moneda: string | null
    moneda_base: string | null
    total: number
  }[]
  materiales: { nombre: string; veces: number }[]
}

const ESTADOS: Record<string, { dot: string; bg: string; fg: string }> = {
  Borrador: { dot: 'bg-[#94a3b8]', bg: 'bg-[#f1f5f9]', fg: 'text-[#64748b]' },
  // Confirmada pero sin emitir: todavía no se mandó, falta el paso de emisión.
  'Falta emisión': { dot: 'bg-[#f59e0b]', bg: 'bg-[#fff8ee]', fg: 'text-[#b45309]' },
  'Observación': { dot: 'bg-[#94a3b8]', bg: 'bg-[#f1f5f9]', fg: 'text-[#64748b]' },
  Emitida: { dot: 'bg-brand', bg: 'bg-brand-50', fg: 'text-brand-700' },
  Confirmada: { dot: 'bg-brand', bg: 'bg-brand-50', fg: 'text-brand-700' },
  Vendida: { dot: 'bg-success', bg: 'bg-success-bg', fg: 'text-success-ink' },
}

/*
  Accesos rápidos. Los cuatro de siempre siguen en su lugar (la gente ya sabe
  dónde están); los que se suman van al final.
*/
const QUICK: { label: string; to: string; icon: LucideIcon; fg: string; ayuda: string }[] = [
  { label: 'Nueva empresa', to: '/empresas/nueva', icon: Briefcase, fg: 'text-[#7c3aed]', ayuda: 'Dar de alta una empresa' },
  { label: 'Buscar cotizacion', to: '/buscador?grano=consultas', icon: Search, fg: 'text-brand', ayuda: 'Buscar entre las cotizaciones' },
  { label: 'Consultas por fecha', to: '/consultas/fecha', icon: CalendarRange, fg: 'text-[#0d9488]', ayuda: 'Ver las cotizaciones de un período' },
  { label: 'Imprimir', to: '/imprimir', icon: Printer, fg: 'text-[#d97706]', ayuda: 'Imprimir una cotización' },
  { label: 'Preguntale a Cordes', to: '/buscador', icon: Sparkles, fg: 'text-ia', ayuda: 'Escribir lo que buscás con tus palabras' },
  { label: 'Reportes', to: '/reportes', icon: BarChart3, fg: 'text-[#0369a1]', ayuda: 'Por material, por empresa, ventas y vencimientos' },
  { label: 'Sin respuesta', to: '/buscador?grano=consultas&sin_respuesta=1', icon: MessageCircleQuestion, fg: 'text-[#b45309]', ayuda: 'Cotizaciones que nadie contestó' },
  { label: 'Vencen esta semana', to: '/buscador?grano=consultas&por_vencer=7', icon: CalendarClock, fg: 'text-[#be123c]', ayuda: 'Cotizaciones por vencer en los próximos 7 días' },
]

function EstadoPill({
  estado,
  emitida,
  tipo,
  compra,
}: {
  estado: string
  emitida?: boolean
  tipo?: string
  compra?: { parcial: boolean; compradas: number; total: number } | null
}) {
  // "Confirmada" no dice si ya se hizo. Sin emitir todavía no se mandó: falta
  // la emisión. Emitida es la que ya salió. El resto se muestra tal cual.
  const texto = tipo === 'Observacion' ? 'Observación' : estado === 'Vendida' && compra?.parcial ? `Compra parcial · ${compra.compradas} de ${compra.total}` : estado === 'Confirmada' ? (emitida ? 'Emitida' : 'Falta emisión') : estado
  const s = ESTADOS[texto] ?? (texto.startsWith('Compra parcial') ? ESTADOS.Vendida : ESTADOS.Borrador)

  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full ${s.bg} px-2.5 py-1 text-[11.5px] font-semibold ${s.fg}`}
    >
      <span className={`h-1.5 w-1.5 rounded-full ${s.dot}`} />
      {texto}
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

/** El título y los dos botones de siempre. Están aunque el resumen no haya llegado. */
function Encabezado({ periodo }: { periodo?: string }) {
  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h1 className="text-[26px] font-bold tracking-tight text-ink">Dashboard</h1>
        <p className="mt-1 text-[14px] text-muted">
          Resumen de actividad comercial{periodo ? ` · ${periodo}` : ''}
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
  )
}

/** Los atajos a lo que más se hace. No dependen de los números: andan siempre. */
function AccesosRapidos() {
  return (
    <div className="rounded-card border border-line bg-white p-6 shadow-[var(--shadow-card)]">
      <h2 className="text-[16px] font-semibold text-ink">Accesos rápidos</h2>
      <div className="mt-4 grid grid-cols-2 gap-3">
        {QUICK.map((q) => {
          const Icon = q.icon

          return (
            <Link
              key={q.label}
              to={q.to}
              title={q.ayuda}
              className="group flex flex-col gap-3 rounded-xl border border-line bg-white p-4 outline-none transition-colors hover:border-brand-200 hover:bg-brand-50 focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/30"
            >
              <Icon size={20} strokeWidth={2} className={`${q.fg} transition-transform duration-150 group-hover:scale-110`} />
              <span className="text-[13px] font-medium text-ink">{q.label}</span>
            </Link>
          )
        })}
      </div>
    </div>
  )
}

export default function Dashboard() {
  const { user } = useAuth()
  const esAdmin = user?.role === 'Administrador'
  const { datos, cargando, recargar } = useCarga(
    () => api.get<Resumen>('/resumen').then((r) => r.data),
    [],
  )
  const [pagina, setPagina] = useState(1)

  if (cargando) return <Cargando texto="Armando el resumen…" />
  // Antes esto era `return null`: si el resumen fallaba, la pantalla quedaba en blanco,
  // sin los accesos ni nada. Ahora se ve lo que no depende de los números y se puede reintentar.
  if (!datos) {
    return (
      <div className="flex flex-col gap-6">
        <Encabezado />
        <NecesitaAtencion />
        <div className="grid grid-cols-1 items-start gap-5 lg:grid-cols-3">
          <div className="rounded-card border border-line bg-white p-6 shadow-[var(--shadow-card)] lg:col-span-2">
            <h2 className="text-[16px] font-semibold text-ink">No pudimos armar el resumen</h2>
            <p className="mt-1.5 max-w-[560px] text-[13.5px] leading-relaxed text-muted">
              Los números no llegaron. Podés seguir trabajando con los accesos de al lado, o probar de nuevo.
            </p>
            <button
              type="button"
              onClick={recargar}
              className="mt-4 inline-flex items-center gap-2 rounded-lg border border-line-strong bg-white px-4 py-2.5 text-[13.5px] font-semibold text-ink transition-colors hover:bg-app"
            >
              <RefreshCw size={16} strokeWidth={2.2} /> Reintentar
            </button>
          </div>
          <AccesosRapidos />
        </div>
      </div>
    )
  }

  const { totales, ultimas, materiales } = datos
  const masCotizado = materiales[0]?.veces ?? 1

  // Las últimas, de a diez, con paginador: así la tarjeta se llena y no quedan
  // seis filas con medio metro de blanco debajo.
  const porPagina = 10
  const paginas = Math.max(1, Math.ceil(ultimas.length / porPagina))
  const paginaActual = Math.min(pagina, paginas)
  const visibles = ultimas.slice((paginaActual - 1) * porPagina, paginaActual * porPagina)

  return (
    <div className="flex flex-col gap-6">
      <Encabezado periodo={datos.periodo} />

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

      <div className="grid grid-cols-1 items-start gap-5 lg:grid-cols-3">
        {/* últimas cotizaciones */}
        <div className="rounded-card border border-line bg-white shadow-[var(--shadow-card)] lg:col-span-2">
          <div className="flex items-start justify-between gap-4 px-6 pb-4 pt-5">
            <div>
              <h2 className="text-[16px] font-semibold text-ink">Últimas cotizaciones</h2>
              <p className="mt-0.5 text-[12.5px] text-faint">Lo último que se cargó</p>
            </div>
            <Link
              to="/buscador?grano=consultas"
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
            <>
            <div className="overflow-x-auto">
              <div className="min-w-[820px]">
                <div className="grid grid-cols-[1.3fr_0.9fr_0.75fr_0.9fr_1fr_1.6fr] gap-2 border-y border-line bg-app/60 px-6 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-faint">
                  <span>Empresa</span>
                  <span>Código</span>
                  <span>Fecha</span>
                  <span className="text-right">Total</span>
                  <span className="text-right">Estado</span>
                  <span className="text-right">Acciones</span>
                </div>
                {visibles.map((c, i) => (
                  <div
                    key={c.id}
                    className={`grid grid-cols-[1.3fr_0.9fr_0.75fr_0.9fr_1fr_1.6fr] items-center gap-2 px-6 py-[13px] transition-colors hover:bg-app ${
                      i < visibles.length - 1 ? 'border-b border-line' : ''
                    }`}
                  >
                    <span className="truncate text-[13px] font-semibold text-ink">{c.empresa}</span>
                    {/* Un borrador no tiene número; una importada del sistema viejo tampoco. */}
                    <span className="flex min-w-0 flex-col">
                      {c.numero_con_revision ? (
                        <span className="truncate text-[12.5px] font-semibold tabular-nums text-slate-600">
                          {c.numero_con_revision}
                        </span>
                      ) : (
                        <span className="text-[12px] text-faint">{c.emitida || c.tipo === 'Observacion' ? '—' : 'sin emitir'}</span>
                      )}
                      {/* El número con el que el cliente pidió (portal, licitación, orden de compra). */}
                      {c.solicitud_nro && (
                        <span className="truncate text-[11px] text-faint" title="N° de pedido del cliente">
                          Pedido {c.solicitud_nro}
                        </span>
                      )}
                    </span>
                    <span className="text-[13px] text-muted">{fmtFecha(c.fecha)}</span>
                    <span className="text-right text-[13px] font-semibold text-ink">
                      {plata(c.total)}
                    </span>
                    <span className="flex justify-end">
                      <EstadoPill estado={c.estado} emitida={c.emitida} tipo={c.tipo} compra={c.compra} />
                    </span>
                    {/* Dos botones claros: a la empresa o a la cotización. Con
                        borradores de por medio, el nombre solo no alcanza. */}
                    <span className="flex items-center justify-end gap-1.5">
                      <Link
                        to={`/empresas/${c.empresa_id}`}
                        className="rounded-[7px] border border-line-strong bg-white px-2.5 py-1 text-[11.5px] font-semibold text-slate-700 transition-colors hover:bg-app"
                      >
                        Ir a empresa
                      </Link>
                      <Link
                        to={`/consultas/${c.id}`}
                        className="rounded-[7px] border border-brand-200 bg-[#f3f9fe] px-2.5 py-1 text-[11.5px] font-semibold text-brand-600 transition-colors hover:bg-brand-50"
                      >
                        Ir a cotización
                      </Link>
                    </span>
                  </div>
                ))}
              </div>
            </div>
            {paginas > 1 && (
              <div className="flex items-center justify-between gap-3 border-t border-line px-6 py-3">
                <span className="text-[12px] text-faint">
                  Página {paginaActual} de {paginas}
                </span>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => setPagina((p) => Math.max(1, p - 1))}
                    disabled={paginaActual <= 1}
                    className="rounded-[7px] border border-line-strong bg-white px-3 py-1.5 text-[12px] font-semibold text-slate-700 transition-colors hover:bg-app disabled:cursor-not-allowed disabled:opacity-40"
                  >
                    Anteriores
                  </button>
                  <button
                    type="button"
                    onClick={() => setPagina((p) => Math.min(paginas, p + 1))}
                    disabled={paginaActual >= paginas}
                    className="rounded-[7px] border border-line-strong bg-white px-3 py-1.5 text-[12px] font-semibold text-slate-700 transition-colors hover:bg-app disabled:cursor-not-allowed disabled:opacity-40"
                  >
                    Siguientes
                  </button>
                </div>
              </div>
            )}
            </>
          )}
        </div>

        {/* columna derecha */}
        <div className="flex flex-col gap-5">
          {/* Borradores: lo que quedó a medias, para abrirlos todos de una. */}
          <Link
            to="/buscador?grano=consultas&estado=Sin%20emitir"
            className="group rounded-card border border-line bg-white p-6 shadow-[var(--shadow-card)] transition-colors hover:border-brand-200 hover:bg-brand-50"
          >
            <div className="flex items-start justify-between">
              <div>
                <p className="text-[13px] font-medium text-muted">Borradores sin emitir</p>
                <p className="mt-1 text-[26px] font-bold text-ink">{totales.borradores}</p>
              </div>
              <span className="grid h-10 w-10 place-items-center rounded-[10px] bg-[#f1f5f9] text-[#64748b]">
                <FileText size={20} strokeWidth={2} />
              </span>
            </div>
            <span className="mt-2 inline-flex items-center gap-1 text-[12.5px] font-semibold text-brand-600">
              Ver todos los borradores
              <ArrowRight size={13} strokeWidth={2.4} className="transition-transform group-hover:translate-x-0.5" />
            </span>
          </Link>

          {/* Lo vendido: tiene su acceso, igual que los borradores. */}
          <Link
            to="/buscador?grano=consultas&estado=Vendida"
            className="group rounded-card border border-line bg-white p-6 shadow-[var(--shadow-card)] transition-colors hover:border-brand-200 hover:bg-brand-50"
          >
            <div className="flex items-start justify-between">
              <div>
                <p className="text-[13px] font-medium text-muted">Vendidas</p>
                <p className="mt-1 text-[26px] font-bold text-ink">{totales.vendidas}</p>
              </div>
              <span className="grid h-10 w-10 place-items-center rounded-[10px] bg-success-bg text-success-ink">
                <CheckCircle2 size={20} strokeWidth={2} />
              </span>
            </div>
            <span className="mt-2 inline-flex items-center gap-1 text-[12.5px] font-semibold text-brand-600">
              Ver todo lo que se vendió
              <ArrowRight size={13} strokeWidth={2.4} className="transition-transform group-hover:translate-x-0.5" />
            </span>
          </Link>

          {/* Papelera: solo para administradores, que son los que descartan. */}
          {esAdmin && (
          <Link
            to="/consultas/eliminados"
            className="group rounded-card border border-line bg-white p-6 shadow-[var(--shadow-card)] transition-colors hover:border-brand-200 hover:bg-brand-50"
          >
            <div className="flex items-start justify-between">
              <div>
                <p className="text-[13px] font-medium text-muted">Borradores eliminados</p>
                <p className="mt-1 text-[26px] font-bold text-ink">{totales.borradores_eliminados}</p>
              </div>
              <span className="grid h-10 w-10 place-items-center rounded-[10px] bg-[#fbeaea] text-[#b4472a]">
                <Trash2 size={20} strokeWidth={2} />
              </span>
            </div>
            <span className="mt-2 inline-flex items-center gap-1 text-[12.5px] font-semibold text-brand-600">
              Ver la papelera y restaurar
              <ArrowRight size={13} strokeWidth={2.4} className="transition-transform group-hover:translate-x-0.5" />
            </span>
          </Link>
          )}

          <AccesosRapidos />

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

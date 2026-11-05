import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { motion } from 'motion/react'
import {
  Search,
  Users,
  ListFilter,
  CalendarRange,
  FileSearch,
  Printer,
  Plus,
  ArrowRight,
} from 'lucide-react'
import { Accion, Card, Chip, PageHeader } from '../../components/ui'
import { useUltimasVistas } from '../../lib/indice'

/**
 * El menú del Índice Telefónico.
 *
 * Arriba, un buscador único que encuentra por nombre, teléfono, CUIT o material.
 * Las opciones de abajo son entradas directas al Buscador, que reemplazó a los
 * cuatro buscadores de antes (Cons./Modif. Registros, Busq. Registros p/Cond.,
 * Consultas por fecha y Busq. Consultas p/Cond.).
 */

const OPCIONES = [
  {
    to: '/buscador',
    icono: Users,
    titulo: 'Buscar empresas',
    detalle: 'Entrar a una ficha, a sus cotizaciones o imprimir. Por nombre, teléfono, CUIT o material.',
  },
  {
    to: '/buscador?grano=consultas',
    icono: FileSearch,
    titulo: 'Buscar cotizaciones',
    detalle: 'Por número, empresa, fecha, estado, orden de compra o compra parcial.',
  },
  {
    to: '/buscador?grano=renglones',
    icono: ListFilter,
    titulo: 'A quién le cotizamos un material',
    detalle: 'Escribí el material y mirá a qué empresas se lo cotizamos, cuándo y a cuánto.',
  },
  {
    to: '/buscador?grano=comprobantes',
    icono: CalendarRange,
    titulo: 'Facturas y remitos',
    detalle: 'Buscar por número, tipo o fecha, y ver de qué cotización salieron.',
  },
  {
    to: '/imprimir',
    icono: Printer,
    titulo: 'Imprimir Indice / Cons.',
    detalle: 'Preparar la hoja que recibe el cliente y elegir con qué datos sale.',
  },
]

export default function IndiceMenu() {
  const [texto, setTexto] = useState('')
  const navigate = useNavigate()
  const ultimas = useUltimasVistas()

  function buscar(e: React.FormEvent) {
    e.preventDefault()
    const q = texto.trim()
    navigate(q ? `/buscador?q=${encodeURIComponent(q)}` : '/buscador')
  }

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb="Empresas"
        titulo="Indice Telefonico"
        bajada="Los cuatro buscadores de antes ahora son uno solo, el Buscador. Desde acá se entra directo a lo que se busca más seguido."
        acciones={
          <Link to="/empresas/nueva">
            <span className="inline-flex h-[38px] items-center gap-2 rounded-lg bg-brand px-[15px] text-[13px] font-semibold text-white transition-colors hover:bg-brand-600">
              <Plus size={16} strokeWidth={2.4} />
              Nueva empresa
            </span>
          </Link>
        }
      />

      {/* Buscador único */}
      <Card className="p-[22px]">
        <form onSubmit={buscar} className="flex flex-col gap-3">
          <label htmlFor="buscador" className="text-[10.5px] font-medium text-slate-500">
            Buscar
          </label>
          <div className="flex flex-wrap items-center gap-3">
            <div className="relative min-w-[280px] flex-1">
              <Search
                size={17}
                strokeWidth={2}
                className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-faint"
              />
              <input
                id="buscador"
                value={texto}
                onChange={(e) => setTexto(e.target.value)}
                placeholder="Nombre de la empresa, teléfono, CUIT o material"
                className="h-11 w-full rounded-lg border border-line-strong pl-10 pr-3.5 text-[14px] text-ink outline-none transition-shadow placeholder:text-faint focus:border-brand focus:ring-4 focus:ring-brand-50"
              />
            </div>
            <button
              type="submit"
              className="inline-flex h-11 items-center gap-2 rounded-lg bg-brand px-5 text-[13.5px] font-semibold text-white transition-colors hover:bg-brand-600"
            >
              Buscar
            </button>
          </div>
          <p className="text-[11.5px] text-faint">
            Un solo buscador para todo: reemplaza a la búsqueda por teléfono, que era una pantalla aparte.
          </p>
        </form>
      </Card>

      {/* Entradas directas al Buscador */}
      <div className="grid gap-[14px] sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5">
        {OPCIONES.map((op, i) => {
          const Icono = op.icono

          return (
            <motion.div
              key={op.to}
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.28, delay: i * 0.04, ease: 'easeOut' }}
            >
              <Link to={op.to} className="group block h-full">
                <Card className="flex h-full flex-col gap-3 p-[18px] transition-shadow hover:shadow-pop">
                  <span className="grid h-10 w-10 place-items-center rounded-[10px] bg-brand-50 text-brand-600 transition-colors group-hover:bg-brand group-hover:text-white">
                    <Icono size={19} strokeWidth={2} />
                  </span>
                  <div className="flex flex-1 flex-col gap-1.5">
                    <h2 className="text-[14px] font-bold leading-snug text-ink">{op.titulo}</h2>
                    <p className="text-[11.5px] leading-relaxed text-muted">{op.detalle}</p>
                  </div>
                  <span className="inline-flex items-center gap-1.5 text-[11.5px] font-semibold text-brand-600">
                    Entrar
                    <ArrowRight
                      size={13}
                      strokeWidth={2.4}
                      className="transition-transform group-hover:translate-x-0.5"
                    />
                  </span>
                </Card>
              </Link>
            </motion.div>
          )
        })}
      </div>

      {/* Últimas empresas vistas */}
      <Card className="overflow-hidden">
        <div className="flex flex-wrap items-center gap-2.5 px-[22px] pb-3.5 pt-[17px]">
          <h2 className="text-[15px] font-semibold text-ink">Ultimas empresas vistas</h2>
          <span className="ml-auto text-[11px] text-faint">
            Para volver rápido a lo que se estaba mirando
          </span>
        </div>

        {ultimas.length === 0 ? (
          <div className="border-t border-[#eef2f6] px-[22px] py-8 text-center text-[12.5px] text-muted">
            Todavía no entraste a ninguna ficha. Buscá una empresa arriba y va a quedar acá.
          </div>
        ) : (
          <ul className="border-t border-[#eef2f6]">
            {ultimas.map((e) => (
              <li
                key={e.id}
                className="flex flex-wrap items-center gap-3 border-b border-[#eef2f6] px-[22px] py-3 last:border-b-0"
              >
                <Link
                  to={`/empresas/${e.id}`}
                  className="min-w-0 flex-1 text-[12.5px] font-semibold text-ink hover:text-brand-600"
                >
                  {e.nombre}
                </Link>
                <div className="flex items-center gap-1.5">
                  {e.relaciones.map((r) => (
                    <Chip key={r} tono={r === 'Cliente' ? 'verde' : r === 'Proveedor' ? 'brand' : 'neutro'}>
                      {r}
                    </Chip>
                  ))}
                </div>
                <span className="w-32 text-[11.5px] text-muted">{e.localidad ?? '—'}</span>
                <div className="flex items-center gap-3.5">
                  <Accion to={`/empresas/${e.id}`}>Ver ficha</Accion>
                  <Accion to={`/empresas/${e.id}#cotizaciones`}>Cotizaciones</Accion>
                  <Accion to={`/imprimir?empresa=${e.id}`}>Imprimir</Accion>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}

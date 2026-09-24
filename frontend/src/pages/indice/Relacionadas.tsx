import { Link } from 'react-router-dom'
import { ArrowUpRight, GitBranch } from 'lucide-react'
import { Card, CardHeader, Chip, NotaPie } from '../../components/ui'
import { fecha as fmtFecha, plata, traerRelacionadas, useCarga } from '../../lib/indice'
import type { ConsultaHermana } from '../../types/indice'

/**
 * Cotizaciones relacionadas por cómo se generó ésta.
 *
 * No es por parecido ni por inteligencia: es porque una salió de la otra.
 * Si ayer le cotizamos a A y hoy tomamos esa para cotizarle a B y a C, en las
 * tres figuran las otras dos.
 */
export default function Relacionadas({ consultaId }: { consultaId: number }) {
  const { datos, cargando } = useCarga(() => traerRelacionadas(consultaId), [consultaId])

  if (cargando || !datos) return null

  const { madre, hermanas, hijas } = datos
  const total = (madre ? 1 : 0) + hermanas.length + hijas.length

  if (total === 0) return null

  return (
    <Card className="overflow-hidden">
      <CardHeader
        titulo="Cotizaciones relacionadas"
        cuenta={total}
        chips={<Chip tono="brand">por como se genero</Chip>}
        ayuda="No es por parecido: estas cotizaciones están relacionadas porque una salió de la otra."
      />

      <div className="flex flex-col gap-4 border-t border-[#eef2f6] px-[22px] py-4">
        {madre && (
          <Grupo
            titulo="Esta cotizacion salio de"
            detalle="Se tomó como base para armar la de acá."
            icono={<ArrowUpRight size={14} strokeWidth={2.2} className="rotate-180" />}
            tono="ambar"
            items={[madre]}
          />
        )}

        {hermanas.length > 0 && (
          <Grupo
            titulo="Salieron de la misma"
            detalle={`Se armaron el mismo día a partir de ${madre?.empresa ?? 'la misma cotización'}.`}
            icono={<GitBranch size={14} strokeWidth={2.2} />}
            tono="neutro"
            items={hermanas}
          />
        )}

        {hijas.length > 0 && (
          <Grupo
            titulo="Se uso como base para"
            detalle="Estas cotizaciones se armaron tomando ésta como modelo."
            icono={<ArrowUpRight size={14} strokeWidth={2.2} />}
            tono="verde"
            items={hijas}
          />
        )}
      </div>

      <NotaPie>
        Cada cotización copiada guarda de cuál salió. Si después hay que revisar por qué a uno se le
        puso otro precio, se abre la de al lado y se compara.
      </NotaPie>
    </Card>
  )
}

function Grupo({
  titulo,
  detalle,
  icono,
  tono,
  items,
}: {
  titulo: string
  detalle: string
  icono: React.ReactNode
  tono: 'ambar' | 'verde' | 'neutro'
  items: ConsultaHermana[]
}) {
  const estilos = {
    ambar: 'border-[#f3d9a6] bg-[#fffdf7] text-warning-ink',
    verde: 'border-[#cdebd8] bg-[#f4fbf6] text-success-ink',
    neutro: 'border-line bg-app text-slate-600',
  }[tono]

  return (
    <div className={`rounded-[10px] border p-3.5 ${estilos}`}>
      <div className="mb-2.5 flex flex-wrap items-center gap-2">
        {icono}
        <span className="text-[12.5px] font-bold">{titulo}</span>
        <span className="text-[11px] opacity-80">{detalle}</span>
      </div>

      <ul className="flex flex-col gap-1.5">
        {items.map((c) => (
          <li key={c.id}>
            <Link
              to={`/consultas/${c.id}`}
              className="flex flex-wrap items-center gap-3 rounded-lg border border-line bg-white px-3 py-2 transition-colors hover:border-brand-200"
            >
              <span className="min-w-0 flex-1 text-[12.5px] font-semibold text-ink">
                {c.empresa}
              </span>
              <span className="text-[11.5px] text-muted">{fmtFecha(c.fecha)}</span>
              <span className="text-[11.5px] text-muted">
                {c.lineas} {c.lineas === 1 ? 'linea' : 'lineas'}
              </span>
              <span className="w-24 text-right text-[12px] font-semibold text-ink">
                {c.total > 0 ? plata(c.total) : <span className="text-faint">sin precios</span>}
              </span>
              <Chip
                tono={
                  c.estado === 'Vendida' ? 'verde' : c.estado === 'Borrador' ? 'neutro' : 'brand'
                }
              >
                {c.estado}
              </Chip>
              <span className="w-10 text-right text-[11px] text-brand-600">{c.quien}</span>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  )
}

import { Link, useLocation } from 'react-router-dom'
import { Layers } from 'lucide-react'
import { NAV_ROUTES } from '../data/navigation'

export default function Placeholder() {
  const { pathname } = useLocation()
  const match = NAV_ROUTES.find((r) => r.to === pathname)
  const label = match?.label ?? 'Sección'
  const parent = match?.parent

  return (
    <div className="flex flex-col gap-6">
      <div>
        <p className="text-[12px] font-medium text-faint">{parent ? `${parent} · ` : ''}CORDES</p>
        <h1 className="mt-0.5 text-[24px] font-bold tracking-tight text-ink">{label}</h1>
      </div>

      <div className="grid place-items-center rounded-card border border-dashed border-line-strong bg-white px-8 py-20 text-center shadow-[var(--shadow-card)]">
        <span className="grid h-14 w-14 place-items-center rounded-2xl bg-brand-50 text-brand">
          <Layers size={26} strokeWidth={2} />
        </span>
        <h2 className="mt-5 text-[18px] font-semibold text-ink">Pantalla en construcción</h2>
        <p className="mt-2 max-w-md text-[14px] leading-relaxed text-muted">
          El diseño de <span className="font-semibold text-ink">{label}</span> ya está en Figma. Esta
          es la siguiente pantalla a implementar pixel-perfect sobre la API.
        </p>
        <Link
          to="/dashboard"
          className="mt-6 inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2.5 text-[13.5px] font-semibold text-white transition-colors hover:bg-brand-600"
        >
          Volver al panel
        </Link>
      </div>
    </div>
  )
}

import type { ReactNode, HTMLAttributes, ButtonHTMLAttributes } from 'react'
import { Link } from 'react-router-dom'

/* ---------------------------------------------------------------------------
   Primitivas de UI del Índice Telefónico.

   Los tamaños y colores salen del Figma tal cual: 15px semibold para el título
   de una sección, 10.5px para las etiquetas, 12.5px para los datos. Tenerlos
   acá evita que cada pantalla invente su propio espaciado.
--------------------------------------------------------------------------- */

function cx(...parts: (string | false | null | undefined)[]) {
  return parts.filter(Boolean).join(' ')
}

/* ------------------------------------------------------------------ tarjeta */

export function Card({
  className,
  children,
  ...rest
}: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cx(
        'rounded-card border border-line bg-white shadow-card',
        className,
      )}
      {...rest}
    >
      {children}
    </div>
  )
}

/** Encabezado de una sección dentro de una tarjeta. */
export function CardHeader({
  titulo,
  cuenta,
  ayuda,
  acciones,
  chips,
  className,
}: {
  titulo: string
  cuenta?: number | string
  ayuda?: string
  acciones?: ReactNode
  chips?: ReactNode
  className?: string
}) {
  return (
    <div className={cx('flex flex-col gap-1.5 px-[22px] pb-3.5 pt-[17px]', className)}>
      <div className="flex flex-wrap items-center gap-2.5">
        <h2 className="text-[15px] font-semibold text-ink">{titulo}</h2>
        {cuenta !== undefined && (
          <span className="rounded-full bg-slate-100 px-2 py-[3px] text-[10.5px] font-semibold text-slate-600">
            {cuenta}
          </span>
        )}
        {chips}
        <div className="ml-auto flex items-center gap-2.5">{acciones}</div>
      </div>
      {ayuda && <p className="text-[11.5px] leading-relaxed text-faint">{ayuda}</p>}
    </div>
  )
}

/* --------------------------------------------------------------------- chip */

type ChipTono = 'neutro' | 'brand' | 'verde' | 'ambar' | 'violeta' | 'rojo'

const TONOS: Record<ChipTono, string> = {
  neutro: 'bg-slate-100 text-slate-600',
  brand: 'bg-brand-50 text-brand-600',
  verde: 'bg-success-bg text-success-ink',
  ambar: 'bg-warning-bg text-warning-ink',
  violeta: 'bg-[#f3eefe] text-[#6d28d9]',
  rojo: 'bg-[#fef6f6] text-[#b91c1c]',
}

export function Chip({
  children,
  tono = 'neutro',
  className,
}: {
  children: ReactNode
  tono?: ChipTono
  className?: string
}) {
  return (
    <span
      className={cx(
        'inline-flex shrink-0 items-center rounded-full px-2.5 py-[3px] text-[10.5px] font-semibold whitespace-nowrap',
        TONOS[tono],
        className,
      )}
    >
      {children}
    </span>
  )
}

/* ------------------------------------------------------------------- botón */

type BotonVariante = 'primario' | 'suave' | 'texto' | 'peligro'

export function Boton({
  variante = 'suave',
  className,
  children,
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { variante?: BotonVariante }) {
  const base =
    'inline-flex items-center justify-center gap-2 rounded-lg text-[13px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60'
  const estilos: Record<BotonVariante, string> = {
    primario: 'h-[38px] bg-brand px-[15px] text-white hover:bg-brand-600',
    suave: 'h-[38px] border border-line-strong bg-white px-[15px] text-slate-700 hover:bg-app',
    texto: 'text-brand-600 hover:text-brand-700',
    // Para lo que no se puede deshacer: tiene que verse distinto de un guardar.
    peligro: 'h-[38px] bg-danger px-[15px] text-white hover:brightness-95',
  }

  return (
    <button className={cx(base, estilos[variante], className)} {...rest}>
      {children}
    </button>
  )
}

/** Un enlace que se ve como acción secundaria dentro de una fila. */
export function Accion({
  to,
  onClick,
  children,
  apagado,
}: {
  to?: string
  onClick?: () => void
  children: ReactNode
  apagado?: boolean
}) {
  const clase = cx(
    'text-[11.5px] font-semibold whitespace-nowrap',
    apagado ? 'text-faint hover:text-muted' : 'text-brand-600 hover:text-brand-700',
  )

  if (to) {
    return (
      <Link to={to} className={clase}>
        {children}
      </Link>
    )
  }

  return (
    <button type="button" onClick={onClick} className={clase}>
      {children}
    </button>
  )
}

/* -------------------------------------------------------------------- campo */

/** Etiqueta chica arriba y el dato abajo, como en la ficha. */
export function Campo({
  etiqueta,
  valor,
  tag,
  destacado,
  className,
}: {
  etiqueta: string
  valor?: ReactNode
  tag?: ReactNode
  destacado?: boolean
  className?: string
}) {
  const vacio = valor === null || valor === undefined || valor === ''

  return (
    <div className={cx('flex min-w-0 flex-col gap-1.5', className)}>
      <div className="flex items-center gap-1.5">
        <span className={cx('text-[10.5px] font-medium', destacado ? 'text-brand-600' : 'text-slate-500')}>
          {etiqueta}
        </span>
        {tag}
      </div>
      <div
        className={cx(
          'flex min-h-[36px] items-center rounded-[7px] border px-[11px] py-2 text-[12.5px]',
          destacado
            ? 'border-brand-200 bg-[#f3f9fe] font-semibold text-ink'
            : 'border-line-strong bg-white text-ink',
        )}
      >
        {/* Si no tenemos el dato, queda en blanco. No se inventa nada. */}
        {vacio ? <span className="text-faint">—</span> : valor}
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------- tabla */

export function Tabla({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={cx('w-full overflow-x-auto', className)}>
      <table className="w-full border-collapse text-left">{children}</table>
    </div>
  )
}

export function Th({
  children,
  className,
  ancho,
  derecha,
}: {
  children?: ReactNode
  className?: string
  ancho?: string
  derecha?: boolean
}) {
  return (
    <th
      style={ancho ? { width: ancho } : undefined}
      className={cx(
        'border-y border-[#eef2f6] bg-[#f8fafc] px-3 py-2.5 text-[10.5px] font-semibold text-slate-500 first:pl-[22px] last:pr-[22px]',
        derecha && 'text-right',
        className,
      )}
    >
      {children}
    </th>
  )
}

export function Td({
  children,
  className,
  derecha,
  colSpan,
}: {
  children?: ReactNode
  className?: string
  derecha?: boolean
  colSpan?: number
}) {
  return (
    <td
      colSpan={colSpan}
      className={cx(
        'border-b border-[#eef2f6] px-3 py-3 align-middle text-[12px] text-slate-700 first:pl-[22px] last:pr-[22px]',
        derecha && 'text-right',
        className,
      )}
    >
      {children}
    </td>
  )
}

/** Pie de una tarjeta con una aclaración. */
export function NotaPie({ children }: { children: ReactNode }) {
  return (
    <div className="border-t border-[#eef2f6] bg-[#f8fafc] px-[22px] py-3 text-[11.5px] leading-relaxed text-muted">
      {children}
    </div>
  )
}

/* --------------------------------------------------------- avisos de color */

type AvisoTono = 'info' | 'ambar' | 'verde' | 'violeta'

const AVISOS: Record<AvisoTono, string> = {
  info: 'border-brand-200 bg-[#f3f9fe] text-brand-600',
  ambar: 'border-[#f3d9a6] bg-[#fff8ee] text-warning-ink',
  verde: 'border-[#cdebd8] bg-[#f4fbf6] text-success-ink',
  violeta: 'border-[#e4dcf7] bg-[#fbfafe] text-[#5b4b78]',
}

export function Aviso({
  children,
  tono = 'info',
  titulo,
  className,
}: {
  children: ReactNode
  tono?: AvisoTono
  titulo?: string
  className?: string
}) {
  return (
    <div
      className={cx(
        'rounded-[9px] border px-3.5 py-2.5 text-[11.5px] leading-relaxed',
        AVISOS[tono],
        className,
      )}
    >
      {titulo && <span className="mr-2 text-[9.5px] font-bold uppercase">{titulo}</span>}
      {children}
    </div>
  )
}

/* ------------------------------------------------------ encabezado de página */

export function PageHeader({
  breadcrumb,
  titulo,
  chips,
  bajada,
  acciones,
}: {
  breadcrumb?: ReactNode
  titulo: string
  chips?: ReactNode
  bajada?: string
  acciones?: ReactNode
}) {
  return (
    <header className="flex flex-wrap items-start gap-4">
      <div className="min-w-0 flex-1">
        {breadcrumb && (
          <div className="mb-1 text-[11px] font-medium text-faint">{breadcrumb}</div>
        )}
        <div className="flex flex-wrap items-center gap-2.5">
          <h1 className="text-[23px] font-bold tracking-tight text-ink">{titulo}</h1>
          {chips}
        </div>
        {bajada && (
          <p className="mt-1 max-w-4xl text-[12.5px] leading-relaxed text-muted">{bajada}</p>
        )}
      </div>
      {acciones && <div className="flex flex-wrap items-center gap-2.5">{acciones}</div>}
    </header>
  )
}

/* ------------------------------------------------------------------ estados */

export function Cargando({ texto = 'Buscando…' }: { texto?: string }) {
  return (
    <div className="flex items-center justify-center gap-3 py-16 text-[13px] text-muted">
      <span className="h-4 w-4 animate-spin rounded-full border-2 border-line-strong border-t-brand" />
      {texto}
    </div>
  )
}

export function SinResultados({
  titulo,
  detalle,
  accion,
}: {
  titulo: string
  detalle?: string
  accion?: ReactNode
}) {
  return (
    <div className="flex flex-col items-center gap-2 px-6 py-16 text-center">
      <p className="text-[14px] font-semibold text-ink">{titulo}</p>
      {detalle && <p className="max-w-md text-[12.5px] leading-relaxed text-muted">{detalle}</p>}
      {accion && <div className="mt-2">{accion}</div>}
    </div>
  )
}

export { cx }

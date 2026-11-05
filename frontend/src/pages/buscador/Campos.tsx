import type { InputHTMLAttributes, ReactNode } from 'react'
import { ChevronDown } from 'lucide-react'
import { Combo, Texto } from '../../components/ui/form'
import { cx } from '../../components/ui'
import { useTextoDiferido } from './useTextoDiferido'

/* ---------------------------------------------------------------------------
   Piezas del buscador: campos que esperan a que se deje de escribir y
   secciones que se abren y se cierran.
--------------------------------------------------------------------------- */

type PropsDeTexto = Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange'> & {
  etiqueta?: string
  ayuda?: string
  valor: string
  alCambiar: (v: string) => void
}

export function TextoDiferido({ valor, alCambiar, ...rest }: PropsDeTexto) {
  const [local, setLocal] = useTextoDiferido(valor, alCambiar)

  return <Texto {...rest} value={local} onChange={(e) => setLocal(e.target.value)} />
}

export function ComboDiferido({
  valor,
  alCambiar,
  opciones,
  id,
  ...rest
}: Omit<PropsDeTexto, 'type'> & { opciones: string[]; id: string }) {
  const [local, setLocal] = useTextoDiferido(valor, alCambiar)

  return <Combo {...rest} id={id} opciones={opciones} value={local} onChange={(e) => setLocal(e.target.value)} />
}

/** Un grupo de filtros que se abre y se cierra, con cuántos hay puestos. */
export function Seccion({
  titulo,
  ayuda,
  cuantos,
  abierta,
  alternar,
  children,
}: {
  titulo: string
  ayuda: string
  cuantos: number
  abierta: boolean
  alternar: () => void
  children: ReactNode
}) {
  return (
    <section className="rounded-[10px] border border-line">
      <button
        type="button"
        onClick={alternar}
        aria-expanded={abierta}
        className="flex w-full items-center gap-2.5 px-4 py-3 text-left"
      >
        <span className="text-[13px] font-semibold text-ink">{titulo}</span>
        {cuantos > 0 && (
          <span className="rounded-full bg-brand px-2 py-[2px] text-[10px] font-bold text-white">{cuantos}</span>
        )}
        <span className="text-[11.5px] text-faint">{ayuda}</span>
        <ChevronDown
          size={16}
          strokeWidth={2.2}
          className={cx('ml-auto shrink-0 text-faint transition-transform', abierta && 'rotate-180')}
        />
      </button>
      {abierta && <div className="grid gap-3 border-t border-line px-4 pb-4 pt-3.5 sm:grid-cols-2 lg:grid-cols-4">{children}</div>}
    </section>
  )
}

import { useLayoutEffect, useRef, useState } from 'react'
import type { ComponentProps } from 'react'
import { Asterisk, Check, Eraser } from 'lucide-react'
import { AreaTexto, Etiqueta } from './ui/form'
import {
  COLORES,
  alternarResaltado,
  juntar,
  separar,
  sinFormato,
  tieneFormato,
  trozos,
  type ColorDeTexto,
} from '../lib/formato'

/**
 * Un texto con sus resaltados, tal como sale en la hoja.
 *
 * No se arma HTML: cada trozo es un elemento de React con su estilo, así que lo
 * escrito nunca se interpreta como código. La regla es la del servidor
 * (lib/formato.ts).
 */
export function TextoConFormato({ texto, className }: { texto: string; className?: string }) {
  return (
    <span className={className ? `whitespace-pre-wrap ${className}` : 'whitespace-pre-wrap'}>
      {trozos(texto).map((t, i) => (
        <span
          key={i}
          style={{
            color: t.color ? COLORES[t.color] : undefined,
            fontWeight: t.negrita ? 700 : undefined,
          }}
        >
          {t.texto}
        </span>
      ))}
    </span>
  )
}

const NEGRO = '#0f172a'

const OPCIONES: { clave: ColorDeTexto | null; nombre: string; hex: string }[] = [
  { clave: null, nombre: 'Negro', hex: NEGRO },
  ...(Object.keys(COLORES) as ColorDeTexto[]).map((c) => ({
    clave: c,
    nombre: c.charAt(0).toUpperCase() + c.slice(1),
    hex: COLORES[c],
  })),
]

const BOTON =
  'inline-flex h-[30px] items-center justify-center gap-1.5 rounded-[6px] border border-line bg-white px-2.5 text-[12.5px] font-medium text-ink transition-colors hover:border-brand-200 disabled:cursor-not-allowed disabled:opacity-40'

type Props = Omit<ComponentProps<typeof AreaTexto>, 'value' | 'onChange'> & {
  /** El texto guardado: con el color elegido al principio, si hay. */
  value: string
  /** Recibe el texto nuevo, listo para guardar. */
  onTexto: (valor: string) => void
  /**
   * 'siempre': la barra está a la vista. 'al-tocar': aparece solo mientras se
   * escribe en este campo (para listas con varios, como las condiciones).
   */
  barra?: 'siempre' | 'al-tocar'
}

/**
 * Un campo de texto largo donde se puede resaltar.
 *
 * Lo que se pone entre asteriscos —*así*— sale en negrita, como en WhatsApp. Se
 * puede escribir a mano o seleccionar el texto y tocar "Resaltar". El color se
 * elige una vez y vale para todo lo resaltado de ese texto. Debajo se ve cómo
 * sale en la hoja.
 *
 * El campo muestra solo el texto: el color se guarda al principio, pero acá no
 * se ve (ver lib/formato.ts). Un texto sin asteriscos sigue siendo el de siempre.
 */
export function AreaConFormato({ value, onTexto, etiqueta, ayuda, barra = 'siempre', ...rest }: Props) {
  const caja = useRef<HTMLDivElement>(null)
  const reseleccionar = useRef<{ desde: number; hasta: number } | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)
  const { color, cuerpo } = separar(value)

  function resaltar() {
    const area = caja.current?.querySelector('textarea')

    if (!area) return

    const r = alternarResaltado(cuerpo, area.selectionStart, area.selectionEnd)

    if (!r) {
      setAviso('Primero seleccioná con el mouse el texto que querés resaltar, o escribilo entre asteriscos: *así*')

      return
    }

    setAviso(null)
    // Queda seleccionado lo mismo (ver el efecto de abajo).
    reseleccionar.current = { desde: r.desde, hasta: r.hasta }
    onTexto(juntar(color, r.texto))
  }

  /*
    Al cambiar el texto el navegador manda el cursor al final: se vuelve a dejar
    seleccionado lo mismo. Va en un efecto que corre apenas React escribe el
    texto, y no en un requestAnimationFrame: ése espera al próximo cuadro, y si
    se tocan dos botones seguidos el segundo se encontraba sin selección.
  */
  useLayoutEffect(() => {
    const marcado = reseleccionar.current
    const area = caja.current?.querySelector('textarea')

    if (!marcado || !area) return

    reseleccionar.current = null
    area.focus()
    area.setSelectionRange(marcado.desde, marcado.hasta)
  }, [value])

  // Los botones no le sacan el foco al texto: si no, se pierde la selección.
  const sinPerderLaSeleccion = (e: React.MouseEvent) => e.preventDefault()
  const elegido = OPCIONES.find((o) => o.clave === color) ?? OPCIONES[0]

  return (
    <div ref={caja} className="group flex min-w-0 flex-col">
      {etiqueta && <Etiqueta ayuda={ayuda}>{etiqueta}</Etiqueta>}

      <AreaTexto
        {...rest}
        value={cuerpo}
        onChange={(e) => onTexto(juntar(color, e.target.value))}
      />

      {/*
        Debajo del campo y no arriba: si apareciera arriba, al tocar el campo se
        correría hacia abajo y se arruinaría la selección. Los botones no le
        sacan el foco al texto, así que mientras se usan la barra sigue a la vista.
      */}
      <div
        className={`mt-1.5 flex-wrap items-center gap-x-3 gap-y-1.5 ${
          barra === 'al-tocar' ? 'hidden group-focus-within:flex' : 'flex'
        }`}
      >
        <button
          type="button"
          aria-label="Resaltar lo seleccionado"
          title="Selecciona el texto y toca acá: queda entre asteriscos y sale en negrita"
          onMouseDown={sinPerderLaSeleccion}
          onClick={resaltar}
          className={BOTON}
        >
          <Asterisk size={14} strokeWidth={2.4} />
          Resaltar
        </button>

        <div className="flex items-center gap-1.5">
          <span className="text-[11.5px] text-muted">Color del resaltado:</span>
          {OPCIONES.map((o) => (
            <button
              key={o.nombre}
              type="button"
              aria-label={`Color ${o.nombre.toLowerCase()}`}
              aria-pressed={o.clave === color}
              title={cuerpo ? o.nombre : 'Escribí el texto primero'}
              disabled={cuerpo === ''}
              onMouseDown={sinPerderLaSeleccion}
              onClick={() => onTexto(juntar(o.clave, cuerpo))}
              className="grid size-[24px] place-items-center rounded-full border-2 transition-transform hover:scale-110 disabled:cursor-not-allowed disabled:opacity-40"
              style={{
                background: o.hex,
                borderColor: o.clave === color ? '#fff' : 'transparent',
                boxShadow: o.clave === color ? `0 0 0 2px ${o.hex}` : undefined,
              }}
            >
              {o.clave === color && <Check size={12} strokeWidth={3.5} className="text-white" />}
            </button>
          ))}
          <span className="text-[11.5px] font-semibold text-ink">{elegido.nombre}</span>
        </div>

        <button
          type="button"
          aria-label="Sacar los resaltados"
          title="Sacar todos los resaltados de este texto"
          disabled={!tieneFormato(value) && color === null}
          onMouseDown={sinPerderLaSeleccion}
          onClick={() => {
            setAviso(null)
            onTexto(sinFormato(value))
          }}
          className={BOTON}
        >
          <Eraser size={13} strokeWidth={2} />
          Sacar
        </button>

        {aviso ? (
          <span className="basis-full text-[11.5px] text-warning-ink">{aviso}</span>
        ) : (
          <span className="basis-full text-[11px] text-faint">
            Lo que va entre asteriscos sale en negrita: *así*
          </span>
        )}
      </div>

      {tieneFormato(value) && (
        <div className="mt-1.5 rounded-[7px] border border-line bg-soft px-2.5 py-2 text-[12px] leading-relaxed text-ink">
          <span className="mr-2 text-[9.5px] font-bold uppercase tracking-wide text-faint">Así sale</span>
          <TextoConFormato texto={value} />
        </div>
      )}
    </div>
  )
}

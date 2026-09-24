import type { ReactNode, InputHTMLAttributes, TextareaHTMLAttributes, SelectHTMLAttributes } from 'react'
import { useEffect } from 'react'
import { createPortal } from 'react-dom'
import { motion, AnimatePresence } from 'motion/react'
import { Check, X } from 'lucide-react'
import { cx, Boton } from './index'

/* ---------------------------------------------------------------------------
   Campos de formulario y ventana modal.
   Mismos tamaños que los campos de sólo lectura, para que al pasar a
   modificar la pantalla no se mueva.
--------------------------------------------------------------------------- */

const BASE_CAMPO =
  'w-full rounded-[7px] border border-line-strong bg-white px-[11px] text-[12.5px] text-ink outline-none transition-shadow placeholder:text-faint focus:border-brand focus:ring-4 focus:ring-brand-50 disabled:bg-app disabled:text-muted'

/**
 * La etiqueta de un campo.
 *
 * Altura minima fija: una etiqueta que ocupa dos renglones empujaba su campo
 * mas abajo que el de al lado y la fila quedaba despareja.
 */
export function Etiqueta({
  children,
  ayuda,
  obligatorio,
}: {
  children: ReactNode
  ayuda?: string
  obligatorio?: boolean
}) {
  return (
    <div className="mb-1.5 flex min-h-[16px] items-center gap-1.5">
      <span className="text-[10.5px] font-medium text-slate-500">
        {children}
        {obligatorio && <span className="ml-0.5 text-danger">*</span>}
      </span>
      {ayuda && <span className="text-[10px] text-faint">{ayuda}</span>}
    </div>
  )
}

export function Texto({
  etiqueta,
  ayuda,
  error,
  obligatorio,
  className,
  ...rest
}: InputHTMLAttributes<HTMLInputElement> & {
  etiqueta?: string
  ayuda?: string
  error?: string
  obligatorio?: boolean
}) {
  return (
    <div className={cx('flex min-w-0 flex-col', className)}>
      {etiqueta && (
        <Etiqueta ayuda={ayuda} obligatorio={obligatorio}>
          {etiqueta}
        </Etiqueta>
      )}
      <input className={cx(BASE_CAMPO, 'h-[36px]', error && 'border-danger')} {...rest} />
      {error && <span className="mt-1 text-[10.5px] text-danger">{error}</span>}
    </div>
  )
}

export function AreaTexto({
  etiqueta,
  ayuda,
  error,
  className,
  filas = 3,
  ...rest
}: TextareaHTMLAttributes<HTMLTextAreaElement> & {
  etiqueta?: string
  ayuda?: string
  error?: string
  filas?: number
}) {
  return (
    <div className={cx('flex min-w-0 flex-col', className)}>
      {etiqueta && <Etiqueta ayuda={ayuda}>{etiqueta}</Etiqueta>}
      <textarea
        rows={filas}
        className={cx(BASE_CAMPO, 'resize-y py-2 leading-relaxed', error && 'border-danger')}
        {...rest}
      />
      {error && <span className="mt-1 text-[10.5px] text-danger">{error}</span>}
    </div>
  )
}

export function Lista({
  etiqueta,
  ayuda,
  error,
  opciones,
  vacio = 'Sin elegir',
  className,
  ...rest
}: SelectHTMLAttributes<HTMLSelectElement> & {
  etiqueta?: string
  ayuda?: string
  error?: string
  vacio?: string
  opciones: { valor: string | number; texto: string }[]
}) {
  return (
    <div className={cx('flex min-w-0 flex-col', className)}>
      {etiqueta && <Etiqueta ayuda={ayuda}>{etiqueta}</Etiqueta>}
      <select className={cx(BASE_CAMPO, 'h-[36px]', error && 'border-danger')} {...rest}>
        <option value="">{vacio}</option>
        {opciones.map((o) => (
          <option key={o.valor} value={o.valor}>
            {o.texto}
          </option>
        ))}
      </select>
      {error && <span className="mt-1 text-[10.5px] text-danger">{error}</span>}
    </div>
  )
}

/**
 * Una lista de la que ademas se puede escribir algo que no figura.
 *
 * Se usa donde el catalogo puede no tener lo que el cliente pidio. Elegir de
 * la lista sigue siendo lo normal —se despliega igual que un select— pero que
 * falte una opcion no puede dejar el campo vacio: lo escrito vale.
 */
export function Combo({
  etiqueta,
  ayuda,
  error,
  aviso,
  opciones,
  id,
  className,
  ...rest
}: InputHTMLAttributes<HTMLInputElement> & {
  etiqueta?: string
  ayuda?: string
  error?: string
  /** Lo que hay que saber de lo escrito, sin que sea un error. */
  aviso?: string
  opciones: string[]
  id: string
}) {
  return (
    <div className={cx('flex min-w-0 flex-col', className)}>
      {etiqueta && <Etiqueta ayuda={ayuda}>{etiqueta}</Etiqueta>}
      <input
        list={`${id}-opciones`}
        className={cx(
          BASE_CAMPO,
          'h-[36px]',
          aviso && !error && 'border-[#e0b57a] bg-[#fffdf8]',
          error && 'border-danger',
        )}
        {...rest}
      />
      <datalist id={`${id}-opciones`}>
        {opciones.map((o) => (
          <option key={o} value={o} />
        ))}
      </datalist>
      {aviso && !error && <span className="mt-1 text-[10.5px] text-warning-ink">{aviso}</span>}
      {error && <span className="mt-1 text-[10.5px] text-danger">{error}</span>}
    </div>
  )
}

/** Casilla que se ve igual que las de la ficha. */
export function Casilla({
  marcada,
  onChange,
  children,
  disabled,
}: {
  marcada: boolean
  onChange: (v: boolean) => void
  children: ReactNode
  disabled?: boolean
}) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={() => onChange(!marcada)}
      className={cx(
        'inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-[12.5px] transition-colors disabled:opacity-60',
        marcada
          ? 'border-brand-200 bg-brand-50 font-semibold text-brand-600'
          : 'border-line-strong bg-white font-medium text-muted hover:bg-app',
      )}
    >
      <span
        className={cx(
          'grid h-4 w-4 place-items-center rounded-[5px] border',
          marcada ? 'border-brand bg-brand text-white' : 'border-slate-300 bg-white',
        )}
      >
        {marcada && <Check size={10} strokeWidth={3.5} />}
      </span>
      {children}
    </button>
  )
}

/* -------------------------------------------------------------------- modal */

export function Modal({
  abierto,
  titulo,
  bajada,
  onCerrar,
  onGuardar,
  guardando,
  textoGuardar = 'Guardar',
  ancho = 'max-w-2xl',
  children,
}: {
  abierto: boolean
  titulo: string
  bajada?: string
  onCerrar: () => void
  onGuardar?: () => void
  guardando?: boolean
  textoGuardar?: string
  ancho?: string
  children: ReactNode
}) {
  useEffect(() => {
    if (!abierto) return
    const cerrarConEsc = (e: KeyboardEvent) => e.key === 'Escape' && onCerrar()
    document.addEventListener('keydown', cerrarConEsc)
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', cerrarConEsc)
      document.body.style.overflow = ''
    }
  }, [abierto, onCerrar])

  return createPortal(
    <AnimatePresence>
      {abierto && (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-8">
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.15 }}
            onClick={onCerrar}
            className="fixed inset-0 bg-navy/40 backdrop-blur-[2px]"
          />
          <motion.div
            initial={{ y: 12, scale: 0.985 }}
            animate={{ y: 0, scale: 1 }}
            exit={{ y: 8, scale: 0.99 }}
            transition={{ duration: 0.18, ease: 'easeOut' }}
            className={cx(
              'relative z-10 my-auto w-full rounded-[14px] border border-line bg-white shadow-pop',
              ancho,
            )}
          >
            <header className="flex items-start gap-4 border-b border-line px-6 py-[18px]">
              <div className="min-w-0 flex-1">
                <h2 className="text-[16px] font-bold text-ink">{titulo}</h2>
                {bajada && <p className="mt-0.5 text-[12px] leading-relaxed text-muted">{bajada}</p>}
              </div>
              <button
                type="button"
                onClick={onCerrar}
                aria-label="Cerrar"
                className="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-faint transition-colors hover:bg-app hover:text-muted"
              >
                <X size={17} strokeWidth={2} />
              </button>
            </header>

            <div className="max-h-[65vh] overflow-y-auto px-6 py-5">{children}</div>

            {onGuardar && (
              <footer className="flex items-center justify-end gap-2.5 border-t border-line px-6 py-4">
                <Boton variante="suave" onClick={onCerrar} disabled={guardando}>
                  Cancelar
                </Boton>
                <Boton variante="primario" onClick={onGuardar} disabled={guardando}>
                  {guardando ? 'Guardando…' : textoGuardar}
                </Boton>
              </footer>
            )}
          </motion.div>
        </div>
      )}
    </AnimatePresence>,
    document.body,
  )
}

/** Confirmación para acciones que sacan algo de la vista. */
export function Confirmar({
  abierto,
  titulo,
  detalle,
  textoConfirmar = 'Archivar',
  onCerrar,
  onConfirmar,
  trabajando,
}: {
  abierto: boolean
  titulo: string
  detalle: string
  textoConfirmar?: string
  onCerrar: () => void
  onConfirmar: () => void
  trabajando?: boolean
}) {
  return (
    <Modal
      abierto={abierto}
      titulo={titulo}
      onCerrar={onCerrar}
      onGuardar={onConfirmar}
      guardando={trabajando}
      textoGuardar={textoConfirmar}
      ancho="max-w-md"
    >
      <p className="text-[13px] leading-relaxed text-muted">{detalle}</p>
    </Modal>
  )
}

/* --------------------------------------------------------------- avisos ok */

export function Guardado({ mensaje, onCerrar }: { mensaje: string | null; onCerrar: () => void }) {
  useEffect(() => {
    if (!mensaje) return
    const id = setTimeout(onCerrar, 3500)

    return () => clearTimeout(id)
  }, [mensaje, onCerrar])

  return createPortal(
    <AnimatePresence>
      {mensaje && (
        <motion.div
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: 8 }}
          className="fixed bottom-6 left-1/2 z-[60] -translate-x-1/2 rounded-lg border border-[#cdebd8] bg-[#f4fbf6] px-4 py-2.5 text-[12.5px] font-medium text-success-ink shadow-pop"
        >
          {mensaje}
        </motion.div>
      )}
    </AnimatePresence>,
    document.body,
  )
}

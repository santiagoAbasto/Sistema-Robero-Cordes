import { RefreshCw, X } from 'lucide-react'
import { actualizarAhora, posponer, useVersion } from '../lib/version'

/* ---------------------------------------------------------------------------
   "Hay una versión nueva": la tira de abajo.

   Aparece sola cuando el servidor tiene una versión distinta a la de esta
   pantalla. Se puede cerrar, y mientras haya algo a medio escribir no cuenta
   para atrás: espera a que guarden. Ver lib/version.ts, que es donde está la
   regla.
--------------------------------------------------------------------------- */

export default function AvisoDeVersion() {
  const { hayVersionNueva, pospuesto, faltan } = useVersion()

  if (!hayVersionNueva || pospuesto) return null

  return (
    <div
      role="status"
      className="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex justify-center px-4 pb-4"
    >
      <div className="pointer-events-auto flex max-w-[560px] flex-wrap items-center gap-3 rounded-[12px] border border-brand-200 bg-white px-4 py-3 shadow-[0_8px_24px_rgba(16,24,40,.14)]">
        <RefreshCw size={16} strokeWidth={2.2} className="shrink-0 text-brand-600" />

        <p className="min-w-0 flex-1 text-[12.5px] leading-relaxed text-ink">
          <span className="font-semibold">Hay una versión nueva del sistema.</span>{' '}
          {faltan === null ? (
            <span className="text-muted">
              Se va a actualizar sola en cuanto termines lo que estás cargando.
            </span>
          ) : (
            <span className="text-muted">
              Se actualiza en {faltan} segundo{faltan === 1 ? '' : 's'}.
            </span>
          )}
        </p>

        <button
          type="button"
          onClick={actualizarAhora}
          className="inline-flex h-[30px] shrink-0 items-center gap-1.5 rounded-lg bg-brand px-3 text-[12px] font-semibold text-white transition-colors hover:bg-brand-600"
        >
          Actualizar ahora
        </button>

        <button
          type="button"
          onClick={posponer}
          aria-label="Ahora no"
          title="Ahora no"
          className="shrink-0 text-faint transition-colors hover:text-ink"
        >
          <X size={15} strokeWidth={2.2} />
        </button>
      </div>
    </div>
  )
}

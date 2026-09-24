import { useEffect, useState } from 'react'
import { Globe, MessageCircle, MapPin, Link2, ExternalLink, Trash2, Plus } from 'lucide-react'
import { Accion, Aviso, Card, CardHeader, Chip, NotaPie } from '../../components/ui'
import { Modal, Texto, Lista } from '../../components/ui/form'
import { borrarEnlace, guardarEnlace, mensajeDeError, useCatalogos } from '../../lib/indice'
import type { Empresa, Enlace } from '../../types/indice'

/* ---------------------------------------------------------------------------
   Los íconos de las redes van dibujados acá: la librería de íconos que usamos
   sacó las marcas, y estos tienen que verse como los conoce la gente.
   Usan currentColor, así heredan el color de cada tarjeta.
--------------------------------------------------------------------------- */

type PropsIcono = { size?: number; strokeWidth?: number; className?: string }

function IconoInstagram({ size = 15, className }: PropsIcono) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden>
      <rect x="2" y="2" width="20" height="20" rx="5" />
      <circle cx="12" cy="12" r="4" />
      <circle cx="17.5" cy="6.5" r="1.2" fill="currentColor" stroke="none" />
    </svg>
  )
}

function IconoFacebook({ size = 15, className }: PropsIcono) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"
      className={className} aria-hidden>
      <path d="M14 8.5V7c0-.83.67-1 1.5-1H17V3h-2.5C12 3 10.5 4.5 10.5 7v1.5H8V12h2.5v9H14v-9h2.6l.4-3.5H14z" />
    </svg>
  )
}

function IconoLinkedIn({ size = 15, className }: PropsIcono) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"
      className={className} aria-hidden>
      <path d="M4.5 3.5a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM3 9h3v12H3V9zm5.5 0h2.9v1.6h.04c.4-.75 1.4-1.6 2.9-1.6 3.1 0 3.66 2 3.66 4.6V21h-3v-5.6c0-1.34-.03-3.06-1.9-3.06-1.9 0-2.2 1.45-2.2 2.96V21h-3V9z" />
    </svg>
  )
}

function IconoYouTube({ size = 15, className }: PropsIcono) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"
      className={className} aria-hidden>
      <path d="M21.6 7.2a2.5 2.5 0 0 0-1.75-1.77C18.25 5 12 5 12 5s-6.25 0-7.85.43A2.5 2.5 0 0 0 2.4 7.2 26 26 0 0 0 2 12a26 26 0 0 0 .4 4.8 2.5 2.5 0 0 0 1.75 1.77C5.75 19 12 19 12 19s6.25 0 7.85-.43a2.5 2.5 0 0 0 1.75-1.77A26 26 0 0 0 22 12a26 26 0 0 0-.4-4.8zM10 15.2V8.8l5.2 3.2-5.2 3.2z" />
    </svg>
  )
}

/** Cada tipo con su ícono y su color de marca. */
type ComponenteIcono = (p: PropsIcono) => React.ReactElement

const ICONOS: Record<string, { icono: ComponenteIcono; clase: string }> = {
  Web: { icono: Globe as ComponenteIcono, clase: 'bg-brand-50 text-brand-600' },
  Instagram: { icono: IconoInstagram, clase: 'bg-[#fdf2f8] text-[#c2408a]' },
  Facebook: { icono: IconoFacebook, clase: 'bg-[#eff4fe] text-[#1877f2]' },
  LinkedIn: { icono: IconoLinkedIn, clase: 'bg-[#eef6fb] text-[#0a66c2]' },
  YouTube: { icono: IconoYouTube, clase: 'bg-[#fef2f2] text-[#dc2626]' },
  WhatsApp: { icono: MessageCircle as ComponenteIcono, clase: 'bg-success-bg text-success-ink' },
  Mapa: { icono: MapPin as ComponenteIcono, clase: 'bg-warning-bg text-warning-ink' },
  Otro: { icono: Link2 as ComponenteIcono, clase: 'bg-slate-100 text-slate-600' },
}

/**
 * Web, redes y mapa.
 *
 * Se cargan una vez y quedan clickeables: se aprieta y abre la página.
 * El mapa se arma solo con la dirección de la ficha.
 */
export default function Enlaces({
  empresa,
  onCambio,
}: {
  empresa: Empresa
  onCambio: (mensaje: string) => void
}) {
  const [editando, setEditando] = useState<Enlace | null | undefined>(undefined)

  return (
    <Card className="overflow-hidden">
      <CardHeader
        titulo="Web y redes"
        cuenta={empresa.enlaces.length}
        acciones={
          <>
            <span className="text-[11px] text-faint">Se aprieta y abre la pagina</span>
            <Accion onClick={() => setEditando(null)}>
              <span className="inline-flex items-center gap-1">
                <Plus size={12} strokeWidth={2.6} />
                Agregar enlace
              </span>
            </Accion>
          </>
        }
      />

      <div className="flex flex-wrap gap-2.5 px-[22px] pb-4">
        {/* El mapa se arma con la dirección: no hay que pegar nada. */}
        {empresa.mapa && (
          <a
            href={empresa.mapa}
            target="_blank"
            rel="noreferrer"
            className="group inline-flex items-center gap-2.5 rounded-[10px] border border-line bg-app px-3 py-2 transition-colors hover:border-brand-200 hover:bg-white"
          >
            <span className="grid h-7 w-7 place-items-center rounded-lg bg-warning-bg text-warning-ink">
              <MapPin size={15} strokeWidth={2} />
            </span>
            <span className="flex flex-col">
              <span className="text-[12px] font-semibold text-ink">Ver en el mapa</span>
              <span className="text-[10px] text-faint">Se arma con la direccion</span>
            </span>
            <ExternalLink
              size={13}
              strokeWidth={2}
              className="ml-1 text-faint transition-colors group-hover:text-brand-600"
            />
          </a>
        )}

        {empresa.enlaces.map((e) => {
          const { icono: Icono, clase } = ICONOS[e.tipo] ?? ICONOS.Otro

          return (
            <span key={e.id} className="group relative inline-flex">
              <a
                href={e.url_completa}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-2.5 rounded-[10px] border border-line bg-app py-2 pl-3 pr-9 transition-colors hover:border-brand-200 hover:bg-white"
              >
                <span className={`grid h-7 w-7 place-items-center rounded-lg ${clase}`}>
                  <Icono size={15} />
                </span>
                <span className="flex flex-col">
                  <span className="text-[12px] font-semibold text-ink">
                    {e.etiqueta || e.tipo}
                  </span>
                  <span className="max-w-[220px] truncate text-[10px] text-faint">{e.url}</span>
                </span>
                <ExternalLink
                  size={13}
                  strokeWidth={2}
                  className="ml-1 text-faint transition-colors group-hover:text-brand-600"
                />
              </a>

              <span className="absolute right-1.5 top-1.5 hidden gap-1 group-hover:flex">
                <button
                  type="button"
                  aria-label={`Modificar ${e.tipo}`}
                  onClick={() => setEditando(e)}
                  className="rounded p-0.5 text-faint hover:text-brand-600"
                >
                  <Link2 size={12} strokeWidth={2} />
                </button>
              </span>
            </span>
          )
        })}

        {empresa.enlaces.length === 0 && !empresa.mapa && (
          <p className="py-3 text-[12.5px] text-muted">
            Todavía no hay enlaces. Cargá la web, el Instagram o lo que usen.
          </p>
        )}
      </div>

      <NotaPie>
        Los enlaces se abren en otra pestaña. El del mapa se arma solo con la dirección cargada
        arriba: si se corrige la dirección, el mapa se corrige con ella.
      </NotaPie>

      <ModalEnlace
        abierto={editando !== undefined}
        empresaId={empresa.id}
        enlace={editando}
        onCerrar={() => setEditando(undefined)}
        onGuardado={onCambio}
      />
    </Card>
  )
}

function ModalEnlace({
  abierto,
  empresaId,
  enlace,
  onCerrar,
  onGuardado,
}: {
  abierto: boolean
  empresaId: number
  enlace?: Enlace | null
  onCerrar: () => void
  onGuardado: (mensaje: string) => void
}) {
  const catalogos = useCatalogos()
  const [tipo, setTipo] = useState('Web')
  const [url, setUrl] = useState('')
  const [etiqueta, setEtiqueta] = useState('')
  const [guardando, setGuardando] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!abierto) return
    setTipo(enlace?.tipo ?? 'Web')
    setUrl(enlace?.url ?? '')
    setEtiqueta(enlace?.etiqueta ?? '')
    setError(null)
  }, [abierto, enlace])

  async function guardar() {
    if (!url.trim()) {
      setError('Falta la dirección.')

      return
    }

    setGuardando(true)
    setError(null)

    try {
      await guardarEnlace(empresaId, { tipo, url: url.trim(), etiqueta: etiqueta || null }, enlace?.id)
      onGuardado(enlace ? 'Enlace modificado.' : 'Enlace agregado.')
      onCerrar()
    } catch (err) {
      setError(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  async function quitar() {
    if (!enlace) return
    setGuardando(true)

    try {
      await borrarEnlace(enlace.id)
      onGuardado('Enlace quitado.')
      onCerrar()
    } catch (err) {
      setError(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  return (
    <Modal
      abierto={abierto}
      titulo={enlace ? 'Modificar enlace' : 'Agregar enlace'}
      bajada="La web, el Instagram, el mapa… se pega la dirección y queda clickeable."
      onCerrar={onCerrar}
      onGuardar={guardar}
      guardando={guardando}
      ancho="max-w-lg"
    >
      <div className="flex flex-col gap-4">
        {error && <Aviso tono="ambar">{error}</Aviso>}

        <Lista
          etiqueta="Tipo"
          value={tipo}
          vacio=""
          onChange={(e) => setTipo(e.target.value)}
          opciones={(catalogos?.tipos_enlace ?? []).map((t) => ({ valor: t, texto: t }))}
        />

        <Texto
          etiqueta="Direccion"
          obligatorio
          value={url}
          onChange={(e) => setUrl(e.target.value)}
          placeholder="www.silmar.com.ar   ·   instagram.com/silmar"
          ayuda="con o sin https"
        />

        <Texto
          etiqueta="Como se llama"
          value={etiqueta}
          onChange={(e) => setEtiqueta(e.target.value)}
          placeholder="Sitio web, Instagram de la planta…"
        />

        <div className="flex items-center gap-2">
          <Chip tono="brand">{tipo}</Chip>
          <span className="text-[11px] text-faint">
            Cada tipo tiene su ícono. Si no está en la lista, va como Otro.
          </span>
        </div>

        {enlace && (
          <button
            type="button"
            onClick={quitar}
            disabled={guardando}
            className="inline-flex items-center gap-1.5 self-start text-[11.5px] font-semibold text-danger hover:underline"
          >
            <Trash2 size={13} strokeWidth={2} />
            Quitar este enlace
          </button>
        )}
      </div>
    </Modal>
  )
}

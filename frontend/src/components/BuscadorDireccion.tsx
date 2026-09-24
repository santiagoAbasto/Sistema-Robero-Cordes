import { useEffect, useRef, useState } from 'react'
import { MapPin, Loader2 } from 'lucide-react'
import api from '../lib/api'
import { useDebounce } from '../lib/indice'
import { Etiqueta } from './ui/form'

/* ---------------------------------------------------------------------------
   Predictivos de dirección.

   Se escribe la calle y el sistema propone direcciones. Al elegir una,
   completa localidad, provincia, país y código postal.

   Si los predictivos no están configurados, el campo funciona como siempre:
   se escribe la dirección a mano y no cambia nada.
--------------------------------------------------------------------------- */

interface Sugerencia {
  id: string
  texto: string
  calle: string | null
  resto: string | null
}

export interface DireccionElegida {
  direccion: string | null
  codigo_postal: string | null
  provincia_id: number | null
  provincia_nombre: string | null
  localidad_id: number | null
  localidad_nombre: string | null
  pais_id: number | null
  pais_nombre: string | null
}

function nuevaSesion(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : Math.random().toString(36).slice(2)
}

export default function BuscadorDireccion({
  valor,
  onCambio,
  onElegir,
  activo,
  className,
}: {
  valor: string
  onCambio: (v: string) => void
  onElegir: (d: DireccionElegida) => void
  activo: boolean
  className?: string
}) {
  const [sugerencias, setSugerencias] = useState<Sugerencia[]>([])
  const [abierto, setAbierto] = useState(false)
  const [buscando, setBuscando] = useState(false)
  const [aviso, setAviso] = useState<string | null>(null)
  const contenedor = useRef<HTMLDivElement>(null)
  // Lo último que se eligió: evita volver a buscar apenas se completa.
  const recienElegido = useRef('')
  // Google agrupa lo tipeado con la dirección elegida y lo cobra como una sola
  // búsqueda. Se renueva después de cada elección.
  const sesion = useRef(nuevaSesion())

  const texto = useDebounce(valor, 350)

  useEffect(() => {
    if (!activo || texto.trim().length < 3 || texto === recienElegido.current) {
      setSugerencias([])

      return
    }

    let vivo = true
    setBuscando(true)

    api
      .get<{ activo: boolean; sugerencias: Sugerencia[] }>('/direcciones/sugerencias', {
        params: { q: texto, s: sesion.current },
      })
      .then(({ data }) => {
        if (!vivo) return
        setSugerencias(data.sugerencias)
        setAbierto(data.sugerencias.length > 0)
      })
      .catch(() => vivo && setSugerencias([]))
      .finally(() => vivo && setBuscando(false))

    return () => {
      vivo = false
    }
  }, [texto, activo])

  // Cerrar al hacer click afuera.
  useEffect(() => {
    function afuera(e: MouseEvent) {
      if (contenedor.current && !contenedor.current.contains(e.target as Node)) setAbierto(false)
    }

    document.addEventListener('mousedown', afuera)

    return () => document.removeEventListener('mousedown', afuera)
  }, [])

  async function elegir(s: Sugerencia) {
    setAbierto(false)
    setBuscando(true)

    try {
      const { data } = await api.get<DireccionElegida>('/direcciones/detalle', {
        params: { id: s.id, s: sesion.current },
      })

      // La búsqueda terminó acá: lo que se escriba después es una nueva.
      sesion.current = nuevaSesion()
      recienElegido.current = data.direccion ?? s.texto
      onElegir(data)

      // Lo que no está en nuestras listas hay que elegirlo a mano.
      const faltantes = [
        !data.localidad_id && data.localidad_nombre ? `la localidad ${data.localidad_nombre}` : null,
        !data.provincia_id && data.provincia_nombre ? `la provincia ${data.provincia_nombre}` : null,
      ].filter(Boolean)

      setAviso(
        faltantes.length > 0
          ? `Completamos lo que pudimos. ${faltantes.join(' y ')} no está en la lista: elegila a mano o avisanos para agregarla.`
          : null,
      )
    } catch {
      setAviso('No pudimos traer los datos de esa dirección. Escribila a mano.')
    } finally {
      setBuscando(false)
    }
  }

  return (
    <div className={className} ref={contenedor}>
      <Etiqueta ayuda={activo ? 'escribí y elegí de la lista' : undefined}>Direccion</Etiqueta>

      <div className="relative">
        <input
          value={valor}
          onChange={(e) => {
            onCambio(e.target.value)
            setAviso(null)
          }}
          onFocus={() => sugerencias.length > 0 && setAbierto(true)}
          placeholder={activo ? 'Cid Campeador 375' : 'Calle y numero'}
          autoComplete="off"
          className="h-[36px] w-full rounded-[7px] border border-line-strong bg-white px-[11px] pr-9 text-[12.5px] text-ink outline-none transition-shadow placeholder:text-faint focus:border-brand focus:ring-4 focus:ring-brand-50"
        />

        {activo && (
          <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-faint">
            {buscando ? (
              <Loader2 size={14} strokeWidth={2} className="animate-spin" />
            ) : (
              <MapPin size={14} strokeWidth={2} />
            )}
          </span>
        )}

        {abierto && sugerencias.length > 0 && (
          <ul className="absolute z-30 mt-1 w-full overflow-hidden rounded-[9px] border border-line bg-white shadow-pop">
            {sugerencias.map((s) => (
              <li key={s.id}>
                <button
                  type="button"
                  onClick={() => elegir(s)}
                  className="flex w-full items-start gap-2.5 border-b border-[#eef2f6] px-3 py-2.5 text-left transition-colors last:border-b-0 hover:bg-app"
                >
                  <MapPin size={13} strokeWidth={2} className="mt-0.5 shrink-0 text-brand-600" />
                  <span className="min-w-0">
                    <span className="block text-[12.5px] font-medium text-ink">
                      {s.calle ?? s.texto}
                    </span>
                    {s.resto && <span className="block text-[11px] text-faint">{s.resto}</span>}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      {aviso && <p className="mt-1 text-[10.5px] leading-relaxed text-warning-ink">{aviso}</p>}
    </div>
  )
}

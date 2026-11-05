import { useEffect, useState } from 'react'
import { Search } from 'lucide-react'
import { Aviso, Cargando, Chip } from '../../components/ui'
import { Modal } from '../../components/ui/form'
import {
  buscarEmpresas,
  combinarFichas,
  compararFichas,
  fecha,
  mensajeDeError,
  useDebounce,
} from '../../lib/indice'
import type { ResumenDeFicha } from '../../lib/indice'

type Comparacion = { esta: ResumenDeFicha; otra: ResumenDeFicha; recomendada: number }

/**
 * Combinar dos fichas de la misma empresa en una sola.
 *
 * Pedido de Roberto (04-11-2026): buscando un cliente salían dos fichas,
 * porque el nombre de antes de migrar no era exactamente igual. Se elige la otra
 * ficha, se ven las dos lado a lado y se dice cuál queda. A la que queda se le
 * pasa todo; la otra queda archivada. No se borra nada.
 */
export default function CombinarFichas({
  abierto,
  empresa,
  parecidas,
  onCerrar,
  onCombinada,
}: {
  abierto: boolean
  empresa: { id: number; nombre: string }
  /** Las que el sistema ya sospecha que son la misma empresa. */
  parecidas: ResumenDeFicha[]
  onCerrar: () => void
  /** Qué ficha quedó viva y qué decir. */
  onCombinada: (empresaId: number, mensaje: string) => void
}) {
  const [otra, setOtra] = useState<{ id: number; nombre: string } | null>(null)
  const [comparacion, setComparacion] = useState<Comparacion | null>(null)
  const [queda, setQueda] = useState<'esta' | 'otra'>('esta')
  const [texto, setTexto] = useState('')
  const buscado = useDebounce(texto.trim(), 300)
  const [resultados, setResultados] = useState<{ id: number; nombre: string; localidad: string | null; cotizaciones: number }[]>([])
  const [buscando, setBuscando] = useState(false)
  const [trabajando, setTrabajando] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Cada vez que se abre, de cero.
  useEffect(() => {
    if (!abierto) return
    setOtra(null)
    setComparacion(null)
    setQueda('esta')
    setTexto('')
    setResultados([])
    setError(null)
  }, [abierto])

  useEffect(() => {
    if (!abierto || buscado.length < 2) {
      setResultados([])

      return
    }

    let vigente = true
    setBuscando(true)

    buscarEmpresas({ buscar: buscado })
      .then((r) => {
        if (vigente) setResultados(r.data.filter((e) => e.id !== empresa.id).slice(0, 8))
      })
      .catch(() => vigente && setResultados([]))
      .finally(() => vigente && setBuscando(false))

    return () => {
      vigente = false
    }
  }, [abierto, buscado, empresa.id])

  // Al elegir la otra, se traen las dos para compararlas.
  useEffect(() => {
    if (!otra) {
      setComparacion(null)

      return
    }

    let vigente = true
    setError(null)

    compararFichas(empresa.id, otra.id)
      .then((c) => {
        if (!vigente) return
        setComparacion(c)
        // Se propone la que más cotizaciones tiene: es la que menos cambia.
        setQueda(c.recomendada === c.otra.id ? 'otra' : 'esta')
      })
      .catch((err) => vigente && setError(mensajeDeError(err)))

    return () => {
      vigente = false
    }
  }, [otra, empresa.id])

  async function combinar() {
    if (!otra) return

    setTrabajando(true)
    setError(null)

    try {
      const r = await combinarFichas(empresa.id, { otra_id: otra.id, queda })
      onCombinada(r.empresa_id, r.mensaje)
      onCerrar()
    } catch (err) {
      setError(mensajeDeError(err))
    } finally {
      setTrabajando(false)
    }
  }

  const fichaQueQueda = comparacion ? (queda === 'esta' ? comparacion.esta : comparacion.otra) : null
  const fichaQueSobra = comparacion ? (queda === 'esta' ? comparacion.otra : comparacion.esta) : null

  return (
    <Modal
      abierto={abierto}
      titulo="Combinar fichas"
      bajada="Juntá en una sola dos fichas que son de la misma empresa. No se borra nada."
      onCerrar={onCerrar}
      onGuardar={comparacion ? combinar : undefined}
      guardando={trabajando}
      textoGuardar="Combinar las dos fichas"
      ancho="max-w-2xl"
    >
      <div className="flex flex-col gap-4">
        {error && <Aviso tono="ambar">{error}</Aviso>}

        {!otra && (
          <>
            <p className="text-[13px] leading-relaxed text-muted">
              Elegí la otra ficha, la que querés juntar con <strong className="text-ink">{empresa.nombre}</strong>.
            </p>

            {parecidas.length > 0 && (
              <div className="flex flex-col gap-1.5">
                <h3 className="text-[12px] font-semibold text-ink">Fichas con un nombre parecido</h3>
                {parecidas.map((f) => (
                  <Opcion key={f.id} nombre={f.nombre} detalle={detalleDe(f)} onElegir={() => setOtra({ id: f.id, nombre: f.nombre })} />
                ))}
              </div>
            )}

            <div className="flex flex-col gap-1.5">
              <h3 className="text-[12px] font-semibold text-ink">
                {parecidas.length > 0 ? '¿Es otra? Buscala por el nombre' : 'Buscá la otra ficha por el nombre'}
              </h3>
              <label className="relative block">
                <Search size={15} strokeWidth={2} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-faint" />
                <input
                  value={texto}
                  onChange={(e) => setTexto(e.target.value)}
                  placeholder="Empezá a escribir el nombre…"
                  className="h-[38px] w-full rounded-[7px] border border-line-strong pl-9 pr-3 text-[13px] text-ink outline-none placeholder:text-faint focus:border-brand focus:ring-4 focus:ring-brand-50"
                />
              </label>

              {buscando && <p className="text-[12px] text-faint">Buscando…</p>}
              {!buscando && buscado.length >= 2 && resultados.length === 0 && (
                <p className="text-[12px] text-muted">No encontramos otra ficha activa con ese nombre.</p>
              )}
              {resultados.map((f) => (
                <Opcion
                  key={f.id}
                  nombre={f.nombre}
                  detalle={[f.localidad, `${f.cotizaciones} ${f.cotizaciones === 1 ? 'cotización' : 'cotizaciones'}`].filter(Boolean).join(' · ')}
                  onElegir={() => setOtra({ id: f.id, nombre: f.nombre })}
                />
              ))}
            </div>
          </>
        )}

        {otra && !comparacion && !error && <Cargando texto="Comparando las dos fichas…" />}

        {otra && comparacion && fichaQueQueda && fichaQueSobra && (
          <>
            <p className="text-[13px] leading-relaxed text-muted">
              Elegí <strong className="text-ink">cuál de las dos queda</strong>. A esa se le pasa todo lo de la otra.
            </p>

            <div className="grid gap-3 sm:grid-cols-2">
              {(['esta', 'otra'] as const).map((clave) => {
                const f = comparacion[clave]
                const elegida = queda === clave

                return (
                  <label
                    key={clave}
                    className={`flex cursor-pointer flex-col gap-2 rounded-[10px] border-2 p-3.5 transition-colors ${
                      elegida ? 'border-brand bg-brand-50' : 'border-line hover:border-brand-200'
                    }`}
                  >
                    <span className="flex flex-wrap items-center gap-2">
                      <input type="radio" name="queda" checked={elegida} onChange={() => setQueda(clave)} />
                      <span className="text-[12px] font-semibold text-brand-600">Dejar esta ficha</span>
                      {f.id === comparacion.recomendada && <Chip tono="verde">Recomendada</Chip>}
                    </span>
                    <span className="text-[14px] font-bold leading-snug text-ink">{f.nombre}</span>
                    <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-[12px]">
                      <Dato etiqueta="CUIT" valor={f.cuit} />
                      <Dato etiqueta="Localidad" valor={f.localidad} />
                      <Dato etiqueta="Cotizaciones" valor={String(f.cotizaciones)} fuerte />
                      <Dato etiqueta="Contactos" valor={String(f.contactos)} />
                      <Dato etiqueta="Última cotización" valor={f.ultima ? fecha(f.ultima) : null} />
                    </dl>
                  </label>
                )
              })}
            </div>

            <Aviso tono="info" titulo="Qué va a pasar">
              Todo lo de «{fichaQueSobra.nombre}» —cotizaciones, contactos, razones sociales, condiciones y cambios— pasa a
              «{fichaQueQueda.nombre}». La ficha «{fichaQueSobra.nombre}» queda archivada: no se borra nada. El nombre que
              queda es «{fichaQueQueda.nombre}»; si hace falta, después se corrige con Modificar.
            </Aviso>

            <button
              type="button"
              onClick={() => setOtra(null)}
              className="w-fit text-[12px] text-brand-600 underline"
            >
              Elegir otra ficha
            </button>
          </>
        )}
      </div>
    </Modal>
  )
}

function detalleDe(f: ResumenDeFicha): string {
  return [f.localidad, `${f.cotizaciones} ${f.cotizaciones === 1 ? 'cotización' : 'cotizaciones'}`, f.contactos > 0 ? `${f.contactos} ${f.contactos === 1 ? 'contacto' : 'contactos'}` : null]
    .filter(Boolean)
    .join(' · ')
}

function Opcion({ nombre, detalle, onElegir }: { nombre: string; detalle: string; onElegir: () => void }) {
  return (
    <button
      type="button"
      onClick={onElegir}
      className="flex w-full items-center gap-3 rounded-[8px] border border-line px-3 py-2.5 text-left transition-colors hover:border-brand-200 hover:bg-brand-50"
    >
      <span className="min-w-0 flex-1">
        <span className="block truncate text-[13px] font-semibold text-ink">{nombre}</span>
        <span className="block truncate text-[11.5px] text-muted">{detalle}</span>
      </span>
      <span className="shrink-0 text-[12px] font-semibold text-brand-600">Elegir</span>
    </button>
  )
}

function Dato({ etiqueta, valor, fuerte }: { etiqueta: string; valor: string | null; fuerte?: boolean }) {
  return (
    <>
      <dt className="text-muted">{etiqueta}</dt>
      <dd className={fuerte ? 'font-bold text-ink' : 'text-ink'}>{valor || <span className="text-faint">—</span>}</dd>
    </>
  )
}

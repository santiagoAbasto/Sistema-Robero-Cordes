import { useState } from 'react'
import { Boton, Chip } from '../../components/ui'
import { AreaTexto, Lista, Texto } from '../../components/ui/form'
import {
  agregarComprobante,
  borrarComprobante,
  cambiarEstadoConsulta,
  editarComprobante,
  fecha as fmtFecha,
  guardarDatosDeLaVenta,
  hoy,
  marcarCompra,
  mensajeDeError,
  numeroComoSeEscribe,
  plata,
  useCatalogos,
} from '../../lib/indice'
import type { DatosDeComprobante, DatosDeLaVenta as Datos } from '../../lib/indice'
import type { Comprobante, ConsultaLinea, EstadoConsulta } from '../../types/indice'

/*
  "¿Cómo siguió?": el paso que faltaba después de mandarla (pedido del
  cliente, 29-10-2026). El servidor ya sabía registrarlo —y pide el motivo al
  cerrar—, pero no había botón. Lo que se escribe queda en las observaciones
  de la cotización, con la fecha y quién lo cargó; la hoja emitida no cambia.
*/
const OPCIONES: { estado: EstadoConsulta; boton: string; titulo: string; pide: string; obligatorio: boolean }[] = [
  { estado: 'Vendida', boton: 'La compró', titulo: 'Vendida', pide: 'Comentario del pedido (lo podés editar después)', obligatorio: false },
  {
    estado: 'Cerrada por declinacion',
    boton: 'No la compró',
    titulo: 'Cerrada: no la compró',
    pide: '¿Por qué no la compró?',
    obligatorio: true,
  },
  {
    estado: 'Vencida por tiempo',
    boton: 'Venció sin respuesta',
    titulo: 'Vencida sin respuesta',
    pide: '¿Qué pasó? Por ejemplo: no contestó los mensajes',
    obligatorio: true,
  },
]

const ABIERTA: EstadoConsulta = 'Confirmada'

type ConsultaQueSigue = {
  id: number
  estado: EstadoConsulta
  nro_pedido_interno?: string | null
  fecha_pedido?: string | null
  tipo_cambio_factura?: number | null
  comentario_venta?: string | null
  lineas?: ConsultaLinea[]
  comprobantes?: Comprobante[]
}

/*
  Lo que se va sabiendo de una vendida, en el orden en que pasa (cliente,
  29-10-2026): el pedido se carga en el Access y da un numero; sale la
  mercaderia con remito; con la conformidad se factura, con su tipo de cambio.
  Cada uno con su fecha —la del remito y la factura pueden no coincidir
  (30-10-2026)—. Se completa de a uno y se puede corregir.
*/
const SIN_DATOS: Datos = {
  nro_pedido_interno: '',
  fecha_pedido: '',
  tipo_cambio_factura: '',
  comentario_venta: '',
}

/** Lo que ya estaba guardado, listo para editar. La fecha en blanco se queda en blanco. */
function datosDesde(c: ConsultaQueSigue): Datos {
  return {
    nro_pedido_interno: c.nro_pedido_interno ?? '',
    fecha_pedido: c.fecha_pedido ?? '',
    tipo_cambio_factura: c.tipo_cambio_factura == null ? '' : String(c.tipo_cambio_factura).replace('.', ','),
    comentario_venta: c.comentario_venta ?? '',
  }
}

/**
 * Los datos de la venta: cada numero con su fecha, y el tipo de cambio.
 * Los mismos al marcarla y al completarla despues. En dos columnas: a la
 * izquierda el numero, a la derecha su fecha, parejos.
 */
function CamposDeLaVenta({ datos, onCambio }: { datos: Datos; onCambio: (d: Datos) => void }) {
  const campo = (clave: keyof Datos) => ({
    value: datos[clave],
    onChange: (e: { target: { value: string } }) => onCambio({ ...datos, [clave]: e.target.value }),
  })

  return (
    <>
      <Texto etiqueta="N° de pedido (Access)" placeholder="12345" {...campo('nro_pedido_interno')} />
      <Texto etiqueta="Fecha del pedido" type="date" {...campo('fecha_pedido')} />
      <Texto etiqueta="Tipo de cambio factura" inputMode="decimal" placeholder="1050,50" {...campo('tipo_cambio_factura')} />
    </>
  )
}

/** Los ítems que se pueden comprar: los cotizados, sin las quitadas. Las alternativas van aparte, debajo de su ítem. */
function lineasDeLaCompra(lineas?: ConsultaLinea[]): ConsultaLinea[] {
  return (lineas ?? []).filter((l) => !l.quitada && !l.no_cotizada)
}

/** Casillas por ítem: qué se compró. Compraron 1 de 3 no es lo mismo que una compra total. */
function ListaDeCompra({
  lineas,
  marcadas,
  onCambio,
  aviso,
}: {
  lineas?: ConsultaLinea[]
  marcadas: Set<number>
  onCambio: (m: Set<number>) => void
  aviso?: string
}) {
  const items = lineasDeLaCompra(lineas)
  if (items.length === 0) return null

  const alternar = (id: number) => {
    const nueva = new Set(marcadas)
    if (nueva.has(id)) nueva.delete(id)
    else nueva.add(id)
    onCambio(nueva)
  }

  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-[12px] font-semibold text-ink">¿Qué compraron?</span>
      {items.map((l, i) => (
        <label
          key={l.id}
          className={`flex cursor-pointer items-start gap-2 rounded-[7px] border border-line px-2.5 py-1.5 text-[12.5px] ${
            l.alternativa_de_id ? 'ml-5 bg-app/60' : ''
          }`}
        >
          <input type="checkbox" className="mt-0.5" checked={marcadas.has(l.id)} onChange={() => alternar(l.id)} />
          <span className="min-w-0 flex-1 text-ink">
            {l.alternativa_de_id && <span className="text-faint">Alternativa · </span>}
            {l.descripcion || `Ítem ${i + 1}`}
          </span>
          <span className="shrink-0 font-semibold tabular-nums text-ink">{l.importe ? plata(Number(l.importe)) : '—'}</span>
        </label>
      ))}
      <span className="text-[11.5px] text-muted">
        Tildá lo que compraron. Si no son todos, en las listas figura como compra parcial.
      </span>
      {aviso && <span className="text-[11.5px] text-danger">{aviso}</span>}
    </div>
  )
}

/** Lo comprado de una vendida: se ve y se corrige cuando haga falta. */
function CompraGuardada({
  consulta,
  onListo,
  onError,
}: {
  consulta: ConsultaQueSigue
  onListo: (mensaje: string) => void
  onError: (mensaje: string) => void
}) {
  const items = lineasDeLaCompra(consulta.lineas)
  // Sin ninguna marcada (vendidas de antes) se lee como compra total.
  const guardadas = new Set(items.filter((l) => l.comprada).map((l) => l.id))
  const inicial = guardadas.size === 0 ? new Set(items.map((l) => l.id)) : guardadas
  const [marcadas, setMarcadas] = useState<Set<number>>(inicial)
  const [guardando, setGuardando] = useState(false)
  const cambio = [...marcadas].sort().join() !== [...inicial].sort().join()

  if (items.length === 0) return null

  async function guardar() {
    setGuardando(true)
    try {
      await marcarCompra(consulta.id, [...marcadas])
      onListo(marcadas.size < items.length ? 'Listo: quedó como compra parcial.' : 'Listo: quedó como compra total.')
    } catch (err) {
      onError(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  return (
    <div className="flex flex-col gap-2 border-t border-line pt-2.5">
      <ListaDeCompra lineas={consulta.lineas} marcadas={marcadas} onCambio={setMarcadas} />
      {cambio && (
        <div>
          <Boton variante="primario" onClick={guardar} disabled={guardando || marcadas.size === 0}>
            {guardando ? 'Guardando…' : 'Guardar lo comprado'}
          </Boton>
        </div>
      )}
    </div>
  )
}

const SIN_COMPROBANTE: DatosDeComprobante = { tipo: '', numero: '', fecha: '', nota: '' }

/**
 * Los comprobantes de la compra, uno por renglón y sin tope: factura de
 * anticipo, de saldo, remito, nota de crédito si llegó de menos, hasta el vale
 * de retiro del expreso (pedido del cliente, 03-11-2026).
 */
function Comprobantes({
  consulta,
  onListo,
  onError,
}: {
  consulta: ConsultaQueSigue
  onListo: (mensaje: string) => void
  onError: (mensaje: string) => void
}) {
  const catalogos = useCatalogos()
  // null: nada abierto. 0: renglón nuevo. Otro número: el comprobante que se edita.
  const [abierto, setAbierto] = useState<number | null>(null)
  const [datos, setDatos] = useState<DatosDeComprobante>(SIN_COMPROBANTE)
  const [guardando, setGuardando] = useState(false)
  const [intentado, setIntentado] = useState(false)

  const tipos = catalogos?.tipos_comprobante ?? []
  // Un tipo que ya no está en la lista sigue pudiéndose elegir al editar.
  const opciones = [...new Set([...tipos, ...(datos.tipo ? [datos.tipo] : [])])].map((t) => ({ valor: t, texto: t }))

  function abrir(c?: Comprobante) {
    setDatos(c ? { tipo: c.tipo, numero: c.numero ?? '', fecha: c.fecha ?? '', nota: c.nota ?? '' } : SIN_COMPROBANTE)
    setAbierto(c ? c.id : 0)
    setIntentado(false)
  }

  async function guardar() {
    if (!datos.tipo) {
      setIntentado(true)
      return
    }

    setGuardando(true)
    try {
      if (abierto) await editarComprobante(consulta.id, abierto, datos)
      else await agregarComprobante(consulta.id, datos)
      setAbierto(null)
      onListo(abierto ? 'Listo: el comprobante quedó corregido.' : 'Listo: comprobante agregado.')
    } catch (err) {
      onError(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  async function borrar(c: Comprobante) {
    if (!window.confirm(`¿Borramos ${c.tipo}${c.numero ? ' ' + c.numero : ''}?`)) return

    try {
      await borrarComprobante(consulta.id, c.id)
      onListo('Listo: comprobante borrado.')
    } catch (err) {
      onError(mensajeDeError(err))
    }
  }

  const lista = consulta.comprobantes ?? []

  return (
    <div className="flex flex-col gap-2 border-t border-line pt-2.5">
      <div className="flex flex-wrap items-center gap-2.5">
        <span className="text-[12px] font-semibold text-ink">Comprobantes</span>
        {abierto === null && (
          <Boton variante="suave" onClick={() => abrir()}>
            + Agregar comprobante
          </Boton>
        )}
      </div>

      {lista.length === 0 && abierto === null && (
        <p className="text-[11.5px] text-muted">
          Factura de anticipo, de saldo, remito, nota de crédito, vale de retiro del expreso: uno por renglón, los que
          hagan falta.
        </p>
      )}

      {lista.map((c) =>
        abierto === c.id ? null : (
          <div key={c.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-[7px] border border-line px-2.5 py-1.5 text-[12.5px]">
            <Chip tono="neutro">{c.tipo}</Chip>
            <span className="font-semibold tabular-nums text-ink">{c.numero || 'sin número'}</span>
            <span className="text-muted">{c.fecha ? fmtFecha(c.fecha) : 'sin fecha'}</span>
            {c.nota && <span className="min-w-0 flex-1 truncate text-muted">{c.nota}</span>}
            <span className="ml-auto flex gap-2">
              <Boton variante="texto" onClick={() => abrir(c)}>
                Editar
              </Boton>
              <Boton variante="texto" onClick={() => borrar(c)}>
                Borrar
              </Boton>
            </span>
          </div>
        ),
      )}

      {abierto !== null && (
        <div className="flex flex-col gap-2.5 rounded-[7px] border border-brand-200 bg-[#f3f9fe] p-2.5">
          <div className="grid items-end gap-3 sm:grid-cols-3">
            <Lista
              etiqueta="Tipo"
              value={datos.tipo}
              onChange={(e) => setDatos({ ...datos, tipo: e.target.value })}
              opciones={opciones}
              error={intentado && !datos.tipo ? 'Elegí el tipo' : undefined}
            />
            <Texto etiqueta="Número" placeholder="0001-00001234" value={datos.numero} onChange={(e) => setDatos({ ...datos, numero: e.target.value })} />
            <Texto etiqueta="Fecha" type="date" value={datos.fecha} onChange={(e) => setDatos({ ...datos, fecha: e.target.value })} />
          </div>
          <Texto
            etiqueta="Nota"
            placeholder="Por ejemplo: llegó de menos, anticipo del 50%"
            value={datos.nota}
            onChange={(e) => setDatos({ ...datos, nota: e.target.value })}
          />
          <div className="flex flex-wrap gap-2.5">
            <Boton variante="primario" onClick={guardar} disabled={guardando}>
              {guardando ? 'Guardando…' : 'Guardar'}
            </Boton>
            <Boton variante="suave" onClick={() => setAbierto(null)}>
              Cancelar
            </Boton>
          </div>
        </div>
      )}
    </div>
  )
}

function DatosDeLaVenta({
  consulta,
  onListo,
  onError,
}: {
  consulta: ConsultaQueSigue
  onListo: (mensaje: string) => void
  onError: (mensaje: string) => void
}) {
  const inicial = datosDesde(consulta)
  const [datos, setDatos] = useState(inicial)
  const [guardando, setGuardando] = useState(false)
  const cambio = JSON.stringify(datos) !== JSON.stringify(inicial)

  async function guardar() {
    setGuardando(true)
    try {
      onListo(await guardarDatosDeLaVenta(consulta.id, datos))
    } catch (err) {
      onError(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  return (
    <div className="flex flex-col gap-3 border-t border-line pt-2.5">
      {/* El comentario del pedido, siempre a la vista y editable (cliente, 30-10-2026). */}
      <AreaTexto
        etiqueta="Comentario del pedido"
        filas={2}
        placeholder="Por ejemplo: encargado al proveedor, con su N° de orden"
        value={datos.comentario_venta}
        onChange={(e) => setDatos({ ...datos, comentario_venta: e.target.value })}
      />
      <p className="text-[11.5px] text-muted">
        El número del pedido con su fecha y, cuando se factura, el tipo de cambio. Las facturas, remitos y demás
        comprobantes se cargan abajo, uno por renglón.
      </p>
      {/* Número a la izquierda, su fecha a la derecha; etiquetas de un renglón, parejas. */}
      <div className="grid items-end gap-3 sm:grid-cols-2">
        <CamposDeLaVenta datos={datos} onCambio={setDatos} />
      </div>
      <div>
        <Boton variante="primario" onClick={guardar} disabled={guardando || !cambio}>
          {guardando ? 'Guardando…' : 'Guardar los datos de la venta'}
        </Boton>
      </div>
    </div>
  )
}

export default function ComoSiguio({
  consulta,
  onListo,
  onError,
}: {
  consulta: ConsultaQueSigue
  onListo: (mensaje: string) => void
  onError: (mensaje: string) => void
}) {
  const yaCerrada = OPCIONES.find((o) => o.estado === consulta.estado)
  const [corrigiendo, setCorrigiendo] = useState(false)
  const [elegido, setElegido] = useState<EstadoConsulta | null>(null)
  const [comentario, setComentario] = useState('')
  const [fecha, setFecha] = useState(hoy())
  // "La compró": lo que ya se sepa de la venta va en el mismo paso.
  const [datosVenta, setDatosVenta] = useState<Datos>(SIN_DATOS)
  // Qué líneas compraron: al marcarla vendida arrancan todas tildadas.
  const [compradas, setCompradas] = useState<Set<number>>(new Set())
  const [guardando, setGuardando] = useState(false)
  // El aviso del motivo recién cuando intentan guardar, no apenas se abre.
  const [intentado, setIntentado] = useState(false)

  const opcion = OPCIONES.find((o) => o.estado === elegido)
  const falta = opcion?.obligatorio && !comentario.trim()

  function elegir(estado: EstadoConsulta) {
    setElegido(estado)
    setComentario('')
    setFecha(hoy())
    setDatosVenta(datosDesde(consulta))
    setCompradas(new Set(lineasDeLaCompra(consulta.lineas).map((l) => l.id)))
    setIntentado(false)
  }

  function cancelar() {
    setElegido(null)
    setCorrigiendo(false)
  }

  async function guardar() {
    if (!elegido) return
    if (falta || (elegido === 'Vendida' && lineasDeLaCompra(consulta.lineas).length > 0 && compradas.size === 0)) {
      setIntentado(true)
      return
    }

    setGuardando(true)
    try {
      const vendida = elegido === 'Vendida'
      if (vendida) {
        // El comentario del pedido es un campo propio, editable y siempre a la
        // vista: no entra al hilo de observaciones. Las fechas del pedido, el
        // remito y la factura son el "cuándo" de la venta.
        await cambiarEstadoConsulta(consulta.id, {
          estado: elegido,
          comentario_venta: comentario.trim() || undefined,
          nro_pedido_interno: datosVenta.nro_pedido_interno.trim() || undefined,
          fecha_pedido: datosVenta.fecha_pedido || undefined,
          lineas_compradas: [...compradas],
          tipo_cambio_factura: numeroComoSeEscribe(datosVenta.tipo_cambio_factura),
        })
      } else {
        // Al cerrar o reabrir, el motivo queda en el hilo de observaciones.
        const motivo = comentario.trim() || 'Se corrigió: sigue abierta.'
        await cambiarEstadoConsulta(consulta.id, { estado: elegido, comentario: motivo, fecha })
      }
      cancelar()
      onListo(
        vendida
          ? 'Listo: quedó como Vendida. El comentario del pedido, las fechas y la factura se pueden completar y editar abajo cuando lleguen.'
          : `Listo: quedó como "${opcion?.titulo ?? 'Sigue abierta'}". Está anotado en las observaciones.`,
      )
    } catch (err) {
      onError(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  return (
    <div className="flex flex-col gap-2.5 rounded-[12px] border border-line bg-white p-2.5">
      <div className="flex flex-wrap items-center gap-2.5">
        <span className="pl-1 text-[10.5px] font-bold uppercase tracking-wide text-muted">¿Cómo siguió?</span>

        {yaCerrada && !corrigiendo && !elegido ? (
          <>
            <Chip tono={consulta.estado === 'Vendida' ? 'verde' : 'neutro'}>{yaCerrada.titulo}</Chip>
            <span className="text-[11.5px] text-muted">Quedó anotado en las observaciones de abajo.</span>
            <Boton variante="texto" onClick={() => setCorrigiendo(true)}>
              Corregir
            </Boton>
          </>
        ) : !elegido ? (
          <>
            {OPCIONES.filter((o) => o.estado !== consulta.estado).map((o) => (
              <Boton key={o.estado} variante="suave" onClick={() => elegir(o.estado)}>
                {o.boton}
              </Boton>
            ))}
            {corrigiendo && (
              <>
                <Boton variante="suave" onClick={() => elegir(ABIERTA)}>
                  Sigue abierta
                </Boton>
                <Boton variante="texto" onClick={cancelar}>
                  Cancelar
                </Boton>
              </>
            )}
            {!corrigiendo && (
              <span className="text-[11.5px] text-muted">Cuando el cliente responda, marcá cómo terminó.</span>
            )}
          </>
        ) : (
          <span className="text-[12.5px] font-semibold text-ink">{opcion?.titulo ?? 'Sigue abierta'}</span>
        )}
      </div>

      {consulta.estado === 'Vendida' && !corrigiendo && !elegido && (
        <DatosDeLaVenta
          // Se arma de nuevo cuando cambian los datos guardados.
          key={[
            consulta.nro_pedido_interno, consulta.fecha_pedido, consulta.tipo_cambio_factura, consulta.comentario_venta,
          ].join('|')}
          consulta={consulta}
          onListo={onListo}
          onError={onError}
        />
      )}

      {consulta.estado === 'Vendida' && !corrigiendo && !elegido && (
        <>
          <CompraGuardada consulta={consulta} onListo={onListo} onError={onError} />
          <Comprobantes consulta={consulta} onListo={onListo} onError={onError} />
        </>
      )}

      {elegido && (
        <div className="flex flex-col gap-3 border-t border-line pt-2.5">
          {elegido === 'Vendida' ? (
            // Cada dato de la venta con su fecha: número a la izquierda, fecha a la derecha.
            <>
              <div className="grid items-end gap-3 sm:grid-cols-2">
                <CamposDeLaVenta datos={datosVenta} onCambio={setDatosVenta} />
              </div>
              <ListaDeCompra
                lineas={consulta.lineas}
                marcadas={compradas}
                onCambio={setCompradas}
                aviso={intentado && compradas.size === 0 ? 'Marcá al menos un ítem comprado.' : undefined}
              />
            </>
          ) : (
            // Al cerrar o reabrir, una sola fecha; no ocupa media pantalla.
            <div className="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Texto etiqueta="¿Cuándo?" type="date" value={fecha} onChange={(e) => setFecha(e.target.value)} />
            </div>
          )}
          <AreaTexto
            etiqueta={opcion?.pide ?? 'Comentario (si hace falta)'}
            filas={2}
            autoFocus
            placeholder={elegido === 'Vendida' ? 'Por ejemplo: encargado al proveedor, con su N° de orden' : undefined}
            value={comentario}
            onChange={(e) => setComentario(e.target.value)}
            error={intentado && falta ? 'Escribí el motivo: queda en las observaciones.' : undefined}
          />
          <div className="flex flex-wrap gap-2.5">
            <Boton variante="primario" onClick={guardar} disabled={guardando}>
              {guardando ? 'Guardando…' : 'Guardar'}
            </Boton>
            <Boton variante="suave" onClick={cancelar}>
              Cancelar
            </Boton>
          </div>
        </div>
      )}
    </div>
  )
}

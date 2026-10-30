import { useState } from 'react'
import { Boton, Chip } from '../../components/ui'
import { AreaTexto, Texto } from '../../components/ui/form'
import { cambiarEstadoConsulta, guardarDatosDeLaVenta, hoy, mensajeDeError, numeroComoSeEscribe } from '../../lib/indice'
import type { DatosDeLaVenta as Datos } from '../../lib/indice'
import type { EstadoConsulta } from '../../types/indice'

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
  nro_factura: string | null
  nro_pedido_interno?: string | null
  fecha_pedido?: string | null
  nro_remito?: string | null
  fecha_remito?: string | null
  fecha_factura?: string | null
  tipo_cambio_factura?: number | null
  comentario_venta?: string | null
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
  nro_remito: '',
  fecha_remito: '',
  nro_factura: '',
  fecha_factura: '',
  tipo_cambio_factura: '',
  comentario_venta: '',
}

/** Lo que ya estaba guardado, listo para editar. La fecha en blanco se queda en blanco. */
function datosDesde(c: ConsultaQueSigue): Datos {
  return {
    nro_pedido_interno: c.nro_pedido_interno ?? '',
    fecha_pedido: c.fecha_pedido ?? '',
    nro_remito: c.nro_remito ?? '',
    fecha_remito: c.fecha_remito ?? '',
    nro_factura: c.nro_factura ?? '',
    fecha_factura: c.fecha_factura ?? '',
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
      <Texto etiqueta="N° de remito" placeholder="0001-00004321" {...campo('nro_remito')} />
      <Texto etiqueta="Fecha del remito" type="date" {...campo('fecha_remito')} />
      <Texto etiqueta="N° de factura" placeholder="0001-00001234" {...campo('nro_factura')} />
      <Texto etiqueta="Fecha de la factura" type="date" {...campo('fecha_factura')} />
      <Texto etiqueta="Tipo de cambio factura" inputMode="decimal" placeholder="1050,50" {...campo('tipo_cambio_factura')} />
    </>
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
        Se van completando a medida que pasan: el pedido, el remito y, cuando se factura, la factura con su tipo de
        cambio. Cada uno con su fecha.
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
    setIntentado(false)
  }

  function cancelar() {
    setElegido(null)
    setCorrigiendo(false)
  }

  async function guardar() {
    if (!elegido) return
    if (falta) {
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
          nro_remito: datosVenta.nro_remito.trim() || undefined,
          fecha_remito: datosVenta.fecha_remito || undefined,
          nro_factura: datosVenta.nro_factura.trim() || undefined,
          fecha_factura: datosVenta.fecha_factura || undefined,
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
            consulta.nro_pedido_interno, consulta.fecha_pedido, consulta.nro_remito, consulta.fecha_remito,
            consulta.nro_factura, consulta.fecha_factura, consulta.tipo_cambio_factura, consulta.comentario_venta,
          ].join('|')}
          consulta={consulta}
          onListo={onListo}
          onError={onError}
        />
      )}

      {elegido && (
        <div className="flex flex-col gap-3 border-t border-line pt-2.5">
          {elegido === 'Vendida' ? (
            // Cada dato de la venta con su fecha: número a la izquierda, fecha a la derecha.
            <div className="grid items-end gap-3 sm:grid-cols-2">
              <CamposDeLaVenta datos={datosVenta} onCambio={setDatosVenta} />
            </div>
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

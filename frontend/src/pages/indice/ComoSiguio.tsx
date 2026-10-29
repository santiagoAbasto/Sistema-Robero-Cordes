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
  { estado: 'Vendida', boton: 'La compró', titulo: 'Vendida', pide: 'Comentario (si hace falta)', obligatorio: false },
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
  nro_remito?: string | null
  tipo_cambio_factura?: number | null
}

/*
  Lo que se va sabiendo de una vendida, en el orden en que pasa (cliente,
  29-10-2026): el pedido se carga en el Access y da un numero; sale la
  mercaderia con remito; con la conformidad se factura, con su tipo de cambio.
  Se completa de a uno y se puede corregir.
*/
const SIN_DATOS: Datos = { nro_pedido_interno: '', nro_remito: '', nro_factura: '', tipo_cambio_factura: '' }

/** Los cuatro datos de la venta, en una fila: los mismos al marcarla y al completarla despues. */
function CamposDeLaVenta({ datos, onCambio }: { datos: Datos; onCambio: (d: Datos) => void }) {
  const campo = (clave: keyof Datos) => ({
    value: datos[clave],
    onChange: (e: { target: { value: string } }) => onCambio({ ...datos, [clave]: e.target.value }),
  })

  return (
    <>
      <Texto etiqueta="N° de pedido (Access)" placeholder="12345" {...campo('nro_pedido_interno')} />
      <Texto etiqueta="N° de remito" placeholder="0001-00004321" {...campo('nro_remito')} />
      <Texto etiqueta="N° de factura" placeholder="0001-00001234" {...campo('nro_factura')} />
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
  const inicial = {
    nro_pedido_interno: consulta.nro_pedido_interno ?? '',
    nro_remito: consulta.nro_remito ?? '',
    nro_factura: consulta.nro_factura ?? '',
    tipo_cambio_factura: consulta.tipo_cambio_factura == null ? '' : String(consulta.tipo_cambio_factura).replace('.', ','),
  }
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
      <p className="text-[11.5px] text-muted">
        Se van completando a medida que pasan: el pedido, el remito y, cuando se factura, la factura con su tipo de
        cambio.
      </p>
      {/* Etiquetas de un renglón y casillas alineadas abajo: todo a la misma altura. */}
      <div className="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
    setDatosVenta({
      nro_pedido_interno: consulta.nro_pedido_interno ?? '',
      nro_remito: consulta.nro_remito ?? '',
      nro_factura: consulta.nro_factura ?? '',
      tipo_cambio_factura: consulta.tipo_cambio_factura == null ? '' : String(consulta.tipo_cambio_factura).replace('.', ','),
    })
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
      // Siempre queda un renglon en las observaciones, con la fecha y quien lo cargo.
      const nota = comentario.trim() || (elegido === 'Vendida' ? 'La compró.' : 'Se corrigió: sigue abierta.')
      const vendida = elegido === 'Vendida'
      await cambiarEstadoConsulta(consulta.id, {
        estado: elegido,
        comentario: nota,
        fecha,
        ...(vendida
          ? {
              nro_pedido_interno: datosVenta.nro_pedido_interno.trim() || undefined,
              nro_remito: datosVenta.nro_remito.trim() || undefined,
              nro_factura: datosVenta.nro_factura.trim() || undefined,
              tipo_cambio_factura: numeroComoSeEscribe(datosVenta.tipo_cambio_factura),
            }
          : {}),
      })
      cancelar()
      onListo(
        vendida
          ? 'Listo: quedó como Vendida. Lo que falte del pedido, el remito o la factura se completa abajo cuando llegue.'
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
          key={[consulta.nro_pedido_interno, consulta.nro_remito, consulta.nro_factura, consulta.tipo_cambio_factura].join('|')}
          consulta={consulta}
          onListo={onListo}
          onError={onError}
        />
      )}

      {elegido && (
        <div className="flex flex-col gap-3 border-t border-line pt-2.5">
          {/* Todo en una fila y a la misma altura; la fecha no ocupa media pantalla. */}
          <div className="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <Texto etiqueta="¿Cuándo?" type="date" value={fecha} onChange={(e) => setFecha(e.target.value)} />
            {elegido === 'Vendida' && <CamposDeLaVenta datos={datosVenta} onCambio={setDatosVenta} />}
          </div>
          <AreaTexto
            etiqueta={opcion?.pide ?? 'Comentario (si hace falta)'}
            filas={2}
            autoFocus
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

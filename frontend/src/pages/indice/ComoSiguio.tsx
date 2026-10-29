import { useState } from 'react'
import { Boton, Chip } from '../../components/ui'
import { AreaTexto, Texto } from '../../components/ui/form'
import { cambiarEstadoConsulta, hoy, mensajeDeError } from '../../lib/indice'
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

export default function ComoSiguio({
  consulta,
  onListo,
  onError,
}: {
  consulta: { id: number; estado: EstadoConsulta; nro_factura: string | null }
  onListo: (mensaje: string) => void
  onError: (mensaje: string) => void
}) {
  const yaCerrada = OPCIONES.find((o) => o.estado === consulta.estado)
  const [corrigiendo, setCorrigiendo] = useState(false)
  const [elegido, setElegido] = useState<EstadoConsulta | null>(null)
  const [comentario, setComentario] = useState('')
  const [fecha, setFecha] = useState(hoy())
  const [factura, setFactura] = useState('')
  const [guardando, setGuardando] = useState(false)
  // El aviso del motivo recién cuando intentan guardar, no apenas se abre.
  const [intentado, setIntentado] = useState(false)

  const opcion = OPCIONES.find((o) => o.estado === elegido)
  const falta = opcion?.obligatorio && !comentario.trim()

  function elegir(estado: EstadoConsulta) {
    setElegido(estado)
    setComentario('')
    setFecha(hoy())
    setFactura(estado === 'Vendida' ? (consulta.nro_factura ?? '') : '')
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
      await cambiarEstadoConsulta(consulta.id, {
        estado: elegido,
        comentario: nota,
        fecha,
        nro_factura: elegido === 'Vendida' ? factura.trim() || undefined : undefined,
      })
      cancelar()
      onListo(`Listo: quedó como "${opcion?.titulo ?? 'Sigue abierta'}". Está anotado en las observaciones.`)
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
            <Chip tono={consulta.estado === 'Vendida' ? 'verde' : 'neutro'}>
              {yaCerrada.titulo}
              {consulta.estado === 'Vendida' && consulta.nro_factura ? `  ·  Factura ${consulta.nro_factura}` : ''}
            </Chip>
            <span className="text-[11.5px] text-muted">El detalle quedó en las observaciones de abajo.</span>
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

      {elegido && (
        <div className="flex flex-col gap-3 border-t border-line pt-2.5">
          <div className="grid gap-3 sm:grid-cols-2">
            {elegido === 'Vendida' && (
              <Texto
                etiqueta="Número de factura"
                ayuda="si ya la tienen; se puede dejar vacío"
                value={factura}
                onChange={(e) => setFactura(e.target.value)}
                placeholder="0001-00001234"
              />
            )}
            <Texto etiqueta="¿Cuándo?" type="date" value={fecha} onChange={(e) => setFecha(e.target.value)} />
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

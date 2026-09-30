import { useEffect, useState } from 'react'
import { FileText } from 'lucide-react'
import { Aviso, Card } from '../../components/ui'
import { mensajeDeError, urlDeLaHojaPrevia } from '../../lib/indice'

/* ---------------------------------------------------------------------------
   La cotización como la ve el cliente.

   "Las emitidas no las vemos como el PDF, preview", "hay mucho scroll",
   "similar a como está la presentación del PDF, que está más compacta".
   Una emitida se abría en el editor entero, bloqueado: tres pantallas de
   campos grises para algo que ya no se puede tocar.

   No es una copia de la hoja armada de nuevo en la pantalla: ES la hoja, la
   misma que sale al imprimir. Una copia se desfasaría de la hoja la primera
   vez que alguien cambie una de las dos, y lo que importa es ver exactamente
   lo que tiene el cliente.
--------------------------------------------------------------------------- */

export default function HojaPrevia({
  consultaId,
  recarga,
}: {
  consultaId: number
  /** Cambia cuando la cotización se vuelve a leer: la hoja se rearma. */
  recarga?: unknown
}) {
  const [url, setUrl] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let vivo = true
    let creada: string | null = null

    setUrl(null)
    setError(null)

    urlDeLaHojaPrevia(consultaId)
      .then((u) => {
        creada = u

        // Si ya se fue de la pantalla, se libera en el acto.
        if (vivo) setUrl(u)
        else URL.revokeObjectURL(u)
      })
      .catch((err) => vivo && setError(mensajeDeError(err)))

    return () => {
      vivo = false

      if (creada) URL.revokeObjectURL(creada)
    }
  }, [consultaId, recarga])

  if (error) {
    return <Aviso tono="ambar">No se pudo armar la hoja: {error}</Aviso>
  }

  if (!url) {
    return (
      <Card className="grid h-[70vh] place-items-center">
        <span className="inline-flex items-center gap-2 text-[12.5px] text-muted">
          <FileText size={16} strokeWidth={2} />
          Armando la hoja…
        </span>
      </Card>
    )
  }

  return (
    <Card className="overflow-hidden p-0">
      {/*
        Al ancho de la hoja y sin la columna de miniaturas: el visor abria al
        50% con la columna al costado, y la hoja quedaba chica para leer.
      */}
      <iframe
        title="Hoja de la cotización"
        src={`${url}#navpanes=0&view=FitH`}
        className="block h-[82vh] w-full border-0"
      />
    </Card>
  )
}

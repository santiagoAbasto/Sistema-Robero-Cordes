import { useEffect, useState } from 'react'
import { Download, FileText } from 'lucide-react'
import { Aviso, Boton, Card } from '../../components/ui'
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
  nombre,
}: {
  consultaId: number
  /** Cambia cuando la cotización se vuelve a leer: la hoja se rearma. */
  recarga?: unknown
  /** Con qué nombre se guarda el archivo al bajarlo desde acá. */
  nombre?: string
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
        El botón de bajar, acá arriba y no sólo el del visor.

        El visor del navegador guarda el archivo con la dirección interna que
        usa para mostrarlo —"ddcecb32-cb81-4c59-9610-59a9c78f1a6f.pdf"— y ese
        nombre no lo elegimos nosotros (Roberto, 20-10-2026). Bajándolo desde
        acá sale con el nombre de la cotización.
      */}
      {nombre && (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-[18px] py-2.5">
          <span className="truncate text-[11.5px] text-muted">{nombre}</span>
          <Boton
            variante="suave"
            onClick={() => {
              const a = document.createElement('a')
              a.href = url
              a.download = `${nombre}.pdf`
              a.click()
            }}
          >
            <Download size={13} strokeWidth={2.4} />
            Descargar
          </Boton>
        </div>
      )}
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

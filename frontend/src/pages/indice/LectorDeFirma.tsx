import { useState } from 'react'
import { Wand2 } from 'lucide-react'
import { Boton, Card } from '../../components/ui'
import { AreaTexto } from '../../components/ui/form'
import { imagenPegada, leerFirmaDeMail, mensajeDeError } from '../../lib/indice'
import type { DatosDeLaFirma } from '../../lib/indice'

/**
 * El asistente que completa la ficha de una empresa: se pega el pie de un
 * mail, la consulta de la web o una imagen, y devuelve lo que pudo leer.
 *
 * Lo comparten el alta y Modificar. Acá vive solo el recuadro y la llamada; lo
 * que se hace con los datos lo decide cada pantalla (en el alta se completan
 * empresa y contacto; en Modificar, sólo lo que esté vacío de la empresa).
 * Lo que vuelve es una PROPUESTA: nada se guarda hasta que la persona revisa.
 */
export default function LectorDeFirma({
  onDatos,
  onError,
  aclaracion = 'Completa lo que esta vacio; lo que ya cargaste no se toca',
}: {
  onDatos: (datos: DatosDeLaFirma, mensaje: string) => void
  /** null limpia el error de antes. */
  onError: (mensaje: string | null) => void
  aclaracion?: string
}) {
  const [firma, setFirma] = useState('')
  const [leyendo, setLeyendo] = useState(false)

  async function leer(entrada: string | File = firma) {
    setLeyendo(true)
    onError(null)

    try {
      const { datos, mensaje, texto } = await leerFirmaDeMail(entrada)
      // Lo que copio la IA de la imagen queda a la vista para revisarlo.
      if (entrada instanceof File) setFirma(texto)

      onDatos(datos, mensaje)
    } catch (err) {
      onError(mensajeDeError(err))
    } finally {
      setLeyendo(false)
    }
  }

  return (
    <Card className="flex flex-col gap-3 border-brand-200 bg-[#f3f9fe] p-[22px]">
      <div className="flex flex-wrap items-center gap-2.5">
        {/*
          Dice las dos cosas que entran: si dijera solo "mail", nadie pegaria
          aca la ficha de una consulta de la web, que es lo que mas llega.
        */}
        <h2 className="text-[15px] font-semibold text-brand-600">
          Pega el pie de un mail o la ficha de la web
        </h2>
        <span className="ml-auto text-[11px] text-[#6c93ae]">{aclaracion}</span>
      </div>

      <AreaTexto
        etiqueta="Tal cual llego"
        ayuda="el mail con su firma, o la consulta de la web entera: de ahi salen la empresa, el CUIT, la direccion y el contacto"
        filas={4}
        value={firma}
        onChange={(e) => setFirma(e.target.value)}
        onPaste={(e) => {
          const imagen = imagenPegada(e)
          if (imagen) {
            e.preventDefault()
            void leer(imagen)
          }
        }}
        placeholder={'From: Pablo Ferreyra - Apex Metalurgica <pferreyra@apex.example>\n\nPablo Ferreyra\nSupervisor de Mantenimiento\nCel: (2954) 15-412233\nParque Industrial, Calle 9 esq. 10 | CP 6300\nSanta Rosa, La Pampa, Argentina'}
      />

      <div className="flex flex-wrap items-center gap-3">
        <Boton variante="primario" onClick={() => leer()} disabled={leyendo || !firma.trim()}>
          <Wand2 size={15} strokeWidth={2.2} />
          {leyendo ? 'Leyendo…' : 'Completar con estos datos'}
        </Boton>
        <label className="cursor-pointer text-[12px] text-brand-600 underline">
          o elegí una imagen
          <input
            type="file"
            accept="image/png,image/jpeg,image/webp"
            className="hidden"
            onChange={(e) => {
              const imagen = e.target.files?.[0]
              e.target.value = ''
              if (imagen) void leer(imagen)
            }}
          />
        </label>
        <p className="min-w-[280px] flex-1 text-[11.5px] leading-relaxed text-[#6c93ae]">
          El texto se lee sin IA. Una imagen (Ctrl+V o elegida) la copia la IA y se lee con las
          mismas reglas; lo que las reglas no encuentran lo completa la IA, solo si está
          escrito. Si la localidad no está en la lista, se ubica con Google.
        </p>
      </div>
    </Card>
  )
}

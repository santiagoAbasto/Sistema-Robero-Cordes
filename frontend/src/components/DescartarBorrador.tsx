import { useEffect, useState } from 'react'
import { ShieldAlert } from 'lucide-react'
import { Modal, Texto } from './ui/form'
import { descartarConsulta, mensajeDeError } from '../lib/indice'

/* ---------------------------------------------------------------------------
   Descartar un borrador, con la clave del administrador.

   Descartar borra algo, así que no alcanza con "¿seguro?": hay que escribir la
   clave. El servidor la verifica, cuenta los intentos y a la tercera errada
   hace esperar una hora. El borrador no se pierde: queda 30 días en la papelera
   y se puede restaurar. Sólo se muestra el disparador a los administradores.
--------------------------------------------------------------------------- */

export default function DescartarBorrador({
  consulta,
  onCerrar,
  onListo,
}: {
  /** El borrador a descartar. null = cerrado. */
  consulta: { id: number; empresa: string } | null
  onCerrar: () => void
  /** Se llamó con éxito: el padre recarga y avisa. */
  onListo: (mensaje: string) => void
}) {
  const [clave, setClave] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [trabajando, setTrabajando] = useState(false)

  // Cada vez que se abre para otro borrador, se arranca limpio.
  useEffect(() => {
    if (consulta) {
      setClave('')
      setError(null)
    }
  }, [consulta])

  async function descartar() {
    if (!consulta || trabajando) return
    setTrabajando(true)
    setError(null)

    try {
      await descartarConsulta(consulta.id, clave)
      onListo('Borrador enviado a la papelera. Se puede restaurar por 30 días.')
      onCerrar()
    } catch (err) {
      // Clave incorrecta, intentos que quedan o "esperá una hora": todo viene
      // del servidor con el texto ya armado.
      setError(mensajeDeError(err, 'No se pudo descartar. Probá de nuevo.'))
    } finally {
      setTrabajando(false)
    }
  }

  return (
    <Modal
      abierto={consulta !== null}
      titulo="Descartar el borrador"
      bajada={consulta ? `Se descarta el borrador de ${consulta.empresa}. Queda 30 días en la papelera por si hay que restaurarlo.` : undefined}
      onCerrar={onCerrar}
      onGuardar={descartar}
      guardando={trabajando}
      textoGuardar="Descartar"
      ancho="max-w-md"
    >
      <div className="flex flex-col gap-3">
        <div className="flex items-start gap-2.5 rounded-[9px] border border-[#f3d9a8] bg-[#fff8ee] px-3.5 py-2.5 text-[12px] leading-relaxed text-[#b45309]">
          <ShieldAlert size={15} strokeWidth={2.2} className="mt-0.5 shrink-0" />
          <span>Para descartar hace falta tu clave de administrador. A los tres intentos fallidos hay que esperar una hora.</span>
        </div>

        <Texto
          etiqueta="Tu clave"
          type="password"
          autoFocus
          value={clave}
          error={error ?? undefined}
          onChange={(e) => setClave(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && descartar()}
          placeholder="••••••••"
        />
      </div>
    </Modal>
  )
}

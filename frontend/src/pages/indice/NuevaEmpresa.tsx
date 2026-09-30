import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Wand2 } from 'lucide-react'
import { Boton, Card, PageHeader, Aviso } from '../../components/ui'
import { AreaTexto, Guardado } from '../../components/ui/form'
import EmpresaForm, { estadoInicial } from './EmpresaForm'
import type { EstadoEmpresaForm } from './EmpresaForm'
import { crearEmpresa, leerFirmaDeMail, mensajeDeError } from '../../lib/indice'

/** Alta de una empresa nueva. Antes era una pantalla aparte del índice. */
export default function NuevaEmpresa() {
  const navigate = useNavigate()
  const [valores, setValores] = useState<EstadoEmpresaForm>(estadoInicial())
  const [guardando, setGuardando] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)

  /*
    Pegar el pie de un mail y que complete la ficha.

    "Posibilidad de copiar algo —ejemplo pie de mail— para que complete la
    información". Antes había que mirar el mail en una ventana y tipear en la
    otra: nombre, cargo, teléfono, dirección, localidad, provincia. Doce
    campos a mano con el dato delante de los ojos.

    Lo que vuelve es una propuesta: sólo completa lo que esté vacío, así
    pegar un segundo mail no pisa lo que ya se corrigió a mano.
  */
  const [firma, setFirma] = useState('')
  const [leyendo, setLeyendo] = useState(false)

  async function leerLaFirma() {
    setLeyendo(true)
    setError(null)

    try {
      const { datos, mensaje } = await leerFirmaDeMail(firma)

      setValores((v) => ({
        ...v,
        nombre: v.nombre || (datos.empresa ?? ''),
        direccion: v.direccion || (datos.direccion ?? ''),
        codigo_postal: v.codigo_postal || (datos.codigo_postal ?? ''),
        pais_id: v.pais_id ?? datos.pais_id,
        provincia_id: v.provincia_id ?? datos.provincia_id,
        localidad_id: v.localidad_id ?? datos.localidad_id,
        // El contacto se carga desde la ficha, que es donde viven sus
        // teléfonos y sus mails. Acá queda anotado para no perderlo.
        observacion_general:
          v.observacion_general ||
          [
            datos.contacto && `Contacto: ${datos.contacto}`,
            datos.cargo,
            datos.telefono && `Tel: ${datos.telefono}`,
            datos.mail,
            datos.web,
          ]
            .filter(Boolean)
            .join(' · '),
      }))

      setAviso(mensaje)
    } catch (err) {
      setError(mensajeDeError(err))
    } finally {
      setLeyendo(false)
    }
  }

  async function guardar() {
    if (!valores.nombre.trim()) {
      setError('El nombre de la empresa es lo unico que no puede quedar vacio.')

      return
    }

    setGuardando(true)
    setError(null)

    try {
      const empresa = await crearEmpresa(valores)
      setAviso('Empresa creada.')
      navigate(`/empresas/${empresa.id}`, { replace: true })
    } catch (err) {
      setError(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb={
          <span className="flex items-center gap-1.5">
            <Link to="/empresas" className="hover:text-brand-600">
              Empresas
            </Link>
            <span>›</span>
            <Link to="/empresas/registros" className="hover:text-brand-600">
              Cons./Modif. Registros
            </Link>
          </span>
        }
        titulo="Nueva empresa"
        bajada="Se carga con lo que haya. Lo único imprescindible es el nombre; el resto se completa cuando aparezca."
        acciones={
          <>
            <Link to="/empresas/registros">
              <Boton variante="suave">Cancelar</Boton>
            </Link>
            <Boton variante="primario" onClick={guardar} disabled={guardando}>
              {guardando ? 'Guardando…' : 'Guardar empresa'}
            </Boton>
          </>
        }
      />

      {error && <Aviso tono="ambar">{error}</Aviso>}

      <Card className="flex flex-col gap-3 border-brand-200 bg-[#f3f9fe] p-[22px]">
        <div className="flex flex-wrap items-center gap-2.5">
          {/*
            Dice las dos cosas que entran: si dijera solo "mail", nadie pegaria
            aca la ficha de una consulta de la web, que es lo que mas llega.
          */}
          <h2 className="text-[15px] font-semibold text-brand-600">
            Pega el pie de un mail o la ficha de la web
          </h2>
          <span className="ml-auto text-[11px] text-[#6c93ae]">
            Completa lo que esta vacio; lo que ya cargaste no se toca
          </span>
        </div>

        <AreaTexto
          etiqueta="Tal cual llego"
          ayuda="el mail con su firma, o la consulta de la web entera: de ahi salen la empresa, la direccion y el contacto"
          filas={4}
          value={firma}
          onChange={(e) => setFirma(e.target.value)}
          placeholder={'From: Gonzalo Sack - Apex Metalurgica <gsack@apex.com.ar>\n\nGonzalo Sack\nSupervisor de Mantenimiento\nCel: (2954) 15-584584\nParque Industrial, Calle 9 esq. 10 | CP 6300\nSanta Rosa, La Pampa, Argentina'}
        />

        <div className="flex flex-wrap items-center gap-3">
          <Boton variante="primario" onClick={leerLaFirma} disabled={leyendo || !firma.trim()}>
            <Wand2 size={15} strokeWidth={2.2} />
            {leyendo ? 'Leyendo…' : 'Completar con estos datos'}
          </Boton>
          <p className="min-w-[280px] flex-1 text-[11.5px] leading-relaxed text-[#6c93ae]">
            Sin inteligencia artificial. La localidad y la provincia se enganchan con las que ya
            estan en el catalogo: si no estan, quedan vacias y se eligen a mano.
          </p>
        </div>
      </Card>

      <Card className="p-[22px]">
        <EmpresaForm
          valores={valores}
          onChange={setValores}
          errorNombre={error && !valores.nombre.trim() ? 'Falta el nombre' : undefined}
        />
      </Card>

      <Aviso tono="verde">
        Después de guardarla vas a poder cargarle los contactos, las razones sociales a las que se
        factura y las condiciones de trabajo desde su propia ficha.
      </Aviso>

      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </div>
  )
}

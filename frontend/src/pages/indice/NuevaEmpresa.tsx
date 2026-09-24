import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Boton, Card, PageHeader, Aviso } from '../../components/ui'
import { Guardado } from '../../components/ui/form'
import EmpresaForm, { estadoInicial } from './EmpresaForm'
import type { EstadoEmpresaForm } from './EmpresaForm'
import { crearEmpresa, mensajeDeError } from '../../lib/indice'

/** Alta de una empresa nueva. Antes era una pantalla aparte del índice. */
export default function NuevaEmpresa() {
  const navigate = useNavigate()
  const [valores, setValores] = useState<EstadoEmpresaForm>(estadoInicial())
  const [guardando, setGuardando] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)

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

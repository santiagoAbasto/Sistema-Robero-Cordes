import { useEffect, useState } from 'react'
import { AlertTriangle, Database, Play } from 'lucide-react'
import api from '../../lib/api'
import { Aviso, Boton, Card, Chip, PageHeader } from '../../components/ui'
import { Texto } from '../../components/ui/form'

/* ---------------------------------------------------------------------------
   Traer los datos del sistema anterior.

   Es la pantalla más destructiva del sistema: reemplaza TODO lo cargado. Por
   eso arranca en modo prueba, muestra qué haría, y recién después deja
   aplicar — y para aplicar hay que escribir la frase completa.
--------------------------------------------------------------------------- */

interface Estado {
  hay: {
    empresas: number
    cotizaciones: number
    lineas: number
    materiales: number
    materiales_con_densidad: number
    formas: number
    formas_con_calculo: number
  }
  carpeta_sugerida: string
  archivos_necesarios: string[]
  confirmacion: string
}

const numero = (n: number) => n.toLocaleString('es-AR')

export default function DatosDelSistemaAnterior() {
  const [estado, setEstado] = useState<Estado | null>(null)
  const [carpeta, setCarpeta] = useState('')
  const [confirmacion, setConfirmacion] = useState('')
  const [salida, setSalida] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [corriendo, setCorriendo] = useState(false)
  const [seAplico, setSeAplico] = useState(false)

  async function traerEstado() {
    try {
      const { data } = await api.get<Estado>('/migracion')
      setEstado(data)
      setCarpeta((c) => c || data.carpeta_sugerida)
    } catch {
      setError('No se pudo leer el estado. Esta pantalla es solo para administradores.')
    }
  }

  useEffect(() => {
    traerEstado()
  }, [])

  async function importar(aplicar: boolean) {
    setCorriendo(true)
    setError(null)
    setSalida(null)

    try {
      const { data } = await api.post('/migracion/importar', { carpeta, aplicar, confirmacion })
      setSalida(data.salida)
      setSeAplico(data.aplicado)

      if (data.aplicado) {
        setConfirmacion('')
        traerEstado()
      }
    } catch (e) {
      const r = (e as { response?: { data?: { mensaje?: string } } }).response
      setError(r?.data?.mensaje ?? 'No se pudo correr la importación.')
    } finally {
      setCorriendo(false)
    }
  }

  const puedeAplicar = estado !== null && confirmacion === estado.confirmacion

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        titulo="Datos del sistema anterior"
        bajada="Trae las empresas, las cotizaciones y los materiales del Access. Reemplaza todo lo que haya cargado."
      />

      {error && <Aviso tono="ambar">{error}</Aviso>}

      {estado && (
        <Card className="flex flex-col gap-3 p-[22px]">
          <div className="flex flex-wrap items-center gap-2.5">
            <Database size={15} strokeWidth={2} className="text-brand-600" />
            <h2 className="text-[15px] font-semibold text-ink">Lo que hay cargado ahora</h2>
          </div>

          <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
            {[
              ['Empresas', estado.hay.empresas],
              ['Cotizaciones', estado.hay.cotizaciones],
              ['Líneas cotizadas', estado.hay.lineas],
              ['Materiales', estado.hay.materiales],
              ['— con densidad cargada', estado.hay.materiales_con_densidad],
              ['Formas', estado.hay.formas],
              ['— que calculan peso', estado.hay.formas_con_calculo],
            ].map(([que, cuantos]) => (
              <div
                key={que as string}
                className="flex items-baseline justify-between rounded-[7px] border border-line bg-app px-[13px] py-2.5"
              >
                <span className="text-[12px] text-muted">{que}</span>
                <span className="text-[14px] font-semibold tabular-nums text-ink">
                  {numero(cuantos as number)}
                </span>
              </div>
            ))}
          </div>
        </Card>
      )}

      <Card className="flex flex-col gap-3 p-[22px]">
        <h2 className="text-[15px] font-semibold text-ink">De dónde se traen</h2>

        <Texto
          etiqueta="Carpeta con los archivos exportados del Access"
          ayuda="en el servidor"
          value={carpeta}
          onChange={(e) => setCarpeta(e.target.value)}
        />

        {estado && (
          <p className="text-[11.5px] text-faint">
            Tienen que estar los seis: {estado.archivos_necesarios.join(', ')}
          </p>
        )}

        <div className="flex flex-wrap items-center gap-2.5">
          <Boton variante="suave" onClick={() => importar(false)} disabled={corriendo || !carpeta}>
            <Play size={13} strokeWidth={2.4} />
            {corriendo ? 'Leyendo…' : 'Probar sin escribir'}
          </Boton>
          <span className="text-[11.5px] text-muted">
            Cuenta lo que haría, sin tocar nada. Conviene hacerlo siempre primero.
          </span>
        </div>
      </Card>

      {salida && (
        <Card className="flex flex-col gap-2 p-[22px]">
          <div className="flex flex-wrap items-center gap-2.5">
            <h2 className="text-[15px] font-semibold text-ink">Resultado</h2>
            <Chip tono={seAplico ? 'brand' : 'neutro'}>
              {seAplico ? 'aplicado' : 'solo prueba'}
            </Chip>
          </div>
          <pre className="overflow-x-auto rounded-[7px] border border-line bg-app p-3 text-[11.5px] leading-relaxed text-ink">
            {salida}
          </pre>
        </Card>
      )}

      <Card className="flex flex-col gap-3 border-[#f3d9a6] bg-[#fffdf7] p-[22px]">
        <div className="flex flex-wrap items-center gap-2.5">
          <AlertTriangle size={15} strokeWidth={2} className="text-warning-ink" />
          <h2 className="text-[15px] font-semibold text-warning-ink">Reemplazar los datos</h2>
        </div>

        <p className="text-[12.5px] leading-relaxed text-muted">
          Esto <strong>borra todas las empresas, cotizaciones y materiales</strong> que haya
          cargados y los reemplaza por los del sistema anterior. Los usuarios y sus permisos no se
          tocan. No se puede deshacer desde acá: si hay datos que valen, sacá una copia de la base
          antes.
        </p>

        {estado && (
          <Texto
            etiqueta={`Escribí "${estado.confirmacion}" para habilitar`}
            value={confirmacion}
            onChange={(e) => setConfirmacion(e.target.value)}
            placeholder={estado.confirmacion}
          />
        )}

        <div>
          <Boton
            variante="peligro"
            onClick={() => importar(true)}
            disabled={corriendo || !puedeAplicar || !carpeta}
          >
            {corriendo ? 'Importando…' : 'Reemplazar los datos ahora'}
          </Boton>
        </div>
      </Card>
    </div>
  )
}

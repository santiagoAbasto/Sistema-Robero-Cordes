import { useEffect, useState } from 'react'
import { ArrowRight, Search } from 'lucide-react'
import api from '../../lib/api'
import { Aviso, Boton, Card, Cargando, Chip, PageHeader, SinResultados } from '../../components/ui'
import { Texto } from '../../components/ui/form'

/* ---------------------------------------------------------------------------
   Cómo estaban los datos antes y cómo están ahora.

   PANTALLA TEMPORAL. Es para que CORDES revise con sus propios datos que la
   carga está bien: a la izquierda la ficha del sistema anterior tal cual, a la
   derecha la del sistema nuevo. Cuando den el visto bueno se borra esta
   pantalla, su ruta en App.tsx, la entrada del menú y el endpoint
   /migracion/comparacion.
--------------------------------------------------------------------------- */

type Lado = Record<string, string> | null

interface Fila {
  clave: string | number
  antes: Record<string, string>
  ahora: Lado
}

interface Respuesta {
  total: number
  pagina: number
  paginas: number
  filas: Fila[]
}

const QUE = [
  { id: 'cotizaciones', label: 'Cotizaciones' },
  { id: 'materiales', label: 'Materiales' },
  { id: 'empresas', label: 'Empresas' },
] as const

type Que = (typeof QUE)[number]['id']

const numero = (n: number) => n.toLocaleString('es-AR')

/** Un lado de la comparación: los campos uno debajo del otro. */
function Ficha({ datos, tono }: { datos: Lado; tono: 'antes' | 'ahora' }) {
  if (datos === null) {
    return (
      <div className="flex min-h-[80px] items-center justify-center rounded-[7px] border border-dashed border-line px-3 py-4 text-[12px] text-faint">
        No quedó cargada
      </div>
    )
  }

  return (
    <div
      className={
        tono === 'antes'
          ? 'flex flex-col gap-2 rounded-[7px] border border-line bg-app px-[13px] py-3'
          : 'flex flex-col gap-2 rounded-[7px] border border-brand-200 bg-brand-50/40 px-[13px] py-3'
      }
    >
      {Object.entries(datos).map(([campo, valor]) => (
        <div key={campo} className="flex flex-col gap-0.5">
          <span className="text-[10.5px] uppercase tracking-wide text-faint">{campo}</span>
          <span className="whitespace-pre-wrap break-words text-[12.5px] leading-relaxed text-ink">
            {valor || '—'}
          </span>
        </div>
      ))}
    </div>
  )
}

export default function AntesYAhora() {
  const [que, setQue] = useState<Que>('cotizaciones')
  const [buscar, setBuscar] = useState('')
  const [pagina, setPagina] = useState(1)
  const [datos, setDatos] = useState<Respuesta | null>(null)
  const [cargando, setCargando] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let vigente = true
    setCargando(true)
    setError(null)

    api
      .get<Respuesta>('/migracion/comparacion', { params: { que, buscar, pagina } })
      .then(({ data }) => vigente && setDatos(data))
      .catch(() => vigente && setError('No se pudo leer la comparación. Es solo para administradores.'))
      .finally(() => vigente && setCargando(false))

    return () => {
      vigente = false
    }
  }, [que, buscar, pagina])

  function cambiarQue(nuevo: Que) {
    setQue(nuevo)
    setPagina(1)
  }

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        titulo="Cómo estaban los datos y cómo están ahora"
        bajada="A la izquierda, la ficha del sistema anterior tal cual. A la derecha, la misma ficha ya cargada."
      />

      <Aviso tono="ambar">
        Esta pantalla es para revisar la carga. Cuando esté todo conforme se saca.
      </Aviso>

      {error && <Aviso tono="ambar">{error}</Aviso>}

      <Card className="flex flex-col gap-3 p-[22px]">
        <div className="flex flex-wrap items-center gap-2">
          {QUE.map((o) => (
            <Boton
              key={o.id}
              variante={que === o.id ? 'primario' : 'suave'}
              onClick={() => cambiarQue(o.id)}
            >
              {o.label}
            </Boton>
          ))}
        </div>

        <Texto
          etiqueta="Buscar"
          ayuda="por nombre, número, lo que sea"
          value={buscar}
          onChange={(e) => {
            setBuscar(e.target.value)
            setPagina(1)
          }}
          placeholder="Ej.: FERRUM, Titanio, 4201-5000"
        />

        {datos && (
          <p className="flex flex-wrap items-center gap-2 text-[11.5px] text-muted">
            <Search size={12} strokeWidth={2.2} />
            {numero(datos.total)} fichas
            {datos.paginas > 1 && <span>· página {datos.pagina} de {numero(datos.paginas)}</span>}
          </p>
        )}
      </Card>

      {cargando && <Cargando texto="Comparando…" />}

      {!cargando && datos?.filas.length === 0 && (
        <SinResultados titulo="No hay fichas con eso" detalle="Probá con otra palabra." />
      )}

      {!cargando &&
        datos?.filas.map((f) => (
          <Card key={f.clave} className="flex flex-col gap-2.5 p-[22px]">
            <div className="flex flex-wrap items-center gap-2.5">
              <Chip tono="neutro">Antes</Chip>
              <ArrowRight size={13} strokeWidth={2.4} className="text-faint" />
              <Chip tono="brand">Ahora</Chip>
            </div>

            <div className="grid gap-2.5 lg:grid-cols-2">
              <Ficha datos={f.antes} tono="antes" />
              <Ficha datos={f.ahora} tono="ahora" />
            </div>
          </Card>
        ))}

      {!cargando && datos && datos.paginas > 1 && (
        <div className="flex flex-wrap items-center justify-center gap-2.5">
          <Boton variante="suave" onClick={() => setPagina((p) => p - 1)} disabled={pagina <= 1}>
            Anteriores
          </Boton>
          <span className="text-[12px] text-muted">
            {numero(pagina)} de {numero(datos.paginas)}
          </span>
          <Boton
            variante="suave"
            onClick={() => setPagina((p) => p + 1)}
            disabled={pagina >= datos.paginas}
          >
            Siguientes
          </Boton>
        </div>
      )}
    </div>
  )
}

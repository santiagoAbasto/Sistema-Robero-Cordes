import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Printer } from 'lucide-react'
import {
  Boton,
  Card,
  CardHeader,
  Cargando,
  Chip,
  NotaPie,
  PageHeader,
  SinResultados,
  Tabla,
  Td,
  Th,
} from '../../components/ui'
import { Lista, Texto } from '../../components/ui/form'
import { fechaHora, traerEmpresa, traerHistorial, useCarga, useCatalogos } from '../../lib/indice'

const TONO_ACCION: Record<string, 'neutro' | 'brand' | 'verde' | 'ambar' | 'violeta' | 'rojo'> = {
  Alta: 'neutro',
  Modificacion: 'brand',
  Archivado: 'rojo',
  Impresion: 'violeta',
  Vencimiento: 'ambar',
}

/**
 * Ver cambios.
 *
 * Todo lo que se tocó en esta empresa y en sus cotizaciones: qué cambió, quién
 * lo cambió, cuándo y qué decía antes. Nadie carga esto a mano: se anota solo.
 */
export default function ControlDeCambios() {
  const { id } = useParams<{ id: string }>()
  const catalogos = useCatalogos()

  const [desde, setDesde] = useState('')
  const [hasta, setHasta] = useState('')
  const [usuarioId, setUsuarioId] = useState('')
  const [accion, setAccion] = useState('')

  const { datos: empresa } = useCarga(() => traerEmpresa(id!), [id])
  const { datos, cargando, error } = useCarga(
    () => traerHistorial(id!, { desde, hasta, usuario_id: usuarioId, accion }),
    [id, desde, hasta, usuarioId, accion],
  )

  const cambios = datos?.data ?? []

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb={
          <span className="flex items-center gap-1.5">
            <Link to="/empresas" className="hover:text-brand-600">
              Empresas
            </Link>
            <span>›</span>
            <Link to={`/empresas/${id}`} className="hover:text-brand-600">
              {empresa?.nombre ?? 'Ficha'}
            </Link>
          </span>
        }
        titulo="Ver cambios"
        chips={<Chip tono="verde">se anota solo</Chip>}
        bajada="Todo lo que se tocó en esta empresa y en sus cotizaciones: qué cambió, quién lo cambió, cuándo, y qué decía antes. Nadie carga esto a mano."
        acciones={
          <>
            <Link to={`/empresas/${id}`}>
              <Boton variante="suave">Volver a la ficha</Boton>
            </Link>
            <Boton variante="suave" onClick={() => window.print()}>
              <Printer size={15} strokeWidth={2} />
              Imprimir el historial
            </Boton>
          </>
        }
      />

      <Card className="flex flex-col gap-3 p-[22px]">
        <div className="flex flex-wrap items-center gap-2.5">
          <h2 className="text-[15px] font-semibold text-ink">Filtrar</h2>
          <span className="ml-auto text-[11.5px] text-faint">
            {cargando ? 'Buscando…' : `${datos?.meta.total ?? 0} cambios`}
          </span>
        </div>

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Texto etiqueta="Desde" type="date" value={desde} onChange={(e) => setDesde(e.target.value)} />
          <Texto etiqueta="Hasta" type="date" value={hasta} onChange={(e) => setHasta(e.target.value)} />
          <Lista
            etiqueta="Quien"
            vacio="Todos"
            value={usuarioId}
            onChange={(e) => setUsuarioId(e.target.value)}
            opciones={(catalogos?.usuarios ?? []).map((u) => ({
              valor: u.id,
              texto: u.iniciales ? `${u.iniciales} · ${u.name}` : u.name,
            }))}
          />
          <Lista
            etiqueta="Accion"
            vacio="Todas"
            value={accion}
            onChange={(e) => setAccion(e.target.value)}
            opciones={['Alta', 'Modificacion', 'Archivado', 'Impresion', 'Vencimiento'].map((a) => ({
              valor: a,
              texto: a,
            }))}
          />
        </div>
      </Card>

      <Card className="overflow-hidden">
        <CardHeader titulo="Los cambios" ayuda="Del más nuevo al más viejo." />

        {cargando && <Cargando />}

        {!cargando && error && <SinResultados titulo="No pudimos traer el historial" detalle={error} />}

        {!cargando && !error && cambios.length === 0 && (
          <SinResultados
            titulo="Todavía no hay cambios registrados"
            detalle="En cuanto alguien modifique algo de esta empresa va a aparecer acá."
          />
        )}

        {!cargando && !error && cambios.length > 0 && (
          <>
            <Tabla>
              <thead>
                <tr>
                  <Th ancho="150px">FECHA</Th>
                  <Th ancho="90px">Quien</Th>
                  <Th>Que cambio</Th>
                  <Th ancho="200px">Antes decia</Th>
                  <Th ancho="200px">Ahora dice</Th>
                  <Th ancho="130px">Accion</Th>
                </tr>
              </thead>
              <tbody>
                {cambios.map((c) => (
                  <tr key={c.id}>
                    <Td className="whitespace-nowrap">{fechaHora(c.fecha)}</Td>
                    <Td>
                      <span
                        className={
                          c.quien === 'el sistema'
                            ? 'text-faint'
                            : 'font-semibold text-brand-600'
                        }
                      >
                        {c.quien}
                      </span>
                    </Td>
                    <Td className="text-ink">{c.que_cambio}</Td>
                    <Td>
                      {c.antes ? (
                        <span className="text-warning-ink line-through">{c.antes}</span>
                      ) : (
                        <span className="text-faint">—</span>
                      )}
                    </Td>
                    <Td>
                      {c.ahora ? (
                        <span className="font-semibold text-success-ink">{c.ahora}</span>
                      ) : (
                        <span className="text-faint">—</span>
                      )}
                    </Td>
                    <Td>
                      <Chip tono={TONO_ACCION[c.accion] ?? 'neutro'}>{c.accion}</Chip>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Tabla>

            <NotaPie>
              El historial no se puede editar ni borrar. Si alguien se equivocó, se corrige el dato y
              queda anotada también la corrección.
            </NotaPie>
          </>
        )}
      </Card>

      <div className="grid gap-[18px] lg:grid-cols-3">
        <Card className="flex flex-col gap-2 p-[22px]">
          <h3 className="text-[13.5px] font-bold text-ink">Que se registra</h3>
          <p className="text-[12px] leading-relaxed text-muted">
            Alta, modificación, archivado, impresión y los vencimientos que marca el sistema solo.
          </p>
        </Card>
        <Card className="flex flex-col gap-2 p-[22px]">
          <h3 className="text-[13.5px] font-bold text-ink">Que dice y que no</h3>
          <p className="text-[12px] leading-relaxed text-muted">
            El historial dice QUÉ cambió. La observación dice POR QUÉ. Se leen juntas.
          </p>
        </Card>
        <Card className="flex flex-col gap-2 border-brand-200 bg-[#f3f9fe] p-[22px]">
          <h3 className="text-[13.5px] font-bold text-brand-600">Quien lo puede ver</h3>
          <p className="text-[12px] leading-relaxed text-brand-600">
            Se define por usuario en Quién ve qué. Por defecto, sólo los dueños.
          </p>
        </Card>
      </div>
    </div>
  )
}

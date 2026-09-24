import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Copy } from 'lucide-react'
import {
  Aviso,
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
import { Guardado } from '../../components/ui/form'
import {
  buscarEmpresas,
  copiarConsulta,
  fecha as fmtFecha,
  mensajeDeError,
  plata,
  traerConsulta,
  useCarga,
  useDebounce,
} from '../../lib/indice'

/**
 * Copiar una cotización a otras empresas.
 *
 * Se copian las líneas y los precios. La condición de pago, la lista de precios
 * y el contacto salen de la ficha de cada empresa destino: una puede tener
 * crédito y las otras no.
 */
export default function CopiarCotizacion() {
  const { consultaId } = useParams<{ consultaId: string }>()
  const navigate = useNavigate()

  const [busqueda, setBusqueda] = useState('')
  const busquedaDif = useDebounce(busqueda, 300)
  const [elegidas, setElegidas] = useState<number[]>([])
  const [copiando, setCopiando] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)

  const { datos: original, cargando } = useCarga(() => traerConsulta(consultaId!), [consultaId])
  const { datos: listado } = useCarga(() => buscarEmpresas({ buscar: busquedaDif }), [busquedaDif])

  const candidatas = useMemo(
    () => (listado?.data ?? []).filter((e) => e.id !== original?.empresa?.id),
    [listado, original],
  )

  function alternar(id: number) {
    setElegidas((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  async function copiar() {
    if (elegidas.length === 0) {
      setError('Elegí al menos una empresa.')

      return
    }

    setCopiando(true)
    setError(null)

    try {
      await copiarConsulta(Number(consultaId), elegidas)
      setAviso(`${elegidas.length} borradores creados. Ninguno se manda hasta confirmarlo.`)
      navigate(`/consultas/${consultaId}/borradores`)
    } catch (err) {
      setError(mensajeDeError(err))
    } finally {
      setCopiando(false)
    }
  }

  if (cargando) return <Cargando texto="Abriendo la cotización…" />

  if (!original) {
    return <SinResultados titulo="No encontramos la cotización" detalle="Volvé a la ficha." />
  }

  const lineas = (original.lineas ?? []).filter((l) => !l.quitada)

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb={
          <span className="flex items-center gap-1.5">
            <Link to="/empresas" className="hover:text-brand-600">
              Empresas
            </Link>
            <span>›</span>
            <span>{original.empresa?.nombre}</span>
            <span>›</span>
            <span>Cotizacion del {fmtFecha(original.fecha)}</span>
          </span>
        }
        titulo="Copiar cotizacion a otras empresas"
        bajada="Se toma una cotización ya hecha y se copia como borrador a las empresas que se elijan. Después cada borrador se abre por separado y se le cambia lo que haga falta."
        acciones={
          <>
            <Link to={`/empresas/${original.empresa?.id}`}>
              <Boton variante="suave">Cancelar</Boton>
            </Link>
            <Boton variante="primario" onClick={copiar} disabled={copiando || elegidas.length === 0}>
              <Copy size={15} strokeWidth={2} />
              {copiando ? 'Creando…' : `Crear ${elegidas.length || ''} borradores`}
            </Boton>
          </>
        }
      />

      {error && <Aviso tono="ambar">{error}</Aviso>}

      <div className="grid gap-[18px] xl:grid-cols-[1fr_380px]">
        <div className="flex flex-col gap-[18px]">
          {/* 1 · qué se copia */}
          <Card className="overflow-hidden">
            <CardHeader
              titulo="1 · Que cotizacion se copia"
              acciones={<Link to={`/consultas/${original.id}`} className="text-[11.5px] font-semibold text-brand-600">Ver la original</Link>}
            />

            <div className="mx-[22px] mb-3 flex flex-wrap items-center gap-3 rounded-[9px] border border-brand-200 bg-[#f3f9fe] px-3.5 py-3">
              <div className="min-w-0 flex-1">
                <p className="text-[13px] font-bold text-ink">{original.empresa?.nombre}</p>
                <p className="text-[11.5px] text-muted">
                  {fmtFecha(original.fecha)} · Quien lo hizo {original.quien_lo_hizo} · Moneda{' '}
                  {original.moneda}
                </p>
              </div>
              {original.condicion_pago && <Chip tono="verde">{original.condicion_pago}</Chip>}
            </div>

            <Tabla>
              <thead>
                <tr>
                  <Th ancho="60px">Cant.</Th>
                  <Th ancho="70px">Unidad</Th>
                  <Th>Descripcion del material</Th>
                  <Th ancho="110px" derecha>P. unit.</Th>
                  <Th ancho="110px" derecha>Importe</Th>
                </tr>
              </thead>
              <tbody>
                {lineas.map((l) => (
                  <tr key={l.id}>
                    <Td className="font-semibold text-ink">{l.cantidad}</Td>
                    <Td>{l.unidad}</Td>
                    <Td>{l.descripcion}</Td>
                    <Td derecha>{plata(l.precio_unitario)}</Td>
                    <Td derecha className="font-semibold text-ink">{plata(l.importe)}</Td>
                  </tr>
                ))}
              </tbody>
            </Tabla>

            <div className="flex items-center gap-3 border-t border-[#eef2f6] bg-[#f8fafc] px-[22px] py-3">
              <span className="text-[11.5px] text-muted">{lineas.length} lineas</span>
              <span className="ml-auto text-[13px] font-bold text-ink">{plata(original.total)}</span>
            </div>
          </Card>

          {/* 2 · a qué empresas */}
          <Card className="overflow-hidden">
            <CardHeader
              titulo="2 · A que empresas se copia"
              cuenta={elegidas.length > 0 ? `${elegidas.length} elegidas` : undefined}
              acciones={<span className="text-[11px] text-faint">Se puede elegir más de una</span>}
            />

            <div className="px-[22px] pb-3">
              <input
                value={busqueda}
                onChange={(e) => setBusqueda(e.target.value)}
                placeholder="Buscar empresa por nombre, rubro o localidad"
                className="h-[38px] w-full rounded-[7px] border border-line-strong px-3 text-[12.5px] outline-none focus:border-brand focus:ring-4 focus:ring-brand-50"
              />
            </div>

            <Tabla>
              <thead>
                <tr>
                  <Th ancho="46px" />
                  <Th>EMPRESA</Th>
                  <Th ancho="170px">Condicion de pago</Th>
                  <Th ancho="140px">Lista de precios</Th>
                </tr>
              </thead>
              <tbody>
                {candidatas.map((e) => {
                  const elegida = elegidas.includes(e.id)

                  return (
                    <tr
                      key={e.id}
                      onClick={() => alternar(e.id)}
                      className={`cursor-pointer transition-colors ${elegida ? 'bg-[#f3f9fe]' : 'hover:bg-app'}`}
                    >
                      <Td>
                        <span
                          className={`grid h-4 w-4 place-items-center rounded-[5px] border ${
                            elegida ? 'border-brand bg-brand text-white' : 'border-slate-300 bg-white'
                          }`}
                        >
                          {elegida && <span className="text-[9px] font-bold">✓</span>}
                        </span>
                      </Td>
                      <Td>
                        <span className="font-semibold text-ink">{e.nombre}</span>
                        <span className="ml-2 text-[10.5px] text-faint">
                          {e.relaciones.join(' · ')}
                        </span>
                      </Td>
                      <Td>—</Td>
                      <Td>—</Td>
                    </tr>
                  )
                })}
              </tbody>
            </Tabla>

            <NotaPie>
              La condición de pago, la lista de precios y el contacto salen de la ficha de cada
              empresa. No se copian los de {original.empresa?.nombre}.
            </NotaPie>
          </Card>
        </div>

        {/* 3 · qué se copia y qué se ajusta */}
        <div className="flex flex-col gap-[18px]">
          <Card className="flex flex-col gap-3.5 p-[22px]">
            <h2 className="text-[15px] font-semibold text-ink">3 · Que se copia y que se ajusta</h2>

            <Bloque titulo="Se copia igual" tono="verde" items={[
              'Las lineas: material, medida y cantidad',
              'Los precios unitarios',
              'Las condiciones tecnicas',
              'La moneda de la cotizacion',
            ]} />

            <Bloque titulo="Se ajusta a cada empresa" tono="info" items={[
              'Contactar a: el principal de esa empresa',
              'La condicion de pago de su ficha',
              'Su lista de precios',
              'La fecha del dia y la validez',
            ]} />

            <Bloque titulo="No se copia" tono="ambar" items={[
              'La NOTA de la original',
              'Las observaciones de la original',
              'El Nro. de Factura',
            ]} />
          </Card>

          <Card className="flex flex-col gap-2 border-[#f3d9a6] bg-[#fff8ee] p-[22px]">
            <h3 className="text-[13.5px] font-bold text-warning-ink">
              Por que la condicion de pago no se copia
            </h3>
            <p className="text-[12px] leading-relaxed text-[#7a5a1e]">
              Una empresa puede tener crédito y las otras no. Si se copiara tal cual, saldrían
              cotizaciones con una condición que no corresponde. Cada borrador toma la de su propia
              ficha, y después se puede cambiar a mano.
            </p>
          </Card>

          <Card className="flex flex-col gap-2 p-[22px]">
            <h3 className="text-[13.5px] font-bold text-ink">Se crean como borrador</h3>
            <p className="text-[12px] leading-relaxed text-muted">
              Ninguno se manda solo. Quedan en una lista aparte hasta que alguien los abre, los
              revisa y los confirma. Mientras son borrador no figuran en el historial de la empresa.
            </p>
          </Card>
        </div>
      </div>

      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </div>
  )
}

function Bloque({
  titulo,
  tono,
  items,
}: {
  titulo: string
  tono: 'verde' | 'info' | 'ambar'
  items: string[]
}) {
  const estilos = {
    verde: 'border-[#cdebd8] bg-[#f4fbf6] text-success-ink',
    info: 'border-brand-200 bg-[#f3f9fe] text-brand-600',
    ambar: 'border-[#f3d9a6] bg-[#fffdf7] text-warning-ink',
  }[tono]

  return (
    <div className={`rounded-[10px] border p-3.5 ${estilos}`}>
      <p className="mb-2 text-[13px] font-bold">{titulo}</p>
      <ul className="flex flex-col gap-1.5">
        {items.map((t) => (
          <li key={t} className="flex items-start gap-2 text-[12px] leading-relaxed">
            <span className="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-current opacity-60" />
            {t}
          </li>
        ))}
      </ul>
    </div>
  )
}

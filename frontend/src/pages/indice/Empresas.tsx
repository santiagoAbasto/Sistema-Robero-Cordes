import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Search, Plus } from 'lucide-react'
import {
  Accion,
  Card,
  Cargando,
  Chip,
  PageHeader,
  SinResultados,
  Tabla,
  Td,
  Th,
  NotaPie,
} from '../../components/ui'
import Paginador from '../../components/Paginador'
import { buscarEmpresas, useCarga, useCatalogos, useDebounce } from '../../lib/indice'
import type { Relacion } from '../../types/indice'

/** Cons./Modif. Registros — la lista de empresas con sus atajos. */
export default function Empresas() {
  const [params, setParams] = useSearchParams()
  const [texto, setTexto] = useState(params.get('buscar') ?? '')
  const [relacion, setRelacion] = useState(params.get('relacion') ?? '')
  const [rubroId, setRubroId] = useState(params.get('rubro_id') ?? '')
  const [provinciaId, setProvinciaId] = useState(params.get('provincia_id') ?? '')

  const catalogos = useCatalogos()
  const textoDiferido = useDebounce(texto, 300)

  // La URL guarda la búsqueda: así se puede volver con el botón de atrás.
  useEffect(() => {
    const nuevos: Record<string, string> = {}
    if (textoDiferido) nuevos.buscar = textoDiferido
    if (relacion) nuevos.relacion = relacion
    if (rubroId) nuevos.rubro_id = rubroId
    if (provinciaId) nuevos.provincia_id = provinciaId
    setParams(nuevos, { replace: true })
  }, [textoDiferido, relacion, rubroId, provinciaId, setParams])

  const [pagina, setPagina] = useState(1)

  // Cambiar un filtro vuelve a la primera pagina: si no, se busca "titanio"
  // estando en la 12 y la lista aparece vacia sin explicacion.
  useEffect(() => setPagina(1), [textoDiferido, relacion, rubroId, provinciaId])

  const { datos, cargando, error } = useCarga(
    () =>
      buscarEmpresas({
        page: pagina,
        buscar: textoDiferido,
        relacion,
        rubro_id: rubroId ? Number(rubroId) : '',
        provincia_id: provinciaId ? Number(provinciaId) : '',
      }),
    [textoDiferido, relacion, rubroId, provinciaId, pagina],
  )

  const empresas = datos?.data ?? []
  const total = datos?.meta.total ?? 0

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb={<Link to="/empresas" className="hover:text-brand-600">Empresas</Link>}
        titulo="Cons./Modif. Registros"
        bajada="Buscar una empresa y entrar a su ficha, a sus cotizaciones o imprimir, sin dar más vueltas."
        acciones={
          <Link to="/empresas/nueva">
            <span className="inline-flex h-[38px] items-center gap-2 rounded-lg bg-brand px-[15px] text-[13px] font-semibold text-white transition-colors hover:bg-brand-600">
              <Plus size={16} strokeWidth={2.4} />
              Nueva empresa
            </span>
          </Link>
        }
      />

      {/* Buscador y filtros */}
      <Card className="flex flex-col gap-4 p-[22px]">
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-[280px] flex-1">
            <label htmlFor="q" className="mb-1.5 block text-[10.5px] font-medium text-slate-500">
              Nombre, telefono, CUIT o material
            </label>
            <div className="relative">
              <Search
                size={16}
                strokeWidth={2}
                className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-faint"
              />
              <input
                id="q"
                value={texto}
                onChange={(e) => setTexto(e.target.value)}
                placeholder="METALURGICA S"
                className="h-[38px] w-full rounded-[7px] border border-line-strong pl-9 pr-3 text-[12.5px] text-ink outline-none transition-shadow placeholder:text-faint focus:border-brand focus:ring-4 focus:ring-brand-50"
              />
            </div>
          </div>

          <Filtro
            etiqueta="Relacion"
            valor={relacion}
            onChange={setRelacion}
            opciones={(catalogos?.relaciones ?? []).map((r: Relacion) => ({ valor: r, texto: r }))}
          />
          <Filtro
            etiqueta="Rubro"
            valor={rubroId}
            onChange={setRubroId}
            opciones={(catalogos?.rubros ?? []).map((r) => ({ valor: String(r.id), texto: r.nombre }))}
          />
          <Filtro
            etiqueta="Provincia"
            valor={provinciaId}
            onChange={setProvinciaId}
            opciones={(catalogos?.provincias ?? []).map((p) => ({ valor: String(p.id), texto: p.nombre }))}
          />
        </div>

        <p className="text-[11.5px] text-faint">
          {cargando ? 'Buscando…' : `${total} ${total === 1 ? 'resultado' : 'resultados'}`}
        </p>
      </Card>

      {/* Resultados */}
      <Card className="overflow-hidden">
        {cargando && <Cargando />}

        {!cargando && error && (
          <SinResultados titulo="No pudimos traer las empresas" detalle={error} />
        )}

        {!cargando && !error && empresas.length === 0 && (
          <SinResultados
            titulo="No encontramos ninguna empresa"
            detalle="Probá con parte del nombre, un teléfono o el material que le cotizaron."
          />
        )}

        {!cargando && !error && empresas.length > 0 && (
          <>
            <Tabla>
              <thead>
                <tr>
                  <Th>EMPRESA</Th>
                  <Th ancho="190px">Contactar a:</Th>
                  <Th ancho="150px">Localidad</Th>
                  <Th ancho="150px">Rubro</Th>
                  <Th ancho="80px" derecha>
                    Cotiz.
                  </Th>
                  <Th ancho="230px" />
                </tr>
              </thead>
              <tbody>
                {empresas.map((e) => (
                  <tr key={e.id} className="transition-colors hover:bg-[#f8fcfe]">
                    <Td>
                      <div className="flex flex-col gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <Link
                            to={`/empresas/${e.id}`}
                            className="text-[12.5px] font-semibold text-ink hover:text-brand-600"
                          >
                            {e.nombre}
                          </Link>
                          {e.relaciones.map((r) => (
                            <Chip
                              key={r}
                              tono={r === 'Cliente' ? 'verde' : r === 'Proveedor' ? 'brand' : 'neutro'}
                            >
                              {r}
                            </Chip>
                          ))}
                        </div>
                        {e.tambien_factura_como && (
                          <span className="text-[10.5px] text-faint">
                            Tambien factura como {e.tambien_factura_como}
                            {e.otras_razones > 0 && `  +${e.otras_razones}`}
                          </span>
                        )}
                      </div>
                    </Td>
                    <Td>
                      {e.contacto_principal ? (
                        <span className="inline-flex items-center gap-1.5">
                          {e.contacto_principal.nombre}
                          {e.otros_contactos > 0 && (
                            <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[9.5px] font-semibold text-slate-500">
                              +{e.otros_contactos}
                            </span>
                          )}
                        </span>
                      ) : (
                        <span className="text-faint">—</span>
                      )}
                    </Td>
                    <Td>{e.localidad ?? <span className="text-faint">—</span>}</Td>
                    <Td>{e.rubro ?? <span className="text-faint">—</span>}</Td>
                    <Td derecha className="font-semibold text-ink">
                      {e.cotizaciones}
                    </Td>
                    <Td>
                      <div className="flex items-center justify-end gap-3.5">
                        <Accion to={`/empresas/${e.id}`}>Ver ficha</Accion>
                        <Accion to={`/empresas/${e.id}#cotizaciones`}>Cotizaciones</Accion>
                        <Accion to={`/imprimir?empresa=${e.id}`}>Imprimir</Accion>
                      </div>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Tabla>

            <Paginador
              pagina={datos?.meta.current_page ?? 1}
              paginas={datos?.meta.last_page ?? 1}
              total={total}
              porPagina={datos?.meta.per_page ?? 25}
              onIr={setPagina}
              que="empresas"
            />

            <NotaPie>
              Desde acá se entra a la ficha, se ve la lista de cotizaciones o se imprime una, sin dar
              más vueltas.
            </NotaPie>
          </>
        )}
      </Card>
    </div>
  )
}

function Filtro({
  etiqueta,
  valor,
  onChange,
  opciones,
}: {
  etiqueta: string
  valor: string
  onChange: (v: string) => void
  opciones: { valor: string; texto: string }[]
}) {
  return (
    <div className="w-[168px]">
      <label className="mb-1.5 block text-[10.5px] font-medium text-slate-500">{etiqueta}</label>
      <select
        value={valor}
        onChange={(e) => onChange(e.target.value)}
        className="h-[38px] w-full rounded-[7px] border border-line-strong bg-white px-2.5 text-[12.5px] text-ink outline-none transition-shadow focus:border-brand focus:ring-4 focus:ring-brand-50"
      >
        <option value="">Todos</option>
        {opciones.map((o) => (
          <option key={o.valor} value={o.valor}>
            {o.texto}
          </option>
        ))}
      </select>
    </div>
  )
}

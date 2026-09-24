import { useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Accion,
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
import { Casilla, Lista, Texto } from '../../components/ui/form'
import {
  buscarConsultas,
  buscarEmpresas,
  fecha as fmtFecha,
  plata,
  useCarga,
  useCatalogos,
  useDebounce,
} from '../../lib/indice'

/* ===========================================================================
   Busq. Registros p/Cond. — armar listas de empresas
=========================================================================== */

export function EmpresasPorCondicion() {
  const catalogos = useCatalogos()
  const [f, setF] = useState({
    relacion: '',
    rubro_id: '',
    provincia_id: '',
    localidad_id: '',
    observacion: '',
    material_id: '',
    sin_cotizar_meses: '',
    con_whatsapp: false,
    con_mail: false,
  })
  const observacionDif = useDebounce(f.observacion, 350)

  const { datos, cargando } = useCarga(
    () =>
      buscarEmpresas({
        relacion: f.relacion,
        rubro_id: f.rubro_id ? Number(f.rubro_id) : '',
        provincia_id: f.provincia_id ? Number(f.provincia_id) : '',
        localidad_id: f.localidad_id ? Number(f.localidad_id) : '',
        observacion: observacionDif,
        material_id: f.material_id ? Number(f.material_id) : '',
        sin_cotizar_meses: f.sin_cotizar_meses ? Number(f.sin_cotizar_meses) : '',
        con_whatsapp: f.con_whatsapp,
        con_mail: f.con_mail,
      }),
    [
      f.relacion, f.rubro_id, f.provincia_id, f.localidad_id, observacionDif,
      f.material_id, f.sin_cotizar_meses, f.con_whatsapp, f.con_mail,
    ],
  )

  const set = (k: keyof typeof f, v: string | boolean) => setF({ ...f, [k]: v })
  const empresas = datos?.data ?? []

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb={<Link to="/empresas" className="hover:text-brand-600">Empresas</Link>}
        titulo="Busq. Registros p/Cond."
        bajada="Armar listas de empresas por lo que se pida: por sus datos, o por lo que se les cotizó."
      />

      <div className="grid gap-[18px] lg:grid-cols-2">
        <Card className="flex flex-col gap-3.5 p-[22px]">
          <h2 className="text-[15px] font-semibold text-ink">Por sus datos</h2>

          <div className="grid gap-3 sm:grid-cols-2">
            <Lista
              etiqueta="Relacion" vacio="Todas" value={f.relacion}
              onChange={(e) => set('relacion', e.target.value)}
              opciones={(catalogos?.relaciones ?? []).map((r) => ({ valor: r, texto: r }))}
            />
            <Lista
              etiqueta="Rubro" vacio="Todos" value={f.rubro_id}
              onChange={(e) => set('rubro_id', e.target.value)}
              opciones={(catalogos?.rubros ?? []).map((r) => ({ valor: r.id, texto: r.nombre }))}
            />
            <Lista
              etiqueta="Provincia" vacio="Todas" value={f.provincia_id}
              onChange={(e) => set('provincia_id', e.target.value)}
              opciones={(catalogos?.provincias ?? []).map((p) => ({ valor: p.id, texto: p.nombre }))}
            />
            <Lista
              etiqueta="Localidad" vacio="Todas" value={f.localidad_id}
              onChange={(e) => set('localidad_id', e.target.value)}
              opciones={(catalogos?.localidades ?? [])
                .filter((l) => !f.provincia_id || l.provincia_id === Number(f.provincia_id))
                .map((l) => ({ valor: l.id, texto: l.nombre }))}
            />
          </div>

          <Texto
            etiqueta="Texto en la observacion"
            value={f.observacion}
            onChange={(e) => set('observacion', e.target.value)}
            placeholder="Atanor, recortes, planta…"
          />

          <div className="flex flex-wrap gap-2.5">
            <Casilla marcada={f.con_whatsapp} onChange={(v) => set('con_whatsapp', v)}>
              Tiene WhatsApp
            </Casilla>
            <Casilla marcada={f.con_mail} onChange={(v) => set('con_mail', v)}>
              Tiene mail
            </Casilla>
          </div>
        </Card>

        <Card className="flex flex-col gap-3.5 p-[22px]">
          <h2 className="text-[15px] font-semibold text-ink">Por lo que les cotizamos</h2>

          <Lista
            etiqueta="Material que les cotice" vacio="Cualquiera" value={f.material_id}
            onChange={(e) => set('material_id', e.target.value)}
            opciones={(catalogos?.materiales ?? []).map((m) => ({ valor: m.id, texto: m.nombre }))}
          />

          <Lista
            etiqueta="Ultima cotizacion" vacio="Cualquiera" value={f.sin_cotizar_meses}
            onChange={(e) => set('sin_cotizar_meses', e.target.value)}
            opciones={[
              { valor: 3, texto: 'Hace mas de 3 meses' },
              { valor: 6, texto: 'Hace mas de 6 meses' },
              { valor: 12, texto: 'Hace mas de un año' },
            ]}
          />

          <p className="text-[11.5px] leading-relaxed text-faint">
            Por ejemplo: empresas de Córdoba que trabajan titanio y hace 6 meses que no les cotizo.
          </p>
        </Card>
      </div>

      <Card className="overflow-hidden">
        <CardHeader
          titulo="Resultado"
          cuenta={datos?.meta.total ?? 0}
          ayuda="Desde acá se entra a la ficha de cada una."
        />

        {cargando && <Cargando />}

        {!cargando && empresas.length === 0 && (
          <SinResultados
            titulo="Ninguna empresa cumple con eso"
            detalle="Probá aflojando alguno de los filtros."
          />
        )}

        {!cargando && empresas.length > 0 && (
          <Tabla>
            <thead>
              <tr>
                <Th>EMPRESA</Th>
                <Th ancho="180px">Contactar a:</Th>
                <Th ancho="150px">Localidad</Th>
                <Th ancho="150px">Rubro</Th>
                <Th ancho="80px" derecha>Cotiz.</Th>
                <Th ancho="110px" />
              </tr>
            </thead>
            <tbody>
              {empresas.map((e) => (
                <tr key={e.id} className="hover:bg-[#f8fcfe]">
                  <Td>
                    <div className="flex flex-wrap items-center gap-2">
                      <Link to={`/empresas/${e.id}`} className="font-semibold text-ink hover:text-brand-600">
                        {e.nombre}
                      </Link>
                      {e.relaciones.map((r) => (
                        <Chip key={r} tono={r === 'Cliente' ? 'verde' : r === 'Proveedor' ? 'brand' : 'neutro'}>
                          {r}
                        </Chip>
                      ))}
                    </div>
                  </Td>
                  <Td>{e.contacto_principal?.nombre ?? <span className="text-faint">—</span>}</Td>
                  <Td>{e.localidad ?? <span className="text-faint">—</span>}</Td>
                  <Td>{e.rubro ?? <span className="text-faint">—</span>}</Td>
                  <Td derecha className="font-semibold text-ink">{e.cotizaciones}</Td>
                  <Td><Accion to={`/empresas/${e.id}`}>Ver ficha</Accion></Td>
                </tr>
              ))}
            </tbody>
          </Tabla>
        )}
      </Card>
    </div>
  )
}

/* ===========================================================================
   Consultas por fecha
=========================================================================== */

export function ConsultasPorFecha() {
  const catalogos = useCatalogos()
  const hoy = new Date().toISOString().slice(0, 10)
  const haceUnAnio = new Date(Date.now() - 365 * 864e5).toISOString().slice(0, 10)

  const [f, setF] = useState({
    desde: haceUnAnio,
    hasta: hoy,
    material_id: '',
    usuario_id: '',
    tipo: '',
  })

  const { datos, cargando } = useCarga(
    () =>
      buscarConsultas({
        desde: f.desde,
        hasta: f.hasta,
        material_id: f.material_id ? Number(f.material_id) : '',
        usuario_id: f.usuario_id ? Number(f.usuario_id) : '',
        tipo: f.tipo,
      }),
    [f.desde, f.hasta, f.material_id, f.usuario_id, f.tipo],
  )

  const consultas = datos?.data ?? []
  const total = consultas.reduce((s, c) => s + c.total, 0)
  const vendidas = consultas.filter((c) => c.estado === 'Vendida').length
  const set = (k: keyof typeof f, v: string) => setF({ ...f, [k]: v })

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb={<Link to="/empresas" className="hover:text-brand-600">Empresas</Link>}
        titulo="Consultas por fecha"
        bajada="Todo lo que se cotizó o se vendió en un período. Se puede filtrar por material y por quién lo hizo."
      />

      <Card className="flex flex-col gap-3.5 p-[22px]">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <Texto etiqueta="Desde" type="date" value={f.desde} onChange={(e) => set('desde', e.target.value)} />
          <Texto etiqueta="Hasta" type="date" value={f.hasta} onChange={(e) => set('hasta', e.target.value)} />
          <Lista
            etiqueta="Material" vacio="Todos" value={f.material_id}
            onChange={(e) => set('material_id', e.target.value)}
            opciones={(catalogos?.materiales ?? []).map((m) => ({ valor: m.id, texto: m.nombre }))}
          />
          <Lista
            etiqueta="Quien lo hizo" vacio="Todos" value={f.usuario_id}
            onChange={(e) => set('usuario_id', e.target.value)}
            opciones={(catalogos?.usuarios ?? []).map((u) => ({
              valor: u.id, texto: u.iniciales ?? u.name,
            }))}
          />
          <Lista
            etiqueta="Tipo" vacio="Todos" value={f.tipo}
            onChange={(e) => set('tipo', e.target.value)}
            opciones={(catalogos?.tipos_consulta ?? []).map((t) => ({ valor: t, texto: t }))}
          />
        </div>
      </Card>

      <div className="grid gap-[14px] sm:grid-cols-2 xl:grid-cols-4">
        <Kpi valor={String(datos?.meta.total ?? 0)} etiqueta="Consultas del periodo" />
        <Kpi valor={plata(total)} etiqueta="Importe total" />
        <Kpi valor={String(vendidas)} etiqueta="Terminaron en venta" tono="text-success-ink" />
        <Kpi
          valor={String(consultas.filter((c) => c.esta_vencida && c.estado !== 'Vendida').length)}
          etiqueta="Vencidas sin respuesta"
          tono="text-warning-ink"
        />
      </div>

      <Card className="overflow-hidden">
        <CardHeader titulo="Lo que se movio" ayuda="Del más nuevo al más viejo." />

        {cargando && <Cargando />}

        {!cargando && consultas.length === 0 && (
          <SinResultados titulo="No hubo movimientos en ese período" detalle="Probá ampliando las fechas." />
        )}

        {!cargando && consultas.length > 0 && <TablaConsultas consultas={consultas} />}
      </Card>
    </div>
  )
}

/* ===========================================================================
   Busq. Consultas p/Cond. — buscar cotizaciones, no empresas
=========================================================================== */

export function BuscarConsultas() {
  const catalogos = useCatalogos()
  const [f, setF] = useState({
    material_id: '',
    forma_id: '',
    diametro_desde: '',
    diametro_hasta: '',
    estado: '',
    usuario_id: '',
    sin_respuesta: false,
  })

  const { datos, cargando } = useCarga(
    () =>
      buscarConsultas({
        material_id: f.material_id ? Number(f.material_id) : '',
        forma_id: f.forma_id ? Number(f.forma_id) : '',
        diametro_desde: f.diametro_desde ? Number(f.diametro_desde) : '',
        diametro_hasta: f.diametro_hasta ? Number(f.diametro_hasta) : '',
        estado: f.estado,
        usuario_id: f.usuario_id ? Number(f.usuario_id) : '',
        sin_respuesta: f.sin_respuesta,
      }),
    [f.material_id, f.forma_id, f.diametro_desde, f.diametro_hasta, f.estado, f.usuario_id, f.sin_respuesta],
  )

  const set = (k: keyof typeof f, v: string | boolean) => setF({ ...f, [k]: v })
  const consultas = datos?.data ?? []

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb={<Link to="/empresas" className="hover:text-brand-600">Empresas</Link>}
        titulo="Busq. Consultas p/Cond."
        bajada="Busca cotizaciones, no empresas: se llega por el material. Antes había que acordarse de la empresa para llegar a la cotización."
      />

      <Card className="flex flex-col gap-3.5 p-[22px]">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Lista
            etiqueta="Material" vacio="Cualquiera" value={f.material_id}
            onChange={(e) => set('material_id', e.target.value)}
            opciones={(catalogos?.materiales ?? []).map((m) => ({ valor: m.id, texto: m.nombre }))}
          />
          <Lista
            etiqueta="Forma" vacio="Cualquiera" value={f.forma_id}
            onChange={(e) => set('forma_id', e.target.value)}
            opciones={(catalogos?.formas ?? []).map((x) => ({ valor: x.id, texto: x.nombre }))}
          />
          <Texto
            etiqueta="Diametro desde" ayuda="mm" type="number"
            value={f.diametro_desde} onChange={(e) => set('diametro_desde', e.target.value)}
          />
          <Texto
            etiqueta="Diametro hasta" ayuda="mm" type="number"
            value={f.diametro_hasta} onChange={(e) => set('diametro_hasta', e.target.value)}
          />
        </div>

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Lista
            etiqueta="Estado" vacio="Todos" value={f.estado}
            onChange={(e) => set('estado', e.target.value)}
            opciones={(catalogos?.estados ?? []).map((x) => ({ valor: x, texto: x }))}
          />
          <Lista
            etiqueta="Quien lo hizo" vacio="Todos" value={f.usuario_id}
            onChange={(e) => set('usuario_id', e.target.value)}
            opciones={(catalogos?.usuarios ?? []).map((u) => ({
              valor: u.id, texto: u.iniciales ?? u.name,
            }))}
          />
          <div className="flex items-end">
            <Casilla marcada={f.sin_respuesta} onChange={(v) => set('sin_respuesta', v)}>
              Solo las que quedaron sin respuesta
            </Casilla>
          </div>
        </div>

        <p className="text-[11.5px] leading-relaxed text-faint">
          El diámetro es un número, así que se puede pedir &quot;todas las barras de entre 30 y 40
          mm&quot;. Eso sólo se puede porque forma, material y dimensiones están separados.
        </p>
      </Card>

      <Card className="overflow-hidden">
        <CardHeader titulo="Cotizaciones encontradas" cuenta={datos?.meta.total ?? 0} />

        {cargando && <Cargando />}

        {!cargando && consultas.length === 0 && (
          <SinResultados
            titulo="No encontramos cotizaciones con esas condiciones"
            detalle="Probá con otro material o aflojando el rango de medidas."
          />
        )}

        {!cargando && consultas.length > 0 && <TablaConsultas consultas={consultas} conMaterial />}
      </Card>
    </div>
  )
}

/* ------------------------------------------------------------- compartidos */

function TablaConsultas({
  consultas,
  conMaterial,
}: {
  consultas: import('../../types/indice').Consulta[]
  conMaterial?: boolean
}) {
  return (
    <>
      <Tabla>
        <thead>
          <tr>
            <Th ancho="100px">FECHA</Th>
            <Th ancho="220px">EMPRESA</Th>
            <Th>{conMaterial ? 'Material y medida' : 'Cotizacion'}</Th>
            <Th ancho="120px" derecha>Importe</Th>
            <Th ancho="70px">Quien</Th>
            <Th ancho="130px">Estado</Th>
            <Th ancho="100px" />
          </tr>
        </thead>
        <tbody>
          {consultas.map((c) => (
            <tr key={c.id} className="hover:bg-[#f8fcfe]">
              <Td className="whitespace-nowrap font-semibold text-ink">{fmtFecha(c.fecha)}</Td>
              <Td>
                <Link to={`/empresas/${c.empresa?.id}`} className="hover:text-brand-600">
                  {c.empresa?.nombre}
                </Link>
              </Td>
              <Td>
                {(c.lineas ?? []).slice(0, 2).map((l) => (
                  <div key={l.id} className="truncate">
                    {conMaterial && l.material ? (
                      <>
                        <span className="font-medium text-ink">{l.material}</span>
                        {l.forma && <span className="text-faint"> · {l.forma}</span>}
                        {l.dimensiones && <span className="text-slate-600"> · {l.dimensiones}</span>}
                      </>
                    ) : (
                      l.descripcion
                    )}
                  </div>
                ))}
                {(c.lineas?.length ?? 0) > 2 && (
                  <span className="text-[10.5px] text-faint">
                    +{(c.lineas?.length ?? 0) - 2} lineas mas
                  </span>
                )}
              </Td>
              <Td derecha className="font-semibold text-ink">{plata(c.total)}</Td>
              <Td className="text-brand-600">{c.quien_lo_hizo}</Td>
              <Td>
                <Chip
                  tono={
                    c.estado === 'Vendida' ? 'verde'
                      : c.esta_vencida ? 'ambar'
                      : c.estado === 'Borrador' ? 'neutro'
                      : 'brand'
                  }
                >
                  {c.esta_vencida && c.estado !== 'Vendida' ? 'Vencida por tiempo' : c.estado}
                </Chip>
              </Td>
              <Td>
                <div className="flex items-center justify-end gap-3">
                  <Accion to={`/consultas/${c.id}`}>Abrir</Accion>
                  <Accion to={`/imprimir?empresa=${c.empresa?.id}&consulta=${c.id}`}>Imprimir</Accion>
                </div>
              </Td>
            </tr>
          ))}
        </tbody>
      </Tabla>

      <NotaPie>
        Desde acá se abre la cotización o se imprime, sin tener que pasar por la ficha de la empresa.
      </NotaPie>
    </>
  )
}

function Kpi({ valor, etiqueta, tono }: { valor: string; etiqueta: string; tono?: string }) {
  return (
    <Card className="flex flex-col gap-1 p-[18px]">
      <span className={`text-[22px] font-bold ${tono ?? 'text-ink'}`}>{valor}</span>
      <span className="text-[11.5px] font-medium text-muted">{etiqueta}</span>
    </Card>
  )
}

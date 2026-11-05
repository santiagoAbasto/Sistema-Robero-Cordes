import { useCallback, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Download } from 'lucide-react'
import { Aviso, Boton, Card, CardHeader, Cargando, NotaPie, PageHeader, cx } from '../../components/ui'
import { Lista, Texto } from '../../components/ui/form'
import { bajarReporte, PERIODOS, rangoDelPeriodo, traerReporte, type Periodo } from '../../lib/reportes'
import { fecha as fmtFecha, fechaHora, mensajeDeError, useCarga, useCatalogos } from '../../lib/indice'
import type { NombreDeReporte } from '../../types/reportes'
import { ComboDiferido, TextoDiferido } from '../buscador/Campos'
import ReporteDeSeguimiento from './Seguimiento'
import TablaDeReporte from './TablaDeReporte'

/* ---------------------------------------------------------------------------
   Reportes: lo que se cotiza, a quién, cuánto se vende y qué hay que seguir.

   Cuatro preguntas —por material, por empresa, compras y comprobantes, ranking
   y vencimientos— más el balance de seguimiento que ya existía. Todo queda en la
   dirección (el período, los filtros), así un reporte se puede guardar o mandar.
   Cada uno se baja a Excel con las mismas columnas que se ven.
--------------------------------------------------------------------------- */

type Pestania = 'material' | 'empresa' | 'comprobantes' | 'ranking' | 'seguimiento'

const PESTANIAS: { clave: Pestania; titulo: string }[] = [
  { clave: 'material', titulo: 'Por material' },
  { clave: 'empresa', titulo: 'Por empresa' },
  { clave: 'comprobantes', titulo: 'Compras y comprobantes' },
  { clave: 'ranking', titulo: 'Ranking y vencimientos' },
  { clave: 'seguimiento', titulo: 'Seguimiento' },
]

const BAJADA: Record<Pestania, string> = {
  material: 'Qué material se cotiza más, a cuántas empresas, y cuánto de eso se vende. Cada fila lleva a sus renglones.',
  empresa: 'Lo cotizado contra lo vendido, empresa por empresa o año por año.',
  comprobantes: 'Las ventas, las facturas y los remitos, mes por mes, con lo facturado por moneda.',
  ranking: 'Quiénes cotizan y venden más, y qué cotizaciones abiertas vencen (o ya vencieron) y cuándo.',
  seguimiento: 'Qué quedó abierto, qué se cerró y por qué, en el período elegido.',
}

const MEDIDAS = [
  { valor: 'cotizaciones', texto: 'Más cotizaciones' },
  { valor: 'vendidas', texto: 'Más ventas' },
  { valor: 'cotizado', texto: 'Más plata cotizada' },
  { valor: 'vendido', texto: 'Más plata vendida' },
]

export default function Reportes() {
  const [params, setParams] = useSearchParams()
  const catalogos = useCatalogos()
  const g = (k: string, por = '') => params.get(k) ?? por

  const pestania = (PESTANIAS.some((p) => p.clave === g('r')) ? g('r') : 'material') as Pestania
  const sub = g('sub') === 'vencimientos' ? 'vencimientos' : 'ranking'
  const agrupar = g('agrupar') === 'anio' ? 'anio' : 'empresa'
  const nombre: NombreDeReporte | null = pestania === 'seguimiento' ? null : pestania === 'ranking' ? sub : pestania

  /** Por defecto, lo de este año; los reportes que comparan años o miran lo abierto, todo. */
  const porDefecto: Periodo =
    pestania === 'comprobantes' ? '12m' : pestania === 'empresa' && agrupar === 'anio' ? 'todo' : 'anio'
  const periodo = (PERIODOS.some((p) => p.clave === g('periodo')) ? g('periodo') : porDefecto) as Periodo

  const cambiar = useCallback(
    (cambios: Record<string, string>, historial = false) =>
      setParams(
        (previos) => {
          const nuevos = new URLSearchParams(previos)
          for (const [k, v] of Object.entries(cambios)) {
            if (v) nuevos.set(k, v)
            else nuevos.delete(k)
          }

          return nuevos
        },
        { replace: !historial },
      ),
    [setParams],
  )

  /* ----------------------------------------------------------- lo que se pide */

  const q: Record<string, string> = {}
  if (nombre && nombre !== 'vencimientos') Object.assign(q, rangoDelPeriodo(periodo, g('desde'), g('hasta')))
  if (g('usuario_id')) q.usuario_id = g('usuario_id')
  if (nombre === 'material' || nombre === 'empresa' || nombre === 'comprobantes' || nombre === 'vencimientos') {
    if (g('empresa')) q.empresa = g('empresa')
  }
  if (nombre === 'material' && g('material')) q.material = g('material')
  if (nombre === 'empresa') q.agrupar = agrupar
  if (nombre === 'ranking') {
    q.por = g('por', 'empresas')
    q.medida = g('medida', 'cotizaciones')
    q.top = g('top', '25')
    if (g('moneda_id')) q.moneda_id = g('moneda_id')
  }

  const clave = `${nombre}|${JSON.stringify(q)}`
  const { datos, cargando, error } = useCarga(() => (nombre ? traerReporte(nombre, q) : Promise.resolve(null)), [clave])

  const [bajando, setBajando] = useState(false)
  const [falla, setFalla] = useState<string | null>(null)

  async function bajar() {
    if (!nombre) return
    setBajando(true)
    setFalla(null)
    try {
      await bajarReporte(nombre, q)
    } catch (err) {
      setFalla(mensajeDeError(err, 'No pudimos preparar el Excel. Probá de nuevo.'))
    } finally {
      setBajando(false)
    }
  }

  const laHizo = (
    <Lista
      etiqueta="La hizo"
      vacio="Cualquiera"
      value={g('usuario_id')}
      onChange={(e) => cambiar({ usuario_id: e.target.value })}
      opciones={(catalogos?.usuarios ?? []).map((u) => ({ valor: u.id, texto: u.iniciales ?? u.name }))}
    />
  )
  const laEmpresa = (
    <TextoDiferido etiqueta="Empresa" valor={g('empresa')} alCambiar={(v) => cambiar({ empresa: v })} placeholder="parte del nombre" />
  )

  return (
    <div className="flex flex-col gap-4">
      <PageHeader titulo="Reportes" bajada={BAJADA[pestania]} />

      <div role="tablist" className="flex flex-wrap gap-2">
        {PESTANIAS.map((p) => (
          <button
            key={p.clave}
            type="button"
            role="tab"
            aria-selected={pestania === p.clave}
            onClick={() => cambiar({ r: p.clave === 'material' ? '' : p.clave }, true)}
            className={cx(
              'h-10 rounded-lg border px-4 text-[13px] font-semibold transition-colors',
              pestania === p.clave ? 'border-brand bg-brand text-white' : 'border-line-strong bg-white text-slate-600 hover:bg-app',
            )}
          >
            {p.titulo}
          </button>
        ))}
      </div>

      {pestania === 'seguimiento' ? (
        <ReporteDeSeguimiento embebido />
      ) : (
        <Card>
          <CardHeader
            titulo={nombre === 'vencimientos' ? 'Vencimientos' : (datos?.titulo ?? 'Reporte')}
            ayuda={
              datos
                ? `Generado el ${fechaHora(datos.generado_el.replace('T', ' ').slice(0, 16))}${
                    datos.periodo.desde || datos.periodo.hasta
                      ? ` · del ${datos.periodo.desde ? fmtFecha(datos.periodo.desde) : 'principio'} al ${datos.periodo.hasta ? fmtFecha(datos.periodo.hasta) : 'día de hoy'}`
                      : ' · de todo lo que hay'
                  }`
                : undefined
            }
            acciones={
              <Boton onClick={bajar} disabled={bajando || cargando || !datos?.filas.length} title="Baja lo mismo que se ve, en una planilla">
                <Download size={15} strokeWidth={2.2} />
                {bajando ? 'Preparando…' : 'Bajar a Excel'}
              </Boton>
            }
          />

          {/* Filtros */}
          <div className="flex flex-col gap-3.5 border-b border-line px-[22px] pb-4">
            {nombre === 'ranking' && (
              <div className="flex flex-wrap items-end gap-3">
                <div className="flex gap-2">
                  {[
                    ['empresas', 'Empresas'],
                    ['materiales', 'Materiales'],
                    ['usuarios', 'Quién la hizo'],
                  ].map(([valor, texto]) => (
                    <button
                      key={valor}
                      type="button"
                      aria-pressed={g('por', 'empresas') === valor}
                      onClick={() => cambiar({ por: valor === 'empresas' ? '' : valor })}
                      className={cx(
                        'h-9 rounded-lg border px-3 text-[12.5px] font-semibold',
                        g('por', 'empresas') === valor ? 'border-brand-200 bg-brand-50 text-brand-600' : 'border-line-strong bg-white text-slate-600 hover:bg-app',
                      )}
                    >
                      {texto}
                    </button>
                  ))}
                </div>
                <Lista etiqueta="Ordenar por" vacio="" value={g('medida', 'cotizaciones')} onChange={(e) => cambiar({ medida: e.target.value })} opciones={MEDIDAS} className="w-[190px]" />
                <Lista etiqueta="Cuántos" vacio="" value={g('top', '25')} onChange={(e) => cambiar({ top: e.target.value })} opciones={['10', '25', '50', '100'].map((n) => ({ valor: n, texto: `Los ${n} primeros` }))} className="w-[150px]" />
                {['cotizado', 'vendido'].includes(g('medida')) && (
                  <Lista
                    etiqueta="En la moneda"
                    vacio="La que más mueve"
                    value={g('moneda_id')}
                    onChange={(e) => cambiar({ moneda_id: e.target.value })}
                    opciones={(catalogos?.monedas ?? []).map((m) => ({ valor: m.id, texto: m.nombre }))}
                    className="w-[230px]"
                  />
                )}
              </div>
            )}

            {pestania === 'ranking' && (
              <div className="flex gap-2">
                {[
                  ['ranking', 'Ranking'],
                  ['vencimientos', 'Vencimientos'],
                ].map(([valor, texto]) => (
                  <button
                    key={valor}
                    type="button"
                    aria-pressed={sub === valor}
                    onClick={() => cambiar({ sub: valor === 'ranking' ? '' : valor }, true)}
                    className={cx('text-[12.5px] font-semibold', sub === valor ? 'text-brand-600 underline underline-offset-4' : 'text-muted hover:text-ink')}
                  >
                    {texto}
                  </button>
                ))}
              </div>
            )}

            {nombre === 'empresa' && (
              <div className="flex gap-2">
                {[
                  ['empresa', 'Por empresa'],
                  ['anio', 'Por año'],
                ].map(([valor, texto]) => (
                  <button
                    key={valor}
                    type="button"
                    aria-pressed={agrupar === valor}
                    onClick={() => cambiar({ agrupar: valor === 'empresa' ? '' : valor })}
                    className={cx(
                      'h-9 rounded-lg border px-3 text-[12.5px] font-semibold',
                      agrupar === valor ? 'border-brand-200 bg-brand-50 text-brand-600' : 'border-line-strong bg-white text-slate-600 hover:bg-app',
                    )}
                  >
                    {texto}
                  </button>
                ))}
              </div>
            )}

            {nombre !== 'vencimientos' && (
              <div className="flex flex-wrap items-end gap-2">
                <span className="mb-2 text-[11px] font-medium text-slate-500">Período:</span>
                {PERIODOS.map((p) => (
                  <button
                    key={p.clave}
                    type="button"
                    aria-pressed={periodo === p.clave}
                    onClick={() => cambiar({ periodo: p.clave === porDefecto ? '' : p.clave })}
                    className={cx(
                      'mb-0.5 h-8 rounded-full border px-3 text-[12px] font-medium',
                      periodo === p.clave ? 'border-brand bg-brand text-white' : 'border-line-strong bg-white text-slate-600 hover:bg-app',
                    )}
                  >
                    {p.texto}
                  </button>
                ))}
                {periodo === 'otro' && (
                  <>
                    <Texto etiqueta="Desde" type="date" value={g('desde')} onChange={(e) => cambiar({ desde: e.target.value })} className="w-[160px]" />
                    <Texto etiqueta="Hasta" type="date" value={g('hasta')} onChange={(e) => cambiar({ hasta: e.target.value })} className="w-[160px]" />
                  </>
                )}
              </div>
            )}

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {nombre === 'material' && (
                <ComboDiferido
                  id="reporte-material"
                  etiqueta="Material"
                  valor={g('material')}
                  alCambiar={(v) => cambiar({ material: v })}
                  opciones={(catalogos?.materiales ?? []).map((m) => m.nombre)}
                  placeholder="Monel, titanio…"
                />
              )}
              {nombre !== 'ranking' && laEmpresa}
              {laHizo}
            </div>
          </div>

          {falla && <Aviso tono="ambar" className="mx-[22px] mt-3">{falla}</Aviso>}
          {error && <Aviso tono="ambar" className="mx-[22px] mt-3">{error}</Aviso>}

          {cargando && !datos ? <Cargando texto="Armando el reporte…" /> : datos && <div className={cx('pt-3', cargando && 'opacity-60')}><TablaDeReporte reporte={datos} /></div>}

          <NotaPie>
            {datos && !datos.permisos.ve_importes
              ? 'No se muestran importes: no tenés permiso para verlos. '
              : 'Los importes van separados por moneda: nunca se suman dólares con pesos. '}
            Una cotización cuenta sin borradores, sin las descartadas y sin las alternativas que se ofrecen.
          </NotaPie>
        </Card>
      )}
    </div>
  )
}

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Download, Plus, Search, SlidersHorizontal, X } from 'lucide-react'
import {
  Aviso,
  Boton,
  Card,
  CardHeader,
  Cargando,
  NotaPie,
  PageHeader,
  SinResultados,
  cx,
} from '../../components/ui'
import { Guardado, Lista } from '../../components/ui/form'
import DescartarBorrador from '../../components/DescartarBorrador'
import Paginador from '../../components/Paginador'
import { useAuth } from '../../lib/auth'
import {
  ATAJOS,
  bajarExcel,
  buscar,
  CLAVES_DE_FILTRO,
  cantidadDeFiltros,
  filtrosActivos,
  grano as granoDe,
  GRANOS,
  GRUPO_DE_LA_VISTA,
  pedidoDeLaUrl,
  type Grupo,
} from '../../lib/buscador'
import { useCarga, useCatalogos } from '../../lib/indice'
import type {
  FilaComprobante,
  FilaConsulta,
  FilaEmpresa,
  FilaRenglon,
  Grano,
  RespuestaDelBuscador,
} from '../../types/buscador'
import CordesIA from './CordesIA'
import { useTextoDiferido } from './useTextoDiferido'
import PanelDeFiltros from './PanelDeFiltros'
import {
  Resumen,
  TablaDeComprobantes,
  TablaDeConsultas,
  TablaDeEmpresas,
  TablaDeRenglones,
} from './Resultados'

/* ---------------------------------------------------------------------------
   El Buscador: los cuatro buscadores del menú (Cons./Modif. Registros,
   Busq. Registros p/Cond., Consultas por fecha y Busq. Consultas p/Cond.)
   en una sola pantalla.

   Se escribe lo que se sabe, se elige qué se quiere ver y, si hace falta, se
   afina con los filtros. Todo lo que se pide queda en la dirección, así que
   el botón de atrás vuelve a la búsqueda anterior.
--------------------------------------------------------------------------- */

const GUARDA_PANEL = 'cordes_buscador_filtros'

/** Los filtros arrancan escondidos —lo primero que hay que ver son los resultados— y se recuerda si se abrieron. */
function panelAbiertoDeAntes(): boolean {
  try {
    return localStorage.getItem(GUARDA_PANEL) === 'abierto'
  } catch {
    return false
  }
}

const ORDENES: Record<Grano, { valor: string; texto: string }[]> = {
  empresas: [
    { valor: 'nombre', texto: 'Por nombre' },
    { valor: 'relevancia', texto: 'Las que más se parecen a lo escrito' },
    { valor: 'reciente', texto: 'Cotizadas hace menos' },
    { valor: 'antiguo', texto: 'Cotizadas hace más' },
  ],
  consultas: [
    { valor: 'reciente', texto: 'Más nuevas primero' },
    { valor: 'antiguo', texto: 'Más viejas primero' },
    { valor: 'nombre', texto: 'Por nombre de empresa' },
  ],
  renglones: [
    { valor: 'reciente', texto: 'Más nuevos primero' },
    { valor: 'antiguo', texto: 'Más viejos primero' },
    { valor: 'nombre', texto: 'Por nombre de empresa' },
    { valor: 'importe', texto: 'Mayor importe primero' },
  ],
  comprobantes: [
    { valor: 'reciente', texto: 'Más nuevos primero' },
    { valor: 'antiguo', texto: 'Más viejos primero' },
    { valor: 'nombre', texto: 'Por nombre de empresa' },
    { valor: 'importe', texto: 'Mayor importe primero' },
  ],
}

const QUE = {
  empresas: 'empresas',
  consultas: 'cotizaciones',
  renglones: 'renglones',
  comprobantes: 'comprobantes',
} as const

export default function Buscador() {
  const [params, setParams] = useSearchParams()
  const pedido = useMemo(() => pedidoDeLaUrl(params), [params])
  const vista = granoDe(pedido)
  const catalogos = useCatalogos()
  const { user } = useAuth()
  const esAdmin = user?.role === 'Administrador'

  /**
   * Cambia lo pedido. Escribir y filtrar reemplazan la entrada del historial
   * (si no, atrás tendría que desandar cada letra); cambiar de vista o de
   * página la agregan.
   */
  const cambiar = useCallback(
    (cambios: Record<string, string>, opciones: { historial?: boolean; conservarPagina?: boolean } = {}) => {
      setParams(
        (previos) => {
          const nuevos = new URLSearchParams(previos)
          for (const [clave, valor] of Object.entries(cambios)) {
            if (valor) nuevos.set(clave, valor)
            else nuevos.delete(clave)
          }
          if (!opciones.conservarPagina) nuevos.delete('pagina')

          return nuevos
        },
        { replace: !opciones.historial },
      )
    },
    [setParams],
  )

  /* ------------------------------------------------------------ la búsqueda */

  const claveDeLaUrl = params.toString()
  const { datos, cargando, error, recargar } = useCarga(
    () => buscar<FilaEmpresa | FilaConsulta | FilaRenglon | FilaComprobante>(pedido, catalogos?.materiales),
    // El material del catálogo se pide por su número: hay que esperar a tenerlo.
    [claveDeLaUrl, catalogos?.materiales.length ?? 0],
  )

  // Los datos de otra vista no sirven para dibujar ésta.
  const respuesta = datos && datos.meta.grano === vista ? datos : null
  const total = respuesta?.meta.total ?? 0
  const veImportes = respuesta?.permisos.ve_importes ?? true

  /* ------------------------------------------------------------- el texto */

  const [texto, setTexto] = useTextoDiferido(pedido.q ?? '', (q) => cambiar({ q }))

  /* ------------------------------------------------------ filtros y chips */

  const activos = useMemo(() => filtrosActivos(pedido, catalogos), [pedido, catalogos])
  const cuantosFiltros = cantidadDeFiltros(pedido)

  const [panelAbierto, setPanelAbierto] = useState(panelAbiertoDeAntes)
  const [grupos, setGrupos] = useState<Set<Grupo>>(
    () => new Set<Grupo>([GRUPO_DE_LA_VISTA[vista], ...activos.map((f) => f.grupo)]),
  )

  // Un filtro que llegó por un atajo o un link tiene que verse: se abre su grupo.
  const gruposConFiltros = [...new Set(activos.map((f) => f.grupo))].join(',')
  useEffect(() => {
    if (!gruposConFiltros) return
    setGrupos((antes) => new Set([...antes, ...(gruposConFiltros.split(',') as Grupo[])]))
  }, [gruposConFiltros])

  function alternarPanel() {
    setPanelAbierto((abierto) => {
      try {
        localStorage.setItem(GUARDA_PANEL, abierto ? 'cerrado' : 'abierto')
      } catch {
        /* sin almacenamiento: queda como estaba para esta visita */
      }

      return !abierto
    })
  }

  function alternarGrupo(grupo: Grupo) {
    setGrupos((antes) => {
      const despues = new Set(antes)
      if (despues.has(grupo)) despues.delete(grupo)
      else despues.add(grupo)

      return despues
    })
  }

  function quitarTodos() {
    cambiar(Object.fromEntries(CLAVES_DE_FILTRO.map((k) => [k, ''])))
  }

  function cambiarDeVista(nueva: Grano) {
    setGrupos((antes) => new Set([...antes, GRUPO_DE_LA_VISTA[nueva]]))
    // El orden de una vista puede no existir en otra.
    cambiar({ grano: nueva === 'empresas' ? '' : nueva, orden: '' }, { historial: true })
  }

  /** Cordes armó una búsqueda: es una búsqueda nueva, no se mezcla con lo que había. */
  function aplicarPregunta(nuevo: Record<string, string>) {
    setParams(new URLSearchParams(nuevo), { replace: false })
    setPanelAbierto(true)
  }

  function aplicarAtajo(i: number) {
    setParams(new URLSearchParams(ATAJOS[i].pedido()), { replace: false })
    // Es una búsqueda nueva: lo que se había escrito ya no corresponde.
    setTexto('')
  }

  /* ------------------------------------------------------- Excel y acciones */

  const [bajando, setBajando] = useState(false)
  const [falloElExcel, setFalloElExcel] = useState<string | null>(null)

  async function bajar() {
    setBajando(true)
    setFalloElExcel(null)
    try {
      await bajarExcel(pedido, catalogos?.materiales)
    } catch (err) {
      setFalloElExcel(err instanceof Error ? err.message : 'No pudimos preparar el Excel.')
    } finally {
      setBajando(false)
    }
  }

  const [aDescartar, setADescartar] = useState<{ id: number; empresa: string; sinEmitir?: boolean } | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)

  const inicioDeResultados = useRef<HTMLDivElement>(null)
  function irAPagina(pagina: number) {
    cambiar({ pagina: pagina > 1 ? String(pagina) : '' }, { historial: true, conservarPagina: true })
    inicioDeResultados.current?.scrollIntoView({ block: 'start', behavior: 'smooth' })
  }

  /* ------------------------------------------------------------------ vista */

  const ayudaDeLaVista = GRANOS.find((g) => g.clave === vista)?.ayuda
  const hayInactivas = vista === 'empresas' && (respuesta?.resumen.inactivas ?? 0) > 0 && !pedido.activa

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb={<Link to="/empresas" className="hover:text-brand-600">Empresas</Link>}
        titulo="Buscador"
        bajada="Un solo lugar para encontrar empresas, cotizaciones, material cotizado y facturas. Escribí lo que sepas y, si hace falta, afiná con los filtros."
        acciones={
          <>
            <Boton onClick={bajar} disabled={bajando || cargando || total === 0} title="Baja todo lo que se ve, no sólo esta página">
              <Download size={15} strokeWidth={2.2} />
              {bajando ? 'Preparando…' : 'Bajar a Excel'}
            </Boton>
            <Link to="/empresas/nueva">
              <span className="inline-flex h-[38px] items-center gap-2 rounded-lg bg-brand px-[15px] text-[13px] font-semibold text-white transition-colors hover:bg-brand-600">
                <Plus size={16} strokeWidth={2.4} />
                Nueva empresa
              </span>
            </Link>
          </>
        }
      />

      <CordesIA aplicar={aplicarPregunta} pedidoActual={pedido} catalogos={catalogos} />

      <Card className="flex flex-col gap-4 p-[22px]">
        {/* Lo que se sabe */}
        <div>
          <label htmlFor="buscador-texto" className="mb-1.5 block text-[10.5px] font-medium text-slate-500">
            Qué buscás
          </label>
          <div className="relative">
            <Search
              size={18}
              strokeWidth={2}
              className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-faint"
            />
            <input
              id="buscador-texto"
              value={texto}
              onChange={(e) => setTexto(e.target.value)}
              placeholder="Nombre de la empresa, material, teléfono, CUIT, número de cotización o de factura…"
              className="h-12 w-full rounded-lg border border-line-strong pl-11 pr-11 text-[14.5px] text-ink outline-none transition-shadow placeholder:text-faint focus:border-brand focus:ring-4 focus:ring-brand-50"
            />
            {texto && (
              <button
                type="button"
                onClick={() => {
                  setTexto('')
                  cambiar({ q: '' })
                }}
                className="absolute right-2.5 top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-md text-faint hover:bg-app hover:text-muted"
                aria-label="Borrar lo escrito"
                title="Borrar lo escrito"
              >
                <X size={16} strokeWidth={2.2} />
              </button>
            )}
          </div>
        </div>

        {/* Qué se quiere ver */}
        <div>
          <span className="mb-1.5 block text-[10.5px] font-medium text-slate-500">Qué querés ver</span>
          <div role="tablist" className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            {GRANOS.map((g) => (
              <button
                key={g.clave}
                type="button"
                role="tab"
                aria-selected={vista === g.clave}
                title={g.ayuda}
                onClick={() => cambiarDeVista(g.clave)}
                className={cx(
                  'h-11 rounded-lg border px-3 text-[13.5px] font-semibold transition-colors',
                  vista === g.clave
                    ? 'border-brand bg-brand text-white'
                    : 'border-line-strong bg-white text-slate-600 hover:bg-app',
                )}
              >
                {g.titulo}
              </button>
            ))}
          </div>
          <p className="mt-1.5 text-[11.5px] text-faint">{ayudaDeLaVista}</p>
        </div>

        {/* Atajos */}
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[11px] font-medium text-slate-500">Atajos:</span>
          {ATAJOS.map((a, i) => (
            <button
              key={a.texto}
              type="button"
              title={a.ayuda}
              onClick={() => aplicarAtajo(i)}
              className="rounded-full border border-line-strong bg-white px-3 py-1.5 text-[11.5px] font-medium text-slate-600 transition-colors hover:border-brand-200 hover:bg-brand-50 hover:text-brand-600"
            >
              {a.texto}
            </button>
          ))}
        </div>

        {/* Filtros */}
        <div className="flex flex-col gap-3 border-t border-line pt-3.5">
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={alternarPanel}
              aria-expanded={panelAbierto}
              className="inline-flex h-9 items-center gap-2 rounded-lg border border-line-strong bg-white px-3 text-[12.5px] font-semibold text-slate-600 hover:bg-app"
            >
              <SlidersHorizontal size={15} strokeWidth={2.2} />
              {panelAbierto ? 'Esconder los filtros' : 'Mostrar los filtros'}
              {cuantosFiltros > 0 && (
                <span className="rounded-full bg-brand px-2 py-[1px] text-[10px] font-bold text-white">
                  {cuantosFiltros}
                </span>
              )}
            </button>

            {activos.map((f) => (
              <button
                key={f.claves.join('+')}
                type="button"
                onClick={() => cambiar(Object.fromEntries(f.claves.map((k) => [k, ''])))}
                title="Sacar este filtro"
                className="inline-flex items-center gap-1.5 rounded-full bg-brand-50 py-1.5 pl-3 pr-2 text-[11.5px] font-semibold text-brand-600 hover:bg-[#dcecf8]"
              >
                {f.texto}
                <X size={13} strokeWidth={2.6} />
              </button>
            ))}

            {cuantosFiltros > 0 && (
              <button type="button" onClick={quitarTodos} className="text-[11.5px] font-semibold text-muted hover:text-ink">
                Quitar todos
              </button>
            )}
          </div>

          {panelAbierto && (
            <PanelDeFiltros
              pedido={pedido}
              cambiar={cambiar}
              catalogos={catalogos}
              abiertos={grupos}
              alternar={alternarGrupo}
            />
          )}
        </div>
      </Card>

      {falloElExcel && <Aviso tono="ambar" titulo="Excel">{falloElExcel}</Aviso>}

      {respuesta && (
        <Resumen grano={vista} resumen={respuesta.resumen} total={total} />
      )}

      <div ref={inicioDeResultados} id="resultados-del-buscador" className="scroll-mt-4" />

      <Card className="overflow-hidden">
        <CardHeader
          titulo="Resultado"
          cuenta={respuesta ? total.toLocaleString('es-AR') : undefined}
          ayuda={hayInactivas ? 'Las empresas que cerraron salen al final, en rojo: se ven con su historia, pero no se les puede cotizar.' : undefined}
          acciones={
            <div className="w-[230px]">
              <Lista
                aria-label="Ordenar"
                vacio="Ordenar: como corresponde"
                value={pedido.orden ?? ''}
                onChange={(e) => cambiar({ orden: e.target.value })}
                opciones={ORDENES[vista].filter(
                  (o) => (o.valor !== 'importe' || veImportes) && (o.valor !== 'relevancia' || !!pedido.q),
                )}
              />
            </div>
          }
        />

        {cargando && !respuesta && <Cargando />}

        {!cargando && error && <SinResultados titulo="No pudimos hacer la búsqueda" detalle={error} accion={<Boton onClick={recargar}>Probar de nuevo</Boton>} />}

        {!error && respuesta && respuesta.data.length === 0 && !cargando && (
          <SinResultados
            titulo={`No encontramos ${QUE[vista]} con eso`}
            detalle="Probá con menos palabras, o sacá algún filtro."
            accion={
              cuantosFiltros > 0 ? <Boton onClick={quitarTodos}>Quitar todos los filtros</Boton> : undefined
            }
          />
        )}

        {!error && respuesta && respuesta.data.length > 0 && (
          <div className={cx('transition-opacity', cargando && 'opacity-60')}>
            <Tablas
              grano={vista}
              respuesta={respuesta}
              veImportes={veImportes}
              puedeDescartar={esAdmin}
              alDescartar={setADescartar}
            />

            <Paginador
              pagina={respuesta.meta.current_page}
              paginas={respuesta.meta.last_page}
              total={total}
              porPagina={respuesta.meta.per_page}
              onIr={irAPagina}
              que={QUE[vista]}
            />

            <NotaPie>
              {vista === 'empresas'
                ? 'Desde acá se entra a la ficha, se ve la lista de cotizaciones o se imprime.'
                : 'Desde acá se abre la cotización o se imprime, sin pasar por la ficha de la empresa.'}
              {!veImportes && ' No se muestran importes: no tenés permiso para verlos.'}
            </NotaPie>
          </div>
        )}
      </Card>

      <DescartarBorrador
        consulta={aDescartar}
        onCerrar={() => setADescartar(null)}
        onListo={(m) => {
          setAviso(m)
          recargar()
        }}
      />
      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </div>
  )
}

/** La tabla que corresponde a la vista. */
function Tablas({
  grano,
  respuesta,
  veImportes,
  puedeDescartar,
  alDescartar,
}: {
  grano: Grano
  respuesta: RespuestaDelBuscador<FilaEmpresa | FilaConsulta | FilaRenglon | FilaComprobante>
  veImportes: boolean
  puedeDescartar: boolean
  alDescartar: (consulta: { id: number; empresa: string }) => void
}) {
  switch (grano) {
    case 'consultas':
      return (
        <TablaDeConsultas
          filas={respuesta.data as FilaConsulta[]}
          veImportes={veImportes}
          puedeDescartar={puedeDescartar}
          alDescartar={alDescartar}
        />
      )
    case 'renglones':
      return <TablaDeRenglones filas={respuesta.data as FilaRenglon[]} veImportes={veImportes} />
    case 'comprobantes':
      return <TablaDeComprobantes filas={respuesta.data as FilaComprobante[]} veImportes={veImportes} />
    default:
      return <TablaDeEmpresas filas={respuesta.data as FilaEmpresa[]} />
  }
}


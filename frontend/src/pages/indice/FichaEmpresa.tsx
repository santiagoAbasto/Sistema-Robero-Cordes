import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { ChevronRight, LayoutGrid, List, Plus, Printer, Pencil, History, Trash2 } from 'lucide-react'
import {
  Accion,
  Aviso,
  Boton,
  Campo,
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
import { Confirmar, Guardado } from '../../components/ui/form'
import {
  actualizarEmpresa,
  anotarVisita,
  archivarContacto,
  archivarRazonSocial,
  borrarCampo,
  cantidad,
  fecha,
  mensajeDeError,
  plata,
  traerEmpresa,
  usarComoBorrador,
  useCarga,
} from '../../lib/indice'
import EmpresaForm, { estadoInicial } from './EmpresaForm'
import type { EstadoEmpresaForm } from './EmpresaForm'
import { ModalCampo, ModalContacto, ModalRazonSocial } from './modales'
import Enlaces from './Enlaces'
import Revisiones from './Revisiones'
import type { CampoEmpresa, Consulta, Contacto, Empresa, RazonSocial } from '../../types/indice'


/**
 * La ficha de la empresa: es la pantalla que reemplaza a la hoja del índice.
 *
 * Arriba los datos, la web y la observación general, que se miran siempre.
 * Abajo una solapa por sección —contactos, a quién se le factura,
 * condiciones, cotizaciones, pedidos, observaciones— y se ve una sola.
 */
export default function FichaEmpresa() {
  const { id } = useParams<{ id: string }>()
  const { datos: empresa, cargando, error, recargar } = useCarga(() => traerEmpresa(id!), [id])

  // La solapa abierta va en el #: "/empresas/12#cotizaciones" desde la lista
  // abre directo ahi, y volver atras vuelve a la misma.
  const { hash } = useLocation()
  const navigate = useNavigate()
  const solapa = (SOLAPAS.find((s) => `#${s}` === hash) ?? 'cotizaciones') as Solapa

  // Modo modificar de los datos de la ficha.
  const [editando, setEditando] = useState(false)
  const [valores, setValores] = useState<EstadoEmpresaForm>(estadoInicial())
  const [guardando, setGuardando] = useState(false)
  const [errorForm, setErrorForm] = useState<string | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)

  // Ventanas de lo que cuelga de la ficha.
  const [contactoEdit, setContactoEdit] = useState<Contacto | null | undefined>(undefined)
  const [razonEdit, setRazonEdit] = useState<RazonSocial | null | undefined>(undefined)
  const [campoEdit, setCampoEdit] = useState<CampoEmpresa | null | undefined>(undefined)
  const [aArchivar, setAArchivar] = useState<
    { tipo: 'contacto' | 'razon' | 'campo'; id: number; nombre: string } | null
  >(null)
  const [archivando, setArchivando] = useState(false)

  function abrirEdicion() {
    setValores(estadoInicial(empresa))
    setErrorForm(null)
    setEditando(true)
  }

  async function guardarDatos() {
    if (!valores.nombre.trim()) {
      setErrorForm('El nombre de la empresa es lo unico que no puede quedar vacio.')

      return
    }

    setGuardando(true)
    setErrorForm(null)

    try {
      await actualizarEmpresa(empresa!.id, valores)
      setEditando(false)
      setAviso('Datos guardados. El cambio quedó en el historial.')
      recargar()
    } catch (err) {
      setErrorForm(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  async function confirmarArchivado() {
    if (!aArchivar) return
    setArchivando(true)

    try {
      if (aArchivar.tipo === 'contacto') await archivarContacto(aArchivar.id)
      if (aArchivar.tipo === 'razon') await archivarRazonSocial(aArchivar.id)
      if (aArchivar.tipo === 'campo') await borrarCampo(aArchivar.id)

      setAviso(aArchivar.tipo === 'campo' ? 'Campo quitado.' : 'Quedó desactivado. No se borró nada.')
      setAArchivar(null)
      recargar()
    } catch (err) {
      setAviso(mensajeDeError(err))
    } finally {
      setArchivando(false)
    }
  }

  // Para "Últimas empresas vistas" del menú.
  useEffect(() => {
    if (!empresa) return
    anotarVisita({
      id: empresa.id,
      nombre: empresa.nombre,
      relaciones: empresa.relaciones.filter((r) => r.activa).map((r) => r.relacion),
      localidad: empresa.localidad,
    })
  }, [empresa])

  if (cargando) return <Cargando texto="Abriendo la ficha…" />

  if (error || !empresa) {
    return (
      <SinResultados
        titulo="No pudimos abrir la ficha"
        detalle={error ?? 'La empresa no existe o fue archivada.'}
        accion={
          <Link to="/empresas/registros">
            <Boton variante="suave">Volver a la lista</Boton>
          </Link>
        }
      />
    )
  }

  const activas = empresa.relaciones.filter((r) => r.activa).map((r) => r.relacion)

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
        titulo={empresa.nombre}
        chips={activas.map((r) => (
          <Chip key={r} tono={r === 'Cliente' ? 'verde' : r === 'Proveedor' ? 'brand' : 'neutro'}>
            {r}
          </Chip>
        ))}
        acciones={
          <>
            <Link to={`/empresas/${empresa.id}/cambios`}>
              <Boton variante="suave">
                <History size={15} strokeWidth={2} />
                Ver cambios
              </Boton>
            </Link>
            <Link to={`/imprimir?empresa=${empresa.id}`}>
              <Boton variante="suave">
                <Printer size={15} strokeWidth={2} />
                Imprimir
              </Boton>
            </Link>
            <Boton variante="suave" onClick={abrirEdicion}>
              <Pencil size={15} strokeWidth={2} />
              Modificar
            </Boton>
            <Link to={`/empresas/${empresa.id}/agregar?tipo=Cotizacion`}>
              <Boton variante="primario">
                <Plus size={15} strokeWidth={2.4} />
                Nueva cotización
              </Boton>
            </Link>
          </>
        }
      />

      {editando ? (
        <Card className="flex flex-col gap-4 p-[22px]">
          <div className="flex flex-wrap items-center gap-2.5">
            <h2 className="text-[15px] font-semibold text-ink">Modificar los datos</h2>
            <Chip tono="ambar">estás editando</Chip>
            <div className="ml-auto flex items-center gap-2.5">
              <Boton variante="suave" onClick={() => setEditando(false)} disabled={guardando}>
                Cancelar
              </Boton>
              <Boton variante="primario" onClick={guardarDatos} disabled={guardando}>
                {guardando ? 'Guardando…' : 'Guardar cambios'}
              </Boton>
            </div>
          </div>

          {errorForm && <Aviso tono="ambar">{errorForm}</Aviso>}

          <EmpresaForm valores={valores} onChange={setValores} />
        </Card>
      ) : (
        <DatosDeLaEmpresa empresa={empresa} onModificar={abrirEdicion}>
          <Enlaces
            empresa={empresa}
            onCambio={(m) => {
              setAviso(m)
              recargar()
            }}
          />
        </DatosDeLaEmpresa>
      )}

      <Solapas
        empresa={empresa}
        abierta={solapa}
        onAbrir={(s) => navigate({ hash: s }, { replace: true })}
      />

      {solapa === 'contactos' && <Contactos
        contactos={empresa.contactos}
        onAgregar={() => setContactoEdit(null)}
        onModificar={(c) => setContactoEdit(c)}
        onArchivar={(c) => setAArchivar({ tipo: 'contacto', id: c.id, nombre: c.nombre })}
      />}
      {solapa === 'facturacion' && <SeFacturaA
        empresa={empresa}
        onAgregar={() => setRazonEdit(null)}
        onModificar={(r) => setRazonEdit(r)}
        onArchivar={(r) => setAArchivar({ tipo: 'razon', id: r.id, nombre: r.razon_social })}
      />}
      {solapa === 'condiciones' && <CondicionesDeTrabajo
        empresa={empresa}
        onAgregar={() => setCampoEdit(null)}
        onModificar={(c) => setCampoEdit(c)}
        onQuitar={(c) => setAArchivar({ tipo: 'campo', id: c.id, nombre: c.titulo })}
      />}

      {solapa === 'cotizaciones' && <Historial
        id="cotizaciones"
        titulo="Cotizaciones"
        consultas={empresa.cotizaciones}
        ayuda="Todo lo que se le cotizó a esta empresa. Cada una guarda su moneda, el tipo de cambio, quién la hizo y su observación interna."
        accion={{ texto: '+ Nueva cotización', to: `/empresas/${empresa.id}/agregar?tipo=Cotizacion` }}
        empresaId={empresa.id}
      />}

      {solapa === 'pedidos' && <Historial
        id="pedidos"
        titulo="Pedidos"
        consultas={empresa.pedidos}
        ayuda="Ventas de material que ya está en stock. Se descuentan del depósito al confirmarse."
        accion={{ texto: '+ Agregar pedido', to: `/empresas/${empresa.id}/agregar?tipo=Pedido` }}
        empresaId={empresa.id}
      />}

      {solapa === 'observaciones' && <Historial
        id="observaciones"
        titulo="Observaciones"
        consultas={empresa.observaciones_empresa}
        ayuda="Lo que pasó con la empresa y no es una cotización: una llamada, una visita, un reclamo."
        accion={{ texto: '+ Agregar observacion', to: `/empresas/${empresa.id}/agregar?tipo=Observacion` }}
        empresaId={empresa.id}
        sinImprimir
      />}

      <ModalContacto
        abierto={contactoEdit !== undefined}
        empresaId={empresa.id}
        contacto={contactoEdit}
        onCerrar={() => setContactoEdit(undefined)}
        onGuardado={(m) => {
          setAviso(m)
          recargar()
        }}
      />

      <ModalRazonSocial
        abierto={razonEdit !== undefined}
        empresaId={empresa.id}
        razon={razonEdit}
        onCerrar={() => setRazonEdit(undefined)}
        onGuardado={(m) => {
          setAviso(m)
          recargar()
        }}
      />

      <ModalCampo
        abierto={campoEdit !== undefined}
        empresaId={empresa.id}
        campo={campoEdit}
        onCerrar={() => setCampoEdit(undefined)}
        onGuardado={(m) => {
          setAviso(m)
          recargar()
        }}
      />

      <Confirmar
        abierto={aArchivar !== null}
        titulo={aArchivar?.tipo === 'campo' ? 'Quitar el campo' : 'Desactivar'}
        detalle={
          aArchivar?.tipo === 'campo'
            ? `Se quita "${aArchivar?.nombre}" de las condiciones de trabajo de esta empresa.`
            : `"${aArchivar?.nombre}" deja de aparecer, pero no se borra: el historial viejo lo sigue mostrando.`
        }
        textoConfirmar={aArchivar?.tipo === 'campo' ? 'Quitar' : 'Desactivar'}
        onCerrar={() => setAArchivar(null)}
        onConfirmar={confirmarArchivado}
        trabajando={archivando}
      />

      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </div>
  )
}

/* ---------------------------------------------------------------- los datos */

const SOLAPAS = ['contactos', 'facturacion', 'condiciones', 'cotizaciones', 'pedidos', 'observaciones'] as const
type Solapa = (typeof SOLAPAS)[number]

/**
 * Una solapa por sección, y se ve una sola.
 *
 * Eran ocho tarjetas apiladas, la mayoría vacías, y lo que casi siempre se
 * busca —las cotizaciones— quedaba al final: "la saturacion es esta
 * pantalla, hacen mucho scroll". Cada solapa dice cuántos tiene, así se sabe
 * qué hay sin abrirla.
 */
function Solapas({
  empresa,
  abierta,
  onAbrir,
}: {
  empresa: Empresa
  abierta: Solapa
  onAbrir: (s: Solapa) => void
}) {
  const solapas: { s: Solapa; texto: string; n: number }[] = [
    { s: 'contactos', texto: 'Contactos', n: empresa.contactos?.length ?? 0 },
    { s: 'facturacion', texto: 'Se factura a', n: empresa.razones_sociales?.length ?? 0 },
    { s: 'condiciones', texto: 'Condiciones', n: empresa.campos?.length ?? 0 },
    { s: 'cotizaciones', texto: 'Cotizaciones', n: empresa.cotizaciones?.length ?? 0 },
    { s: 'pedidos', texto: 'Pedidos', n: empresa.pedidos?.length ?? 0 },
    { s: 'observaciones', texto: 'Observaciones', n: empresa.observaciones_empresa?.length ?? 0 },
  ]

  return (
    <div
      role="tablist"
      className="sticky top-2 z-30 -mb-2 flex flex-wrap items-center gap-1 rounded-[10px] border border-line bg-white/95 p-1.5 shadow-sm backdrop-blur"
    >
      {solapas.map(({ s, texto, n }) => {
        const activa = s === abierta

        return (
          <button
            key={s}
            type="button"
            role="tab"
            aria-selected={activa}
            onClick={() => onAbrir(s)}
            className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12.5px] transition-colors ${
              activa ? 'bg-brand text-white font-semibold' : 'font-medium text-slate-600 hover:bg-app hover:text-ink'
            }`}
          >
            {texto}
            <span
              className={`rounded-full px-1.5 py-0.5 text-[10.5px] font-bold tabular-nums ${
                activa ? 'bg-white/20 text-white' : n > 0 ? 'bg-brand-50 text-brand-600' : 'bg-app text-faint'
              }`}
            >
              {n}
            </span>
          </button>
        )
      })}
    </div>
  )
}

/**
 * Los datos, la web y la observación general en una sola tarjeta.
 *
 * El nombre y la relación ya están en el título; el aviso de "puede ser
 * cliente y proveedor" se ve al modificar, que es cuando sirve.
 */
function DatosDeLaEmpresa({
  empresa,
  onModificar,
  children,
}: {
  empresa: Empresa
  onModificar: () => void
  children: React.ReactNode
}) {
  return (
    <Card className="flex flex-col gap-3 p-[18px]">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Campo etiqueta="Rubro" valor={empresa.rubro} />
        <Campo etiqueta="CUIT" valor={empresa.cuit} />
        <Campo etiqueta="Codigo ISIS" valor={empresa.codigo_isis} />
        <Campo etiqueta="ID del indice" valor={empresa.codigo_indice} />
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr]">
        <Campo etiqueta="Direccion" valor={empresa.direccion} />
        <Campo etiqueta="Localidad" valor={empresa.localidad} />
        <Campo etiqueta="Provincia" valor={empresa.provincia} />
        <Campo etiqueta="Pais" valor={empresa.pais} />
      </div>

      {children}

      {/* Lo que hay que saber antes de atenderla: siempre a la vista. */}
      <div className="flex flex-col gap-1 rounded-[9px] border border-[#e4dcf7] bg-[#fbfafe] px-3.5 py-2.5 sm:flex-row sm:items-start sm:gap-3">
        <span className="shrink-0 pt-px text-[10.5px] font-semibold text-[#6d28d9]">Observacion general</span>
        <p
          className={`min-w-0 flex-1 text-[12.5px] leading-relaxed ${
            empresa.observacion_general ? 'text-slate-700' : 'text-faint'
          }`}
        >
          {empresa.observacion_general ||
            'Sin observación. Acá va lo que hay que saber de esta empresa antes de atenderla.'}
        </p>
        <Accion onClick={onModificar}>Modificar</Accion>
      </div>
    </Card>
  )
}

/* ------------------------------------------------------------- los contactos */

function Contactos({
  contactos,
  onAgregar,
  onModificar,
  onArchivar,
}: {
  contactos: Contacto[]
  onAgregar: () => void
  onModificar: (c: Contacto) => void
  onArchivar: (c: Contacto) => void
}) {
  return (
    <Card id="contactos" className="scroll-mt-24 overflow-hidden">
      <CardHeader
        titulo="Contactar a:"
        cuenta={contactos.length}
        acciones={
          <>
            <span className="text-[11px] text-faint">
              Cada persona con sus propios teléfonos y mail
            </span>
            <Accion onClick={onAgregar}>+ Agregar contacto</Accion>
          </>
        }
      />

      {contactos.length === 0 ? (
        <div className="border-t border-[#eef2f6] px-[22px] py-8 text-center text-[12.5px] text-muted">
          Todavía no hay contactos cargados.
        </div>
      ) : (
        <Tabla>
          <thead>
            <tr>
              <Th>Nombre</Th>
              <Th ancho="150px">Sector</Th>
              <Th ancho="160px">Telefono y celular</Th>
              <Th ancho="160px">WhatsApp</Th>
              <Th>Mail</Th>
              <Th ancho="140px" />
            </tr>
          </thead>
          <tbody>
            {contactos.map((c) => (
              <tr key={c.id}>
                <Td>
                  <span className="inline-flex items-center gap-2">
                    <span className="font-semibold text-ink">{c.nombre}</span>
                    {c.principal && <Chip tono="verde">principal</Chip>}
                    {!c.activo && <Chip tono="neutro">ya no está</Chip>}
                  </span>
                  {/* El cargo se guardaba y no se veia en ningun lado. */}
                  {c.cargo && <span className="block text-[11px] text-muted">{c.cargo}</span>}
                </Td>
                <Td>{c.sector ?? <span className="text-faint">—</span>}</Td>
                <Td>
                  {/* El celular en la misma columna, marcado: se disca distinto. */}
                  <Medios
                    valores={[
                      ...c.telefonos.map((t) => t.valor),
                      ...(c.celulares ?? []).map((t) => `${t.valor} · cel`),
                    ]}
                  />
                </Td>
                <Td>
                  <Medios valores={c.whatsapps.map((t) => t.valor)} />
                </Td>
                <Td className="break-all">
                  <Medios valores={c.mails.map((t) => t.valor)} />
                </Td>
                <Td>
                  <div className="flex items-center justify-end gap-3">
                    <Accion onClick={() => onModificar(c)}>Modificar</Accion>
                    {c.activo && (
                      <button
                        type="button"
                        aria-label={`Desactivar a ${c.nombre}`}
                        onClick={() => onArchivar(c)}
                        className="text-faint transition-colors hover:text-danger"
                      >
                        <Trash2 size={14} strokeWidth={2} />
                      </button>
                    )}
                  </div>
                </Td>
              </tr>
            ))}
          </tbody>
        </Tabla>
      )}

      <NotaPie>
        El contacto marcado como principal es el que aparece por defecto al imprimir. En cada
        impresión se puede elegir otro, y eso no cambia la ficha.
      </NotaPie>
    </Card>
  )
}

/** Una persona puede tener varios de cada tipo: se muestran todos. */
function Medios({ valores }: { valores: string[] }) {
  if (valores.length === 0) return <span className="text-faint">—</span>

  return (
    <span className="flex flex-col gap-0.5">
      {valores.map((v, i) => (
        <span key={v} className={i === 0 ? 'text-slate-700' : 'text-faint'}>
          {v}
        </span>
      ))}
    </span>
  )
}

/* --------------------------------------------------------- se factura a ... */

function SeFacturaA({
  empresa,
  onAgregar,
  onModificar,
  onArchivar,
}: {
  empresa: Empresa
  onAgregar: () => void
  onModificar: (r: RazonSocial) => void
  onArchivar: (r: RazonSocial) => void
}) {
  const razones = empresa.razones_sociales

  return (
    <Card id="facturacion" className="scroll-mt-24 overflow-hidden">
      <CardHeader
        titulo="Se factura a"
        cuenta={razones.length}
        acciones={
          <>
            <span className="text-[11px] text-faint">
              Un mismo cliente puede facturar a varias razones sociales
            </span>
            <Accion onClick={onAgregar}>+ Agregar razon social</Accion>
          </>
        }
      />

      {razones.length === 0 ? (
        <div className="border-t border-[#eef2f6] px-[22px] py-8 text-center text-[12.5px] text-muted">
          No hay razones sociales cargadas. Se factura con los datos de la empresa.
        </div>
      ) : (
        <Tabla>
          <thead>
            <tr>
              <Th>Razon social</Th>
              <Th ancho="160px">CUIT</Th>
              <Th ancho="150px">Condicion IVA</Th>
              <Th ancho="140px">Localidad</Th>
              <Th ancho="140px" />
            </tr>
          </thead>
          <tbody>
            {razones.map((r) => (
              <tr key={r.id}>
                <Td>
                  <div className="flex flex-col gap-1">
                    <span className="inline-flex items-center gap-2">
                      <span className="font-semibold text-ink">{r.razon_social}</span>
                      {r.habitual && <Chip tono="verde">habitual</Chip>}
                    </span>
                    {r.iibb_condicion && (
                      <span className="text-[10.5px] text-faint">
                        IIBB {r.iibb_condicion}
                        {r.iibb_provincia_sede && `   ·   Sede ${r.iibb_provincia_sede}`}
                        {r.iibb_numero && `   ·   ${r.iibb_numero}`}
                      </span>
                    )}
                  </div>
                </Td>
                <Td>{r.cuit}</Td>
                <Td>{r.condicion_iva ?? <span className="text-faint">—</span>}</Td>
                <Td>{r.localidad ?? <span className="text-faint">—</span>}</Td>
                <Td>
                  <div className="flex items-center justify-end gap-3">
                    <Accion onClick={() => onModificar(r)}>Modificar</Accion>
                    {r.activa && !r.habitual && (
                      <button
                        type="button"
                        aria-label={`Desactivar ${r.razon_social}`}
                        onClick={() => onArchivar(r)}
                        className="text-faint transition-colors hover:text-danger"
                      >
                        <Trash2 size={14} strokeWidth={2} />
                      </button>
                    )}
                  </div>
                </Td>
              </tr>
            ))}
          </tbody>
        </Tabla>
      )}
    </Card>
  )
}

/* ------------------------------------------------- condiciones de trabajo */

function CondicionesDeTrabajo({
  empresa,
  onAgregar,
  onModificar,
  onQuitar,
}: {
  empresa: Empresa
  onAgregar: () => void
  onModificar: (c: CampoEmpresa) => void
  onQuitar: (c: CampoEmpresa) => void
}) {
  return (
    <Card className="flex flex-col gap-3.5 p-[22px]">
      <div className="flex flex-wrap items-center gap-2.5">
        <h2 className="text-[15px] font-semibold text-ink">Condiciones de trabajo</h2>
        <span className="ml-auto text-[11px] text-faint">
          Cada empresa arma los campos que necesita
        </span>
      </div>

      {empresa.campos.length === 0 ? (
        <p className="text-[12.5px] text-muted">
          Esta empresa todavía no tiene condiciones cargadas.
        </p>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {empresa.campos.map((c) => (
            <div key={c.id} className="group relative">
              <Campo
                etiqueta={c.titulo}
                valor={c.valor}
                tag={c.usar_al_cotizar ? <Chip tono="brand">se usa al cotizar</Chip> : undefined}
              />
              <div className="absolute right-1.5 top-0 flex items-center gap-2 opacity-0 transition-opacity group-hover:opacity-100">
                <Accion onClick={() => onModificar(c)}>Modificar</Accion>
                <button
                  type="button"
                  aria-label={`Quitar ${c.titulo}`}
                  onClick={() => onQuitar(c)}
                  className="text-faint transition-colors hover:text-danger"
                >
                  <Trash2 size={13} strokeWidth={2} />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3.5">
        <Accion onClick={onAgregar}>+ Agregar campo</Accion>
        <span className="ml-auto text-[11px] text-faint">
          Se escribe el título y abajo el dato. Los que no se usan no aparecen.
        </span>
      </div>
    </Card>
  )
}

/* ---------------------------------------------------------------- historial */

function Historial({
  id,
  titulo,
  consultas,
  ayuda,
  accion,
  empresaId,
  sinImprimir,
}: {
  id: string
  titulo: string
  consultas: Consulta[]
  ayuda: string
  accion: { texto: string; to: string }
  empresaId: number
  sinImprimir?: boolean
}) {
  const [visibles, setVisibles] = useState(10)
  // Por defecto, cajas abiertas: se ve cada cotización entera sin tener que
  // abrirla. "Lista" es la vista compacta de antes, para recorrer muchas.
  const [vista, setVista] = useState<'cards' | 'lista'>('cards')

  return (
    <Card id={id} className="scroll-mt-24 overflow-hidden">
      <CardHeader
        titulo={titulo}
        cuenta={consultas.length}
        ayuda={ayuda}
        acciones={
          <div className="flex items-center gap-3">
            {consultas.length > 0 && (
              <div className="flex rounded-[8px] border border-line-strong bg-white p-0.5">
                {([
                  { v: 'cards', Icono: LayoutGrid, texto: 'Cards' },
                  { v: 'lista', Icono: List, texto: 'Lista' },
                ] as const).map(({ v, Icono, texto }) => (
                  <button
                    key={v}
                    type="button"
                    onClick={() => setVista(v)}
                    aria-pressed={vista === v}
                    className={`flex items-center gap-1.5 rounded-[6px] px-2.5 py-1 text-[11.5px] font-semibold transition-colors ${
                      vista === v ? 'bg-brand text-white' : 'text-slate-600 hover:bg-app'
                    }`}
                  >
                    <Icono size={13} strokeWidth={2.2} />
                    {texto}
                  </button>
                ))}
              </div>
            )}
            <Accion to={accion.to}>{accion.texto}</Accion>
          </div>
        }
      />

      {consultas.length === 0 ? (
        <div className="border-t border-[#eef2f6] px-[22px] py-8 text-center text-[12.5px] text-muted">
          Todavía no hay nada cargado en esta sección.
        </div>
      ) : (
        <>
          <ul
            className={
              vista === 'cards'
                ? 'flex flex-col gap-3 border-t border-[#eef2f6] bg-[#fbfcfe] p-[18px]'
                : 'border-t border-[#eef2f6]'
            }
          >
            {consultas.slice(0, visibles).map((c) => (
              <EntradaHistorial
                key={c.id}
                consulta={c}
                empresaId={empresaId}
                sinImprimir={sinImprimir}
                vista={vista}
              />
            ))}
          </ul>

          {/*
            De a diez. FABESA tiene 114 cotizaciones y cada una dibujada entera
            hacía una pantalla de varios metros: lo que se busca casi siempre es
            lo último, y para lo viejo está la busqueda por material o fecha.
          */}
          {visibles < consultas.length && (
            <button
              type="button"
              onClick={() => setVisibles((v) => v + 10)}
              className="w-full border-t border-[#eef2f6] bg-app px-[22px] py-2.5 text-[12.5px] font-semibold text-brand-600 transition-colors hover:bg-[#eef4fa]"
            >
              Ver 10 más · quedan {consultas.length - visibles}
            </button>
          )}
        </>
      )}
    </Card>
  )
}

function EntradaHistorial({
  consulta,
  empresaId,
  sinImprimir,
  vista,
}: {
  consulta: Consulta
  empresaId: number
  sinImprimir?: boolean
  vista: 'cards' | 'lista'
}) {
  const navigate = useNavigate()
  const [usando, setUsando] = useState(false)

  // "Usar como borrador": arranca un borrador nuevo con todo lo de esta
  // cotización, en la misma empresa, y lo abre para editarlo. Sirve para
  // recotizar sin tocar la que ya salió.
  async function usarComoBorradorAca() {
    setUsando(true)

    try {
      navigate(`/consultas/${await usarComoBorrador(consulta.id, empresaId)}`)
    } catch {
      setUsando(false)
    }
  }

  const lineas = (consulta.lineas ?? []).filter((l) => !l.quitada)
  const observaciones = consulta.observaciones ?? []

  /*
    Cerrada de entrada.

    Cada cotizacion dibujada entera —sus lineas, lo que habia pedido, sus
    observaciones y el pie— ocupaba media pantalla, y una empresa como FABESA
    tiene 114. Lo que se mira al abrir la ficha es la fecha, el estado y de que
    era; el detalle se abre cuando interesa.
  */
  // En vista de cards arranca abierta (es una caja entera); en lista, cerrada.
  // Cambiar la vista reabre o cierra todas, pero cada una se puede tocar aparte.
  const [abierto, setAbierto] = useState(vista === 'cards')
  useEffect(() => setAbierto(vista === 'cards'), [vista])

  // "Antes y después": la bitácora de esta cotización, para ver cómo llegaron
  // los datos (qué decía antes y qué dice ahora en cada guardado).
  const [verCambios, setVerCambios] = useState(false)

  // De que era, en un renglon: el primer material y cuantos mas hay.
  const resumen = consulta.tipo === 'Observacion'
    ? (consulta.texto ?? '').slice(0, 90)
    : lineas.length === 0
      ? 'sin lineas'
      : lineas[0].descripcion + (lineas.length > 1 ? `  +${lineas.length - 1}` : '')

  return (
    <li
      className={
        vista === 'cards'
          ? 'flex flex-col gap-2.5 rounded-[12px] border border-line bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,.05)]'
          : 'flex flex-col gap-2.5 border-b border-[#eef2f6] px-[22px] py-3.5 last:border-b-0'
      }
    >
      {/* fecha, contacto y estado */}
      <div
        role="button"
        tabIndex={0}
        aria-expanded={abierto}
        onClick={() => setAbierto((a) => !a)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault()
            setAbierto((a) => !a)
          }
        }}
        className="-mx-2 flex cursor-pointer flex-wrap items-center gap-2.5 rounded-lg px-2 py-1 transition-colors hover:bg-app"
      >
        <ChevronRight
          size={14}
          strokeWidth={2.4}
          className={`shrink-0 text-faint transition-transform ${abierto ? 'rotate-90' : ''}`}
        />
        <span className="text-[12.5px] font-semibold text-ink">{fecha(consulta.fecha)}</span>
        {/*
          El número con su revisión: es por el que pregunta el cliente, y cada
          versión aparece con la suya. Sin emitir no tiene número todavía.
        */}
        {consulta.numero_con_revision ? (
          <span className="rounded-md bg-app px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-slate-600">
            {consulta.numero_con_revision}
          </span>
        ) : (
          !consulta.emitida && !consulta.id_sistema && consulta.tipo !== 'Observacion' && (
            <Chip tono="ambar">Borrador</Chip>
          )
        )}
        {/* De que era, para no tener que abrirla para saberlo. */}
        {! abierto && (
          <span className="min-w-0 max-w-[40%] flex-1 truncate text-[11.5px] text-slate-600">
            {resumen}
          </span>
        )}

        {abierto && consulta.contacto && (
          <>
            <span className="text-[11px] text-[#c3cdd6]">·</span>
            <span className="text-[11.5px] text-muted">Contactar a  {consulta.contacto.nombre}</span>
          </>
        )}
        <div className="ml-auto flex items-center gap-2">
          {consulta.total > 0 && (
            <span className="text-[12px] font-semibold text-ink">{plata(consulta.total)}</span>
          )}
          {consulta.estado === 'Vendida' && consulta.nro_factura && (
            <Chip tono="verde">Vendida  ·  Factura {consulta.nro_factura}</Chip>
          )}
          {consulta.esta_vencida && consulta.estado !== 'Vendida' && (
            <Chip tono="ambar">Vencida el {fecha(consulta.vence_el)}</Chip>
          )}
          {consulta.lineas_iguales_a_lo_pedido && (
            <Chip tono="neutro">{consulta.lineas_iguales_a_lo_pedido} iguales a lo pedido</Chip>
          )}
        </div>
      </div>

      {abierto && (
        <>
      {/* las líneas, o el texto si es una observación */}
      {consulta.tipo === 'Observacion' ? (
        <div className="rounded-[9px] border border-[#e4dcf7] bg-[#fbfafe] px-3.5 py-3 text-[12px] leading-relaxed text-slate-700">
          {consulta.texto}
        </div>
      ) : (
        lineas.length > 0 && (
          <div className="rounded-[9px] border border-[#eef2f6] bg-[#f8fafc] px-3.5 py-3">
            <ul className="flex flex-col gap-1.5">
              {lineas.map((l) => (
                <li key={l.id} className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                  <span className="min-w-0 flex-1 text-[12px] text-slate-700">
                    {l.descripcion}
                    {l.desde_stock && <span className="ml-2 text-[11px] text-faint">de stock</span>}
                  </span>

                  {/* Cuando se cotiza por metro y se factura por kilo. */}
                  {l.cambia_de_unidad && (
                    <span className="text-[11px] text-brand-600">
                      {cantidad(l.cantidad)} {l.unidad} × {cantidad(l.factor_conversion)} ={' '}
                      {cantidad(l.cantidad_facturar)} {l.unidad_factura} × {plata(l.precio_por_kilo)}{' '}
                      por kilo
                    </span>
                  )}

                  <span className="w-24 text-right text-[12px] font-semibold text-ink">
                    {plata(l.importe)}
                  </span>
                </li>
              ))}
            </ul>

            {/* Lo que se cotizó distinto a lo que pidieron. */}
            {(consulta.lineas ?? [])
              .filter((l) => !l.quitada && !l.igual_a_lo_pedido && l.pedido)
              .map((l) => (
                <div
                  key={`pedido-${l.id}`}
                  className="mt-2 flex flex-wrap items-center gap-2 rounded-lg border border-[#f3d9a6] bg-[#fff8ee] px-3 py-2"
                >
                  <span className="text-[9.5px] font-bold text-warning-ink">Le habia pedido</span>
                  <span className="min-w-0 flex-1 text-[11.5px] text-[#7a5a1e]">
                    {l.pedido?.texto}
                  </span>
                  {l.motivo_cambio && <Chip tono="ambar">{l.motivo_cambio}</Chip>}
                </div>
              ))}
          </div>
        )
      )}

      {/* La observación interna de esta cotización. */}
      {observaciones.length > 0 && (
        <div className="flex flex-col gap-1.5">
          {observaciones.map((o) => (
            <div
              key={o.id}
              className="flex flex-wrap items-center gap-2 rounded-lg border border-[#e4dcf7] bg-[#fbfafe] px-3 py-2"
            >
              <span className="text-[10px] font-semibold text-[#6d28d9]">
                Observacion {o.numero}
              </span>
              <span className="min-w-0 flex-1 text-[11.5px] text-[#5b4b78]">{o.texto}</span>
              <span className="text-[10.5px] text-faint">{o.quien}</span>
            </div>
          ))}
        </div>
      )}

      {/* pie: moneda, quién lo hizo, NOTA y acciones */}
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
        {consulta.moneda && (
          <span className="text-[11px] font-medium text-muted">Moneda  {consulta.moneda}</span>
        )}
        {consulta.tipo_cambio && (
          <span className="text-[11px] font-medium text-muted">
            Tipo de cambio  $ {plata(consulta.tipo_cambio)}
          </span>
        )}
        {consulta.quien_lo_hizo && (
          <span className="text-[11px] font-medium text-brand-600">
            Quien lo hizo  {consulta.quien_lo_hizo}
          </span>
        )}
        {consulta.nota && (
          <span className="min-w-0 flex-1 truncate text-[11px] text-[#b0bcc7]">
            NOTA  {consulta.nota}
          </span>
        )}

        <div className="ml-auto flex items-center gap-3.5">
          {/* Antes y después: cómo llegaron los datos, acá mismo. */}
          <Accion onClick={() => setVerCambios((v) => !v)} apagado={!verCambios}>
            {verCambios ? 'Ocultar antes y después' : 'Antes y después'}
          </Accion>
          {consulta.tipo !== 'Observacion' && (
            <>
              <Accion onClick={usarComoBorradorAca} apagado={usando}>
                {usando ? 'Creando…' : 'Usar como borrador'}
              </Accion>
              <Accion to={`/consultas/${consulta.id}/copiar`}>Copiar a otra empresa</Accion>
            </>
          )}
          {!sinImprimir && (
            <Accion to={`/imprimir?empresa=${empresaId}&consulta=${consulta.id}`}>Imprimir</Accion>
          )}
          {/* Emitida no se modifica: se abre para verla o para revisarla. */}
          <Accion to={`/consultas/${consulta.id}`}>{consulta.emitida ? 'Ver' : 'Modificar'}</Accion>
        </div>
      </div>

      {/* La bitácora de esta cotización: cada guardado, con lo que decía antes
          y lo que dice ahora. Se abre a pedido, debajo de las acciones. */}
      {verCambios && (
        <div className="mt-1">
          <Revisiones
            consultaId={consulta.id}
            vacio="Esta cotización no tuvo cambios después de crearse."
          />
        </div>
      )}
        </>
      )}
    </li>
  )
}

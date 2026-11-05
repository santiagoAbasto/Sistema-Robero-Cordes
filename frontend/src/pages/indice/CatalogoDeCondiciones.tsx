import { useEffect, useState } from 'react'
import { ArrowDown, ArrowUp, Check, Pencil, Plus, Trash2, X } from 'lucide-react'
import { Aviso, Boton, Card, Cargando, Chip, PageHeader, Tabla, Td, Th, cx } from '../../components/ui'
import { Casilla, Confirmar, Guardado, Lista, Modal, Texto } from '../../components/ui/form'
import { useAuth } from '../../lib/auth'
import { mensajeDeError, useCarga } from '../../lib/indice'
import {
  adoptarDato,
  agregarCondicion,
  agregarDato,
  borrarCondicion,
  borrarDato,
  cambiarCondicion,
  cambiarDato,
  ordenarCondiciones,
  traerCatalogoDeCondiciones,
  unirCondiciones,
  unirDatos,
  type CondicionAdmin,
  type DatoDeCondicion,
} from '../../lib/condicionesDeLaFicha'

/*
  Configuración > Condiciones de la ficha.

  La lista de las condiciones de trabajo que se cargan en la ficha de cada empresa ("Tipo de pago",
  "Flete", "Cómo lo retira") y los datos de cada una. En la ficha SÓLO se elige de acá: nada se escribe
  a mano, así dos personas no cargan lo mismo de dos formas.

  Renombrar o unir algo cambia el texto en todas las fichas que lo usan. Lo que ya está en uso no se
  borra: se desactiva (deja de ofrecerse pero las fichas lo conservan).
*/

type Pendiente =
  | { tipo: 'unir-condicion'; origen: CondicionAdmin }
  | { tipo: 'unir-dato'; origen: DatoDeCondicion; condicion: CondicionAdmin }
  | { tipo: 'borrar-condicion'; origen: CondicionAdmin }
  | { tipo: 'borrar-dato'; origen: DatoDeCondicion }
  | null

export default function CatalogoDeCondiciones() {
  const { user } = useAuth()
  const puedeCambiar = user?.role === 'Administrador'
  const { datos, cargando, recargar } = useCarga(traerCatalogoDeCondiciones, [])
  const [elegidaId, setElegidaId] = useState<number | null>(null)
  const [nueva, setNueva] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)
  const [pendiente, setPendiente] = useState<Pendiente>(null)
  const [verSueltos, setVerSueltos] = useState(false)

  const elegida = datos?.tipos.find((t) => t.id === elegidaId) ?? datos?.tipos[0] ?? null

  // Al cambiar la lista, se sigue en la misma condición si todavía existe.
  useEffect(() => {
    if (datos && elegidaId !== null && !datos.tipos.some((t) => t.id === elegidaId)) setElegidaId(null)
  }, [datos, elegidaId])

  if (cargando && !datos) return <Cargando texto="Abriendo la lista de condiciones…" />
  if (!datos) return null

  async function hacer(accion: () => Promise<unknown>, mensaje?: string) {
    setError(null)
    try {
      await accion()
      if (mensaje) setAviso(mensaje)
      recargar()
    } catch (err) {
      setError(mensajeDeError(err))
    }
  }

  async function mover(id: number, sentido: -1 | 1) {
    const ids = datos!.tipos.map((t) => t.id)
    const i = ids.indexOf(id)
    const j = i + sentido
    if (j < 0 || j >= ids.length) return
    ;[ids[i], ids[j]] = [ids[j], ids[i]]
    await hacer(() => ordenarCondiciones(ids))
  }

  async function agregar() {
    const nombre = nueva.trim()
    if (!nombre) return
    await hacer(async () => {
      const creada = (await agregarCondicion(nombre)) as { id: number }
      setNueva('')
      setElegidaId(creada.id)
    }, 'Condición agregada.')
  }

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb="Configuración"
        titulo="Condiciones de la ficha"
        bajada="Las condiciones de trabajo que se cargan en la ficha de cada empresa (Tipo de pago, Flete, Cómo lo retira…) y los datos de cada una. En la ficha sólo se elige de esta lista."
      />

      {!puedeCambiar && <Aviso tono="info">Se puede ver la lista, pero sólo los administradores la pueden cambiar.</Aviso>}
      {error && <Aviso tono="ambar">{error}</Aviso>}

      {datos.sueltos.fichas > 0 && (
        <Card className="flex flex-col gap-3 border-[#e4dcf7] bg-[#fbfafe] p-[18px]">
          <div className="flex flex-wrap items-center gap-3">
            <Chip tono="violeta">Fuera de la lista</Chip>
            <p className="min-w-[240px] flex-1 text-[12.5px] leading-relaxed text-[#5b4b78]">
              Hay <strong>{datos.sueltos.fichas}</strong> {datos.sueltos.fichas === 1 ? 'ficha' : 'fichas'} con un dato escrito a mano, de
              antes de la lista. Siguen saliendo en la cotización; revisalos y sumá a la lista los que valgan.
            </p>
            <Boton onClick={() => setVerSueltos((v) => !v)}>{verSueltos ? 'Esconder' : 'Revisarlos'}</Boton>
          </div>

          {verSueltos && (
            <Tabla>
              <thead>
                <tr>
                  <Th ancho="200px">Condición</Th>
                  <Th>Dato</Th>
                  <Th ancho="70px" derecha>Fichas</Th>
                  <Th ancho="200px" />
                </tr>
              </thead>
              <tbody>
                {datos.sueltos.datos.map((s) => (
                  <tr key={`${s.titulo}\u0000${s.valor}`}>
                    <Td className="font-medium text-ink">{s.titulo}</Td>
                    <Td>{s.valor}</Td>
                    <Td derecha>{s.n}</Td>
                    <Td>
                      {puedeCambiar && (
                        <Boton
                          className="whitespace-nowrap"
                          onClick={() =>
                            hacer(() => adoptarDato(s.titulo, s.valor), 'Agregado a la lista.')
                          }
                        >
                          Agregar a la lista
                        </Boton>
                      )}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Tabla>
          )}
        </Card>
      )}

      <div className="grid gap-[18px] lg:grid-cols-[320px_1fr]">
        {/* Las condiciones */}
        <Card className="flex flex-col gap-3 p-[18px]">
          <h2 className="text-[15px] font-semibold text-ink">Condiciones</h2>

          <ul className="flex flex-col gap-1">
            {datos.tipos.map((t, i) => (
              <li key={t.id}>
                <div
                  className={cx(
                    'flex items-center gap-1.5 rounded-lg border px-2.5 py-2 transition-colors',
                    elegida?.id === t.id ? 'border-brand-200 bg-brand-50' : 'border-transparent hover:bg-app',
                  )}
                >
                  <button type="button" onClick={() => setElegidaId(t.id)} className="flex min-w-0 flex-1 items-center gap-2 text-left">
                    <span className={cx('truncate text-[13px] font-semibold', t.activo ? 'text-ink' : 'text-faint line-through')}>
                      {t.nombre}
                    </span>
                    <span className="shrink-0 rounded-full bg-slate-100 px-1.5 py-[1px] text-[10px] font-semibold text-slate-500">
                      {t.fichas}
                    </span>
                  </button>
                  {puedeCambiar && (
                    <span className="flex shrink-0 items-center">
                      <button type="button" aria-label="Subir" disabled={i === 0} onClick={() => mover(t.id, -1)} className="p-1 text-faint hover:text-ink disabled:opacity-30">
                        <ArrowUp size={13} />
                      </button>
                      <button type="button" aria-label="Bajar" disabled={i === datos.tipos.length - 1} onClick={() => mover(t.id, 1)} className="p-1 text-faint hover:text-ink disabled:opacity-30">
                        <ArrowDown size={13} />
                      </button>
                    </span>
                  )}
                </div>
              </li>
            ))}
          </ul>

          {puedeCambiar && (
            <form
              onSubmit={(e) => {
                e.preventDefault()
                void agregar()
              }}
              className="flex items-end gap-2 border-t border-line pt-3"
            >
              <Texto className="flex-1" etiqueta="Condición nueva" value={nueva} maxLength={60} onChange={(e) => setNueva(e.target.value)} placeholder="Embalaje, Documentación…" />
              <Boton type="submit" variante="primario" disabled={!nueva.trim()} aria-label="Agregar la condición">
                <Plus size={15} strokeWidth={2.4} />
              </Boton>
            </form>
          )}
        </Card>

        {/* Lo de la elegida */}
        {elegida ? (
          <Detalle
            key={elegida.id}
            condicion={elegida}
            puedeCambiar={puedeCambiar}
            hacer={hacer}
            alUnir={(origen) => setPendiente({ tipo: 'unir-condicion', origen })}
            alBorrar={(origen) => setPendiente({ tipo: 'borrar-condicion', origen })}
            alUnirDato={(origen) => setPendiente({ tipo: 'unir-dato', origen, condicion: elegida })}
            alBorrarDato={(origen) => setPendiente({ tipo: 'borrar-dato', origen })}
          />
        ) : (
          <Card className="grid place-items-center p-10 text-[13px] text-muted">Todavía no hay condiciones en la lista.</Card>
        )}
      </div>

      <Pregunta
        pendiente={pendiente}
        condiciones={datos.tipos}
        onCerrar={() => setPendiente(null)}
        onHecho={(m) => {
          setPendiente(null)
          setAviso(m)
          recargar()
        }}
        onError={(m) => {
          setPendiente(null)
          setError(m)
        }}
      />
      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </div>
  )
}

/* ------------------------------------------------------------------ detalle */

function Detalle({
  condicion,
  puedeCambiar,
  hacer,
  alUnir,
  alBorrar,
  alUnirDato,
  alBorrarDato,
}: {
  condicion: CondicionAdmin
  puedeCambiar: boolean
  hacer: (accion: () => Promise<unknown>, mensaje?: string) => Promise<void>
  alUnir: (c: CondicionAdmin) => void
  alBorrar: (c: CondicionAdmin) => void
  alUnirDato: (d: DatoDeCondicion) => void
  alBorrarDato: (d: DatoDeCondicion) => void
}) {
  const [nombre, setNombre] = useState(condicion.nombre)
  const [dato, setDato] = useState('')

  return (
    <Card className="flex flex-col gap-4 p-[22px]">
      <div className="flex flex-wrap items-end gap-3">
        <Texto
          className="min-w-[220px] flex-1"
          etiqueta="Nombre de la condición"
          ayuda="así sale en la cotización"
          value={nombre}
          maxLength={60}
          disabled={!puedeCambiar}
          onChange={(e) => setNombre(e.target.value)}
        />
        {puedeCambiar && nombre.trim() !== condicion.nombre && (
          <Boton variante="primario" onClick={() => hacer(() => cambiarCondicion(condicion.id, { nombre }), 'Renombrada en todas las fichas.')}>
            Guardar nombre
          </Boton>
        )}
      </div>

      {puedeCambiar && (
        <div className="flex flex-wrap items-center gap-2.5">
          <Casilla marcada={condicion.activo} onChange={(v) => hacer(() => cambiarCondicion(condicion.id, { activo: v }))}>
            Se ofrece al cargar una ficha
          </Casilla>
          <Casilla
            marcada={condicion.proponer_al_cotizar}
            onChange={(v) => hacer(() => cambiarCondicion(condicion.id, { proponer_al_cotizar: v }))}
          >
            Viene tildada &quot;se usa al cotizar&quot;
          </Casilla>
          <span className="ml-auto flex items-center gap-3">
            <button type="button" onClick={() => alUnir(condicion)} className="text-[12px] font-semibold text-brand-600 hover:text-brand-700">
              Unir con otra…
            </button>
            <button type="button" onClick={() => alBorrar(condicion)} className="text-faint hover:text-danger" aria-label="Borrar la condición" title="Borrar (sólo si ninguna ficha la usa)">
              <Trash2 size={15} />
            </button>
          </span>
        </div>
      )}

      <div className="flex flex-col gap-2 border-t border-line pt-3.5">
        <div className="flex items-center gap-2">
          <h3 className="text-[13.5px] font-semibold text-ink">Datos</h3>
          <span className="text-[11px] text-faint">
            {condicion.fichas} {condicion.fichas === 1 ? 'ficha usa' : 'fichas usan'} esta condición
          </span>
        </div>

        {condicion.opciones.length === 0 && <p className="text-[12.5px] text-muted">Todavía no hay datos para esta condición.</p>}

        <ul className="flex flex-col gap-1">
          {condicion.opciones.map((o) => (
            <FilaDeDato key={o.id} dato={o} puedeCambiar={puedeCambiar} hacer={hacer} alUnir={alUnirDato} alBorrar={alBorrarDato} />
          ))}
        </ul>

        {puedeCambiar && (
          <form
            onSubmit={(e) => {
              e.preventDefault()
              if (!dato.trim()) return
              void hacer(async () => {
                await agregarDato(condicion.id, dato)
                setDato('')
              }, 'Dato agregado.')
            }}
            className="flex items-end gap-2 pt-1"
          >
            <Texto className="flex-1" etiqueta="Dato nuevo" value={dato} maxLength={255} onChange={(e) => setDato(e.target.value)} placeholder="Escribilo como querés que salga en la cotización" />
            <Boton type="submit" variante="primario" disabled={!dato.trim()}>
              Agregar
            </Boton>
          </form>
        )}
      </div>
    </Card>
  )
}

function FilaDeDato({
  dato,
  puedeCambiar,
  hacer,
  alUnir,
  alBorrar,
}: {
  dato: DatoDeCondicion
  puedeCambiar: boolean
  hacer: (accion: () => Promise<unknown>, mensaje?: string) => Promise<void>
  alUnir: (d: DatoDeCondicion) => void
  alBorrar: (d: DatoDeCondicion) => void
}) {
  const [editando, setEditando] = useState(false)
  const [texto, setTexto] = useState(dato.texto)

  return (
    <li className="flex items-center gap-2.5 rounded-lg border border-line px-3 py-2">
      {editando ? (
        <>
          <Texto className="flex-1" value={texto} maxLength={255} autoFocus onChange={(e) => setTexto(e.target.value)} />
          <button
            type="button"
            aria-label="Guardar"
            onClick={() => hacer(() => cambiarDato(dato.id, { texto }), 'Cambiado en todas las fichas.').then(() => setEditando(false))}
            className="text-success hover:text-success-ink"
          >
            <Check size={16} />
          </button>
          <button type="button" aria-label="Cancelar" onClick={() => { setTexto(dato.texto); setEditando(false) }} className="text-faint hover:text-ink">
            <X size={16} />
          </button>
        </>
      ) : (
        <>
          <span className={cx('min-w-0 flex-1 text-[13px]', dato.activo ? 'text-ink' : 'text-faint line-through')}>{dato.texto}</span>
          <span className="shrink-0 text-[11px] text-faint">
            {dato.fichas} {dato.fichas === 1 ? 'ficha' : 'fichas'}
          </span>
          {puedeCambiar && (
            <span className="flex shrink-0 items-center gap-2.5">
              <button type="button" aria-label="Renombrar" title="Cambiar cómo se escribe" onClick={() => setEditando(true)} className="text-faint hover:text-ink">
                <Pencil size={14} />
              </button>
              <button type="button" onClick={() => hacer(() => cambiarDato(dato.id, { activo: !dato.activo }))} className="text-[11.5px] font-semibold text-muted hover:text-ink">
                {dato.activo ? 'Desactivar' : 'Activar'}
              </button>
              <button type="button" onClick={() => alUnir(dato)} className="text-[11.5px] font-semibold text-brand-600 hover:text-brand-700">
                Unir…
              </button>
              <button type="button" aria-label="Borrar" title="Borrar (sólo si ninguna ficha lo usa)" onClick={() => alBorrar(dato)} className="text-faint hover:text-danger">
                <Trash2 size={14} />
              </button>
            </span>
          )}
        </>
      )}
    </li>
  )
}

/* --------------------------------------------------------- confirmaciones */

function Pregunta({
  pendiente,
  condiciones,
  onCerrar,
  onHecho,
  onError,
}: {
  pendiente: Pendiente
  condiciones: CondicionAdmin[]
  onCerrar: () => void
  onHecho: (mensaje: string) => void
  onError: (mensaje: string) => void
}) {
  const [con, setCon] = useState('')
  const [trabajando, setTrabajando] = useState(false)

  useEffect(() => setCon(''), [pendiente])

  async function ejecutar() {
    if (!pendiente) return
    setTrabajando(true)
    try {
      if (pendiente.tipo === 'borrar-condicion') {
        await borrarCondicion(pendiente.origen.id)
        onHecho('Condición borrada.')
      } else if (pendiente.tipo === 'borrar-dato') {
        await borrarDato(pendiente.origen.id)
        onHecho('Dato borrado.')
      } else if (pendiente.tipo === 'unir-condicion') {
        const r = await unirCondiciones(pendiente.origen.id, Number(con))
        onHecho(r.mensaje)
      } else {
        const r = await unirDatos(pendiente.origen.id, Number(con))
        onHecho(r.mensaje)
      }
    } catch (err) {
      onError(mensajeDeError(err))
    } finally {
      setTrabajando(false)
    }
  }

  if (pendiente?.tipo === 'borrar-condicion' || pendiente?.tipo === 'borrar-dato') {
    const nombre = pendiente.tipo === 'borrar-condicion' ? pendiente.origen.nombre : pendiente.origen.texto

    return (
      <Confirmar
        abierto
        titulo="Borrar de la lista"
        detalle={`Se borra «${nombre}» de la lista. Si alguna ficha lo usa, no se borra: en ese caso desactivalo.`}
        textoConfirmar="Borrar"
        onCerrar={onCerrar}
        onConfirmar={ejecutar}
        trabajando={trabajando}
      />
    )
  }

  const opciones =
    pendiente?.tipo === 'unir-condicion'
      ? condiciones.filter((c) => c.id !== pendiente.origen.id).map((c) => ({ valor: c.id, texto: c.nombre }))
      : pendiente?.tipo === 'unir-dato'
        ? pendiente.condicion.opciones.filter((o) => o.id !== pendiente.origen.id).map((o) => ({ valor: o.id, texto: o.texto }))
        : []
  const nombre = pendiente?.tipo === 'unir-condicion' ? pendiente.origen.nombre : pendiente?.tipo === 'unir-dato' ? pendiente.origen.texto : ''

  return (
    <Modal
      abierto={pendiente !== null}
      titulo="Unir"
      bajada={`«${nombre}» y el que elijas son lo mismo: todas las fichas pasan al que elijas y «${nombre}» se borra de la lista. No se puede deshacer.`}
      onCerrar={onCerrar}
      onGuardar={con ? ejecutar : undefined}
      guardando={trabajando}
      textoGuardar="Unir"
      ancho="max-w-md"
    >
      <Lista etiqueta="Dejar todo en" vacio="Elegí uno…" value={con} onChange={(e) => setCon(e.target.value)} opciones={opciones} />
    </Modal>
  )
}

import { useEffect, useMemo, useState } from 'react'
import {
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  Check,
  FlaskConical,
  Play,
  Plus,
  Trash2,
  X,
} from 'lucide-react'
import {
  Aviso,
  Boton,
  Card,
  CardHeader,
  Cargando,
  Chip,
  NotaPie,
  PageHeader,
  Tabla,
  Td,
  Th,
} from '../../components/ui'
import { Casilla, Etiqueta, Guardado, Texto } from '../../components/ui/form'
import { mensajeDeError, useCarga, useDebounce } from '../../lib/indice'
import {
  borrarForma,
  guardarForma,
  ordenarFormas,
  probarFormula,
  traerFormas,
  type CampoDeForma,
  type FormaAdmin,
  type PruebaDeFormula,
} from '../../lib/formas'

/* ---------------------------------------------------------------------------
   Formas y formulas.

   Acá se define qué medidas pide cada forma y con qué cuenta se saca su
   volumen. La pantalla de cotización arma sus campos con esto: agregar una
   forma o cambiarle las medidas no necesita tocar código.

   Lo que se cambie acá vale de acá en adelante. Las cotizaciones ya hechas NO
   se recalculan nunca: cada línea guardó su propia foto el día que se hizo.
--------------------------------------------------------------------------- */

interface Borrador {
  id?: number
  clave: string
  nombre: string
  orden: string
  campos: CampoDeForma[]
  expresion: string
  usa_cano: boolean
  activo: boolean
  lineas_cotizadas: number
  materiales_que_la_usan: number
  /** Con historial la clave queda congelada: renombrarla es una migración. */
  claveEditable: boolean
}

/**
 * Cómo está la cuenta de una forma, de un vistazo.
 *
 * Los tres estados importan por motivos distintos: "sin fórmula" es una
 * decisión (una brida no es un sólido simple), "fórmula inválida" es algo roto
 * que hay que arreglar antes de que devuelva pesos equivocados.
 */
function EstadoDeFormula({ forma }: { forma: FormaAdmin }) {
  if (forma.estado_formula === 'sin_formula') {
    return (
      <div>
        <Chip tono="ambar">Sin formula</Chip>
        <span className="mt-0.5 block text-[10px] text-faint">el peso se carga a mano</span>
      </div>
    )
  }

  if (forma.estado_formula === 'invalida') {
    return (
      <div>
        <Chip tono="rojo">Formula invalida</Chip>
        <span className="mt-0.5 block text-[10px] leading-tight text-danger">
          {forma.problema_formula}
        </span>
        <code className="mt-0.5 block text-[10px] text-faint line-through">{forma.expresion}</code>
      </div>
    )
  }

  return (
    <div>
      <Chip tono="verde">Formula valida</Chip>
      <code className="mt-0.5 block text-[10px] text-muted">{forma.expresion}</code>
    </div>
  )
}

/** "BARRA REDONDA" -> "barra_redonda". Es lo mismo que hace el servidor. */
function sugerirClave(nombre: string): string {
  return (
    nombre
      .toLowerCase()
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '') || 'forma'
  )
}

function borradorNuevo(): Borrador {
  return {
    clave: '',
    nombre: '',
    orden: '',
    campos: [],
    expresion: '',
    usa_cano: false,
    activo: true,
    lineas_cotizadas: 0,
    materiales_que_la_usan: 0,
    claveEditable: true,
  }
}

function desdeForma(f: FormaAdmin): Borrador {
  return {
    id: f.id,
    clave: f.clave,
    nombre: f.nombre,
    orden: String(f.orden ?? ''),
    campos: f.campos,
    expresion: f.expresion ?? '',
    usa_cano: f.usa_cano,
    activo: f.activo,
    lineas_cotizadas: f.lineas_cotizadas,
    materiales_que_la_usan: f.materiales_que_la_usan,
    claveEditable: f.clave_editable,
  }
}

export default function FormasYFormulas() {
  const { datos, cargando, recargar } = useCarga(traerFormas, [])
  const [editando, setEditando] = useState<Borrador | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  async function mover(id: number, hacia: -1 | 1) {
    const ids = (datos?.formas ?? []).map((f) => f.id)
    const i = ids.indexOf(id)
    const j = i + hacia

    if (i < 0 || j < 0 || j >= ids.length) return
    ;[ids[i], ids[j]] = [ids[j], ids[i]]

    try {
      await ordenarFormas(ids)
      recargar()
    } catch (e) {
      setError(mensajeDeError(e))
    }
  }

  if (cargando) return <Cargando texto="Abriendo las formas…" />
  if (!datos) return null

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb="Configuracion  ›  Formas y formulas"
        titulo="Formas y formulas"
        bajada="Cada forma define qué medidas hay que cargar y con qué cuenta se saca su volumen. La pantalla de cotización arma sus campos con esto."
        acciones={
          <Boton variante="primario" onClick={() => setEditando(borradorNuevo())}>
            <Plus size={15} strokeWidth={2.2} /> Nueva forma
          </Boton>
        }
      />

      {error && <Aviso tono="ambar">{error}</Aviso>}

      <Aviso tono="info">
        Lo que se cambie acá vale de acá en adelante.{' '}
        <strong>Las cotizaciones ya hechas no se recalculan nunca</strong>: cada línea guardó la
        densidad, la fórmula y las medidas del día que se cotizó, y esa foto es la que vale.
      </Aviso>

      <Card className="overflow-hidden">
        <CardHeader titulo="Las formas" cuenta={`${datos.formas.length}`} />

        <Tabla>
          <thead>
            <tr>
              <Th ancho="70px">Orden</Th>
              <Th>Forma</Th>
              <Th>Medidas que pide</Th>
              <Th>Cuenta</Th>
              <Th ancho="130px">Ya cotizado</Th>
              <Th ancho="90px" />
            </tr>
          </thead>
          <tbody>
            {datos.formas.map((f, i) => (
              <tr key={f.id} className={f.activo ? '' : 'opacity-55'}>
                <Td>
                  <div className="flex items-center gap-0.5">
                    <button
                      type="button"
                      onClick={() => mover(f.id, -1)}
                      disabled={i === 0}
                      className="rounded p-1 text-faint hover:bg-app hover:text-ink disabled:opacity-30"
                    >
                      <ArrowUp size={12} strokeWidth={2.4} />
                    </button>
                    <button
                      type="button"
                      onClick={() => mover(f.id, 1)}
                      disabled={i === datos.formas.length - 1}
                      className="rounded p-1 text-faint hover:bg-app hover:text-ink disabled:opacity-30"
                    >
                      <ArrowDown size={12} strokeWidth={2.4} />
                    </button>
                  </div>
                </Td>
                <Td>
                  <span className="font-semibold text-ink">{f.nombre}</span>
                  {!f.activo && <span className="ml-2 text-[10.5px] text-faint">desactivada</span>}
                  {f.usa_cano && (
                    <span className="ml-2 text-[10.5px] text-brand-600">con caño estandar</span>
                  )}
                  <code className="block text-[10px] text-faint">{f.clave}</code>
                </Td>
                <Td>
                  {f.campos.length === 0 ? (
                    <span className="text-faint">—</span>
                  ) : (
                    <span className="text-[11.5px]">
                      {f.campos.map((c) => c.label).join(' · ')}
                    </span>
                  )}
                </Td>
                <Td>
                  <EstadoDeFormula forma={f} />
                </Td>
                <Td>
                  {f.lineas_cotizadas > 0 ? (
                    <span className="text-[11.5px] text-muted">
                      {f.lineas_cotizadas} {f.lineas_cotizadas === 1 ? 'linea' : 'lineas'}
                    </span>
                  ) : (
                    <span className="text-faint">—</span>
                  )}
                  {f.materiales_que_la_usan > 0 && (
                    <span className="block text-[10.5px] text-faint">
                      {f.materiales_que_la_usan} material
                      {f.materiales_que_la_usan === 1 ? '' : 'es'}
                    </span>
                  )}
                </Td>
                <Td>
                  <button
                    type="button"
                    onClick={() => setEditando(desdeForma(f))}
                    className="text-[11.5px] font-semibold text-brand-600 hover:text-brand"
                  >
                    Editar
                  </button>
                </Td>
              </tr>
            ))}
          </tbody>
        </Tabla>

        <NotaPie>
          Las medidas se nombran igual que en la fórmula. Una forma sin cuenta funciona: el peso se
          carga a mano.
        </NotaPie>
      </Card>

      {editando && (
        <EditorDeForma
          borrador={editando}
          medidasPosibles={datos.medidas_posibles}
          onCerrar={() => setEditando(null)}
          onGuardado={(nombre) => {
            setEditando(null)
            setAviso(
              nombre.includes(' ')
                ? nombre
                : `${nombre} guardada. La pantalla de cotización ya la usa.`,
            )
            recargar()
          }}
        />
      )}

      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </div>
  )
}

/* ------------------------------------------------------------------ editor */

function EditorDeForma({
  borrador,
  medidasPosibles,
  onCerrar,
  onGuardado,
}: {
  borrador: Borrador
  medidasPosibles: CampoDeForma[]
  onCerrar: () => void
  onGuardado: (nombre: string) => void
}) {
  const [f, setF] = useState<Borrador>(borrador)
  const [valores, setValores] = useState<Record<string, string>>({})
  const [prueba, setPrueba] = useState<PruebaDeFormula | null>(null)
  const [probando, setProbando] = useState(false)
  const [guardando, setGuardando] = useState(false)
  const [error, setError] = useState<string | null>(null)

  /** La corre a pedido, con los valores que haya cargados en ese momento. */
  async function probarAhora() {
    if (!f.expresion.trim()) return

    setProbando(true)

    try {
      setPrueba(
        await probarFormula({
          expresion: f.expresion,
          campos: f.campos,
          valores: Object.fromEntries(
            Object.entries(valores).map(([k, v]) => [k, Number(v) || 0]),
          ),
        }),
      )
    } catch {
      setPrueba(null)
    } finally {
      setProbando(false)
    }
  }

  const set = <K extends keyof Borrador>(k: K, v: Borrador[K]) => setF((p) => ({ ...p, [k]: v }))

  // Se prueba sola mientras se escribe, pero sin ametrallar al servidor.
  const expresionDif = useDebounce(f.expresion, 400)
  const camposDif = useDebounce(JSON.stringify(f.campos), 400)
  const valoresDif = useDebounce(JSON.stringify(valores), 400)

  useEffect(() => {
    if (!expresionDif.trim()) {
      setPrueba(null)

      return
    }

    let vivo = true

    probarFormula({
      expresion: expresionDif,
      campos: f.campos,
      valores: Object.fromEntries(
        Object.entries(valores).map(([k, v]) => [k, Number(v) || 0]),
      ),
    })
      .then((r) => vivo && setPrueba(r))
      .catch(() => vivo && setPrueba(null))

    return () => {
      vivo = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [expresionDif, camposDif, valoresDif])

  const claves = useMemo(() => f.campos.map((c) => c.clave), [f.campos])
  const libres = medidasPosibles.filter((m) => !claves.includes(m.clave))

  function agregarCampo(clave: string) {
    const m = medidasPosibles.find((x) => x.clave === clave)

    if (m) set('campos', [...f.campos, { clave: m.clave, label: m.label }])
  }

  function moverCampo(i: number, hacia: -1 | 1) {
    const j = i + hacia

    if (j < 0 || j >= f.campos.length) return

    const campos = [...f.campos]
    ;[campos[i], campos[j]] = [campos[j], campos[i]]
    set('campos', campos)
  }

  async function borrar() {
    if (!f.id) return

    setError(null)

    try {
      const r = await borrarForma(f.id)

      // Si tenia historial no se borro: quedo desactivada, y el mensaje del
      // servidor explica por que.
      onGuardado(r.borrada ? `${f.nombre} borrada` : r.mensaje)
    } catch (e) {
      setError(mensajeDeError(e))
    }
  }

  async function guardar() {
    setGuardando(true)
    setError(null)

    try {
      await guardarForma(
        {
          nombre: f.nombre,
          // Vacía: la arma el servidor a partir del nombre.
          clave: f.clave.trim() || null,
          orden: f.orden.trim() ? Number(f.orden) : null,
          campos: f.campos,
          expresion: f.expresion.trim() || null,
          usa_cano: f.usa_cano,
          activo: f.activo,
        },
        f.id,
      )
      onGuardado(f.nombre)
    } catch (e) {
      setError(mensajeDeError(e))
    } finally {
      setGuardando(false)
    }
  }

  const sinDeclarar = prueba?.sin_declarar ?? []
  const puedeGuardar =
    f.nombre.trim() !== '' && !guardando && (f.expresion.trim() === '' || prueba?.ok === true)

  return (
    <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/25 p-6">
      <Card className="w-full max-w-[820px]">
        <CardHeader
          titulo={f.id ? `Editar ${borrador.nombre}` : 'Nueva forma'}
          acciones={
            <button type="button" onClick={onCerrar} className="text-faint hover:text-ink">
              <X size={17} strokeWidth={2.2} />
            </button>
          }
        />

        <div className="flex flex-col gap-4 px-[22px] pb-[22px]">
          {error && <Aviso tono="ambar">{error}</Aviso>}

          {/* el impacto de tocarla */}
          {(f.lineas_cotizadas > 0 || f.materiales_que_la_usan > 0) && (
            <div className="flex items-start gap-2.5 rounded-[9px] border border-[#f3d9a6] bg-[#fff8ee] px-3.5 py-3">
              <AlertTriangle size={15} strokeWidth={2.2} className="mt-0.5 shrink-0 text-warning" />
              <p className="text-[12px] leading-relaxed text-[#7a5a1e]">
                Esta forma la usan <strong>{f.lineas_cotizadas} líneas ya cotizadas</strong>
                {f.materiales_que_la_usan > 0 && (
                  <>
                    {' '}
                    y <strong>{f.materiales_que_la_usan} materiales</strong> la tienen como forma
                    habitual
                  </>
                )}
                . Esas cotizaciones <strong>no se van a recalcular</strong>: guardaron su propia
                cuenta. El cambio vale para lo que se cotice de ahora en adelante.
              </p>
            </div>
          )}

          <div className="grid gap-3.5 sm:grid-cols-[1.4fr_1fr_80px]">
            <Texto
              etiqueta="Nombre"
              value={f.nombre}
              onChange={(e) => set('nombre', e.target.value.toUpperCase())}
              placeholder="BARRA REDONDA"
            />
            {/* Con historial la clave esta congelada: es lo que identifica a
                la forma, y cambiarla dejaria las cotizaciones viejas apuntando
                a algo que ya no existe. Renombrarla es una migracion. */}
            <Texto
              etiqueta="Clave"
              ayuda={
                !f.id ? 'se arma sola' : f.claveEditable ? 'todavía se puede cambiar' : 'congelada'
              }
              value={f.clave}
              disabled={Boolean(f.id) && !f.claveEditable}
              onChange={(e) => set('clave', e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_'))}
              placeholder={f.nombre ? sugerirClave(f.nombre) : 'barra_redonda'}
              spellCheck={false}
            />
            <Texto
              etiqueta="Orden"
              value={f.orden}
              onChange={(e) => set('orden', e.target.value.replace(/[^0-9]/g, ''))}
              placeholder="—"
            />
          </div>

          <div className="flex flex-wrap items-center gap-4">
            <Casilla marcada={f.usa_cano} onChange={(v) => set('usa_cano', v)}>
              Usa caño estandar
            </Casilla>
            <Casilla marcada={f.activo} onChange={(v) => set('activo', v)}>
              Activa
            </Casilla>
            {!f.activo && (
              <span className="text-[11px] text-warning-ink">
                Desactivada no aparece al cotizar. Lo ya cotizado no cambia.
              </span>
            )}
          </div>

          {/* medidas */}
          <div>
            <Etiqueta ayuda="son los nombres que usa la formula">Medidas que pide</Etiqueta>

            <div className="mt-1 flex flex-col gap-1.5">
              {f.campos.map((c, i) => (
                <div
                  key={c.clave}
                  className="flex items-center gap-2 rounded-[7px] border border-line bg-app/50 px-2.5 py-1.5"
                >
                  {/* El orden de los campos es el orden en que se van a pedir
                      al cotizar: primero el diametro, despues el largo. */}
                  <div className="flex shrink-0 flex-col">
                    <button
                      type="button"
                      onClick={() => moverCampo(i, -1)}
                      disabled={i === 0}
                      className="rounded px-1 text-faint hover:text-ink disabled:opacity-25"
                    >
                      <ArrowUp size={10} strokeWidth={2.6} />
                    </button>
                    <button
                      type="button"
                      onClick={() => moverCampo(i, 1)}
                      disabled={i === f.campos.length - 1}
                      className="rounded px-1 text-faint hover:text-ink disabled:opacity-25"
                    >
                      <ArrowDown size={10} strokeWidth={2.6} />
                    </button>
                  </div>
                  <code className="w-[110px] shrink-0 text-[11px] font-semibold text-brand-600">
                    {c.clave}
                  </code>
                  <input
                    value={c.label}
                    onChange={(e) => {
                      const campos = [...f.campos]
                      campos[i] = { ...c, label: e.target.value }
                      set('campos', campos)
                    }}
                    className="h-[28px] min-w-0 flex-1 rounded-[6px] border border-line-strong bg-white px-2 text-[12px] outline-none focus:border-brand"
                  />
                  <button
                    type="button"
                    onClick={() =>
                      set(
                        'campos',
                        f.campos.filter((x) => x.clave !== c.clave),
                      )
                    }
                    className="rounded p-1 text-faint hover:bg-white hover:text-danger"
                  >
                    <Trash2 size={13} strokeWidth={2.2} />
                  </button>
                </div>
              ))}

              {f.campos.length === 0 && (
                <p className="text-[11.5px] text-faint">
                  Todavía no pide ninguna medida. Agregá las que necesite la cuenta.
                </p>
              )}
            </div>

            {libres.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1.5">
                {libres.map((m) => (
                  <button
                    key={m.clave}
                    type="button"
                    onClick={() => agregarCampo(m.clave)}
                    className="rounded-full border border-line-strong bg-white px-2.5 py-1 text-[11px] text-muted transition-colors hover:border-brand hover:text-brand"
                  >
                    <Plus size={10} strokeWidth={2.6} className="mr-1 inline" />
                    {m.label}
                  </button>
                ))}
              </div>
            )}

            {f.usa_cano && (
              <p className="mt-2 text-[11px] leading-relaxed text-brand-600">
                Al usar caño comercial, elegir uno de la lista completa el diámetro exterior y la
                pared. Si el caño no es de medida, se cargan a mano.
              </p>
            )}
          </div>

          {/* Cómo va a quedar del otro lado */}
          <div>
            <Etiqueta ayuda="tal cual lo va a ver quien cotiza">Vista previa</Etiqueta>

            <div className="mt-1 rounded-[10px] border border-line bg-app/40 p-3">
              {f.campos.length === 0 && !f.usa_cano ? (
                <p className="text-[11.5px] text-faint">
                  Sin medidas, al cotizar no aparece ningún campo y el peso se carga a mano.
                </p>
              ) : (
                <>
                  {f.usa_cano && (
                    <div className="mb-2">
                      <span className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-faint">
                        Caño comercial
                      </span>
                      <div className="flex h-[30px] items-center rounded-[6px] border border-line-strong bg-white px-2 text-[11.5px] text-faint">
                        1" · SCH 40 · Ø33.40 pared 3.38
                      </div>
                    </div>
                  )}

                  <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {f.campos.map((c) => (
                      <div key={c.clave}>
                        <span className="mb-1 block truncate text-[10px] font-semibold uppercase tracking-wide text-faint">
                          {c.label || c.clave}
                        </span>
                        <div className="flex">
                          <div className="h-[30px] flex-1 rounded-l-[6px] border border-line-strong bg-white" />
                          <div className="grid h-[30px] w-[34px] place-items-center rounded-r-[6px] border border-l-0 border-line-strong bg-white text-[10px] text-faint">
                            mm
                          </div>
                        </div>
                      </div>
                    ))}
                    <div>
                      <span className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-faint">
                        Piezas
                      </span>
                      <div className="h-[30px] rounded-[6px] border border-line-strong bg-white" />
                    </div>
                  </div>
                </>
              )}
            </div>
          </div>

          {/* la cuenta */}
          <div>
            <Etiqueta ayuda="se puede pegar de un Excel">Cuenta del volumen, en cm³</Etiqueta>
            <textarea
              value={f.expresion}
              onChange={(e) => set('expresion', e.target.value)}
              rows={2}
              spellCheck={false}
              placeholder="(pi * pow(diameter, 2) / 4 * length) / 1000"
              className="mt-1 w-full rounded-[7px] border border-line-strong bg-white px-[11px] py-2 font-mono text-[12px] text-ink outline-none focus:border-brand focus:ring-4 focus:ring-brand-50"
            />
            <p className="mt-1 text-[10.5px] leading-relaxed text-faint">
              Las medidas entran en milímetros. Se puede usar pi, pow, sqrt, abs, max y min. Si lo
              pegás de una planilla —con <code>=</code>, <code>POTENCIA()</code>, <code>;</code> o
              comas decimales— se acomoda solo.
            </p>
          </div>

          {/* la prueba */}
          {f.expresion.trim() !== '' && (
            <div className="rounded-[10px] border border-line bg-app/40 p-3.5">
              <div className="mb-2.5 flex items-center gap-2">
                <FlaskConical size={14} strokeWidth={2.2} className="text-brand" />
                <span className="text-[12px] font-bold uppercase tracking-wide text-muted">
                  Probala antes de guardar
                </span>
                {/* Se prueba sola mientras se escribe; el boton es para
                    volver a correrla despues de cambiar los valores. */}
                <button
                  type="button"
                  onClick={probarAhora}
                  disabled={probando}
                  className="ml-auto flex items-center gap-1 rounded-full border border-line-strong bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-600 transition-colors hover:border-brand hover:bg-brand-50 disabled:opacity-50"
                >
                  <Play size={10} strokeWidth={2.6} />
                  {probando ? 'Probando…' : 'Probar formula'}
                </button>
              </div>

              {prueba && (
                <>
                  {prueba.formula !== f.expresion.trim() && (
                    <p className="mb-2 text-[11px] text-muted">
                      Queda guardada así: <code className="text-brand-600">{prueba.formula}</code>
                    </p>
                  )}

                  {sinDeclarar.length > 0 && (
                    <div className="mb-2.5 rounded-[7px] border border-[#f3d9a6] bg-[#fff8ee] px-3 py-2">
                      <p className="text-[11.5px] leading-relaxed text-[#7a5a1e]">
                        La cuenta usa{' '}
                        <strong>
                          {sinDeclarar.map((v) => (
                            <code key={v}>{v}</code>
                          ))}
                        </strong>{' '}
                        y esa medida no está en la lista de arriba. Tomaría cero y el peso saldría
                        mal sin avisar: agregá la medida o corregí la cuenta.
                      </p>
                    </div>
                  )}

                  {prueba.error && (
                    <p className="mb-2.5 text-[11.5px] text-danger">{prueba.error}</p>
                  )}

                  {prueba.variables.length > 0 && (
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                      {prueba.variables.map((v) => (
                        <label key={v} className="block">
                          <span className="mb-0.5 block truncate text-[10px] font-semibold uppercase tracking-wide text-faint">
                            {v} (mm)
                          </span>
                          <input
                            type="number"
                            step="any"
                            value={valores[v] ?? '1'}
                            onChange={(e) => setValores({ ...valores, [v]: e.target.value })}
                            className="h-[28px] w-full rounded-[6px] border border-line-strong bg-white px-2 text-[12px] tabular-nums outline-none focus:border-brand"
                          />
                        </label>
                      ))}
                    </div>
                  )}

                  <div className="mt-2.5 flex items-center gap-2">
                    {prueba.ok ? (
                      <>
                        <Check size={14} strokeWidth={2.6} className="text-success" />
                        <span className="text-[12.5px] text-ink">
                          Con esos valores da{' '}
                          <strong className="tabular-nums">
                            {prueba.volumen_cm3?.toLocaleString('es-AR', {
                              maximumFractionDigits: 4,
                            })}{' '}
                            cm³
                          </strong>
                        </span>
                      </>
                    ) : (
                      <span className="text-[12px] text-warning-ink">
                        Corregila para poder guardar.
                      </span>
                    )}
                  </div>
                </>
              )}
            </div>
          )}

          <div className="flex items-center gap-2.5">
            <Boton variante="primario" onClick={guardar} disabled={!puedeGuardar}>
              {guardando ? 'Guardando…' : 'Guardar'}
            </Boton>
            <Boton variante="suave" onClick={onCerrar}>
              Cancelar
            </Boton>

            {f.expresion.trim() !== '' && prueba && !prueba.ok && (
              <span className="text-[11.5px] text-faint">
                No se puede guardar una cuenta que no cierra.
              </span>
            )}

            {/* Una forma con historial no se borra: el servidor la desactiva
                y lo dice. Esa decisión no la toma esta pantalla. */}
            {f.id && (
              <button
                type="button"
                onClick={borrar}
                className="ml-auto flex items-center gap-1.5 text-[11.5px] font-semibold text-faint hover:text-danger"
              >
                <Trash2 size={12} strokeWidth={2.2} />
                {f.lineas_cotizadas > 0 || f.materiales_que_la_usan > 0
                  ? 'Desactivar'
                  : 'Borrar forma'}
              </button>
            )}
          </div>
        </div>
      </Card>
    </div>
  )
}

import { useEffect, useState } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { Accion, Aviso, Chip } from '../../components/ui'
import { AreaTexto, Casilla, Etiqueta, Lista, Modal, Texto } from '../../components/ui/form'
import {
  guardarCampo,
  guardarContacto,
  guardarRazonSocial,
  mensajeDeError,
  useCatalogos,
} from '../../lib/indice'
import type { CampoEmpresa, Contacto, RazonSocial } from '../../types/indice'

/* ---------------------------------------------------------------------------
   Las ventanas para cargar y modificar lo que cuelga de la ficha.
--------------------------------------------------------------------------- */

interface MedioEditable {
  tipo_medio_id: number
  valor: string
  principal: boolean
  nota: string
}

/** Un contacto con todos sus teléfonos, WhatsApp y mails. */
export function ModalContacto({
  abierto,
  empresaId,
  contacto,
  onCerrar,
  onGuardado,
}: {
  abierto: boolean
  empresaId: number
  contacto?: Contacto | null
  onCerrar: () => void
  onGuardado: (mensaje: string) => void
}) {
  const catalogos = useCatalogos()
  const tipos = catalogos?.tipos_medio ?? []
  const idPorNombre = (n: string) => tipos.find((t) => t.nombre === n)?.id ?? 0

  const [nombre, setNombre] = useState('')
  const [sector, setSector] = useState('')
  const [principal, setPrincipal] = useState(false)
  const [observacion, setObservacion] = useState('')
  const [medios, setMedios] = useState<MedioEditable[]>([])
  const [guardando, setGuardando] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!abierto) return

    setNombre(contacto?.nombre ?? '')
    setSector(contacto?.sector ?? '')
    setPrincipal(contacto?.principal ?? false)
    setObservacion(contacto?.observacion ?? '')
    setError(null)

    if (contacto) {
      const armar = (lista: { valor: string; principal: boolean; nota: string | null }[], tipo: string) =>
        lista.map((m) => ({
          tipo_medio_id: idPorNombre(tipo),
          valor: m.valor,
          principal: m.principal,
          nota: m.nota ?? '',
        }))

      setMedios([
        ...armar(contacto.telefonos, 'Telefono'),
        ...armar(contacto.whatsapps, 'WhatsApp'),
        ...armar(contacto.mails, 'Mail'),
      ])
    } else {
      setMedios([{ tipo_medio_id: idPorNombre('Telefono'), valor: '', principal: true, nota: '' }])
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [abierto, contacto, tipos.length])

  function cambiarMedio(i: number, cambios: Partial<MedioEditable>) {
    setMedios((prev) => prev.map((m, k) => (k === i ? { ...m, ...cambios } : m)))
  }

  async function guardar() {
    if (!nombre.trim()) {
      setError('Falta el nombre de la persona.')

      return
    }

    setGuardando(true)
    setError(null)

    try {
      await guardarContacto(
        empresaId,
        {
          nombre,
          sector: sector || null,
          principal,
          observacion: observacion || null,
          medios: medios
            .filter((m) => m.valor.trim() !== '')
            .map((m) => ({
              tipo_medio_id: m.tipo_medio_id,
              valor: m.valor.trim(),
              principal: m.principal,
              nota: m.nota || null,
            })),
        },
        contacto?.id,
      )

      onGuardado(contacto ? 'Contacto modificado.' : 'Contacto agregado.')
      onCerrar()
    } catch (err) {
      setError(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  return (
    <Modal
      abierto={abierto}
      titulo={contacto ? `Modificar a ${contacto.nombre}` : 'Agregar contacto'}
      bajada="Cada persona puede tener varios teléfonos, WhatsApp y mails."
      onCerrar={onCerrar}
      onGuardar={guardar}
      guardando={guardando}
    >
      <div className="flex flex-col gap-4">
        {error && <Aviso tono="ambar">{error}</Aviso>}

        <div className="grid gap-3 sm:grid-cols-2">
          <Texto
            etiqueta="Nombre"
            obligatorio
            value={nombre}
            onChange={(e) => setNombre(e.target.value)}
            placeholder="Nombre y apellido"
          />
          <Texto
            etiqueta="Sector"
            value={sector}
            onChange={(e) => setSector(e.target.value)}
            placeholder="Compras, Administracion, Dueño…"
          />
        </div>

        <Casilla marcada={principal} onChange={setPrincipal}>
          Es el contacto principal
        </Casilla>
        <p className="-mt-2 text-[11px] text-faint">
          El principal es el que aparece por defecto al imprimir. Sólo uno por empresa.
        </p>

        <div className="flex flex-col gap-2.5">
          <div className="flex items-center gap-2.5">
            <Etiqueta>Telefonos, WhatsApp y mails</Etiqueta>
            <div className="ml-auto">
              <Accion
                onClick={() =>
                  setMedios((m) => [
                    ...m,
                    { tipo_medio_id: idPorNombre('Telefono'), valor: '', principal: false, nota: '' },
                  ])
                }
              >
                <span className="inline-flex items-center gap-1">
                  <Plus size={12} strokeWidth={2.6} />
                  Agregar otro
                </span>
              </Accion>
            </div>
          </div>

          {medios.map((m, i) => (
            <div key={i} className="flex flex-wrap items-end gap-2.5 rounded-lg border border-line bg-app p-2.5">
              <Lista
                aria-label="Tipo"
                className="w-[130px]"
                value={m.tipo_medio_id}
                vacio="Tipo"
                onChange={(e) => cambiarMedio(i, { tipo_medio_id: Number(e.target.value) })}
                opciones={tipos.map((t) => ({ valor: t.id, texto: t.nombre }))}
              />
              <Texto
                aria-label="Valor"
                className="min-w-[200px] flex-1"
                value={m.valor}
                onChange={(e) => cambiarMedio(i, { valor: e.target.value })}
                placeholder="Numero o direccion de mail"
              />
              <Texto
                aria-label="Nota"
                className="w-[150px]"
                value={m.nota}
                onChange={(e) => cambiarMedio(i, { nota: e.target.value })}
                placeholder="Linea directa…"
              />
              <Casilla
                marcada={m.principal}
                onChange={(v) => cambiarMedio(i, { principal: v })}
              >
                principal
              </Casilla>
              <button
                type="button"
                aria-label="Quitar"
                onClick={() => setMedios((prev) => prev.filter((_, k) => k !== i))}
                className="grid h-[36px] w-9 place-items-center rounded-lg text-faint transition-colors hover:bg-white hover:text-danger"
              >
                <Trash2 size={15} strokeWidth={2} />
              </button>
            </div>
          ))}

          <p className="text-[11px] text-faint">
            Marcá como principal el que se usa por defecto de cada tipo. Los medios se pueden ampliar:
            hoy teléfono, WhatsApp y mail; mañana lo que aparezca.
          </p>
        </div>

        <AreaTexto
          etiqueta="Observacion"
          filas={2}
          value={observacion}
          onChange={(e) => setObservacion(e.target.value)}
          placeholder="Atiende de 8 a 13. Los viernes no viene."
        />
      </div>
    </Modal>
  )
}

/* ------------------------------------------------------- razón social */

export function ModalRazonSocial({
  abierto,
  empresaId,
  razon,
  onCerrar,
  onGuardado,
}: {
  abierto: boolean
  empresaId: number
  razon?: RazonSocial | null
  onCerrar: () => void
  onGuardado: (mensaje: string) => void
}) {
  const catalogos = useCatalogos()
  const [v, setV] = useState({
    razon_social: '',
    cuit: '',
    condicion_iva: '',
    iibb_condicion: '',
    iibb_provincia_sede_id: '' as string | number,
    iibb_numero: '',
    inicio_actividades: '',
    direccion_fiscal: '',
    localidad: '',
    habitual: false,
  })
  const [guardando, setGuardando] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!abierto) return
    setError(null)
    setV({
      razon_social: razon?.razon_social ?? '',
      cuit: razon?.cuit ?? '',
      condicion_iva: razon?.condicion_iva ?? '',
      iibb_condicion: razon?.iibb_condicion ?? '',
      iibb_provincia_sede_id: '',
      iibb_numero: razon?.iibb_numero ?? '',
      inicio_actividades: razon?.inicio_actividades ?? '',
      direccion_fiscal: razon?.direccion_fiscal ?? '',
      localidad: razon?.localidad ?? '',
      habitual: razon?.habitual ?? false,
    })
  }, [abierto, razon])

  async function guardar() {
    if (!v.razon_social.trim() || !v.cuit.trim()) {
      setError('La razón social y el CUIT son obligatorios: sin eso no se puede facturar.')

      return
    }

    setGuardando(true)
    setError(null)

    try {
      await guardarRazonSocial(
        empresaId,
        {
          razon_social: v.razon_social,
          cuit: v.cuit,
          condicion_iva: v.condicion_iva || null,
          iibb_condicion: v.iibb_condicion || null,
          iibb_provincia_sede_id: v.iibb_provincia_sede_id ? Number(v.iibb_provincia_sede_id) : null,
          iibb_numero: v.iibb_numero || null,
          inicio_actividades: v.inicio_actividades || null,
          direccion_fiscal: v.direccion_fiscal || null,
          localidad: v.localidad || null,
          habitual: v.habitual,
        },
        razon?.id,
      )

      onGuardado(razon ? 'Razón social modificada.' : 'Razón social agregada.')
      onCerrar()
    } catch (err) {
      setError(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  const set = (k: keyof typeof v, valor: string | boolean | number) => setV({ ...v, [k]: valor })

  return (
    <Modal
      abierto={abierto}
      titulo={razon ? 'Modificar razon social' : 'Agregar razon social'}
      bajada="Un mismo cliente puede facturar a varias, cada una con su CUIT."
      onCerrar={onCerrar}
      onGuardar={guardar}
      guardando={guardando}
    >
      <div className="flex flex-col gap-4">
        {error && <Aviso tono="ambar">{error}</Aviso>}

        <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
          <Texto
            etiqueta="Razon social"
            obligatorio
            value={v.razon_social}
            onChange={(e) => set('razon_social', e.target.value)}
          />
          <Texto
            etiqueta="CUIT"
            obligatorio
            value={v.cuit}
            onChange={(e) => set('cuit', e.target.value)}
            placeholder="30-71028456-3"
          />
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <Lista
            etiqueta="Condicion IVA"
            value={v.condicion_iva}
            onChange={(e) => set('condicion_iva', e.target.value)}
            opciones={(catalogos?.condiciones_iva ?? []).map((c) => ({ valor: c, texto: c }))}
          />
          <Texto
            etiqueta="Inicio de actividades"
            ayuda="la fecha de la constancia de AFIP"
            type="date"
            value={v.inicio_actividades}
            onChange={(e) => set('inicio_actividades', e.target.value)}
          />
        </div>

        <div className="rounded-[9px] border border-line bg-app p-3">
          <Etiqueta>Ingresos Brutos</Etiqueta>
          <div className="grid gap-3 sm:grid-cols-3">
            <Lista
              aria-label="Condicion IIBB"
              value={v.iibb_condicion}
              vacio="Condicion"
              onChange={(e) => set('iibb_condicion', e.target.value)}
              opciones={(catalogos?.iibb_condiciones ?? []).map((c) => ({ valor: c, texto: c }))}
            />
            <Lista
              aria-label="Provincia sede"
              value={v.iibb_provincia_sede_id}
              vacio="Provincia sede"
              onChange={(e) => set('iibb_provincia_sede_id', e.target.value)}
              opciones={(catalogos?.provincias ?? []).map((p) => ({ valor: p.id, texto: p.nombre }))}
            />
            <Texto
              aria-label="Numero de IIBB"
              value={v.iibb_numero}
              onChange={(e) => set('iibb_numero', e.target.value)}
              placeholder="904-283746-2"
            />
          </div>
          <p className="mt-2 text-[11px] text-faint">
            Si no lo tienen a mano se deja vacío: no hace falta para poder guardar.
          </p>
        </div>

        <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
          <Texto
            etiqueta="Direccion fiscal"
            value={v.direccion_fiscal}
            onChange={(e) => set('direccion_fiscal', e.target.value)}
          />
          <Texto
            etiqueta="Localidad"
            value={v.localidad}
            onChange={(e) => set('localidad', e.target.value)}
          />
        </div>

        <Casilla marcada={v.habitual} onChange={(x) => set('habitual', x)}>
          Es la razón social habitual
        </Casilla>
        <p className="-mt-2 text-[11px] text-faint">
          Es la que se propone al cotizar. Sólo una por empresa.
        </p>
      </div>
    </Modal>
  )
}

/* --------------------------------------------------- condición de trabajo */

export function ModalCampo({
  abierto,
  empresaId,
  campo,
  onCerrar,
  onGuardado,
}: {
  abierto: boolean
  empresaId: number
  campo?: CampoEmpresa | null
  onCerrar: () => void
  onGuardado: (mensaje: string) => void
}) {
  const [titulo, setTitulo] = useState('')
  const [valor, setValor] = useState('')
  const [usar, setUsar] = useState(false)
  const [guardando, setGuardando] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!abierto) return
    setTitulo(campo?.titulo ?? '')
    setValor(campo?.valor ?? '')
    setUsar(campo?.usar_al_cotizar ?? false)
    setError(null)
  }, [abierto, campo])

  async function guardar() {
    if (!titulo.trim()) {
      setError('Falta el título del campo.')

      return
    }

    setGuardando(true)
    setError(null)

    try {
      await guardarCampo(empresaId, { titulo, valor: valor || null, usar_al_cotizar: usar }, campo?.id)
      onGuardado(campo ? 'Campo modificado.' : 'Campo agregado.')
      onCerrar()
    } catch (err) {
      setError(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  return (
    <Modal
      abierto={abierto}
      titulo={campo ? 'Modificar condicion' : 'Agregar condicion de trabajo'}
      bajada="Se escribe el título y abajo el dato. Cada empresa arma los campos que necesita."
      onCerrar={onCerrar}
      onGuardar={guardar}
      guardando={guardando}
      ancho="max-w-lg"
    >
      <div className="flex flex-col gap-4">
        {error && <Aviso tono="ambar">{error}</Aviso>}

        <Texto
          etiqueta="Titulo"
          obligatorio
          value={titulo}
          onChange={(e) => setTitulo(e.target.value)}
          placeholder="Tipo de pago, Flete, Como lo retira…"
        />
        <AreaTexto
          etiqueta="Dato"
          filas={2}
          value={valor}
          onChange={(e) => setValor(e.target.value)}
          placeholder="50% anticipo, saldo contra entrega"
        />

        <Casilla marcada={usar} onChange={setUsar}>
          Proponerlo al cotizar
        </Casilla>
        <p className="-mt-2 flex items-center gap-2 text-[11px] text-faint">
          <Chip tono="brand">se usa al cotizar</Chip>
          Se completa solo al armarle una cotización, y al copiarle una de otra empresa.
        </p>
      </div>
    </Modal>
  )
}

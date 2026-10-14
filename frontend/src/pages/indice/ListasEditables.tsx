import { useState } from 'react'
import { ArrowDown, ArrowUp, Check, Eye, EyeOff, Pencil, Plus, Trash2, X } from 'lucide-react'
import { Aviso, Boton, Card, CardHeader, Cargando, PageHeader } from '../../components/ui'
import { mensajeDeError, useCarga } from '../../lib/indice'
import {
  agregarALista,
  borrarItem,
  cambiarItem,
  ordenarLista,
  traerListas,
  type ItemDeLista,
  type NombreDeLista,
} from '../../lib/configuracion'

/*
  Configuracion > Listas (lista de ajustes del cliente, 14-10-2026): lo que se
  propone al cotizar, editable. Hasta ahora estas listas crecian solas con lo
  que se escribia en una linea, pero no habia donde corregir una mal escrita
  ni sacar una que ya no se usa.
*/
const LISTAS: { clave: NombreDeLista; titulo: string; ayuda: string }[] = [
  {
    clave: 'caracteristicas',
    titulo: 'Características',
    ayuda: 'Con o sin costura, laminada, la norma. Se proponen al cotizar y salen impresas.',
  },
  {
    clave: 'motivos_no_cotiza',
    titulo: 'Motivos de "no se cotiza"',
    ayuda: 'Salen impresos debajo del ítem que no se cotiza.',
  },
  {
    clave: 'motivos_cambio',
    titulo: 'Motivos de cambio',
    ayuda: 'Por qué se cotiza distinto a lo que pidió el cliente. Salen impresos.',
  },
]

export default function ListasEditables() {
  const { datos, cargando, recargar } = useCarga(traerListas, [])
  const [error, setError] = useState<string | null>(null)

  if (cargando && !datos) return <Cargando texto="Abriendo las listas…" />
  if (!datos) return null

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb="Configuracion  ›  Listas"
        titulo="Listas"
        bajada="Lo que se propone al cotizar. Corregir, apagar o borrar un ítem no cambia ninguna cotización hecha: cada línea guarda su propio texto."
      />

      {error && <Aviso tono="ambar">{error}</Aviso>}

      <div className="grid items-start gap-[18px] xl:grid-cols-3">
        {LISTAS.map((l) => (
          <ListaEditable key={l.clave} {...l} items={datos[l.clave]} onCambio={recargar} onError={setError} />
        ))}
      </div>
    </div>
  )
}

const CAMPO =
  'h-[32px] min-w-0 flex-1 rounded-[7px] border border-line-strong bg-white px-2.5 text-[12.5px] outline-none focus:border-brand'

function ListaEditable({
  clave,
  titulo,
  ayuda,
  items,
  onCambio,
  onError,
}: {
  clave: NombreDeLista
  titulo: string
  ayuda: string
  items: ItemDeLista[]
  onCambio: () => void
  onError: (e: string | null) => void
}) {
  const [nuevo, setNuevo] = useState('')
  const [editando, setEditando] = useState<{ id: number; nombre: string } | null>(null)

  async function hacer(accion: () => Promise<unknown>) {
    onError(null)
    try {
      await accion()
      onCambio()
    } catch (e) {
      onError(mensajeDeError(e))
    }
  }

  function mover(i: number, paso: number) {
    const ids = items.map((x) => x.id)
    const j = i + paso
    if (j < 0 || j >= ids.length) return
    ;[ids[i], ids[j]] = [ids[j], ids[i]]
    hacer(() => ordenarLista(clave, ids))
  }

  return (
    <Card className="overflow-hidden">
      <CardHeader titulo={titulo} cuenta={items.length} ayuda={ayuda} />

      <form
        className="flex gap-2 px-[22px] pb-3"
        onSubmit={(e) => {
          e.preventDefault()
          if (nuevo.trim()) hacer(async () => {
            await agregarALista(clave, nuevo)
            setNuevo('')
          })
        }}
      >
        <input
          aria-label={`Agregar a ${titulo}`}
          value={nuevo}
          onChange={(e) => setNuevo(e.target.value)}
          placeholder="Escribí uno nuevo"
          className={CAMPO}
        />
        <Boton type="submit" variante="primario" className="!h-[32px]" disabled={!nuevo.trim()}>
          <Plus size={14} strokeWidth={2.4} /> Agregar
        </Boton>
      </form>

      <ul className="divide-y divide-line border-t border-line">
        {items.map((item, i) => (
          <li key={item.id} className="flex items-center gap-1.5 px-[22px] py-1.5">
            {editando?.id === item.id ? (
              <>
                <input
                  aria-label={`Corregir ${item.nombre}`}
                  autoFocus
                  value={editando.nombre}
                  onChange={(e) => setEditando({ ...editando, nombre: e.target.value })}
                  onKeyDown={(e) => {
                    if (e.key === 'Escape') setEditando(null)
                    if (e.key === 'Enter' && editando.nombre.trim()) {
                      hacer(async () => {
                        await cambiarItem(clave, item.id, { nombre: editando.nombre })
                        setEditando(null)
                      })
                    }
                  }}
                  className={CAMPO}
                />
                <Icono titulo="Guardar" onClick={() => editando.nombre.trim() && hacer(async () => {
                  await cambiarItem(clave, item.id, { nombre: editando.nombre })
                  setEditando(null)
                })}>
                  <Check size={14} />
                </Icono>
                <Icono titulo="Cancelar" onClick={() => setEditando(null)}>
                  <X size={14} />
                </Icono>
              </>
            ) : (
              <>
                <span className={`min-w-0 flex-1 truncate text-[12.5px] ${item.activo ? 'text-ink' : 'text-faint line-through'}`}>
                  {item.nombre}
                </span>
                <Icono titulo="Subir" onClick={() => mover(i, -1)} disabled={i === 0}>
                  <ArrowUp size={13} />
                </Icono>
                <Icono titulo="Bajar" onClick={() => mover(i, 1)} disabled={i === items.length - 1}>
                  <ArrowDown size={13} />
                </Icono>
                <Icono titulo="Corregir" onClick={() => setEditando({ id: item.id, nombre: item.nombre })}>
                  <Pencil size={13} />
                </Icono>
                <Icono
                  titulo={item.activo ? 'Apagar: deja de proponerse' : 'Prender'}
                  onClick={() => hacer(() => cambiarItem(clave, item.id, { activo: !item.activo }))}
                >
                  {item.activo ? <Eye size={13} /> : <EyeOff size={13} />}
                </Icono>
                <Icono
                  titulo="Borrar"
                  onClick={() => window.confirm(`¿Borrar "${item.nombre}" de la lista?`) && hacer(() => borrarItem(clave, item.id))}
                >
                  <Trash2 size={13} />
                </Icono>
              </>
            )}
          </li>
        ))}
      </ul>
    </Card>
  )
}

function Icono({
  titulo,
  onClick,
  disabled,
  children,
}: {
  titulo: string
  onClick: () => void
  disabled?: boolean
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      title={titulo}
      aria-label={titulo}
      onClick={onClick}
      disabled={disabled}
      className="grid h-7 w-7 shrink-0 place-items-center rounded-md text-muted hover:bg-app hover:text-ink disabled:opacity-30"
    >
      {children}
    </button>
  )
}

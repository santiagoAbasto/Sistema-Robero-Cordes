import { useState } from 'react'
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react'
import { Aviso, Boton, Card, Cargando, PageHeader } from '../../components/ui'
import { AreaTexto, Casilla, Guardado, Texto } from '../../components/ui/form'
import { mensajeDeError, useCarga } from '../../lib/indice'
import {
  borrarCondicion,
  guardarCondicion,
  ordenarCondiciones,
  traerCondiciones,
  type CondicionHabitualAdmin,
} from '../../lib/configuracion'

/*
  Configuracion > Condiciones (lista de ajustes del cliente, 14-10-2026): los
  parrafos de cada juego —Importacion, Stock, Reventa— los escribe la empresa.
  Los de Reventa estaban vacios a proposito, esperando ese texto.

  Una cotizacion copia sus condiciones al armarse: lo que se cambie aca vale
  para las que se armen de ahora en adelante.
*/
const NOMBRE: Record<string, string> = { Importacion: 'Importación', Stock: 'Stock', Reventa: 'Reventa' }

export default function CondicionesHabituales() {
  const { datos, cargando, recargar } = useCarga(traerCondiciones, [])
  const [juego, setJuego] = useState('Importacion')
  const [agregando, setAgregando] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)

  if (cargando && !datos) return <Cargando texto="Abriendo las condiciones…" />
  if (!datos) return null

  const delJuego = datos.condiciones.filter((c) => c.juego === juego)

  async function hacer(accion: () => Promise<unknown>, mensaje?: string) {
    setError(null)
    try {
      await accion()
      recargar()
      if (mensaje) setAviso(mensaje)
    } catch (e) {
      setError(mensajeDeError(e))
    }
  }

  function mover(i: number, paso: number) {
    const ids = delJuego.map((c) => c.id)
    const j = i + paso
    if (j < 0 || j >= ids.length) return
    ;[ids[i], ids[j]] = [ids[j], ids[i]]
    hacer(() => ordenarCondiciones(ids))
  }

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb="Configuracion  ›  Condiciones"
        titulo="Condiciones"
        bajada="Los párrafos de cada juego. Al cotizar se elige el juego y trae los que vienen puestos. Lo que se cambie acá vale para las cotizaciones que se armen de ahora en adelante: las ya hechas guardaron su propio texto."
      />

      <div className="flex flex-wrap gap-2">
        {datos.juegos.map((j) => (
          <Boton
            key={j}
            variante={j === juego ? 'primario' : 'suave'}
            onClick={() => {
              setJuego(j)
              setAgregando(false)
            }}
          >
            {NOMBRE[j] ?? j}
            <span className="rounded-full bg-white/20 px-1.5 text-[11px]">
              {datos.condiciones.filter((c) => c.juego === j).length}
            </span>
          </Boton>
        ))}
      </div>

      {error && <Aviso tono="ambar">{error}</Aviso>}
      {delJuego.length === 0 && !agregando && (
        <Aviso tono="info">
          {NOMBRE[juego] ?? juego} todavía no tiene párrafos. Agregalos acá y quedan listos para la próxima
          cotización que use este juego.
        </Aviso>
      )}

      {delJuego.map((c, i) => (
        <Parrafo
          key={c.id}
          condicion={c}
          primero={i === 0}
          ultimo={i === delJuego.length - 1}
          onMover={(paso) => mover(i, paso)}
          onGuardar={(d) => hacer(() => guardarCondicion(d, c.id), 'Párrafo guardado.')}
          onBorrar={() =>
            window.confirm('¿Borrar este párrafo? Las cotizaciones ya hechas lo conservan.') &&
            hacer(() => borrarCondicion(c.id), 'Párrafo borrado.')
          }
        />
      ))}

      {agregando ? (
        <Parrafo
          condicion={{ id: 0, juego, titulo: '', texto: '', orden: 0, activo: true, por_defecto: true }}
          onGuardar={(d) =>
            hacer(async () => {
              await guardarCondicion(d)
              setAgregando(false)
            }, 'Párrafo agregado.')
          }
          onBorrar={() => setAgregando(false)}
        />
      ) : (
        <div>
          <Boton variante="suave" onClick={() => setAgregando(true)}>
            <Plus size={15} strokeWidth={2.2} /> Agregar párrafo a {NOMBRE[juego] ?? juego}
          </Boton>
        </div>
      )}

      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </div>
  )
}

type Datos = Pick<CondicionHabitualAdmin, 'juego' | 'titulo' | 'texto' | 'activo' | 'por_defecto'>

function Parrafo({
  condicion,
  primero = true,
  ultimo = true,
  onMover,
  onGuardar,
  onBorrar,
}: {
  condicion: CondicionHabitualAdmin
  primero?: boolean
  ultimo?: boolean
  onMover?: (paso: number) => void
  onGuardar: (d: Datos) => void
  onBorrar: () => void
}) {
  const inicial: Datos = {
    juego: condicion.juego,
    titulo: condicion.titulo ?? '',
    texto: condicion.texto,
    activo: condicion.activo,
    por_defecto: condicion.por_defecto,
  }
  const [d, setD] = useState<Datos>(inicial)
  const cambiado = JSON.stringify(d) !== JSON.stringify(inicial)
  const nuevo = condicion.id === 0

  return (
    <Card className={`p-4 ${d.activo ? '' : 'opacity-70'}`}>
      <div className="grid gap-2.5 lg:grid-cols-[220px_1fr]">
        <Texto
          etiqueta="Título"
          ayuda="opcional"
          value={d.titulo ?? ''}
          maxLength={60}
          placeholder="Entrega, Pago, Origen…"
          onChange={(e) => setD({ ...d, titulo: e.target.value })}
        />
        <AreaTexto
          etiqueta="Texto"
          ayuda="sale impreso así"
          filas={3}
          value={d.texto}
          onChange={(e) => setD({ ...d, texto: e.target.value })}
        />
      </div>
      <div className="mt-2.5 flex flex-wrap items-center gap-2">
        <Casilla marcada={d.por_defecto} onChange={(v) => setD({ ...d, por_defecto: v })}>
          Viene puesto al elegir el juego
        </Casilla>
        <Casilla marcada={d.activo} onChange={(v) => setD({ ...d, activo: v })}>
          Activo
        </Casilla>
        <div className="ml-auto flex items-center gap-1.5">
          {onMover && (
            <>
              <Boton variante="suave" className="!h-[34px] !px-2.5" title="Subir" disabled={primero} onClick={() => onMover(-1)}>
                <ArrowUp size={14} />
              </Boton>
              <Boton variante="suave" className="!h-[34px] !px-2.5" title="Bajar" disabled={ultimo} onClick={() => onMover(1)}>
                <ArrowDown size={14} />
              </Boton>
            </>
          )}
          <Boton variante="suave" className="!h-[34px]" onClick={onBorrar}>
            {nuevo ? 'Cancelar' : <><Trash2 size={14} /> Borrar</>}
          </Boton>
          <Boton
            variante="primario"
            className="!h-[34px]"
            disabled={!d.texto.trim() || (!cambiado && !nuevo)}
            onClick={() => onGuardar({ ...d, titulo: d.titulo?.trim() || null })}
          >
            Guardar
          </Boton>
        </div>
      </div>
    </Card>
  )
}

import { useState } from 'react'
import { Check, ShieldCheck } from 'lucide-react'
import {
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
import { Guardado, Lista } from '../../components/ui/form'
import api from '../../lib/api'
import { mensajeDeError, useCarga } from '../../lib/indice'

interface PermisosUsuario {
  ve_fichas: string
  ve_importes: boolean
  ve_notas_de_otros: boolean
  puede_modificar: boolean
  puede_imprimir: boolean
  puede_archivar: boolean
  ve_control_cambios: boolean
}

interface UsuarioPermisos {
  id: number
  nombre: string
  iniciales: string
  rol: string
  es_admin: boolean
  permisos: PermisosUsuario
}

const COLUMNAS: { clave: keyof PermisosUsuario; titulo: string }[] = [
  { clave: 've_importes', titulo: 'Importes' },
  { clave: 've_notas_de_otros', titulo: 'Notas de otros' },
  { clave: 'puede_modificar', titulo: 'Modifica' },
  { clave: 'puede_imprimir', titulo: 'Imprime' },
  { clave: 'puede_archivar', titulo: 'Archiva' },
  { clave: 've_control_cambios', titulo: 'Ve los cambios' },
]

/**
 * Quién ve qué.
 *
 * Se marca con casillas, por persona. Los dueños ven todo siempre, para que
 * nunca quede una ficha que nadie pueda abrir.
 */
export default function QuienVeQue() {
  const [aviso, setAviso] = useState<string | null>(null)
  const [guardando, setGuardando] = useState<number | null>(null)

  const { datos, cargando, error, recargar } = useCarga(
    async () => {
      const { data } = await api.get<{
        usuarios: UsuarioPermisos[]
        reservadas: { id: number; nombre: string; visible_para: string }[]
      }>('/permisos')

      return data
    },
    [],
  )

  async function cambiar(usuario: UsuarioPermisos, cambios: Partial<PermisosUsuario>) {
    if (usuario.es_admin) return

    setGuardando(usuario.id)

    try {
      await api.put(`/permisos/${usuario.id}`, { ...usuario.permisos, ...cambios })
      setAviso(`Permisos de ${usuario.nombre} guardados.`)
      recargar()
    } catch (err) {
      setAviso(mensajeDeError(err))
    } finally {
      setGuardando(null)
    }
  }

  if (cargando) return <Cargando texto="Buscando los usuarios…" />

  if (error || !datos) {
    return <SinResultados titulo="No pudimos traer los permisos" detalle={error ?? ''} />
  }

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb="Configuracion  ›  Usuarios"
        titulo="Quien ve que"
        bajada="Se marca con casillas, por persona. Los dueños ven todo siempre, para que nunca quede una ficha que nadie pueda abrir."
      />

      <Card className="overflow-hidden">
        <CardHeader
          titulo="Permisos por persona"
          cuenta={datos.usuarios.length}
          ayuda="Ver no es modificar: se puede dar acceso de sólo lectura."
        />

        <Tabla>
          <thead>
            <tr>
              <Th>USUARIO</Th>
              <Th ancho="190px">Que fichas ve</Th>
              {COLUMNAS.map((c) => (
                <Th key={c.clave} ancho="110px">
                  {c.titulo}
                </Th>
              ))}
            </tr>
          </thead>
          <tbody>
            {datos.usuarios.map((u) => (
              <tr key={u.id} className={u.es_admin ? 'bg-[#f8fcfe]' : undefined}>
                <Td>
                  <div className="flex flex-col gap-0.5">
                    <span className="inline-flex items-center gap-2 font-semibold text-ink">
                      {u.nombre}
                      {u.es_admin && (
                        <Chip tono="brand">
                          <ShieldCheck size={10} strokeWidth={2.6} className="mr-1" />
                          ve todo
                        </Chip>
                      )}
                    </span>
                    <span className="text-[10.5px] text-faint">
                      {u.iniciales} · {u.rol}
                    </span>
                  </div>
                </Td>
                <Td>
                  {u.es_admin ? (
                    <span className="text-[12px] text-muted">Todas</span>
                  ) : (
                    <Lista
                      aria-label={`Que fichas ve ${u.nombre}`}
                      value={u.permisos.ve_fichas}
                      vacio=""
                      onChange={(e) => cambiar(u, { ve_fichas: e.target.value })}
                      disabled={guardando === u.id}
                      opciones={[
                        { valor: 'Todas', texto: 'Todas' },
                        { valor: 'Solo las suyas', texto: 'Solo las suyas' },
                        { valor: 'Solo las de un grupo', texto: 'Solo las de un grupo' },
                      ]}
                    />
                  )}
                </Td>
                {COLUMNAS.map((c) => (
                  <Td key={c.clave}>
                    <Tilde
                      marcada={u.es_admin ? true : Boolean(u.permisos[c.clave])}
                      bloqueada={u.es_admin || guardando === u.id}
                      onClick={() => cambiar(u, { [c.clave]: !u.permisos[c.clave] } as Partial<PermisosUsuario>)}
                      etiqueta={`${c.titulo} · ${u.nombre}`}
                    />
                  </Td>
                ))}
              </tr>
            ))}
          </tbody>
        </Tabla>

        <NotaPie>
          Los administradores tienen todo marcado y no se puede destildar. Todo cambio queda igual en
          el historial, sin importar los permisos.
        </NotaPie>
      </Card>

      <div className="grid gap-[18px] lg:grid-cols-[1fr_420px]">
        <Card className="overflow-hidden">
          <CardHeader
            titulo="Fichas reservadas"
            cuenta={datos.reservadas.length}
            ayuda="Para las pocas empresas que no todos deben ver. Se marca de a una, sin tocar los permisos de nadie."
          />

          {datos.reservadas.length === 0 ? (
            <div className="border-t border-[#eef2f6] px-[22px] py-8 text-center text-[12.5px] text-muted">
              No hay ninguna ficha reservada: todas las empresas las ve todo el mundo.
            </div>
          ) : (
            <ul className="border-t border-[#eef2f6]">
              {datos.reservadas.map((e) => (
                <li
                  key={e.id}
                  className="flex items-center gap-3 border-b border-[#eef2f6] px-[22px] py-3 last:border-b-0"
                >
                  <span className="min-w-0 flex-1 text-[12.5px] font-semibold text-ink">
                    {e.nombre}
                  </span>
                  <Chip tono="violeta">{e.visible_para}</Chip>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card className="flex flex-col gap-3 border-brand-200 bg-[#f3f9fe] p-[22px]">
          <h2 className="text-[15px] font-semibold text-brand-600">Como se combinan las dos</h2>

          {[
            ['Por persona', 'Los permisos de arriba: qué ve y qué puede hacer cada uno.'],
            ['Por ficha', 'Las empresas reservadas de la lista de al lado.'],
            ['Cual manda', 'Si cualquiera de las dos dice que no, no se ve.'],
            ['Los dueños', 'Ven todo siempre, en las dos.'],
          ].map(([titulo, detalle]) => (
            <div key={titulo} className="rounded-lg border border-brand-200 bg-white px-3 py-2.5">
              <p className="text-[10.5px] font-bold text-brand-600">{titulo}</p>
              <p className="text-[11.5px] leading-relaxed text-slate-700">{detalle}</p>
            </div>
          ))}
        </Card>
      </div>

      <Card className="flex flex-col gap-2.5 border-[#f3d9a6] bg-[#fffdf7] p-[22px]">
        <h3 className="text-[14px] font-semibold text-warning-ink">
          Lo que ningun permiso permite
        </h3>
        {[
          'Borrar una empresa, un contacto o una cotizacion. Todo se archiva, nunca se borra.',
          'Editar o borrar el historial de cambios.',
          'Quitarle permisos a un administrador.',
        ].map((t) => (
          <p key={t} className="flex items-start gap-2 text-[12px] leading-relaxed text-[#7a5a1e]">
            <span className="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-warning-ink" />
            {t}
          </p>
        ))}
      </Card>

      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </div>
  )
}

function Tilde({
  marcada,
  bloqueada,
  onClick,
  etiqueta,
}: {
  marcada: boolean
  bloqueada?: boolean
  onClick: () => void
  etiqueta: string
}) {
  return (
    <button
      type="button"
      aria-label={etiqueta}
      aria-pressed={marcada}
      disabled={bloqueada}
      onClick={onClick}
      className={`grid h-[19px] w-[19px] place-items-center rounded-[5px] border transition-colors ${
        marcada ? 'border-success-ink bg-success-ink text-white' : 'border-slate-300 bg-white'
      } ${bloqueada ? 'cursor-default opacity-70' : 'hover:border-brand'}`}
    >
      {marcada && <Check size={11} strokeWidth={3.4} />}
    </button>
  )
}

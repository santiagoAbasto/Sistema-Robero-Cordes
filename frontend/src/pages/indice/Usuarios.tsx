import { useEffect, useState } from 'react'
import { Plus, ShieldCheck, UserMinus, UserPlus } from 'lucide-react'
import { Accion, Aviso, Card, CardHeader, Chip, NotaPie, Tabla, Td, Th } from '../../components/ui'
import { Guardado, Lista, Modal, Texto } from '../../components/ui/form'
import api from '../../lib/api'
import { mensajeDeError, useCarga } from '../../lib/indice'

/* ---------------------------------------------------------------------------
   Alta, baja y modificación de usuarios.

   Antes esta pantalla sólo dejaba tocar los permisos de los que ya estaban:
   entraba un vendedor nuevo y hasta que alguien lo cargaba a mano en la base,
   cotizaba con la clave de otro y el historial quedaba a nombre equivocado.

   NADIE SE BORRA, se da de baja. Un usuario es el autor de sus cotizaciones y
   de cada renglón del historial: borrarlo dejaría ocho mil cotizaciones sin
   saber quién las hizo. De baja no entra más y desaparece de las listas, pero
   su firma sigue donde estaba — y se puede volver a activar.
--------------------------------------------------------------------------- */

interface Usuario {
  id: number
  nombre: string
  iniciales: string | null
  email: string
  rol: string
  activo: boolean
  cotizaciones: number
}

export default function Usuarios() {
  const [aviso, setAviso] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editando, setEditando] = useState<Usuario | null | undefined>(undefined)

  const { datos, cargando, recargar } = useCarga(async () => {
    const { data } = await api.get<{ usuarios: Usuario[]; roles: string[] }>('/usuarios')

    return data
  }, [])

  async function cambiarEstado(u: Usuario) {
    setError(null)

    try {
      const { data } = await api.post<{ mensaje: string }>(
        `/usuarios/${u.id}/${u.activo ? 'baja' : 'alta'}`,
      )

      setAviso(data.mensaje)
      recargar()
    } catch (err) {
      setError(mensajeDeError(err))
    }
  }

  if (cargando || !datos) return null

  return (
    <>
      <Card className="overflow-hidden">
        <CardHeader
          titulo="Usuarios"
          cuenta={datos.usuarios.length}
          ayuda="Quién entra al sistema. Dar de baja no borra: el trabajo que firmó sigue estando."
          acciones={
            <Accion onClick={() => setEditando(null)}>
              <Plus size={13} strokeWidth={2.6} /> Agregar usuario
            </Accion>
          }
        />

        {error && (
          <div className="px-[22px] pt-3">
            <Aviso tono="ambar">{error}</Aviso>
          </div>
        )}

        <Tabla>
          <thead>
            <tr>
              <Th>USUARIO</Th>
              <Th ancho="200px">Correo</Th>
              <Th ancho="130px">Rol</Th>
              <Th ancho="110px">Cotizaciones</Th>
              <Th ancho="170px" />
            </tr>
          </thead>
          <tbody>
            {datos.usuarios.map((u) => (
              <tr key={u.id} className={u.activo ? undefined : 'bg-[#fcfaf6] opacity-70'}>
                <Td>
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold text-ink">{u.nombre}</span>
                    {u.iniciales && <Chip tono="neutro">{u.iniciales}</Chip>}
                    {u.rol === 'Administrador' && (
                      <Chip tono="brand">
                        <ShieldCheck size={10} strokeWidth={2.6} className="mr-1" />
                        administra
                      </Chip>
                    )}
                    {!u.activo && <Chip tono="neutro">de baja</Chip>}
                  </div>
                </Td>
                <Td>
                  <span className="text-muted">{u.email}</span>
                </Td>
                <Td>{u.rol}</Td>
                <Td>
                  <span className="text-muted">
                    {u.cotizaciones.toLocaleString('es-AR')}
                  </span>
                </Td>
                <Td>
                  <div className="flex items-center justify-end gap-3">
                    <Accion onClick={() => setEditando(u)}>Modificar</Accion>
                    <Accion onClick={() => cambiarEstado(u)} apagado={u.activo}>
                      {u.activo ? (
                        <>
                          <UserMinus size={13} strokeWidth={2.2} /> Dar de baja
                        </>
                      ) : (
                        <>
                          <UserPlus size={13} strokeWidth={2.2} /> Volver a activar
                        </>
                      )}
                    </Accion>
                  </div>
                </Td>
              </tr>
            ))}
          </tbody>
        </Tabla>

        <NotaPie>
          La cantidad de cotizaciones es lo que perdería de vista un borrado. Por eso la baja
          desactiva y no borra: el usuario deja de entrar, y su firma queda donde estaba.
        </NotaPie>
      </Card>

      <ModalUsuario
        abierto={editando !== undefined}
        usuario={editando ?? null}
        roles={datos.roles}
        onCerrar={() => setEditando(undefined)}
        onGuardado={(m) => {
          setEditando(undefined)
          setAviso(m)
          recargar()
        }}
      />

      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </>
  )
}

function ModalUsuario({
  abierto,
  usuario,
  roles,
  onCerrar,
  onGuardado,
}: {
  abierto: boolean
  usuario: Usuario | null
  roles: string[]
  onCerrar: () => void
  onGuardado: (mensaje: string) => void
}) {
  const [nombre, setNombre] = useState('')
  const [iniciales, setIniciales] = useState('')
  const [email, setEmail] = useState('')
  const [rol, setRol] = useState('Vendedor')
  const [clave, setClave] = useState('')
  const [guardando, setGuardando] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!abierto) return

    setNombre(usuario?.nombre ?? '')
    setIniciales(usuario?.iniciales ?? '')
    setEmail(usuario?.email ?? '')
    setRol(usuario?.rol ?? 'Vendedor')
    // Al modificar arranca vacía: se escribe sólo si la quieren cambiar.
    setClave('')
    setError(null)
  }, [abierto, usuario])

  async function guardar() {
    setGuardando(true)
    setError(null)

    try {
      const cuerpo = {
        nombre,
        iniciales: iniciales || null,
        email,
        rol,
        ...(clave ? { clave } : {}),
      }

      const { data } = usuario
        ? await api.put<{ mensaje: string }>(`/usuarios/${usuario.id}`, cuerpo)
        : await api.post<{ mensaje: string }>('/usuarios', cuerpo)

      onGuardado(data.mensaje)
    } catch (err) {
      setError(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  return (
    <Modal
      abierto={abierto}
      titulo={usuario ? `Modificar a ${usuario.nombre}` : 'Usuario nuevo'}
      bajada={
        usuario
          ? 'La clave se deja vacía si no hay que cambiarla.'
          : 'Entra al sistema con el correo y la clave que le pongas acá.'
      }
      ancho="max-w-xl"
      onCerrar={onCerrar}
      onGuardar={guardar}
      guardando={guardando}
    >
      <div className="flex flex-col gap-3">
        {error && <Aviso tono="ambar">{error}</Aviso>}

        <div className="grid gap-3 sm:grid-cols-[1fr_110px]">
          <Texto
            etiqueta="Nombre y apellido"
            value={nombre}
            onChange={(e) => setNombre(e.target.value)}
            placeholder="Juan Roberti"
          />
          <Texto
            etiqueta="Iniciales"
            ayuda="con las que firma"
            value={iniciales}
            onChange={(e) => setIniciales(e.target.value)}
            placeholder="JR"
          />
        </div>

        <div className="grid gap-3 sm:grid-cols-[1fr_170px]">
          <Texto
            etiqueta="Correo"
            ayuda="es con lo que entra"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="juan@cordes.com"
          />
          <Lista
            etiqueta="Rol"
            value={rol}
            onChange={(e) => setRol(e.target.value)}
            opciones={roles.map((r) => ({ valor: r, texto: r }))}
          />
        </div>

        <Texto
          etiqueta={usuario ? 'Clave nueva' : 'Clave'}
          ayuda="al menos diez caracteres"
          type="password"
          autoComplete="new-password"
          value={clave}
          onChange={(e) => setClave(e.target.value)}
          placeholder={usuario ? 'Dejar vacio para no cambiarla' : ''}
        />

        <p className="text-[11.5px] leading-relaxed text-muted">
          Un <strong>Administrador</strong> entra a todo, incluida esta pantalla. Para los demás,
          lo que ve y lo que puede tocar se ajusta abajo, en los permisos.
        </p>
      </div>
    </Modal>
  )
}

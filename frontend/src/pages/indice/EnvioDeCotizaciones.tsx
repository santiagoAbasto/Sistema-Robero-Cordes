import { useEffect, useRef, useState } from 'react'
import { Aviso, Boton, Card, CardHeader, Cargando, PageHeader } from '../../components/ui'
import { AreaTexto, CampoClave, Casilla, Guardado, Texto } from '../../components/ui/form'
import { completarTexto, mensajeDeError, useCarga } from '../../lib/indice'
import { useAuth } from '../../lib/auth'
import { guardarEnvios, probarCasilla, traerEnvios, type EnviosAGuardar } from '../../lib/configuracion'

/*
  Configuracion > Envio de cotizaciones (pedido del cliente, 29-10-2026):
  "¿donde se modifica el texto base?" y "el mail salio sin la cotizacion
  adjuntada". El texto que acompaña la hoja lo escribe la empresa, y con la
  casilla cargada el correo lo manda el servidor con la hoja adjunta.

  La clave de la casilla no vuelve nunca a la pantalla: se escribe, se guarda
  cifrada y queda la marca de que hay una.
*/
/*
  Lo que el sistema completa solo. Lo usan personas que no tienen por que
  saber que es una "variable": cada uno dice en castellano que es, se agrega
  con un boton y abajo de cada texto se ve como le llega al cliente.
*/
const EJEMPLO_EMPRESA = 'EMPRESA DEL CLIENTE S.A.'
const EJEMPLO_COTIZACION = `Cotización - 2026-0013 R0 - ${EJEMPLO_EMPRESA} - 29-10-2026`

const DATOS = [
  {
    codigo: '{empresa}',
    boton: 'Nombre de la empresa',
    explica: 'el nombre de la empresa a la que se le manda.',
  },
  {
    codigo: '{cotizacion}',
    boton: 'Número de la cotización',
    explica: 'el número de la cotización, con la empresa y la fecha. Es lo mismo que va de asunto en el correo.',
  },
  {
    codigo: '{quien_manda}',
    boton: 'Quién la manda',
    explica: 'el nombre de la persona que la está mandando, como figura en su usuario.',
  },
]

function TextoConDatos({
  etiqueta,
  ayuda,
  filas,
  valor,
  onCambiar,
  puedeCambiar,
  quienManda,
  asunto,
}: {
  etiqueta: string
  ayuda: string
  filas: number
  valor: string
  onCambiar: (v: string) => void
  puedeCambiar: boolean
  quienManda: string
  asunto?: boolean
}) {
  // Donde quedo el cursor: el dato se agrega ahi, no al final.
  const cursor = useRef<number | null>(null)

  function agregar(codigo: string) {
    const en = Math.min(cursor.current ?? valor.length, valor.length)
    // Que no quede pegado a la palabra de al lado.
    const antes = en > 0 && !/\s/.test(valor[en - 1]) ? ' ' : ''
    const despues = en < valor.length && !/[\s.,:;]/.test(valor[en]) ? ' ' : ''
    const agregado = antes + codigo + despues
    onCambiar(valor.slice(0, en) + agregado + valor.slice(en))
    cursor.current = en + agregado.length
  }

  return (
    <div className="flex flex-col gap-2">
      <AreaTexto
        etiqueta={etiqueta}
        ayuda={ayuda}
        filas={filas}
        value={valor}
        onChange={(e) => onCambiar(e.target.value)}
        // Al tocar un boton el texto pierde el foco: ahi se sabe donde estaba el cursor.
        onBlur={(e) => (cursor.current = e.currentTarget.selectionStart)}
        disabled={!puedeCambiar}
      />
      {puedeCambiar && (
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[11.5px] text-muted">Agregar donde está el cursor:</span>
          {DATOS.map((d) => (
            <Boton key={d.codigo} variante="suave" onClick={() => agregar(d.codigo)}>
              + {d.boton}
            </Boton>
          ))}
        </div>
      )}
      <div className="rounded-[9px] border border-line bg-app px-3.5 py-2.5">
        <p className="mb-1 text-[10.5px] font-bold uppercase tracking-wide text-muted">Así le llega al cliente (ejemplo)</p>
        {asunto && (
          <p className="mb-1.5 text-[12.5px] text-ink">
            <span className="font-semibold">Asunto:</span> {EJEMPLO_COTIZACION}
          </p>
        )}
        <p className="whitespace-pre-wrap text-[12.5px] leading-relaxed text-ink">
          {completarTexto(valor, { empresa: EJEMPLO_EMPRESA, cotizacion: EJEMPLO_COTIZACION, quien_manda: quienManda })}
        </p>
      </div>
    </div>
  )
}

export default function EnvioDeCotizaciones() {
  const { datos, cargando } = useCarga(traerEnvios, [])
  const { user } = useAuth()
  const [form, setForm] = useState<EnviosAGuardar | null>(null)
  const [listo, setListo] = useState(false)
  const [tieneClave, setTieneClave] = useState(false)
  const [trabajando, setTrabajando] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)

  useEffect(() => {
    if (!datos) return
    setForm({
      texto_whatsapp: datos.texto_whatsapp,
      texto_correo: datos.texto_correo,
      host: datos.casilla?.host ?? '',
      puerto: datos.casilla?.puerto ?? '587',
      usuario: datos.casilla?.usuario ?? '',
      clave: '',
      remitente: datos.casilla?.remitente ?? '',
      nombre: datos.casilla?.nombre ?? 'Roberto Cordes S.A.',
    })
    setListo(datos.correo_listo)
    setTieneClave(!!datos.casilla?.tiene_clave)
  }, [datos])

  if (cargando && !datos) return <Cargando texto="Abriendo el envío de cotizaciones…" />
  if (!datos || !form) return null

  const esAdmin = datos.es_admin
  const cambiar = (campo: keyof EnviosAGuardar) => (e: { target: { value: string } }) =>
    setForm({ ...form, [campo]: e.target.value })

  async function guardar(extra: Partial<EnviosAGuardar> = {}) {
    const r = await guardarEnvios({ ...form!, ...extra })
    setListo(r.correo_listo)
    setTieneClave(!!r.casilla?.tiene_clave)
    setForm({ ...form!, clave: '' })
  }

  async function hacer(accion: () => Promise<string | void>) {
    setError(null)
    setTrabajando(true)
    try {
      const mensaje = await accion()
      if (mensaje) setAviso(mensaje)
    } catch (e) {
      setError(mensajeDeError(e))
    } finally {
      setTrabajando(false)
    }
  }

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb="Configuración  ›  Envío de cotizaciones"
        titulo="Envío de cotizaciones"
        bajada="El texto que acompaña la hoja cuando se la manda al cliente por WhatsApp o por correo, y la casilla desde la que salen los correos con la hoja adjunta."
      />

      {!esAdmin && (
        <Aviso tono="info">Solo un administrador puede cambiar estos textos y la casilla de correo.</Aviso>
      )}
      {error && <Aviso tono="ambar">{error}</Aviso>}

      <Card className="overflow-hidden">
        <CardHeader titulo="Textos" ayuda="Lo que se le escribe al cliente cuando se le manda la cotización." />
        <div className="flex flex-col gap-5 border-t border-[#eef2f6] p-4">
          <Aviso tono="info" titulo="Lo que el sistema completa solo">
            <p className="mb-1.5">
              Algunas palabras van entre llaves, como {'{empresa}'}. No se mandan así: al mandar la cotización, el
              sistema las cambia por el dato que corresponde.
            </p>
            <ul className="mb-1.5 flex flex-col gap-1">
              {DATOS.map((d) => (
                <li key={d.codigo}>
                  <span className="font-mono font-semibold">{d.codigo}</span> se cambia por {d.explica}
                </li>
              ))}
            </ul>
            <p>
              No hace falta escribirlas: con los botones de abajo de cada texto se agregan solas. Abajo de cada
              texto se ve cómo le llega al cliente.
            </p>
          </Aviso>
          <TextoConDatos
            etiqueta="Por WhatsApp"
            ayuda="La hoja se baja sola a la computadora: WhatsApp no deja adjuntarla desde el sistema."
            filas={3}
            valor={form.texto_whatsapp}
            onCambiar={(v) => setForm({ ...form, texto_whatsapp: v })}
            puedeCambiar={esAdmin}
            quienManda={user?.name ?? ''}
          />
          <TextoConDatos
            etiqueta="Por correo"
            ayuda="Va con la hoja adjunta. El asunto es el número de la cotización."
            filas={8}
            valor={form.texto_correo}
            onCambiar={(v) => setForm({ ...form, texto_correo: v })}
            puedeCambiar={esAdmin}
            quienManda={user?.name ?? ''}
            asunto
          />
        </div>
      </Card>

      {esAdmin && (
        <Card className="overflow-hidden">
          <CardHeader
            titulo="Casilla de correo de la empresa"
            ayuda="Desde acá sale el correo con la hoja adjunta. La respuesta del cliente le llega a quien la mandó, que recibe una copia. Los datos los da quien les maneja el correo: el puerto casi siempre es 587, y «Sale desde» es la dirección que ve el cliente."
          />
          <div className="flex flex-col gap-4 border-t border-[#eef2f6] p-4">
            <Aviso tono={listo ? 'verde' : 'info'}>
              {listo
                ? 'Cargada: el botón Correo manda la hoja adjunta desde esta casilla.'
                : 'Sin cargar: el botón Correo baja la hoja y abre el mail para adjuntarla a mano, como hasta ahora.'}
            </Aviso>
            <div className="grid items-end gap-4 md:grid-cols-[1fr_140px]">
              <Texto
                etiqueta="Servidor de salida (SMTP)"
                placeholder="smtp.ejemplo.com"
                value={form.host}
                onChange={cambiar('host')}
              />
              <Texto
                etiqueta="Puerto"
                inputMode="numeric"
                value={form.puerto}
                onChange={cambiar('puerto')}
              />
            </div>
            <div className="grid items-end gap-4 md:grid-cols-2">
              <Texto etiqueta="Usuario" autoComplete="off" value={form.usuario} onChange={cambiar('usuario')} />
              <CampoClave
                etiqueta="Clave"
                autoComplete="new-password"
                placeholder={tieneClave ? 'Guardada. Dejala vacía para no cambiarla' : ''}
                value={form.clave}
                onChange={cambiar('clave')}
              />
            </div>
            {tieneClave && (
              <div>
                <Casilla marcada={!!form.borrar_clave} onChange={(v) => setForm({ ...form, borrar_clave: v })}>
                  Borrar la clave guardada
                </Casilla>
              </div>
            )}
            <div className="grid items-end gap-4 md:grid-cols-2">
              <Texto
                etiqueta="Sale desde"
                type="email"
                placeholder="ventas@empresa.com"
                value={form.remitente}
                onChange={cambiar('remitente')}
              />
              <Texto etiqueta="Nombre que ve el cliente" value={form.nombre} onChange={cambiar('nombre')} />
            </div>
          </div>
        </Card>
      )}

      {esAdmin && (
        <div className="flex flex-wrap justify-end gap-2.5">
          <Boton
            variante="suave"
            disabled={trabajando}
            onClick={() =>
              hacer(async () => {
                await guardar()
                return probarCasilla()
              })
            }
          >
            Guardar y mandarme un correo de prueba
          </Boton>
          <Boton
            variante="primario"
            disabled={trabajando}
            onClick={() =>
              hacer(async () => {
                await guardar()
                return 'Guardado. Vale para lo que se mande de ahora en adelante.'
              })
            }
          >
            {trabajando ? 'Guardando…' : 'Guardar'}
          </Boton>
        </div>
      )}

      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </div>
  )
}

import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Printer, Download, Mail, MessageCircle } from 'lucide-react'
import {
  Accion,
  Aviso,
  Boton,
  Card,
  CardHeader,
  Cargando,
  Chip,
  NotaPie,
  PageHeader,
  SinResultados,
} from '../../components/ui'
import { Casilla, Guardado, Lista } from '../../components/ui/form'
import api from '../../lib/api'
import {
  buscarEmpresas,
  fecha as fmtFecha,
  plata,
  traerEmpresa,
  useCarga,
  useDebounce,
} from '../../lib/indice'
import type { Empresa } from '../../types/indice'

const VIAS = [
  { via: 'Impresora', icono: Printer, detalle: 'Sale por la impresora de siempre' },
  { via: 'PDF', icono: Download, detalle: 'Se guarda el archivo' },
  { via: 'Correo', icono: Mail, detalle: 'Al mail elegido acá abajo' },
  { via: 'WhatsApp', icono: MessageCircle, detalle: 'Al WhatsApp elegido acá abajo' },
]

/**
 * Imprimir Indice / Cons.
 *
 * Se elige qué cotización se imprime y con qué datos de contacto sale.
 * Lo que se elige acá vale sólo para esta hoja: la ficha no se modifica.
 */
export default function Imprimir() {
  const [params, setParams] = useSearchParams()
  const empresaId = params.get('empresa')
  const consultaParam = params.get('consulta')

  const [busqueda, setBusqueda] = useState('')
  const busquedaDif = useDebounce(busqueda, 300)

  const { datos: listado } = useCarga(
    () => (empresaId ? Promise.resolve(null) : buscarEmpresas({ buscar: busquedaDif })),
    [empresaId, busquedaDif],
  )
  const { datos: empresa, cargando } = useCarga(
    () => (empresaId ? traerEmpresa(empresaId) : Promise.resolve(null)),
    [empresaId],
  )

  if (!empresaId) {
    return (
      <div className="flex flex-col gap-[18px]">
        <PageHeader
          breadcrumb={<Link to="/empresas" className="hover:text-brand-600">Empresas</Link>}
          titulo="Imprimir Indice / Cons."
          bajada="Primero elegí la empresa. Después vas a poder elegir qué cotización se imprime y con qué datos sale."
        />

        <Card className="flex flex-col gap-3 p-[22px]">
          <label htmlFor="q" className="text-[10.5px] font-medium text-slate-500">
            Buscar empresa
          </label>
          <input
            id="q"
            value={busqueda}
            onChange={(e) => setBusqueda(e.target.value)}
            placeholder="Nombre, teléfono, CUIT o material"
            className="h-[38px] rounded-[7px] border border-line-strong px-3 text-[12.5px] outline-none focus:border-brand focus:ring-4 focus:ring-brand-50"
          />
        </Card>

        <Card className="overflow-hidden">
          <ul>
            {(listado?.data ?? []).map((e) => (
              <li
                key={e.id}
                className="flex flex-wrap items-center gap-3 border-b border-[#eef2f6] px-[22px] py-3 last:border-b-0"
              >
                <span className="min-w-0 flex-1 text-[12.5px] font-semibold text-ink">{e.nombre}</span>
                <span className="w-32 text-[11.5px] text-muted">{e.localidad ?? '—'}</span>
                <span className="w-20 text-right text-[11.5px] text-muted">{e.cotizaciones} cotiz.</span>
                <Accion onClick={() => setParams({ empresa: String(e.id) })}>Elegir</Accion>
              </li>
            ))}
          </ul>
        </Card>
      </div>
    )
  }

  if (cargando) return <Cargando texto="Abriendo…" />

  if (!empresa) {
    return <SinResultados titulo="No encontramos la empresa" detalle="Volvé a elegirla." />
  }

  return <ElegirImpresion empresa={empresa} consultaInicial={consultaParam} />
}

function ElegirImpresion({
  empresa,
  consultaInicial,
}: {
  empresa: Empresa
  consultaInicial: string | null
}) {
  const imprimibles = useMemo(
    () => [...empresa.cotizaciones, ...empresa.pedidos],
    [empresa],
  )

  const [consultaId, setConsultaId] = useState<number | null>(
    consultaInicial ? Number(consultaInicial) : (imprimibles[0]?.id ?? null),
  )
  const consulta = imprimibles.find((c) => c.id === consultaId) ?? null

  // Los datos de contacto: arrancan en el principal y se pueden cambiar.
  const principal = empresa.contactos.find((c) => c.principal) ?? empresa.contactos[0]
  const [contactoId, setContactoId] = useState<number | ''>(principal?.id ?? '')
  const [telefono, setTelefono] = useState('')
  const [mail, setMail] = useState('')
  const [nombreEnPdf, setNombreEnPdf] = useState(empresa.nombre)
  const [incluyeImportes, setIncluyeImportes] = useState(true)
  const [incluyeNota, setIncluyeNota] = useState(false)
  const [via, setVia] = useState('Impresora')
  const [aviso, setAviso] = useState<string | null>(null)

  const contacto = empresa.contactos.find((c) => c.id === contactoId)

  // Al cambiar de persona, se proponen sus datos.
  useEffect(() => {
    if (!contacto) return
    setTelefono(contacto.telefonos[0]?.valor ?? contacto.whatsapps[0]?.valor ?? '')
    setMail(contacto.mails[0]?.valor ?? '')
  }, [contactoId]) // eslint-disable-line react-hooks/exhaustive-deps

  const opcionesTelefono = contacto
    ? [...contacto.telefonos, ...contacto.whatsapps].map((m) => ({ valor: m.valor, texto: m.valor }))
    : []
  const opcionesMail = contacto?.mails.map((m) => ({ valor: m.valor, texto: m.valor })) ?? []

  const [generando, setGenerando] = useState(false)

  /**
   * Trae la hoja ya armada del servidor. Se pide con axios para que viaje el
   * token en el encabezado —no en la dirección— y después se abre el archivo.
   */
  async function abrirPdf(descargar: boolean) {
    if (!consulta) return

    setGenerando(true)

    try {
      const { data } = await api.get(`/consultas/${consulta.id}/pdf`, {
        responseType: 'blob',
        params: {
          nombre_en_pdf: nombreEnPdf,
          contacto_id: contactoId || undefined,
          telefono: telefono || undefined,
          mail: mail || undefined,
          incluye_importes: incluyeImportes ? 1 : 0,
          incluye_nota: incluyeNota ? 1 : 0,
          via,
        },
      })

      const url = URL.createObjectURL(new Blob([data], { type: 'application/pdf' }))

      if (descargar) {
        const a = document.createElement('a')
        a.href = url
        a.download = `${consulta.tipo}-${nombreEnPdf}-${consulta.fecha}.pdf`
        a.click()
      } else {
        window.open(url, '_blank')
      }

      // Se libera cuando el navegador ya lo abrió.
      setTimeout(() => URL.revokeObjectURL(url), 60_000)

      setAviso(
        consulta.esta_vencida
          ? 'Ojo: esta cotización ya estaba vencida. Igual quedó registrada.'
          : `Listo. Quedó registrado que se mandó por ${via}.`,
      )
    } catch {
      setAviso('No pudimos generar la hoja. Probá de nuevo.')
    } finally {
      setGenerando(false)
    }
  }

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb={
          <span className="flex items-center gap-1.5">
            <Link to="/empresas" className="hover:text-brand-600">
              Empresas
            </Link>
            <span>›</span>
            <Link to={`/empresas/${empresa.id}`} className="hover:text-brand-600">
              {empresa.nombre}
            </Link>
          </span>
        }
        titulo="Imprimir Indice / Cons."
        bajada="Se elige qué cotización o venta se imprime y con qué datos de contacto sale. Las observaciones no se imprimen."
        acciones={
          <>
            <Link to={`/empresas/${empresa.id}`}>
              <Boton variante="suave">Volver a la ficha</Boton>
            </Link>
            <Boton variante="suave" onClick={() => abrirPdf(true)} disabled={!consulta || generando}>
              <Download size={15} strokeWidth={2} />
              Descargar
            </Boton>
            <Boton variante="primario" onClick={() => abrirPdf(false)} disabled={!consulta || generando}>
              <Printer size={15} strokeWidth={2} />
              {generando ? 'Armando la hoja…' : 'Realizar la Impresion'}
            </Boton>
          </>
        }
      />

      <div className="grid gap-[18px] xl:grid-cols-[1fr_360px]">
        <div className="flex flex-col gap-[18px]">
          {/* 1 · qué se imprime */}
          <Card className="overflow-hidden">
            <CardHeader
              titulo="1 · Que se imprime"
              ayuda="Sólo aparecen cotizaciones, ventas y pedidos. Las observaciones no se imprimen."
            />

            {imprimibles.length === 0 ? (
              <SinResultados
                titulo="Esta empresa no tiene nada para imprimir"
                detalle="Cargale una cotización o un pedido desde su ficha."
              />
            ) : (
              <ul className="border-t border-[#eef2f6]">
                {imprimibles.map((c) => (
                  <li key={c.id}>
                    <button
                      type="button"
                      onClick={() => setConsultaId(c.id)}
                      className={`flex w-full flex-wrap items-center gap-3 border-b border-[#eef2f6] px-[22px] py-3 text-left transition-colors last:border-b-0 ${
                        consultaId === c.id ? 'bg-[#f3f9fe]' : 'hover:bg-app'
                      }`}
                    >
                      <span
                        className={`grid h-4 w-4 shrink-0 place-items-center rounded-full border ${
                          consultaId === c.id ? 'border-brand bg-brand' : 'border-slate-300 bg-white'
                        }`}
                      >
                        {consultaId === c.id && <span className="h-1.5 w-1.5 rounded-full bg-white" />}
                      </span>
                      <span className="w-24 text-[11.5px] font-semibold text-ink">
                        {fmtFecha(c.fecha)}
                      </span>
                      <span className="min-w-0 flex-1 text-[12px] text-slate-700">
                        {c.lineas?.[0]?.descripcion ?? '—'}
                        {(c.lineas?.length ?? 0) > 1 && (
                          <span className="text-faint"> +{(c.lineas?.length ?? 1) - 1} lineas</span>
                        )}
                        {c.tipo === 'Pedido' && <span className="text-faint"> (pedido)</span>}
                      </span>
                      <span className="w-24 text-right text-[12px] font-semibold text-ink">
                        {plata(c.total)}
                      </span>
                      <span className="w-24 text-right">
                        {c.nro_factura ? (
                          <Chip tono="verde">{c.nro_factura}</Chip>
                        ) : c.esta_vencida ? (
                          <Chip tono="ambar">vencida</Chip>
                        ) : null}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          {/* 2 · con qué datos sale */}
          <Card className="flex flex-col gap-3.5 p-[22px]">
            <div className="flex flex-wrap items-center gap-2.5">
              <h2 className="text-[15px] font-semibold text-ink">2 · Con que datos sale</h2>
              <div className="ml-auto">
                <Chip tono="ambar">solo para esta impresion</Chip>
              </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <Lista
                etiqueta="Nombre que va en el PDF"
                vacio={empresa.nombre}
                value={nombreEnPdf === empresa.nombre ? '' : nombreEnPdf}
                onChange={(e) => setNombreEnPdf(e.target.value || empresa.nombre)}
                opciones={empresa.razones_sociales.map((r) => ({
                  valor: r.razon_social,
                  texto: r.razon_social,
                }))}
              />
              <Lista
                etiqueta="Contactar a:"
                vacio="Sin contacto"
                value={contactoId}
                onChange={(e) => setContactoId(e.target.value ? Number(e.target.value) : '')}
                opciones={empresa.contactos.map((c) => ({
                  valor: c.id,
                  texto: c.sector ? `${c.nombre} · ${c.sector}` : c.nombre,
                }))}
              />
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <Lista
                etiqueta="Telefono"
                vacio="Sin telefono"
                value={telefono}
                onChange={(e) => setTelefono(e.target.value)}
                opciones={opcionesTelefono}
              />
              <Lista
                etiqueta="Mail"
                vacio="Sin mail"
                value={mail}
                onChange={(e) => setMail(e.target.value)}
                opciones={opcionesMail}
              />
            </div>

            <Aviso tono="ambar">
              Lo que se elija acá sale únicamente en este PDF. La ficha de la empresa no se modifica:
              {principal ? ` ${principal.nombre} sigue siendo el contacto principal.` : ''}
            </Aviso>
          </Card>
        </div>

        <div className="flex flex-col gap-[18px]">
          {/* 3 · qué sale en la hoja */}
          <Card className="flex flex-col gap-3 p-[22px]">
            <h2 className="text-[15px] font-semibold text-ink">3 · Que sale en la hoja</h2>

            <div className="flex flex-col gap-2.5">
              <Casilla marcada onChange={() => {}} disabled>
                Membrete de Roberto Cordes S.A.
              </Casilla>
              <Casilla marcada={incluyeImportes} onChange={setIncluyeImportes}>
                Importes
              </Casilla>
              <Casilla marcada onChange={() => {}} disabled>
                Condiciones y validez
              </Casilla>
              <Casilla marcada={incluyeNota} onChange={setIncluyeNota}>
                NOTA
              </Casilla>
              <p className="text-[11px] text-faint">
                La NOTA y las observaciones son de uso interno. Normalmente no salen.
              </p>
            </div>
          </Card>

          {/* 4 · a dónde va */}
          <Card className="flex flex-col gap-2.5 p-[22px]">
            <h2 className="text-[15px] font-semibold text-ink">4 · A donde va</h2>

            {VIAS.map((v) => {
              const Icono = v.icono
              const elegida = via === v.via

              return (
                <button
                  key={v.via}
                  type="button"
                  onClick={() => setVia(v.via)}
                  className={`flex items-center gap-3 rounded-lg border px-3 py-2.5 text-left transition-colors ${
                    elegida ? 'border-brand-200 bg-[#f3f9fe]' : 'border-line bg-app hover:bg-white'
                  }`}
                >
                  <Icono size={16} strokeWidth={2} className={elegida ? 'text-brand-600' : 'text-faint'} />
                  <span className="min-w-0 flex-1">
                    <span className={`block text-[12.5px] ${elegida ? 'font-semibold text-ink' : 'text-slate-700'}`}>
                      {v.via}
                    </span>
                    <span className="block text-[10.5px] text-faint">{v.detalle}</span>
                  </span>
                </button>
              )
            })}

            <p className="text-[11px] text-faint">
              El correo y el WhatsApp usan los datos elegidos en el paso 2.
            </p>
          </Card>

          {consulta && (
            <Card className="flex flex-col gap-2 p-[22px]">
              <h3 className="text-[13.5px] font-bold text-ink">Queda anotado</h3>
              <p className="text-[12px] leading-relaxed text-muted">
                En el historial de la empresa queda la fecha, a quién se le mandó y por qué vía.
              </p>
              {(consulta.impresiones?.length ?? 0) > 0 && (
                <p className="text-[11.5px] text-faint">
                  Esta cotización ya se mandó {consulta.impresiones?.length}{' '}
                  {consulta.impresiones?.length === 1 ? 'vez' : 'veces'}.
                </p>
              )}
            </Card>
          )}
        </div>
      </div>

      {consulta && (
        <Card className="overflow-hidden">
          <CardHeader
            titulo="Lo que ya se le mando a esta empresa"
            chips={<Chip tono="verde">se anota solo</Chip>}
            ayuda="Cada vez que se imprime, se manda por correo o por WhatsApp, queda anotado a quién y cuándo."
          />
          {(consulta.impresiones ?? []).length === 0 ? (
            <div className="border-t border-[#eef2f6] px-[22px] py-6 text-center text-[12.5px] text-muted">
              Todavía no se mandó ninguna vez.
            </div>
          ) : (
            <ul className="border-t border-[#eef2f6]">
              {(consulta.impresiones ?? []).map((i) => (
                <li
                  key={i.id}
                  className="flex flex-wrap items-center gap-3 border-b border-[#eef2f6] px-[22px] py-2.5 last:border-b-0"
                >
                  <span className="w-36 text-[11.5px] font-semibold text-ink">{i.fecha}</span>
                  <span className="min-w-0 flex-1 text-[12px] text-slate-700">{i.nombre_en_pdf}</span>
                  <span className="w-40 text-[11.5px] text-muted">{i.contacto ?? '—'}</span>
                  <Chip tono={i.via === 'WhatsApp' ? 'verde' : i.via === 'Correo' ? 'brand' : 'neutro'}>
                    {i.via}
                  </Chip>
                  <span className="w-12 text-right text-[11.5px] text-muted">{i.quien}</span>
                </li>
              ))}
            </ul>
          )}
          <NotaPie>
            Si el cliente dice que no le llegó, acá está la fecha, la persona y la vía.
          </NotaPie>
        </Card>
      )}

      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </div>
  )
}

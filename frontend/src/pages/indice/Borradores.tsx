import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Check } from 'lucide-react'
import {
  Accion,
  Boton,
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
  confirmarConsulta,
  descartarConsulta,
  fecha as fmtFecha,
  mensajeDeError,
  plata,
  traerBorradores,
  traerConsulta,
  useCarga,
} from '../../lib/indice'

/** Los borradores que salieron de copiar una cotización a otras empresas. */
export default function Borradores() {
  const { consultaId } = useParams<{ consultaId: string }>()
  const [aviso, setAviso] = useState<string | null>(null)
  const [aDescartar, setADescartar] = useState<{ id: number; empresa: string } | null>(null)
  const [trabajando, setTrabajando] = useState(false)

  const { datos: original } = useCarga(() => traerConsulta(consultaId!), [consultaId])
  const { datos: borradores, cargando, recargar } = useCarga(
    () => traerBorradores(consultaId!),
    [consultaId],
  )

  async function confirmar(id: number) {
    setTrabajando(true)

    try {
      await confirmarConsulta(id)
      setAviso('Confirmada. Ya figura en el historial de la empresa.')
      recargar()
    } catch (err) {
      setAviso(mensajeDeError(err))
    } finally {
      setTrabajando(false)
    }
  }

  async function descartar() {
    if (!aDescartar) return
    setTrabajando(true)

    try {
      await descartarConsulta(aDescartar.id)
      setAviso('Borrador descartado.')
      setADescartar(null)
      recargar()
    } catch (err) {
      setAviso(mensajeDeError(err))
    } finally {
      setTrabajando(false)
    }
  }

  if (cargando) return <Cargando texto="Buscando los borradores…" />

  const lista = borradores ?? []
  const enBorrador = lista.filter((b) => b.estado === 'Borrador').length
  const confirmados = lista.filter((b) => b.estado !== 'Borrador').length
  const parciales = lista.filter((b) => (b.lineas ?? []).some((l) => l.quitada)).length

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb={
          <span className="flex items-center gap-1.5">
            <Link to="/empresas" className="hover:text-brand-600">
              Empresas
            </Link>
            <span>›</span>
            <span>Borradores</span>
          </span>
        }
        titulo="Borradores de cotizacion"
        chips={<Chip tono="brand">{lista.length}</Chip>}
        bajada={
          original
            ? `Copiados de la cotización a ${original.empresa?.nombre} del ${fmtFecha(original.fecha)}. Cada uno se abre, se ajusta y recién ahí se confirma.`
            : 'Cada uno se abre, se ajusta y recién ahí se confirma.'
        }
        acciones={
          <Link to={`/consultas/${consultaId}`}>
            <Boton variante="suave">Ver la original</Boton>
          </Link>
        }
      />

      <div className="grid gap-[14px] sm:grid-cols-2 xl:grid-cols-4">
        <Kpi valor={lista.length} etiqueta="Borradores creados" />
        <Kpi valor={enBorrador} etiqueta="Sin confirmar" tono="text-brand-600" />
        <Kpi valor={parciales} etiqueta="Parciales" tono="text-warning-ink" />
        <Kpi valor={confirmados} etiqueta="Confirmados" tono="text-success-ink" />
      </div>

      <Card className="overflow-hidden">
        <CardHeader
          titulo={`Los ${lista.length} borradores`}
          ayuda="Se abren de a uno. Ninguno sale hasta confirmarlo."
        />

        {lista.length === 0 ? (
          <SinResultados
            titulo="Todavía no hay borradores"
            detalle="Copiá la cotización a otras empresas y van a aparecer acá."
            accion={
              <Link to={`/consultas/${consultaId}/copiar`}>
                <Boton variante="primario">Copiar a otras empresas</Boton>
              </Link>
            }
          />
        ) : (
          <>
            <Tabla>
              <thead>
                <tr>
                  <Th>EMPRESA</Th>
                  <Th ancho="90px">Lineas</Th>
                  <Th ancho="120px" derecha>Importe</Th>
                  <Th ancho="160px">Condicion de pago</Th>
                  <Th ancho="150px">Estado</Th>
                  <Th ancho="190px" />
                </tr>
              </thead>
              <tbody>
                {lista.map((b) => {
                  const total = (b.lineas ?? []).filter((l) => !l.quitada).length
                  const quitadas = (b.lineas ?? []).filter((l) => l.quitada).length
                  const parcial = quitadas > 0

                  return (
                    <tr key={b.id}>
                      <Td>
                        <div className="flex flex-col gap-0.5">
                          <span className="font-semibold text-ink">{b.empresa?.nombre}</span>
                          <span className="text-[10.5px] text-faint">
                            Contactar a {b.contacto?.nombre ?? '—'}
                          </span>
                        </div>
                      </Td>
                      <Td className={parcial ? 'font-semibold text-warning-ink' : ''}>
                        {total} de {total + quitadas}
                      </Td>
                      <Td derecha className="font-semibold text-ink">{plata(b.total)}</Td>
                      <Td>{b.condicion_pago ?? <span className="text-faint">—</span>}</Td>
                      <Td>
                        <div className="flex flex-col gap-1">
                          <span>
                            <Chip
                              tono={
                                b.estado === 'Borrador' ? 'neutro' : parcial ? 'ambar' : 'verde'
                              }
                            >
                              {b.estado}
                            </Chip>
                          </span>
                          {parcial && (
                            <span className="text-[10px] text-faint">
                              se quitaron {quitadas} {quitadas === 1 ? 'linea' : 'lineas'}
                            </span>
                          )}
                        </div>
                      </Td>
                      <Td>
                        <div className="flex items-center justify-end gap-3">
                          <Accion to={`/consultas/${b.id}`}>Abrir</Accion>
                          {b.estado === 'Borrador' && (
                            <>
                              <button
                                type="button"
                                onClick={() => confirmar(b.id)}
                                disabled={trabajando}
                                className="inline-flex items-center gap-1 text-[11.5px] font-semibold text-success-ink hover:underline"
                              >
                                <Check size={12} strokeWidth={3} />
                                Confirmar
                              </button>
                              <Accion
                                apagado
                                onClick={() =>
                                  setADescartar({ id: b.id, empresa: b.empresa?.nombre ?? '' })
                                }
                              >
                                Quitar
                              </Accion>
                            </>
                          )}
                        </div>
                      </Td>
                    </tr>
                  )
                })}
              </tbody>
            </Tabla>

            <NotaPie>
              Mientras están en borrador no figuran en el historial de la empresa. Al confirmar, cada
              una queda como una cotización normal, con su fecha y su número.
            </NotaPie>
          </>
        )}
      </Card>

      <div className="grid gap-[18px] lg:grid-cols-3">
        <Card className="flex flex-col gap-2 border-[#cdebd8] bg-[#f4fbf6] p-[22px]">
          <h3 className="text-[13.5px] font-bold text-success-ink">Lo que se ahorra</h3>
          <p className="text-[12px] leading-relaxed text-[#245c42]">
            Antes eran cotizaciones armadas de cero, una por una, copiando a mano las mismas líneas.
            Ahora se arma una sola y las otras salen de esa.
          </p>
        </Card>
        <Card className="flex flex-col gap-2 p-[22px]">
          <h3 className="text-[13.5px] font-bold text-ink">Todo sigue siendo modificable</h3>
          <p className="text-[12px] leading-relaxed text-muted">
            El borrador no queda atado a la original. Se cambian medidas, cantidades y precios, se
            quitan líneas y se agregan otras.
          </p>
        </Card>
        <Card className="flex flex-col gap-2 p-[22px]">
          <h3 className="text-[13.5px] font-bold text-ink">Queda de donde salio</h3>
          <p className="text-[12px] leading-relaxed text-muted">
            Cada copia guarda de cuál salió. Si después hay que revisar por qué a uno se le puso otro
            precio, se ve al lado la original.
          </p>
        </Card>
      </div>

      <Confirmar
        abierto={aDescartar !== null}
        titulo="Descartar el borrador"
        detalle={`Se descarta el borrador de ${aDescartar?.empresa}. No afecta a la cotización de la que salió.`}
        textoConfirmar="Descartar"
        onCerrar={() => setADescartar(null)}
        onConfirmar={descartar}
        trabajando={trabajando}
      />

      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </div>
  )
}

function Kpi({ valor, etiqueta, tono }: { valor: number; etiqueta: string; tono?: string }) {
  return (
    <Card className="flex flex-col gap-1 p-[18px]">
      <span className={`text-[24px] font-bold ${tono ?? 'text-ink'}`}>{valor}</span>
      <span className="text-[11.5px] font-medium text-muted">{etiqueta}</span>
    </Card>
  )
}

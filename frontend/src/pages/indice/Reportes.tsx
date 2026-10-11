import { useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Card,
  CardHeader,
  Cargando,
  NotaPie,
  PageHeader,
} from '../../components/ui'
import { Texto } from '../../components/ui/form'
import {
  fechaHora as fmtFechaHora,
  fecha as fmtFecha,
  plata,
  traerReporteSeguimiento,
  useCarga,
  hoy,
} from '../../lib/indice'

/* ---------------------------------------------------------------------------
   El balance del período: qué se perdió y por qué.

   La fecha de generación va arriba de todo, no al pie. Un reporte impreso sin
   fecha no se puede comparar con otro ni discutir en una reunión: nadie sabe
   si mira lo de hoy o lo del mes pasado.
--------------------------------------------------------------------------- */

const HOY = hoy()
const ENERO = `${HOY.slice(0, 4)}-01-01`

export default function Reportes() {
  const [desde, setDesde] = useState(ENERO)
  const [hasta, setHasta] = useState(HOY)

  const { datos, cargando } = useCarga(
    () => traerReporteSeguimiento({ desde, hasta }),
    [desde, hasta],
  )

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        titulo="Reporte de seguimiento"
        bajada="Qué quedó abierto, qué se cerró y por qué, en el período elegido."
      />

      <Card>
        <CardHeader
          titulo="Período"
          acciones={
            <div className="flex flex-wrap items-end gap-3">
              <Texto
                etiqueta="Desde"
                type="date"
                value={desde}
                onChange={(e) => setDesde(e.target.value)}
              />
              <Texto
                etiqueta="Hasta"
                type="date"
                value={hasta}
                onChange={(e) => setHasta(e.target.value)}
              />
            </div>
          }
        />

        {cargando || !datos ? (
          <Cargando texto="Armando el reporte…" />
        ) : (
          <div className="flex flex-col gap-5 px-[22px] pb-5">
            {/* Lo primero que se lee: de cuándo es este papel. */}
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-line pb-3">
              <span className="text-[11px] font-semibold uppercase tracking-wide text-faint">
                Generado el
              </span>
              <span className="text-[13px] font-semibold text-ink">
                {fmtFechaHora(datos.generado_el)}
              </span>
              <span className="text-[12px] text-muted">
                · período {fmtFecha(datos.periodo.desde)} a {fmtFecha(datos.periodo.hasta)}
              </span>
            </div>

            <Bloque titulo="Todavía abiertas">
              <Dato
                n={datos.abiertas.total}
                etiqueta="abiertas en el período"
              />
              <Dato
                n={datos.abiertas.por_vencer}
                etiqueta="vencen esta semana"
                tono={datos.abiertas.por_vencer > 0 ? 'ambar' : undefined}
                a="/cotizaciones/seguimiento"
              />
              <Dato
                n={datos.abiertas.vencidas_sin_cerrar}
                etiqueta="vencidas sin cerrar"
                tono={datos.abiertas.vencidas_sin_cerrar > 0 ? 'rojo' : undefined}
                a="/cotizaciones/seguimiento"
              />
            </Bloque>

            <Bloque titulo="Cómo terminaron">
              <Dato n={datos.vendidas.total} etiqueta="vendidas" tono="verde" />
              {datos.cerradas.por_motivo.map((m) => (
                <Dato
                  key={m.motivo}
                  n={m.cuantas}
                  etiqueta={m.motivo}
                  pie={m.importe > 0 ? plata(m.importe) : undefined}
                />
              ))}
            </Bloque>

            {/*
              El número que duele: no se perdieron por precio, se perdieron
              por no llamar. Va aparte del promedio justamente por eso.
            */}
            <Bloque titulo="Seguimiento de las que se cerraron">
              <Dato
                n={datos.seguimiento.sin_ningun_seguimiento}
                etiqueta="se cerraron sin que nadie las tocara"
                tono={datos.seguimiento.sin_ningun_seguimiento > 0 ? 'rojo' : undefined}
              />
              <Dato
                n={datos.seguimiento.promedio_por_cerrada}
                etiqueta="seguimientos en promedio, por cotización cerrada"
              />
            </Bloque>
          </div>
        )}

        <NotaPie>
          Un seguimiento es una nota del hilo de la cotización o un envío de la hoja al cliente.{' '}
          <Link to="/cotizaciones/seguimiento" className="font-semibold text-brand-600">
            Ver las cotizaciones una por una
          </Link>
          .
        </NotaPie>
      </Card>

      {/*
        Apartado independiente: lo que no cotizamos. No es una cotización que
        el cliente cerró —acá fuimos nosotros los que dijimos que no, renglón
        por renglón, con el motivo. Contarlo por motivo y por material deja ver
        si perdemos pedidos siempre por lo mismo, y decidir sobre eso.
      */}
      {datos && (
        <Card>
          <CardHeader
            titulo="Lo que no cotizamos"
            ayuda="Renglones que decidimos no cotizar en el período, con su motivo. Deja ver si perdemos pedidos siempre por lo mismo."
          />
          {datos.declinados.total_lineas === 0 ? (
            <div className="px-[22px] pb-5 text-[13px] text-muted">
              No hubo renglones sin cotizar en este período.
            </div>
          ) : (
            <div className="flex flex-col gap-5 px-[22px] pb-5">
              <Bloque titulo="En números">
                <Dato n={datos.declinados.total_lineas} etiqueta="renglones sin cotizar" />
                <Dato
                  n={datos.declinados.cotizaciones_con_declinadas}
                  etiqueta="cotizaciones con algún renglón sin cotizar"
                />
                <Dato
                  n={datos.declinados.cotizaciones_declinadas}
                  etiqueta="cotizaciones declinadas enteras"
                  tono={datos.declinados.cotizaciones_declinadas > 0 ? 'ambar' : undefined}
                />
              </Bloque>

              <Barras
                titulo="Por motivo"
                datos={datos.declinados.por_motivo.map((m) => ({ label: m.motivo, n: m.cuantas }))}
              />
              <Barras
                titulo="Por material"
                datos={datos.declinados.por_material.map((m) => ({ label: m.material, n: m.cuantas }))}
              />
            </div>
          )}

          <NotaPie>
            Un renglón se marca “No se cotiza”, con su motivo, al armar la cotización.
          </NotaPie>
        </Card>
      )}
    </div>
  )
}

/*
  Barras horizontales, sin librería: una barra por fila, proporcional a la más
  alta. Para un puñado de motivos o materiales no hace falta más que un div con
  el ancho en porcentaje.
*/
function Barras({
  titulo,
  datos,
}: {
  titulo: string
  datos: { label: string; n: number }[]
}) {
  if (datos.length === 0) return null
  const max = Math.max(...datos.map((d) => d.n), 1)

  return (
    <section className="flex flex-col gap-2.5">
      <h3 className="text-[11px] font-semibold uppercase tracking-wide text-faint">{titulo}</h3>
      <div className="flex flex-col gap-2.5">
        {datos.map((d) => (
          <div key={d.label} className="flex flex-col gap-1">
            <div className="flex items-baseline justify-between gap-3">
              <span className="truncate text-[12.5px] text-ink" title={d.label}>
                {d.label}
              </span>
              <span className="text-[12px] font-semibold tabular-nums text-muted">{d.n}</span>
            </div>
            <div className="h-2 rounded-full bg-slate-100">
              <div
                className="h-2 rounded-full bg-[#e0a458]"
                style={{ width: `${(d.n / max) * 100}%` }}
              />
            </div>
          </div>
        ))}
      </div>
    </section>
  )
}

function Bloque({ titulo, children }: { titulo: string; children: React.ReactNode }) {
  return (
    <section className="flex flex-col gap-2.5">
      <h3 className="text-[11px] font-semibold uppercase tracking-wide text-faint">{titulo}</h3>
      <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">{children}</div>
    </section>
  )
}

const TONOS = {
  ambar: 'border-[#f3d9a6] bg-[#fff8ee] text-warning-ink',
  rojo: 'border-[#f1c9c0] bg-[#fdf3f1] text-danger',
  verde: 'border-[#cdebd8] bg-[#f4fbf6] text-success-ink',
} as const

function Dato({
  n,
  etiqueta,
  pie,
  tono,
  a,
}: {
  n: number
  etiqueta: string
  pie?: string
  tono?: keyof typeof TONOS
  a?: string
}) {
  const cuerpo = (
    <div
      className={`flex h-full flex-col rounded-[10px] border px-3.5 py-3 ${
        tono ? TONOS[tono] : 'border-line bg-app text-ink'
      }`}
    >
      <span className="text-[24px] font-bold leading-none tabular-nums">{n}</span>
      <span className="mt-1.5 text-[12px] leading-snug opacity-90">{etiqueta}</span>
      {pie && <span className="mt-1 text-[11.5px] font-semibold opacity-80">{pie}</span>}
    </div>
  )

  return a && n > 0 ? (
    <Link to={a} className="transition-opacity hover:opacity-80">
      {cuerpo}
    </Link>
  ) : (
    cuerpo
  )
}

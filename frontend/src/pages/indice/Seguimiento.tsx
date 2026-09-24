import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { AlarmClock, CalendarX2, Archive } from 'lucide-react'
import {
  Accion,
  Aviso,
  Card,
  CardHeader,
  Cargando,
  Chip,
  PageHeader,
  SinResultados,
  Tabla,
  Td,
  Th,
} from '../../components/ui'
import { buscarConsultas, fecha as fmtFecha, plata, useCarga } from '../../lib/indice'
import type { Consulta } from '../../types/indice'
import Paginador from '../../components/Paginador'

/* ---------------------------------------------------------------------------
   Las cotizaciones que hay que seguir, y las que ya terminaron.

   Tres solapas porque son tres momentos distintos del trabajo, no tres
   filtros de la misma lista: "por vencer" es sobre lo que todavía se puede
   hacer algo, "vencidas" es lo que se cayó solo y nadie cerró, y "cerradas"
   es para consultar por qué se perdió.
--------------------------------------------------------------------------- */

type Solapa = 'por_vencer' | 'vencidas' | 'cerradas'

const SOLAPAS: {
  clave: Solapa
  titulo: string
  icono: typeof AlarmClock
  ayuda: string
  vacio: string
  /** Por que puede estar vacia sin que nada este roto. */
  porQue?: string
}[] = [
  {
    clave: 'por_vencer',
    titulo: 'Por vencer',
    icono: AlarmClock,
    ayuda: 'Todavía se pueden salvar con un llamado.',
    vacio: 'No hay cotizaciones por vencer en este plazo.',
    porQue:
      'Las 8.048 traídas del sistema anterior entraron sin validez, así que no vencen. Las que se carguen de ahora en adelante vencen a los 7 días salvo que se cambie, y aparecen acá.',
  },
  {
    clave: 'vencidas',
    titulo: 'Vencidas sin cerrar',
    icono: CalendarX2,
    ayuda: 'Se les pasó la validez y nadie las cerró. Conviene cerrarlas diciendo por qué.',
    vacio: 'Ninguna cotización quedó vencida sin cerrar.',
    porQue: 'Aparecen acá cuando se les pasa la fecha de validez y todavía nadie las cerró.',
  },
  {
    clave: 'cerradas',
    titulo: 'Cerradas',
    icono: Archive,
    ayuda: 'Terminadas, con el motivo escrito en el hilo de cada una.',
    vacio: 'Todavía no se cerró ninguna cotización.',
    porQue:
      'Una cotización se cierra desde su pantalla, eligiendo si fue por tiempo o porque el cliente declinó, y escribiendo el motivo.',
  },
]

const PLAZOS = [7, 15, 30, 60]

export default function Seguimiento() {
  const [solapa, setSolapa] = useState<Solapa>('por_vencer')
  const [dias, setDias] = useState(15)
  const [pagina, setPagina] = useState(1)

  // Cambiar de solapa o de plazo empieza de nuevo: quedarse en la pagina 7 de
  // la lista anterior deja una pantalla vacia sin explicacion.
  useEffect(() => setPagina(1), [solapa, dias])

  const actual = SOLAPAS.find((s) => s.clave === solapa)!

  const { datos, cargando } = useCarga(
    () =>
      buscarConsultas({
        page: pagina,
        ...(solapa === 'por_vencer'
          ? { por_vencer: dias }
          : solapa === 'vencidas'
            ? { sin_respuesta: true }
            : { cerradas: true }),
      }),
    [solapa, dias, pagina],
  )

  const filas = datos?.data ?? []

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        titulo="Vencimientos y seguimiento"
        bajada="Qué hay que llamar antes de que se caiga, y por qué se perdió lo que se perdió."
      />

      {/* Las solapas: tres momentos del trabajo, no tres filtros. */}
      <div className="flex flex-wrap items-center gap-2.5">
        {SOLAPAS.map(({ clave, titulo, icono: Icono }) => (
          <button
            key={clave}
            type="button"
            onClick={() => setSolapa(clave)}
            className={`inline-flex items-center gap-2 rounded-[9px] border px-4 py-2.5 text-[13px] font-semibold transition-colors ${
              solapa === clave
                ? 'border-brand bg-brand text-white'
                : 'border-line-strong bg-white text-slate-600 hover:bg-app'
            }`}
          >
            <Icono size={14} strokeWidth={2.2} />
            {titulo}
          </button>
        ))}

        <span className="ml-auto text-[11.5px] text-faint">{actual.ayuda}</span>
      </div>

      <Card>
        <CardHeader
          titulo={actual.titulo}
          acciones={
            solapa === 'por_vencer' ? (
              <div className="flex items-center gap-2">
                <span className="text-[11.5px] text-muted">Vencen dentro de</span>
                {PLAZOS.map((d) => (
                  <button
                    key={d}
                    type="button"
                    onClick={() => setDias(d)}
                    className={`rounded-md border px-2.5 py-1 text-[11.5px] font-semibold transition-colors ${
                      dias === d
                        ? 'border-brand bg-brand-50 text-brand-600'
                        : 'border-line-strong bg-white text-slate-600 hover:bg-app'
                    }`}
                  >
                    {d} días
                  </button>
                ))}
              </div>
            ) : undefined
          }
        />

        {cargando ? (
          <Cargando />
        ) : filas.length === 0 ? (
          <SinResultados titulo={actual.vacio} detalle={actual.porQue} />
        ) : (
          <Tabla>
            <thead>
              <tr>
                <Th>Fecha</Th>
                <Th>Empresa</Th>
                <Th>{solapa === 'cerradas' ? 'Cómo terminó' : 'Vence'}</Th>
                <Th derecha>Seguimientos</Th>
                <Th derecha>Total</Th>
                <Th>Quién</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {filas.map((c) => (
                <tr key={c.id}>
                  <Td className="whitespace-nowrap font-semibold text-ink">{fmtFecha(c.fecha)}</Td>
                  <Td>
                    <Link to={`/empresas/${c.empresa?.id}`} className="hover:text-brand-600">
                      {c.empresa?.nombre}
                    </Link>
                  </Td>
                  <Td>{solapa === 'cerradas' ? <ChipEstado c={c} /> : <Vencimiento c={c} />}</Td>
                  <Td derecha>
                    <Seguimientos veces={c.seguimientos ?? 0} />
                  </Td>
                  <Td derecha className="font-semibold text-ink">{plata(c.total)}</Td>
                  <Td className="text-brand-600">{c.quien_lo_hizo}</Td>
                  <Td>
                    <div className="flex items-center justify-end gap-3">
                      <Accion to={`/consultas/${c.id}`}>Abrir</Accion>
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Tabla>
        )}

        <Paginador
          pagina={datos?.meta.current_page ?? 1}
          paginas={datos?.meta.last_page ?? 1}
          total={datos?.meta.total ?? 0}
          porPagina={datos?.meta.per_page ?? 25}
          onIr={setPagina}
          que="cotizaciones"
        />

        {solapa === 'vencidas' && filas.length > 0 && (
          <Aviso tono="ambar">
            {filas.length === 1
              ? 'Hay 1 cotización vencida sin cerrar. Cerrala diciendo qué pasó: ese motivo es lo que sirve para volver a cotizarle.'
              : `Hay ${filas.length} cotizaciones vencidas sin cerrar. Cerralas diciendo qué pasó: ese motivo es lo que sirve para volver a cotizarles.`}
          </Aviso>
        )}
      </Card>
    </div>
  )
}

/** Cuánto falta, o cuánto hace que se pasó. */
function Vencimiento({ c }: { c: Consulta }) {
  const dias = c.dias_para_vencer

  if (c.vence_el === null || dias === null) {
    return <span className="text-[11.5px] text-faint">sin vencimiento</span>
  }

  return (
    <div className="flex flex-col">
      <span className="whitespace-nowrap font-medium text-ink">{fmtFecha(c.vence_el)}</span>
      <span
        className={`text-[11px] ${
          dias < 0 ? 'text-danger' : dias <= 3 ? 'text-warning-ink' : 'text-muted'
        }`}
      >
        {dias < 0
          ? `vencida hace ${Math.abs(dias)} ${Math.abs(dias) === 1 ? 'día' : 'días'}`
          : dias === 0
            ? 'vence hoy'
            : `faltan ${dias} ${dias === 1 ? 'día' : 'días'}`}
      </span>
    </div>
  )
}

function ChipEstado({ c }: { c: Consulta }) {
  return (
    <Chip tono={c.estado === 'Cerrada por declinacion' ? 'neutro' : 'ambar'}>{c.estado}</Chip>
  )
}

/**
 * Cuántas veces se le siguió el rastro.
 *
 * El cero importa más que el número: una cotización que vence sin que nadie la
 * haya tocado es la que hay que llamar primero.
 */
function Seguimientos({ veces }: { veces: number }) {
  if (veces === 0) {
    return <span className="text-[11.5px] font-semibold text-warning-ink">sin seguimiento</span>
  }

  return (
    <span className="font-semibold text-ink">
      {veces}
      <span className="ml-1 text-[11px] font-normal text-faint">
        {veces === 1 ? 'vez' : 'veces'}
      </span>
    </span>
  )
}

import { Link } from 'react-router-dom'
import { Aviso, SinResultados, Tabla, Td, Th, cx } from '../../components/ui'
import { fecha as fmtFecha, FILA_INACTIVA, plata } from '../../lib/indice'
import type { ColumnaDeReporte, FilaDeReporte, RespuestaDeReporte } from '../../types/reportes'

/* ---------------------------------------------------------------------------
   Una tabla para todos los reportes: las columnas, las filas y la fila de
   totales vienen del servidor. La primera columna lleva al detalle (la ficha
   o el Buscador ya filtrado) y la primera de números tiene una barra de
   proporción, para ver de un vistazo qué pesa más.
--------------------------------------------------------------------------- */

function formatear(c: ColumnaDeReporte, v: FilaDeReporte[string]): string {
  if (v === null || v === undefined || v === '') return '—'

  switch (c.tipo) {
    case 'numero':
      return Number(v).toLocaleString('es-AR')
    case 'importe':
      return plata(Number(v))
    case 'porcentaje':
      return `${Number(v).toLocaleString('es-AR', { maximumFractionDigits: 1 })} %`
    case 'fecha':
      return fmtFecha(String(v))
    default:
      return String(v)
  }
}

export default function TablaDeReporte({ reporte }: { reporte: RespuestaDeReporte }) {
  const { columnas, filas, totales } = reporte

  if (filas.length === 0) {
    return <SinResultados titulo="No hay nada para mostrar" detalle="Probá con otro período o sacá algún filtro." />
  }

  // La barra va en la primera columna de números que sea una cuenta (no el puesto del ranking).
  const principal = columnas.find((c) => c.tipo === 'numero' && c.clave !== 'puesto' && !c.sinTotal)
  // El nombre de la fila (la empresa, el material, el mes): la primera columna de texto, que lleva al detalle.
  const titulo = columnas.find((c) => c.tipo === 'texto')
  const maximo = principal ? Math.max(...filas.map((f) => Number(f[principal.clave] ?? 0)), 1) : 1

  return (
    <div className="flex flex-col">
      {reporte.avisos.map((a) => (
        <Aviso key={a} tono="info" className="mx-[22px] mb-3">
          {a.replace(/`([^`]+)`/g, '«$1»')}
        </Aviso>
      ))}

      <Tabla>
        <thead>
          <tr>
            {columnas.map((c) => (
              <Th key={c.clave} derecha={c.tipo !== 'texto'} className={cx(c === titulo && 'min-w-[180px]')}>
                {c.titulo}
              </Th>
            ))}
          </tr>
        </thead>
        <tbody>
          {filas.map((f, n) => (
            <tr key={`${f.clave ?? n}`} className={cx('transition-colors', f.apagada ? FILA_INACTIVA : 'hover:bg-[#f8fcfe]')}>
              {columnas.map((c) => {
                const texto = formatear(c, f[c.clave])
                const esPrincipal = c === principal
                const ancho = esPrincipal ? (Number(f[c.clave] ?? 0) / maximo) * 100 : 0

                return (
                  <Td
                    key={c.clave}
                    derecha={c.tipo !== 'texto'}
                    className={cx(
                      c.tipo !== 'texto' && 'whitespace-nowrap tabular-nums',
                      c === titulo && 'font-semibold text-ink',
                      f.apagada && c === titulo && 'text-[#b91c1c]',
                    )}
                  >
                    <span
                      className={cx(esPrincipal && 'rounded px-1.5 py-0.5')}
                      style={
                        esPrincipal && ancho > 0
                          ? { background: `linear-gradient(to left, #d6ebf8 ${ancho}%, transparent ${ancho}%)` }
                          : undefined
                      }
                    >
                      {c === titulo && f.enlace ? (
                        <Link to={f.enlace} className="hover:text-brand-600" title="Ver el detalle">
                          {texto}
                        </Link>
                      ) : (
                        texto
                      )}
                    </span>
                  </Td>
                )
              })}
            </tr>
          ))}

          {totales && (
            <tr className="bg-[#f1f5f9]">
              {columnas.map((c) => (
                <Td key={c.clave} derecha={c.tipo !== 'texto'} className="whitespace-nowrap font-bold tabular-nums text-ink">
                  {totales[c.clave] === undefined ? '' : formatear(c, totales[c.clave])}
                </Td>
              ))}
            </tr>
          )}
        </tbody>
      </Tabla>
    </div>
  )
}

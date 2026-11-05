import api from './api'
import { hoy } from './indice'
import type { NombreDeReporte, RespuestaDeReporte } from '../types/reportes'

/* ---------------------------------------------------------------------------
   Los reportes: por material, por empresa (o por año), compras y comprobantes,
   ranking y vencimientos. El balance de seguimiento tiene su propio cliente.
--------------------------------------------------------------------------- */

export type Periodo = 'anio' | 'anio_pasado' | '12m' | 'todo' | 'otro'

export const PERIODOS: { clave: Periodo; texto: string }[] = [
  { clave: 'anio', texto: 'Este año' },
  { clave: 'anio_pasado', texto: 'El año pasado' },
  { clave: '12m', texto: 'Últimos 12 meses' },
  { clave: 'todo', texto: 'Todo' },
  { clave: 'otro', texto: 'Otro…' },
]

/** Las fechas que corresponden a un período con nombre; con "otro" valen las que se escribieron. */
export function rangoDelPeriodo(periodo: Periodo, desde = '', hasta = ''): { desde?: string; hasta?: string } {
  const anio = Number(hoy().slice(0, 4))

  switch (periodo) {
    case 'anio':
      return { desde: `${anio}-01-01`, hasta: hoy() }
    case 'anio_pasado':
      return { desde: `${anio - 1}-01-01`, hasta: `${anio - 1}-12-31` }
    case '12m':
      return { desde: hoy(-365), hasta: hoy() }
    case 'todo':
      return {}
    default:
      return { ...(desde ? { desde } : {}), ...(hasta ? { hasta } : {}) }
  }
}

export async function traerReporte(nombre: NombreDeReporte, params: Record<string, string>) {
  const { data } = await api.get<RespuestaDeReporte>(`/reportes/${nombre}`, { params })

  return data
}

/** El mismo reporte, con las mismas columnas, en una planilla de Excel. */
export async function bajarReporte(nombre: NombreDeReporte, params: Record<string, string>) {
  const { data } = await api.get<Blob>(`/reportes/${nombre}/exportar`, { params, responseType: 'blob' })
  const url = URL.createObjectURL(data)
  const enlace = document.createElement('a')
  enlace.href = url
  enlace.download = `reporte-${nombre}-${params.desde ?? 'inicio'}_${params.hasta ?? hoy()}.xlsx`
  enlace.click()
  URL.revokeObjectURL(url)
}

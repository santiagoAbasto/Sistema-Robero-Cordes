/* ---------------------------------------------------------------------------
   Los reportes (GET /api/reportes/{reporte}): todos con la misma forma.
--------------------------------------------------------------------------- */

export type NombreDeReporte = 'material' | 'empresa' | 'comprobantes' | 'ranking' | 'vencimientos'

export type TipoDeColumna = 'texto' | 'numero' | 'importe' | 'porcentaje' | 'fecha'

export interface ColumnaDeReporte {
  clave: string
  titulo: string
  tipo: TipoDeColumna
  /** No se suma en la fila de totales (sumarla contaría dos veces). */
  sinTotal?: boolean
}

export type FilaDeReporte = Record<string, string | number | boolean | null | undefined> & {
  /** A dónde lleva esta fila (la ficha de la empresa, el Buscador ya filtrado). */
  enlace?: string | null
  /** Una empresa que cerró: se ve, pero apagada. */
  apagada?: boolean
}

export interface RespuestaDeReporte {
  reporte: NombreDeReporte
  titulo: string
  generado_el: string
  periodo: { desde: string | null; hasta: string | null }
  permisos: { ve_importes: boolean }
  columnas: ColumnaDeReporte[]
  filas: FilaDeReporte[]
  totales: FilaDeReporte | null
  avisos: string[]
}

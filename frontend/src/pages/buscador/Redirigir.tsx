import { Navigate, useSearchParams } from 'react-router-dom'
import { enlaceAlBuscador } from '../../lib/buscador'
import { hoy } from '../../lib/indice'
import type { Pedido } from '../../types/buscador'

/* ---------------------------------------------------------------------------
   Los cuatro buscadores viejos ya no existen: son este. Las direcciones de
   antes siguen andando (marcadores, links del Dashboard, mails) y llevan al
   Buscador con lo que cada una pedía.
--------------------------------------------------------------------------- */

/** Pasa de lo que decía una dirección vieja a lo que pide el Buscador. */
type Traductor = (viejos: URLSearchParams) => Pedido

function sacarVacios(p: Pedido): Pedido {
  return Object.fromEntries(Object.entries(p).filter(([, v]) => v))
}

/** "Mostrar" de las listas de empresas: antes ?mostrar= o ?inactivas=1, ahora ?activa=. */
function activaDe(viejos: URLSearchParams): string {
  const m = viejos.get('mostrar')
  if (m === 'activas' || m === 'inactivas') return m

  return viejos.get('inactivas') === '1' ? 'inactivas' : ''
}

export const DESDE_REGISTROS: Traductor = (v) =>
  sacarVacios({
    q: v.get('buscar') ?? '',
    relacion: v.get('relacion') ?? '',
    rubro_id: v.get('rubro_id') ?? '',
    provincia_id: v.get('provincia_id') ?? '',
    activa: activaDe(v),
  })

export const DESDE_EMPRESAS_POR_CONDICION: Traductor = (v) => sacarVacios({ ...DESDE_REGISTROS(v), grano: 'empresas' })

export const DESDE_CONSULTAS_POR_FECHA: Traductor = () => ({ grano: 'consultas', desde: hoy(-365), hasta: hoy() })

export const DESDE_CONSULTAS_POR_CONDICION: Traductor = (v) =>
  sacarVacios({ grano: 'consultas', estado: v.get('estado') ?? '', tipo: v.get('tipo') ?? '' })

export const DESDE_BUSCADOR_IA: Traductor = () => ({})

/** "Facturas y remitos" del menú de Compras: una fila por comprobante, con la cotización de la que salió. */
export const DESDE_FACTURAS_Y_REMITOS: Traductor = () => ({ grano: 'comprobantes' })

/** "A clientes" del menú de Cotizaciones: las cotizaciones, una fila por cotización. */
export const DESDE_COTIZACIONES_A_CLIENTES: Traductor = () => ({ grano: 'consultas', tipo: 'Cotizacion' })

/** "Buscar material" del menú de Cotizaciones: a quién se le cotizó, renglón por renglón. */
export const DESDE_BUSCAR_MATERIAL: Traductor = () => ({ grano: 'renglones' })

export default function RedirigirAlBuscador({ traducir }: { traducir: Traductor }) {
  const [viejos] = useSearchParams()

  return <Navigate to={enlaceAlBuscador(traducir(viejos))} replace />
}

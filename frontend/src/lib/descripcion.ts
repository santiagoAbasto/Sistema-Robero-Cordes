import type { CanoEstandar, Forma } from '../types/indice'
import type { MedidaCargada } from './calculadora'

/* ---------------------------------------------------------------------------
   Arma la medida y la descripción con lo que se fue cargando.

   Es lo que hoy se escribe a mano en cada línea, y sale siempre de lo mismo:
   material, forma y medidas. El orden de las medidas lo pone la forma, así que
   una barra redonda sale "38.1 X 145 MM" y una chapa "1000 X 3 X 2000 MM".

   Lo que se arma solo es una propuesta: si alguien la corrige, deja de
   pisarse. La descripción es lo que sale impreso y manda la persona.
--------------------------------------------------------------------------- */

/** "38.1" y no "38.10": los ceros al final molestan en la hoja. */
function numero(valor: string): string {
  const n = Number.parseFloat(valor)

  if (!Number.isFinite(n)) return ''

  return String(Number(n.toFixed(4))).replace('.', ',')
}

/**
 * La medida, como se escribe en la cotización.
 *
 * Si todas las medidas están en la misma unidad, la unidad va una sola vez al
 * final: "38,1 X 145 MM". Si están en unidades distintas, cada una lleva la
 * suya: "1 IN X 6 M".
 */
export function armarDimensiones(
  forma: Forma | null,
  medidas: Record<string, MedidaCargada>,
  cano: CanoEstandar | null,
): string {
  if (!forma) return ''

  const campos = forma.campos ?? []

  // Con un caño comercial la medida es su nombre y su schedule, no el
  // diámetro: es como lo pide el cliente y como lo busca el proveedor.
  if (forma.usa_cano && cano) {
    const largo = medidas.length

    return [
      `${cano.nombre} SCH ${cano.schedule}`,
      largo?.valor ? `X ${numero(largo.valor)} ${largo.unidad.toUpperCase()}` : '',
    ]
      .filter(Boolean)
      .join(' ')
  }

  const cargadas = campos
    .map((c) => medidas[c.clave])
    .filter((m): m is MedidaCargada => Boolean(m?.valor) && numero(m.valor) !== '')

  if (cargadas.length === 0) return ''

  const unidades = new Set(cargadas.map((m) => m.unidad))

  if (unidades.size === 1) {
    return `${cargadas.map((m) => numero(m.valor)).join(' X ')} ${[...unidades][0].toUpperCase()}`
  }

  return cargadas.map((m) => `${numero(m.valor)} ${m.unidad.toUpperCase()}`).join(' X ')
}

/**
 * La descripción: es lo que sale impreso en la cotización.
 *
 * Material, forma y medida, en ese orden, que es como se escribe hoy a mano.
 * El nombre de la forma es el que tenga cargado: si prefieren "BAR RED" en vez
 * de "BARRA REDONDA", se renombra desde la pantalla de formas.
 */
export function armarDescripcion(
  material: string | null,
  forma: Forma | null,
  dimensiones: string,
): string {
  return [material, forma?.nombre, dimensiones].filter(Boolean).join(' ').trim()
}

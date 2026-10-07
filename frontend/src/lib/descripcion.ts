import type { CanoEstandar, Forma } from '../types/indice'
import { A_MILIMETROS } from './calculadora'
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
 *
 * Con largos variables el largo es el rango y no el promedio: "4,76 X
 * 3200-3500 MM", que es lo que se le ofrece al cliente. El promedio queda
 * para el peso y el factor.
 */
export function armarDimensiones(
  forma: Forma | null,
  medidas: Record<string, MedidaCargada>,
  cano: CanoEstandar | null,
  rango?: { min?: number | string | null; max?: number | string | null },
): string {
  if (!forma) return ''

  const campos = forma.campos ?? []

  const min = rango?.min != null ? Number(rango.min) : null
  const max = rango?.max != null ? Number(rango.max) : null

  /** La medida escrita; el largo, como rango si lo hay. */
  const texto = (clave: string, m: MedidaCargada) => {
    if (clave !== 'length' || !min || !max || min === max) return numero(m.valor)

    const porUnidad = A_MILIMETROS[m.unidad] ?? 1

    return `${numero(String(min / porUnidad))}-${numero(String(max / porUnidad))}`
  }

  // Con un caño comercial la medida es su nombre y su schedule, no el
  // diámetro: es como lo pide el cliente y como lo busca el proveedor.
  if (forma.usa_cano && cano) {
    const largo = medidas.length

    return [
      `${cano.nombre} SCH ${cano.schedule}`,
      largo?.valor ? `X ${texto('length', largo)} ${largo.unidad.toUpperCase()}` : '',
    ]
      .filter(Boolean)
      .join(' ')
  }

  const cargadas = campos
    .map((c) => ({ clave: c.clave, m: medidas[c.clave] }))
    // Una medida en 0 no está cargada: "76 X 0 MM" no le sirve a nadie.
    .filter((x): x is { clave: string; m: MedidaCargada } =>
      Boolean(x.m?.valor) && Number.parseFloat(x.m.valor) > 0,
    )

  if (cargadas.length === 0) return ''

  const unidades = new Set(cargadas.map((x) => x.m.unidad))

  if (unidades.size === 1) {
    return `${cargadas.map((x) => texto(x.clave, x.m)).join(' X ')} ${[...unidades][0].toUpperCase()}`
  }

  return cargadas.map((x) => `${texto(x.clave, x.m)} ${x.m.unidad.toUpperCase()}`).join(' X ')
}

/**
 * La descripción: es lo que sale impreso en la cotización.
 *
 * Material, forma, medida y la característica ofrecida, en ese orden, que es
 * como se escribe hoy a mano. El nombre de la forma es el que tenga cargado:
 * si prefieren "BAR RED" en vez de "BARRA REDONDA", se renombra desde la
 * pantalla de formas. La característica —"s/c", "ASTM B348"— se suma al final
 * si no está ya escrita en la descripción, para no repetirla.
 */
export function armarDescripcion(
  material: string | null,
  forma: Forma | null,
  dimensiones: string,
  caracteristicas?: string | null,
): string {
  const base = [material, forma?.nombre, dimensiones].filter(Boolean).join(' ').trim()
  const extra = caracteristicas?.trim()

  if (extra && !base.toUpperCase().includes(extra.toUpperCase())) {
    return `${base} ${extra}`.trim()
  }

  return base
}

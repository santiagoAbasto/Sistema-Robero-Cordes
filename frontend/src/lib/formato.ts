/*
  Resaltar en negrita, y de un color, los textos de la cotización.

      Entrega: *90 días* salvo *venta previa*.

  Lo que va entre asteriscos sale en NEGRITA, como en WhatsApp. El COLOR se
  elige una vez por texto y vale para todo lo resaltado en él: se guarda al
  principio, como un encabezado que la pantalla esconde ("{#rojo}").

  ES LA MISMA REGLA que backend/app/Services/FormatoDeTexto.php (la que arma la
  hoja). Acá se usa para la vista previa. Si se cambia una, se cambia la otra;
  la regla de cuándo un asterisco cuenta está explicada allá.
*/

/** Los colores con los que se imprime: los mismos que el servidor. */
export const COLORES = {
  rojo: '#b91c1c',
  azul: '#1d4ed8',
  verde: '#15803d',
  naranja: '#c2410c',
  violeta: '#6d28d9',
} as const

export type ColorDeTexto = keyof typeof COLORES

export type Trozo = { texto: string; negrita: boolean; color: ColorDeTexto | null }

const ENCABEZADO = /^\{#(rojo|azul|verde|naranja|violeta)\}/i

const esLetraONumero = (c: string) => /^[\p{L}\p{N}]$/u.test(c)
const esEspacio = (c: string) => /^\s$/u.test(c)

/** El color elegido (si hay) y el texto sin el encabezado. */
export function separar(texto: string | null | undefined): { color: ColorDeTexto | null; cuerpo: string } {
  const t = texto ?? ''
  const m = t.match(ENCABEZADO)

  return m
    ? { color: m[1].toLowerCase() as ColorDeTexto, cuerpo: t.slice(m[0].length) }
    : { color: null, cuerpo: t }
}

/** Vuelve a poner el encabezado de color. Sin color, el texto queda como está. */
export function juntar(color: ColorDeTexto | null, cuerpo: string): string {
  return color ? `{#${color}}${cuerpo}` : cuerpo
}

/** ¿Este asterisco puede abrir un resaltado? */
function abre(c: string[], i: number): boolean {
  const antes = i > 0 ? c[i - 1] : null
  const despues = c[i + 1] ?? null

  return (
    (antes === null || (!esLetraONumero(antes) && antes !== '*')) &&
    despues !== null &&
    !esEspacio(despues) &&
    despues !== '*'
  )
}

/** El lugar del asterisco que cierra al de `i`, o null si no hay en esa línea. */
function cierre(c: string[], i: number): number | null {
  for (let j = i + 1; j < c.length; j++) {
    if (c[j] === '\n' || c[j] === '\r') return null
    if (c[j] !== '*') continue

    const despues = c[j + 1] ?? null

    if (
      !esEspacio(c[j - 1]) &&
      c[j - 1] !== '*' &&
      (despues === null || (!esLetraONumero(despues) && despues !== '*'))
    ) {
      return j
    }
  }

  return null
}

/**
 * El texto partido en trozos: lo resaltado va con negrita y, si se eligió un
 * color, con ese color. Lo demás, común.
 */
export function trozos(texto: string | null | undefined): Trozo[] {
  const { color, cuerpo } = separar(texto)

  if (cuerpo === '') return []

  const c = Array.from(cuerpo)
  const salida: Trozo[] = []
  let suelto = ''

  for (let i = 0; i < c.length; i++) {
    if (c[i] === '*' && abre(c, i)) {
      const j = cierre(c, i)

      if (j !== null) {
        if (suelto !== '') {
          salida.push({ texto: suelto, negrita: false, color: null })
          suelto = ''
        }

        salida.push({ texto: c.slice(i + 1, j).join(''), negrita: true, color })
        i = j

        continue
      }
    }

    suelto += c[i]
  }

  if (suelto !== '') salida.push({ texto: suelto, negrita: false, color: null })

  return salida
}

/** El texto sin encabezado ni asteriscos: lo que leería quien no ve el formato. */
export function sinFormato(texto: string | null | undefined): string {
  return trozos(texto)
    .map((t) => t.texto)
    .join('')
}

/** ¿Tiene algo resaltado? */
export function tieneFormato(texto: string | null | undefined): boolean {
  return trozos(texto).some((t) => t.negrita)
}

/**
 * Resalta lo seleccionado (le pone los asteriscos) o, si ya estaba resaltado, se
 * lo saca. Devuelve el texto nuevo y dónde queda la selección, o null si no hay
 * nada seleccionado.
 *
 * Se cuida que el resultado resalte de verdad: se corren los bordes para no
 * dejar espacios pegados a un asterisco, se completan las palabras cortadas por
 * la mitad y se resalta renglón por renglón, porque un resaltado no cruza
 * renglones. Un asterisco que no cuenta se vería tal cual en la hoja.
 */
export function alternarResaltado(
  texto: string,
  desde: number,
  hasta: number,
): { texto: string; desde: number; hasta: number } | null {
  while (desde < hasta && esEspacio(texto[desde])) desde++
  while (hasta > desde && esEspacio(texto[hasta - 1])) hasta--

  if (desde === hasta) return null

  // Ya estaba resaltado, con la selección adentro de los asteriscos: se saca.
  if (texto[desde - 1] === '*' && texto[hasta] === '*') {
    return {
      texto: texto.slice(0, desde - 1) + texto.slice(desde, hasta) + texto.slice(hasta + 1),
      desde: desde - 1,
      hasta: hasta - 1,
    }
  }

  // ...o con los asteriscos dentro de la selección.
  const elegido = texto.slice(desde, hasta)

  if (elegido.length >= 3 && elegido.startsWith('*') && elegido.endsWith('*')) {
    return {
      texto: texto.slice(0, desde) + elegido.slice(1, -1) + texto.slice(hasta),
      desde,
      hasta: hasta - 2,
    }
  }

  // Una palabra cortada por la mitad se completa: "ab*cd*ef" no resalta.
  while (desde > 0 && esLetraONumero(texto[desde - 1]) && esLetraONumero(texto[desde])) desde--
  while (hasta < texto.length && esLetraONumero(texto[hasta]) && esLetraONumero(texto[hasta - 1])) hasta++

  const envuelto = texto
    .slice(desde, hasta)
    .replaceAll('*', '')
    .split('\n')
    .map((renglon) => {
      const m = renglon.match(/^(\s*)([\s\S]*?)(\s*)$/)

      return m && m[2] ? `${m[1]}*${m[2]}*${m[3]}` : renglon
    })
    .join('\n')

  return {
    texto: texto.slice(0, desde) + envuelto + texto.slice(hasta),
    desde,
    hasta: desde + envuelto.length,
  }
}

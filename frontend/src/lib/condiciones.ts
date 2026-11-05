import type { CampoEmpresa, CondicionHabitual } from '../types/indice'

/*
  Con qué condiciones arranca una cotización nueva: el juego de Configuración
  Y lo que la empresa tiene cargado en su ficha. Si hablan de lo mismo, manda
  la FICHA.

  ES LA MISMA REGLA que backend/app/Services/CondicionesDeLaFicha.php (la que
  usa la API cuando no mandan condiciones). Si se cambia una, se cambia la otra.
  Está explicada allá.
*/

/** Sin tildes, en minúsculas y sin signos: "Condic. de Pago" = "condic de pago". */
export function normalizar(texto: string): string {
  return texto
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

/** Cómo se llama el bloque de pago en los juegos. */
const TITULOS_DE_PAGO = ['condic de pago', 'condiciones de pago', 'condicion de pago']

/** Campos de la ficha que ya alimentan la cabecera de la cotización: no son bloques. */
const DE_LA_CABECERA = ['tipo de pago', 'lista de precios', 'moneda habitual']

export type BloqueDeCondicion = {
  titulo: string
  texto: string
  /** El texto de la lista, sin completar la fecha. Solo los del juego. */
  plantilla?: string
  deLaFicha: boolean
}

/** Reemplaza el bloque con el mismo título, o lo agrega antes del de validez. */
function poner(
  bloques: BloqueDeCondicion[],
  titulo: string,
  texto: string,
  iguales: string[] = [normalizar(titulo)],
): BloqueDeCondicion[] {
  const nuevo: BloqueDeCondicion = { titulo, texto, deLaFicha: true }
  const i = bloques.findIndex((b) => iguales.includes(normalizar(b.titulo)))

  if (i >= 0) return bloques.map((b, k) => (k === i ? nuevo : b))

  const validez = bloques.findIndex((b) => normalizar(b.titulo).includes('validez'))

  if (validez >= 0) return [...bloques.slice(0, validez), nuevo, ...bloques.slice(validez)]

  return [...bloques, nuevo]
}

export function condicionesConLaFicha(
  delJuego: CondicionHabitual[],
  ficha: {
    campos: CampoEmpresa[]
    /** La condición de pago que tiene escrita esta cotización. */
    pagoDeLaCotizacion: string
    /** La de la ficha de la empresa. */
    pagoDeLaEmpresa: string
  },
): BloqueDeCondicion[] {
  let bloques: BloqueDeCondicion[] = delJuego.map((c) => ({
    titulo: c.titulo ?? '',
    texto: c.texto,
    plantilla: c.texto,
    deLaFicha: false,
  }))

  const campos = ficha.campos.filter((c) => c.usar_al_cotizar)

  // Campos de la ficha.
  for (const campo of campos) {
    if (DE_LA_CABECERA.includes(normalizar(campo.titulo))) continue
    if (!campo.titulo.trim() || !campo.valor?.trim()) continue

    bloques = poner(bloques, campo.titulo, campo.valor.trim())
  }

  // Condición de pago, salvo que la ficha ya tenga un bloque de pago propio.
  const yaHayPagoDeLaFicha = bloques.some(
    (b) => b.deLaFicha && TITULOS_DE_PAGO.includes(normalizar(b.titulo)),
  )

  const pago = (
    ficha.pagoDeLaCotizacion ||
    ficha.pagoDeLaEmpresa ||
    campos.find((c) => normalizar(c.titulo) === 'tipo de pago')?.valor ||
    ''
  ).trim()

  if (pago && !yaHayPagoDeLaFicha) {
    bloques = poner(bloques, 'Condic. de Pago', pago, TITULOS_DE_PAGO)
  }

  return bloques
}

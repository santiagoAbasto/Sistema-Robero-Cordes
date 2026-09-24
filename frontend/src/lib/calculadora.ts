/* ---------------------------------------------------------------------------
   Calculadora de peso.

   Las formulas NO estan escritas acá: vienen con cada forma desde el servidor.
   Este archivo solo sabe resolverlas. Por eso agregar una forma nueva o
   corregir una cuenta no toca esta pantalla, y el peso que se ve mientras se
   escribe es el mismo que despues guarda el servidor.

   No se usa eval() ni new Function(): lo que viene guardado se lee token por
   token y solo se aceptan numeros, las medidas de la forma, los operadores
   + - * / ^ y las funciones de la lista.
--------------------------------------------------------------------------- */

export const A_MILIMETROS: Record<string, number> = {
  mm: 1,
  cm: 10,
  m: 1000,
  in: 25.4,
  ft: 304.8,
}

export const DESDE_KILOS: Record<string, number> = {
  kg: 1,
  g: 1000,
  lb: 2.2046226218,
  ton: 0.001,
}

export const UNIDADES_MEDIDA = Object.keys(A_MILIMETROS)
export const UNIDADES_PESO = Object.keys(DESDE_KILOS)

export interface CampoForma {
  clave: string
  label: string
}

export interface MedidaCargada {
  valor: string
  unidad: string
}

export interface DatosDelCalculo {
  densidad: number | null
  expresion: string | null
  campos: CampoForma[]
  medidas: Record<string, MedidaCargada>
  piezas: string
  unidadResultado: string
  usaCano: boolean
  cano: { diametro_mm: number | string; pared_mm: number | string } | null
}

/**
 * La formula da el volumen de UNA pieza. El total sale de multiplicar por la
 * cantidad, y los dos importan: el unitario se compara contra una tabla o el
 * plano, el total es el que va al flete y a los kilos de la cotizacion.
 */
export interface ResultadoDelCalculo {
  ok: boolean
  motivo: string | null
  volumenPorPiezaCm3: number | null
  volumenTotalCm3: number | null
  pesoPorPiezaKg: number | null
  pesoTotalKg: number | null
  resultado: number | null
  valores: Record<string, number>
}

export function aMilimetros(valor: string | number, unidad: string): number {
  const n = typeof valor === 'number' ? valor : Number.parseFloat(valor)

  return (Number.isFinite(n) ? n : 0) * (A_MILIMETROS[unidad] ?? 1)
}

/* ------------------------------------------------------------------ cuenta */

export function calcularPeso(datos: DatosDelCalculo): ResultadoDelCalculo {
  const vacio = {
    volumenPorPiezaCm3: null,
    volumenTotalCm3: null,
    pesoPorPiezaKg: null,
    pesoTotalKg: null,
    resultado: null,
    valores: {},
  }

  if (!datos.densidad || datos.densidad <= 0) {
    return { ok: false, motivo: 'No sabemos la densidad de ese material', ...vacio }
  }

  if (!datos.expresion) {
    return { ok: false, motivo: 'Esa forma no tiene calculo automatico', ...vacio }
  }

  const piezas = Number.parseFloat(datos.piezas)

  if (!Number.isFinite(piezas) || piezas <= 0) {
    return { ok: false, motivo: 'La cantidad de piezas tiene que ser mayor que cero', ...vacio }
  }

  const valores = juntarValores(datos)

  // Falta cargar una medida.
  for (const campo of datos.campos) {
    if ((valores[campo.clave] ?? 0) > 0) continue

    if (datos.usaCano && (campo.clave === 'outer' || campo.clave === 'wall')) {
      return {
        ok: false,
        motivo: 'Elegí un caño de la lista o cargá el diametro y la pared',
        ...vacio,
        valores,
      }
    }

    return { ok: false, motivo: `Falta ${campo.label.toLowerCase()}`, ...vacio, valores }
  }

  // Lo que la formula no puede avisar sola porque igual da un numero.
  if (valores.outer && valores.inner && valores.inner >= valores.outer) {
    return {
      ok: false,
      motivo: 'El diametro interior tiene que ser menor que el exterior',
      ...vacio,
      valores,
    }
  }

  if (valores.outer && valores.wall && valores.wall * 2 >= valores.outer) {
    return {
      ok: false,
      motivo: 'La pared no puede llegar a la mitad del diametro: no quedaria agujero',
      ...vacio,
      valores,
    }
  }

  // Antes de resolverla: que la fórmula no nombre medidas que la forma no
  // pide. El evaluador tomaría cero para esas y el mensaje culparía a las
  // medidas, cuando el problema es la cuenta. Es el mismo control del servidor.
  try {
    const sinDeclarar = variablesDe(datos.expresion).filter((v) => !(v in valores))

    if (sinDeclarar.length > 0) {
      return {
        ok: false,
        motivo: `La formula de esta forma necesita revision: nombra ${sinDeclarar.join(' y ')}, que no esta entre sus medidas. Avisá a quien administra las formas.`,
        ...vacio,
        valores,
      }
    }
  } catch {
    // No se puede ni leer: lo resuelve el paso siguiente.
  }

  // La formula devuelve el volumen de una sola pieza.
  let porPieza: number

  try {
    porPieza = resolver(datos.expresion, valores)
  } catch {
    return {
      ok: false,
      motivo:
        'La formula de esta forma necesita revision: no se pudo resolver. Avisá a quien administra las formas.',
      ...vacio,
      valores,
    }
  }

  if (!Number.isFinite(porPieza) || porPieza <= 0) {
    return { ok: false, motivo: 'Con esas medidas el volumen da cero', ...vacio, valores }
  }

  const pesoPorPieza = (porPieza * datos.densidad) / 1000

  return {
    ok: true,
    motivo: null,
    volumenPorPiezaCm3: redondear(porPieza, 4),
    volumenTotalCm3: redondear(porPieza * piezas, 4),
    pesoPorPiezaKg: redondear(pesoPorPieza, 4),
    pesoTotalKg: redondear(pesoPorPieza * piezas, 4),
    resultado: redondear(pesoPorPieza * piezas * (DESDE_KILOS[datos.unidadResultado] ?? 1), 4),
    valores,
  }
}

function juntarValores(datos: DatosDelCalculo): Record<string, number> {
  const valores: Record<string, number> = {}

  for (const campo of datos.campos) {
    const cargada = datos.medidas[campo.clave]
    valores[campo.clave] = cargada ? aMilimetros(cargada.valor, cargada.unidad) : 0
  }

  // El caño elegido pisa el diametro exterior y la pared.
  if (datos.usaCano && datos.cano) {
    valores.outer = Number(datos.cano.diametro_mm) || 0
    valores.wall = Number(datos.cano.pared_mm) || 0
  }

  return valores
}

function redondear(n: number, decimales: number): number {
  const f = 10 ** decimales

  return Math.round(n * f) / f
}

/* -------------------------------------------------------------- evaluador */

interface Token {
  tipo: 'numero' | 'nombre' | 'operador'
  valor: string | number
}

const FUNCIONES: Record<string, (...args: number[]) => number> = {
  abs: Math.abs,
  max: Math.max,
  min: Math.min,
  pow: Math.pow,
  sqrt: Math.sqrt,
}

/** Resuelve la formula. Tira error si tiene algo que no esta permitido. */
export function resolver(formula: string, valores: Record<string, number>): number {
  const tokens = separarEnTokens(formula)
  let posicion = 0

  const proximo = (): string | null => {
    const t = tokens[posicion]

    return t && t.tipo !== 'numero' ? String(t.valor) : null
  }

  const consumir = (): Token => {
    const t = tokens[posicion]

    if (!t) throw new Error('Formula incompleta')
    posicion += 1

    return t
  }

  const esperar = (que: string) => {
    if (proximo() !== que) throw new Error(`Falta "${que}" en la formula`)
    consumir()
  }

  const expresion = (): number => {
    let valor = termino()

    while (proximo() === '+' || proximo() === '-') {
      const op = consumir().valor
      const derecha = termino()
      valor = op === '+' ? valor + derecha : valor - derecha
    }

    return valor
  }

  const termino = (): number => {
    let valor = potencia()

    while (proximo() === '*' || proximo() === '/') {
      const op = consumir().valor
      const derecha = potencia()

      if (op === '/' && derecha === 0) throw new Error('La formula divide por cero')
      valor = op === '*' ? valor * derecha : valor / derecha
    }

    return valor
  }

  const potencia = (): number => {
    const valor = unario()

    return proximo() === '^' ? (consumir(), valor ** potencia()) : valor
  }

  const unario = (): number => {
    if (proximo() === '+') {
      consumir()

      return unario()
    }

    if (proximo() === '-') {
      consumir()

      return -unario()
    }

    return primario()
  }

  const primario = (): number => {
    const token = tokens[posicion]

    if (!token) throw new Error('Formula incompleta')

    if (token.tipo === 'numero') {
      consumir()

      return token.valor as number
    }

    if (token.tipo === 'nombre') return nombre()

    if (token.valor === '(') {
      consumir()
      const valor = expresion()
      esperar(')')

      return valor
    }

    throw new Error(`No se esperaba "${token.valor}"`)
  }

  const nombre = (): number => {
    const como = String(consumir().valor)

    if (proximo() === '(') {
      consumir()
      const argumentos: number[] = []

      if (proximo() !== ')') {
        for (;;) {
          argumentos.push(expresion())

          if (proximo() !== ',') break
          consumir()
        }
      }

      esperar(')')

      if (!FUNCIONES[como]) throw new Error(`Funcion no permitida: ${como}`)

      return FUNCIONES[como](...argumentos)
    }

    if (como === 'pi') return Math.PI

    return Number(valores[como] ?? 0)
  }

  const resultado = expresion()

  if (posicion < tokens.length) {
    throw new Error(`Formula invalida cerca de "${tokens[posicion].valor}"`)
  }

  return resultado
}

/**
 * Los nombres de medida que usa la fórmula.
 *
 * Es la misma cuenta que hace el servidor: sirve para avisar que la fórmula
 * nombra una medida que la forma no pide, en vez de tomarla como cero y
 * culpar a las medidas.
 */
export function variablesDe(formula: string): string[] {
  const tokens = separarEnTokens(formula)
  const nombres: string[] = []

  tokens.forEach((t, i) => {
    if (t.tipo !== 'nombre' || t.valor === 'pi') return
    // Si lo sigue un paréntesis es una función, no una medida.
    if (tokens[i + 1]?.valor === '(') return
    nombres.push(String(t.valor))
  })

  return [...new Set(nombres)]
}

function separarEnTokens(formula: string): Token[] {
  const texto = String(formula ?? '').replace(/\s+/g, '')
  const tokens: Token[] = []
  let i = 0

  while (i < texto.length) {
    const resto = texto.slice(i)

    const numero = resto.match(/^\d+(?:\.\d+)?/)

    if (numero) {
      tokens.push({ tipo: 'numero', valor: Number(numero[0]) })
      i += numero[0].length
      continue
    }

    const identificador = resto.match(/^[a-z][a-z0-9_]*/i)

    if (identificador) {
      tokens.push({ tipo: 'nombre', valor: identificador[0] })
      i += identificador[0].length
      continue
    }

    const caracter = texto[i]

    if ('+-*/^(),'.includes(caracter)) {
      tokens.push({ tipo: 'operador', valor: caracter })
      i += 1
      continue
    }

    throw new Error(`Caracter no permitido: ${caracter}`)
  }

  return tokens
}

/*
 * Las formulas pegadas de un Excel se normalizan y se validan EN EL SERVIDOR
 * (POST /formas/probar). Tener esas reglas tambien acá seria una segunda copia
 * que se desincroniza sola: la pantalla de administracion pregunta y muestra
 * lo que contesta el servidor.
 */

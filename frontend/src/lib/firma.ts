import type { DatosDeLaFirma, DatosEmpresa } from './indice'

const vacio = (v: unknown) => v === null || v === undefined || String(v).trim() === ''

/**
 * Lo que leyó el asistente de una firma, aplicado a una empresa que YA tiene
 * datos (Modificar).
 *
 * SOLO llena lo que está vacío: nunca pisa algo cargado, ni el país (en el alta
 * el país de la firma le gana al de por defecto; acá no, porque la ficha ya
 * tiene el suyo). Provincia y localidad van juntas: una localidad de otra
 * provincia dejaría la ficha incoherente. El nombre no se toca: es lo único
 * obligatorio y ya está. Nada se guarda hasta que la persona aprieta Guardar.
 */
export function llenarSoloVacios<T extends DatosEmpresa>(
  valores: T,
  datos: DatosDeLaFirma,
): { valores: T; completados: string[] } {
  const nuevos = { ...valores }
  const completados: string[] = []

  if (vacio(nuevos.cuit) && !vacio(datos.cuit)) {
    nuevos.cuit = datos.cuit
    completados.push('el CUIT')
  }
  if (vacio(nuevos.direccion) && !vacio(datos.direccion)) {
    nuevos.direccion = datos.direccion
    completados.push('la dirección')
  }
  if (vacio(nuevos.codigo_postal) && !vacio(datos.codigo_postal)) {
    nuevos.codigo_postal = datos.codigo_postal
    completados.push('el código postal')
  }
  if (!nuevos.pais_id && datos.pais_id) {
    nuevos.pais_id = datos.pais_id
    completados.push('el país')
  }

  if (!nuevos.provincia_id && !nuevos.localidad_id && datos.provincia_id) {
    nuevos.provincia_id = datos.provincia_id
    completados.push('la provincia')

    if (datos.localidad_id) {
      nuevos.localidad_id = datos.localidad_id
      completados.push('la localidad')
    }
  } else if (
    nuevos.provincia_id &&
    nuevos.provincia_id === datos.provincia_id &&
    !nuevos.localidad_id &&
    datos.localidad_id
  ) {
    nuevos.localidad_id = datos.localidad_id
    completados.push('la localidad')
  }

  return { valores: nuevos, completados }
}

/** Palabras que no distinguen una empresa de otra. */
const PALABRAS_COMUNES = ['srl', 'sas', 'sca', 'cia', 'ltda', 'hnos', 'sociedad', 'anonima', 'limitada', 'empresa', 'argentina']

const palabrasDe = (t: string) =>
  t
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .split(/[^a-z0-9]+/)
    .filter((p) => p.length >= 3 && !PALABRAS_COMUNES.includes(p))

/**
 * ¿La firma pegada parece de OTRA empresa? Si el nombre que leyó no comparte
 * ninguna palabra con el de la ficha, se avisa: completar la dirección de una
 * empresa con la de otra es el error que hay que evitar. Si no leyó ningún
 * nombre, no se puede saber y no se avisa.
 */
export function pareceDeOtraEmpresa(nombreDeLaFicha: string, nombreLeido: string | null): boolean {
  if (vacio(nombreLeido)) return false

  const ficha = palabrasDe(nombreDeLaFicha)
  const leido = palabrasDe(nombreLeido!)

  if (ficha.length === 0 || leido.length === 0) return false

  return !leido.some((p) => ficha.includes(p))
}

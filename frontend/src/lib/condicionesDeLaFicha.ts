import api from './api'
import { olvidarCatalogos } from './indice'

/*
  Configuración > Condiciones de la ficha: la lista de condiciones de trabajo ("Tipo de pago",
  "Flete") y los datos de cada una. De acá salen todas las fichas; cada cambio olvida los
  catálogos guardados para que la próxima ficha que se abra ya traiga la lista nueva.
*/

export interface DatoDeCondicion {
  id: number
  texto: string
  activo: boolean
  /** Cuántas fichas lo usan. */
  fichas: number
}

export interface CondicionAdmin {
  id: number
  nombre: string
  activo: boolean
  proponer_al_cotizar: boolean
  fichas: number
  opciones: DatoDeCondicion[]
}

export interface DatoSuelto {
  titulo: string
  valor: string
  n: number
}

export interface CatalogoDeCondiciones {
  tipos: CondicionAdmin[]
  /** Lo que está cargado en fichas pero no en la lista. */
  sueltos: { fichas: number; datos: DatoSuelto[] }
}

export async function traerCatalogoDeCondiciones() {
  const { data } = await api.get<CatalogoDeCondiciones>('/config/condiciones-de-la-ficha')

  return data
}

async function cambiar<T>(pedido: Promise<{ data: T }>): Promise<T> {
  const { data } = await pedido
  olvidarCatalogos()

  return data
}

export const agregarCondicion = (nombre: string) => cambiar(api.post('/config/condiciones-de-la-ficha', { nombre }))

export const cambiarCondicion = (id: number, cambios: Partial<Pick<CondicionAdmin, 'nombre' | 'activo' | 'proponer_al_cotizar'>>) =>
  cambiar(api.put(`/config/condiciones-de-la-ficha/${id}`, cambios))

export const unirCondiciones = (id: number, con: number) =>
  cambiar(api.post<{ fichas: number; mensaje: string }>(`/config/condiciones-de-la-ficha/${id}/unir`, { con }))

export const borrarCondicion = (id: number) => cambiar(api.delete(`/config/condiciones-de-la-ficha/${id}`))

export const ordenarCondiciones = (ids: number[]) => cambiar(api.post('/config/condiciones-de-la-ficha/orden', { ids }))

export const agregarDato = (condicionId: number, texto: string) =>
  cambiar(api.post(`/config/condiciones-de-la-ficha/${condicionId}/opciones`, { texto }))

export const cambiarDato = (id: number, cambios: Partial<Pick<DatoDeCondicion, 'texto' | 'activo'>>) =>
  cambiar(api.put(`/config/opciones-de-condicion/${id}`, cambios))

export const unirDatos = (id: number, con: number) =>
  cambiar(api.post<{ fichas: number; mensaje: string }>(`/config/opciones-de-condicion/${id}/unir`, { con }))

export const borrarDato = (id: number) => cambiar(api.delete(`/config/opciones-de-condicion/${id}`))

/** Un dato que estaba en fichas pero no en la lista: se suma a la lista y se vincula. */
export const adoptarDato = (titulo: string, valor: string) =>
  cambiar(api.post<{ fichas: number; mensaje: string }>('/config/condiciones-de-la-ficha/adoptar', { titulo, valor }))

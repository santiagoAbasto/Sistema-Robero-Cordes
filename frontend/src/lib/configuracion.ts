import api from './api'
import { olvidarCatalogos } from './indice'

/*
  Las listas y los juegos de condiciones que la empresa edita en Configuracion
  (lista de ajustes del cliente, 14-10-2026). Cada cambio olvida los catalogos
  guardados: la proxima cotizacion que se abra ya trae la lista nueva.
*/

export type NombreDeLista = 'caracteristicas' | 'motivos_cambio' | 'motivos_no_cotiza'

export interface ItemDeLista {
  id: number
  nombre: string
  orden: number
  activo: boolean
}

export type Listas = Record<NombreDeLista, ItemDeLista[]>

export async function traerListas() {
  const { data } = await api.get<Listas>('/config/listas')

  return data
}

export async function agregarALista(lista: NombreDeLista, nombre: string) {
  await api.post(`/config/listas/${lista}`, { nombre })
  olvidarCatalogos()
}

export async function cambiarItem(lista: NombreDeLista, id: number, cambios: Partial<Pick<ItemDeLista, 'nombre' | 'activo'>>) {
  await api.put(`/config/listas/${lista}/${id}`, cambios)
  olvidarCatalogos()
}

export async function ordenarLista(lista: NombreDeLista, ids: number[]) {
  await api.post(`/config/listas/${lista}/orden`, { ids })
  olvidarCatalogos()
}

export async function borrarItem(lista: NombreDeLista, id: number) {
  await api.delete(`/config/listas/${lista}/${id}`)
  olvidarCatalogos()
}

export interface CondicionHabitualAdmin {
  id: number
  juego: string
  titulo: string | null
  texto: string
  orden: number
  activo: boolean
  por_defecto: boolean
}

export async function traerCondiciones() {
  const { data } = await api.get<{ juegos: string[]; condiciones: CondicionHabitualAdmin[] }>('/config/condiciones')

  return data
}

export async function guardarCondicion(
  datos: Pick<CondicionHabitualAdmin, 'juego' | 'titulo' | 'texto' | 'activo' | 'por_defecto'>,
  id?: number,
) {
  if (id) await api.put(`/config/condiciones/${id}`, datos)
  else await api.post('/config/condiciones', datos)
  olvidarCatalogos()
}

export async function ordenarCondiciones(ids: number[]) {
  await api.post('/config/condiciones/orden', { ids })
  olvidarCatalogos()
}

export async function borrarCondicion(id: number) {
  await api.delete(`/config/condiciones/${id}`)
  olvidarCatalogos()
}

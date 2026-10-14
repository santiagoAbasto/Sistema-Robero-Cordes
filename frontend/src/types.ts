import type { LucideIcon } from 'lucide-react'

export interface User {
  id: number
  name: string
  email: string
  role: string
  initials: string
}

export interface NavLeaf {
  key: string
  label: string
  to: string
  badge?: string
}

export interface NavNode {
  key: string
  label: string
  icon: LucideIcon
  to?: string
  badge?: string
  children?: NavLeaf[]
  /**
   * Sus pantallas se abren en otra pestaña. Configuracion (lista del cliente,
   * 14-10-2026): se entra a corregir una lista en medio de una cotizacion, y
   * en la misma pestaña se perdia lo que se estaba cargando.
   */
  nuevaPestana?: boolean
}

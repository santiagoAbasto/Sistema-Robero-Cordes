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
  /** Qué hay adentro, en una línea: sale al pasar el mouse y sirve para buscar. */
  ayuda?: string
}

export interface NavNode {
  key: string
  label: string
  icon: LucideIcon
  to?: string
  badge?: string
  children?: NavLeaf[]
}

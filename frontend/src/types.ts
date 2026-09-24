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
}

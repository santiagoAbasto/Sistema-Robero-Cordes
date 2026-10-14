import {
  LayoutGrid,
  Briefcase,
  Package,
  FileText,
  ShoppingCart,
  Boxes,
  Mail,
  Upload,
  Zap,
  BarChart3,
  SlidersHorizontal,
} from 'lucide-react'
import type { NavNode } from '../types'

/**
 * Sidebar tree — mirrors the CORDES Figma. Top-level entries with `children`
 * render as expandable groups; entries with `to` are direct links.
 */
export const NAVIGATION: NavNode[] = [
  { key: 'dashboard', label: 'Dashboard', icon: LayoutGrid, to: '/dashboard' },
  {
    key: 'empresas',
    label: 'Empresas',
    icon: Briefcase,
    children: [
      // Los nombres son los mismos del Indice Telefonico que ya usan.
      { key: 'indice', label: 'Indice Telefonico', to: '/empresas' },
      { key: 'registros', label: 'Cons./Modif. Registros', to: '/empresas/registros' },
      { key: 'condicion', label: 'Busq. Registros p/Cond.', to: '/empresas/condicion' },
      { key: 'cons_fecha', label: 'Consultas por fecha', to: '/consultas/fecha' },
      { key: 'cons_cond', label: 'Busq. Consultas p/Cond.', to: '/consultas/condicion' },
      { key: 'imprimir', label: 'Imprimir Indice / Cons.', to: '/imprimir' },
    ],
  },
  {
    key: 'materiales',
    label: 'Materiales',
    icon: Package,
    children: [
      { key: 'catalogo', label: 'Catálogo', to: '/materiales' },
      { key: 'familias', label: 'Familias', to: '/materiales/familias' },
      { key: 'formas', label: 'Formas', to: '/materiales/formas' },
    ],
  },
  {
    key: 'cotizaciones',
    label: 'Cotizaciones',
    icon: FileText,
    children: [
      { key: 'seguimiento', label: 'Vencimientos y seguimiento', to: '/cotizaciones/seguimiento' },
      { key: 'cot_cli', label: 'A clientes', to: '/cotizaciones/clientes' },
      { key: 'cot_prov', label: 'De proveedores', to: '/cotizaciones/proveedores' },
      { key: 'buscar', label: 'Buscar material', to: '/cotizaciones/buscar' },
      { key: 'requerimiento', label: 'Requerimiento', to: '/cotizaciones/requerimiento' },
      { key: 'despiece', label: 'Despiece', to: '/cotizaciones/despiece' },
      { key: 'mezcla', label: 'Mezcla x cont.', to: '/cotizaciones/mezcla' },
      { key: 'comparativa', label: 'Comparativa', to: '/cotizaciones/comparativa' },
      { key: 'comparador', label: 'Comparador', to: '/cotizaciones/comparador' },
      { key: 'costeo', label: 'Costeo', to: '/cotizaciones/costeo' },
      { key: 'variantes', label: 'Variantes', to: '/cotizaciones/variantes' },
    ],
  },
  {
    key: 'compras',
    label: 'Compras',
    icon: ShoppingCart,
    children: [
      { key: 'ordenes', label: 'Órdenes de compra', to: '/compras/ordenes' },
      { key: 'seguimiento', label: 'Seguimiento OC', to: '/compras/seguimiento' },
      { key: 'pagar', label: 'A pagar', to: '/compras/pagar' },
      { key: 'facturas', label: 'Facturas y remitos', to: '/compras/facturas' },
    ],
  },
  {
    key: 'stock',
    label: 'Stock',
    icon: Boxes,
    children: [
      { key: 'existencias', label: 'Existencias', to: '/stock' },
      { key: 'transito', label: 'En tránsito', to: '/stock/transito' },
      { key: 'produccion', label: 'Producción', to: '/stock/produccion' },
      { key: 'movimientos', label: 'Movimientos', to: '/stock/movimientos' },
    ],
  },
  {
    key: 'correos',
    label: 'Correos',
    icon: Mail,
    children: [
      { key: 'bandeja', label: 'Bandeja inteligente', to: '/correos/bandeja' },
      { key: 'cuentas', label: 'Cuentas conectadas', to: '/correos/cuentas' },
      { key: 'adjuntos', label: 'Adjuntos', to: '/correos/adjuntos' },
    ],
  },
  {
    key: 'importaciones',
    label: 'Importaciones',
    icon: Upload,
    children: [
      { key: 'importar', label: 'Importar Excel', to: '/importaciones/importar' },
      { key: 'historial', label: 'Historial', to: '/importaciones/historial' },
    ],
  },
  { key: 'ia', label: 'Buscador IA', icon: Zap, to: '/ia', badge: 'IA' },
  { key: 'reportes', label: 'Reportes', icon: BarChart3, to: '/reportes' },
  {
    key: 'config',
    label: 'Configuración',
    icon: SlidersHorizontal,
    /*
      Los nombres dicen qué hay adentro (14-10-2026): "Listas" o "Formas y
      formulas" no le decían nada al equipo. Primero lo que se toca al
      cotizar, después la gente y la empresa, al final la importación.
    */
    children: [
      {
        key: 'condiciones',
        label: 'Condiciones de cotización',
        to: '/config/condiciones',
        ayuda: 'Los párrafos de Importación, Stock y Reventa que salen al pie de la cotización',
      },
      {
        key: 'listas',
        label: 'Características y motivos',
        to: '/config/listas',
        ayuda: 'Lo que se elige al cotizar: características, motivos de cambio y de "no se cotiza"',
      },
      {
        key: 'formas',
        label: 'Formas y cálculo de peso',
        to: '/config/formas',
        ayuda: 'Qué medidas pide cada forma y con qué fórmula se calcula el peso. Se baja y se sube en Excel',
      },
      {
        key: 'usuarios',
        label: 'Usuarios y permisos',
        to: '/config/usuarios',
        ayuda: 'Quién entra al sistema y qué puede ver cada uno',
      },
      {
        key: 'empresa',
        label: 'Datos de nuestra empresa',
        to: '/config/empresa',
        ayuda: 'Los datos de Roberto Cordes S.A. que salen en las cotizaciones',
      },
      {
        key: 'config_ia',
        label: 'Lectura con IA',
        to: '/config/ia',
        ayuda: 'La inteligencia artificial que lee pedidos e imágenes',
      },
      {
        key: 'migracion',
        label: 'Importar sistema anterior',
        to: '/config/migracion',
        ayuda: 'Trae las empresas, las cotizaciones y los materiales del Access',
      },
      // TEMPORAL: para revisar la carga. Se saca cuando CORDES dé el visto bueno.
      {
        key: 'antes_y_ahora',
        label: 'Revisar lo importado',
        to: '/config/antes-y-ahora',
        ayuda: 'Cada ficha del sistema anterior al lado de cómo quedó en este',
      },
    ],
  },
]

/** Flattened list of every routable leaf, used to register routes. */
export const NAV_ROUTES: { to: string; label: string; parent?: string; ayuda?: string }[] = NAVIGATION.flatMap(
  (node) => {
    if (node.children) {
      return node.children.map((c) => ({ to: c.to, label: c.label, parent: node.label, ayuda: c.ayuda }))
    }
    return node.to ? [{ to: node.to, label: node.label }] : []
  },
)

/* Tipos del Índice Telefónico — reflejan lo que devuelve la API. */

export type Relacion = 'Cliente' | 'Proveedor' | 'Servicio' | 'Empleado' | 'Agenda general'

export type TipoConsulta = 'Cotizacion' | 'Pedido' | 'Observacion'

export type EstadoConsulta =
  | 'Borrador'
  | 'Sin cotizar'
  | 'Confirmada'
  | 'Vendida'
  | 'Cerrada por declinacion'
  | 'Vencida por tiempo'

export interface Medio {
  id: number
  valor: string
  principal: boolean
  nota: string | null
}

export interface Contacto {
  id: number
  nombre: string
  sector: string | null
  cargo: string | null
  principal: boolean
  activo: boolean
  observacion: string | null
  telefonos: Medio[]
  whatsapps: Medio[]
  mails: Medio[]
}

export interface RazonSocial {
  id: number
  razon_social: string
  cuit: string
  condicion_iva: string | null
  iibb_condicion: string | null
  iibb_provincia_sede: string | null
  iibb_numero: string | null
  inicio_actividades: string | null
  direccion_fiscal: string | null
  localidad: string | null
  habitual: boolean
  activa: boolean
}

export interface CampoEmpresa {
  id: number
  titulo: string
  valor: string | null
  usar_al_cotizar: boolean
}

export interface LineaPedida {
  material: string | null
  forma: string | null
  dimensiones: string | null
  cantidad: string | null
  unidad: string | null
  /** El código es para mostrar; el id es el que elige el desplegable al abrir. */
  unidad_id: number | null
  texto: string | null
}

/** Por qué una alternativa es distinta. */
export const TIPOS_DE_OPCION = ['Transporte', 'Cantidad', 'Material', 'Plazo', 'Otra'] as const
export type TipoDeOpcion = (typeof TIPOS_DE_OPCION)[number]

/**
 * Una alternativa de la línea: el mismo ítem cotizado de otra manera.
 *
 * Aérea o marítima, por tramos de cantidad, con otro material. Pisa solo lo que
 * cambia; lo que deja vacío lo hereda de la línea. La base es la que cuenta
 * para el total de la cotización.
 */
export interface OpcionDeLinea {
  id: number
  etiqueta: string
  tipo: TipoDeOpcion
  es_base: boolean
  cantidad: string | null
  precio_unitario: string | null
  precio_por_kilo: string | null
  plazo_dias: number | null
  material_id: number | null
  material: string | null
  descripcion: string | null
  nota: string | null
  /** Ya resueltas contra la línea. */
  cantidad_final: number | null
  precio_final: number | null
  importe: number
}

export interface ConsultaLinea {
  id: number
  orden: number
  material: string | null
  material_id: number | null
  forma: string | null
  forma_id: number | null
  dimensiones: string | null
  descripcion: string
  /** El codigo con el que el cliente llama a este articulo. */
  codigo_cliente: string | null
  /** El numero de item dentro del requerimiento del cliente. */
  item_cliente: string | null
  /** Datos extra del articulo (plano, posicion). Sale impresa, a diferencia
   *  de la NOTA de la cotizacion, que es interna. */
  nota: string | null
  /** El check: si se cotiza tal cual lo pidieron. */
  igual_a_lo_pedido: boolean
  motivo_cambio: string | null
  pedido: LineaPedida | null
  cantidad: string | null
  unidad: string | null
  unidad_factura: string | null
  /** El código es para mostrar; el id es el que elige el desplegable al abrir. */
  unidad_venta_id: number | null
  unidad_factura_id: number | null
  factor_conversion: string | null
  /** Si el factor lo calculó el sistema según la forma, o lo pusieron a mano. */
  factor_calculado: boolean
  /**
   * De dónde salió el factor. Lo decide el servidor por la operación que se
   * usó, no comparando el valor con el que da la fórmula: que un número
   * coincida no prueba de dónde vino.
   */
  origen_factor: 'calculadora' | 'manual' | null
  /** Cuando se cargó a mano: quién lo puso y cuándo. Vacío si es el calculado. */
  factor_cargado_por: string | null
  factor_cargado_el: string | null
  diametro_mm: string | null
  espesor_mm: string | null
  ancho_mm: string | null
  largo_mm: string | null
  /** Lo que pesa lo cotizado, si se calculó. */
  peso_kg: string | null
  /** Alternativas: el mismo item cotizado de otra manera. */
  opciones: OpcionDeLinea[]
  /** La foto del calculo: medidas, densidad y formula del dia que se cotizo. */
  calculo: CalculoDePeso | null
  cantidad_facturar: string | null
  /** Se cotiza en una unidad y se factura en otra (por metro, a facturar por kilo). */
  cambia_de_unidad: boolean
  precio_unitario: string | null
  precio_por_kilo: string | null
  importe: string | null
  aprox: boolean
  idem: boolean
  quitada: boolean
  desde_stock: boolean
  deposito: string | null
  colada: string | null
}

export interface Observacion {
  id: number
  numero: number
  fecha: string
  quien: string | null
  texto: string
}

export interface Impresion {
  id: number
  fecha: string
  nombre_en_pdf: string
  contacto: string | null
  telefono: string | null
  mail: string | null
  via: string
  quien: string | null
  vencida_al_mandar: boolean
}

/**
 * Una condición de la cotización.
 *
 * Los bloques que manda CORDES llevan título ("Entrega", "Forma de Pago") y
 * pasan los 700 caracteres. Las sueltas de una línea siguen valiendo: van sin
 * título y en la hoja salen como viñeta.
 */
export const JUEGOS_DE_CONDICIONES = ['Importacion', 'Stock'] as const

/**
 * Los dos juegos de condiciones.
 *
 * Importación y stock difieren en el plazo de entrega —120 días corridos
 * contra 2 a 4 hábiles— y en la forma de pago. No hay uno por defecto: elegir
 * mal manda la oferta con las condiciones del otro caso.
 */
export type JuegoDeCondiciones = (typeof JUEGOS_DE_CONDICIONES)[number]

export interface CondicionDeCotizacion {
  id: number
  titulo: string | null
  texto: string
  /**
   * Si sale en la hoja del cliente.
   *
   * Algunas líneas son referencias internas —"1RA FILA X 1.10" y parecidas,
   * que la empresa usa para cruzar con su otro sistema—: se ven en pantalla
   * pero no se imprimen.
   */
  imprime: boolean
  origen: string
}

/** Una condición de la lista, lista para agregar a una cotización. */
export interface CondicionHabitual {
  id: number
  titulo: string | null
  /** Importacion o Stock. Null: sirve para los dos. */
  juego: JuegoDeCondiciones | null
  texto: string
  /** Las que entran solas en cada cotización nueva. */
  por_defecto: boolean
}

export interface Consulta {
  id: number
  tipo: TipoConsulta
  fecha: string
  validez_dias: number
  vence_el: string | null
  esta_vencida: boolean
  /** Cuantos dias faltan. Negativo si ya vencio, null si no tiene vencimiento. */
  dias_para_vencer: number | null
  /** Notas del hilo mas envios de la hoja. Solo viene en las listas. */
  seguimientos?: number
  estado: EstadoConsulta
  empresa?: { id: number; nombre: string }
  contacto?: { id: number; nombre: string; sector: string | null } | null
  razon_social?: string | null
  quien_lo_hizo?: string | null
  moneda?: string | null
  tipo_cambio: string | null
  ajuste_dif_cambio: boolean
  ajuste_dif_cambio_detalle: string | null
  nro_factura: string | null
  id_sistema: string | null
  condicion_pago: string | null
  lista_precios: string | null
  nota: string | null
  texto: string | null
  solicitud: { via: string | null; fecha: string | null; texto: string | null } | null
  copiada_de?: { id: number; empresa: string | null; fecha: string | null } | null
  total: number
  /** Sólo cuando hay líneas que se facturan por peso. */
  total_kilos: number | null
  lineas_iguales_a_lo_pedido: string | null
  lineas?: ConsultaLinea[]
  juego_condiciones: JuegoDeCondiciones | null
  condiciones?: CondicionDeCotizacion[]
  observaciones?: Observacion[]
  impresiones?: Impresion[]
}

export interface EmpresaLista {
  id: number
  nombre: string
  codigo_indice: string | null
  codigo_isis: string | null
  cuit: string | null
  relaciones: Relacion[]
  localidad: string | null
  provincia: string | null
  rubro: string | null
  contacto_principal: { nombre: string; sector: string | null } | null
  otros_contactos: number
  tambien_factura_como: string | null
  otras_razones: number
  razon_social_habitual: string | null
  cotizaciones: number
  activa: boolean
}

export interface Enlace {
  id: number
  tipo: string
  url: string
  url_completa: string
  etiqueta: string | null
}

export interface Empresa {
  id: number
  nombre: string
  codigo_indice: string | null
  codigo_isis: string | null
  cuit: string | null
  direccion: string | null
  localidad: string | null
  localidad_id: number | null
  provincia: string | null
  provincia_id: number | null
  pais: string | null
  pais_id: number | null
  codigo_postal: string | null
  rubro: string | null
  rubro_id: number | null
  activa: boolean
  visible_para: string
  relaciones: { id: number; relacion: Relacion; desde: string | null; activa: boolean }[]
  observacion_general: string | null
  enlaces: Enlace[]
  /** Se arma con la dirección cargada. */
  mapa: string | null
  contactos: Contacto[]
  razones_sociales: RazonSocial[]
  campos: CampoEmpresa[]
  cotizaciones: Consulta[]
  pedidos: Consulta[]
  observaciones_empresa: Consulta[]
}

export interface Forma {
  id: number
  /** El nombre corto y estable: "barra_redonda". El visible puede cambiar. */
  clave: string
  nombre: string
  medidas_habituales: string | null
  medidas_necesarias: string | null
  /** Las medidas que pide esta forma, con el nombre que usa la formula. */
  campos: { clave: string; label: string }[] | null
  /** La cuenta que da el volumen en cm3. Null: no tiene calculo automatico. */
  expresion: string | null
  usa_cano: boolean
}

export interface CanoEstandar {
  id: number
  nombre: string
  schedule: string
  diametro_mm: string
  pared_mm: string
}

/**
 * La foto del calculo que quedo guardada con la linea.
 *
 * Guarda con que se calculo, no solo el resultado: si mañana se corrige una
 * densidad o una formula, esta linea sigue diciendo lo que dijo el dia que se
 * cotizo. Nunca se recalcula.
 */
export interface CalculoDePeso {
  ok: boolean
  motivo: string | null
  material_id: number | null
  material: string | null
  densidad_g_cm3: number | null
  uns: string | null
  w_nr: string | null
  forma_id: number | null
  forma: string | null
  formula: string | null
  cano: string | null
  cano_id: number | null
  medidas: Record<string, { label: string; valor: number; unidad: string; valor_mm: number }>
  piezas: number
  /** Lo que da la formula para UNA pieza. */
  volumen_por_pieza_cm3: number | null
  /** El unitario por la cantidad de piezas. */
  volumen_total_cm3: number | null
  peso_por_pieza_kg: number | null
  peso_total_kg: number | null
  /** El peso total en la unidad que eligieron (kg, g, lb, ton). */
  resultado: number | null
  unidad_resultado: string
}

export interface Catalogos {
  relaciones: Relacion[]
  motivos_cambio: string[]
  tipos_consulta: TipoConsulta[]
  estados: EstadoConsulta[]
  solicitud_vias: string[]
  condiciones_iva: string[]
  iibb_condiciones: string[]
  vias_envio: string[]
  ajuste_dif_cambio: string[]
  validez_por_defecto: number
  /** Si hay credencial de IA cargada. */
  ia_activa: boolean
  /** Si los predictivos de direccion estan configurados. */
  direcciones_activas: boolean
  rubros: { id: number; nombre: string }[]
  formas: Forma[]
  materiales: {
    id: number
    nombre: string
    familia: string | null
    densidad: string | null
    uns: string | null
    w_nr: string | null
  }[]
  /** Caños de medida comercial: al elegir uno se completan diametro y pared. */
  canos: CanoEstandar[]
  unidades: {
    id: number
    codigo: string
    nombre: string
    sirve_para_vender: boolean
    sirve_para_facturar: boolean
  }[]
  monedas: {
    id: number
    nombre: string
    moneda_base: string
    referencia: string | null
    por_defecto: boolean
  }[]
  moneda_por_defecto: number | null
  condiciones_pago: { id: number; nombre: string }[]
  tipos_enlace: string[]
  paises: { id: number; nombre: string }[]
  tipos_medio: { id: number; nombre: string }[]
  provincias: { id: number; nombre: string }[]
  localidades: { id: number; nombre: string; provincia_id: number }[]
  condiciones_habituales: CondicionHabitual[]
  usuarios: { id: number; name: string; iniciales: string | null; role: string }[]
}

/** Cotizaciones relacionadas por cómo se generó ésta, no por parecido. */
export interface ConsultaHermana {
  id: number
  empresa: string | null
  empresa_id: number
  fecha: string | null
  estado: string
  quien: string | null
  total: number
  lineas: number
}

export interface Relacionadas {
  madre: ConsultaHermana | null
  hermanas: ConsultaHermana[]
  hijas: ConsultaHermana[]
}

export interface LineaInterpretada {
  descripcion: string
  material_id: number | null
  material: string | null
  forma_id: number | null
  forma: string | null
  dimensiones: string | null
  diametro_mm: number | null
  espesor_mm: number | null
  ancho_mm: number | null
  largo_mm: number | null
  cantidad: number | null
  unidad_venta_id: number | null
  unidad: string | null
  /** Lo que el cliente escribió como material, antes de buscarlo en el catálogo. */
  pedido_material: string | null
  /** Falso cuando no se reconoció el material: hay que mirarlo antes de mandar. */
  igual_a_lo_pedido: boolean
  /**
   * Las variantes que pidió el cliente en su mail, ya armadas: aérea y
   * marítima, tramos de cantidad, dos materiales a comparar. Vienen sin precio.
   */
  alternativas: {
    etiqueta: string
    tipo: string
    es_base: boolean
    cantidad: number | null
    precio_unitario: number | null
    plazo_dias: number | null
    material_id: number | null
  }[]
}

export interface Paginado<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page: number }
}

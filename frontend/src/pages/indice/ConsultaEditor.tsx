import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Calculator, Check, Eye, EyeOff, Lock, Plus, Ruler, Trash2, Wand2, Scale } from 'lucide-react'
import {
  Accion,
  Aviso,
  Boton,
  Card,
  CardHeader,
  Cargando,
  Chip,
  NotaPie,
  PageHeader,
  SinResultados,
} from '../../components/ui'
import { AreaTexto, Casilla, Combo, Etiqueta, Guardado, Lista, Texto } from '../../components/ui/form'
import {
  actualizarConsulta,
  agregarObservacion,
  cantidad as fmtCantidad,
  crearConsulta,
  emitirConsulta,
  fecha as fmtFecha,
  mensajeDeError,
  plata,
  interpretarTexto,
  nuevaRevision,
  traerConsulta,
  traerEmpresa,
  useCarga,
  useCatalogos,
  hoy,
} from '../../lib/indice'
import { JUEGOS_DE_CONDICIONES } from '../../types/indice'
import type { CanoEstandar, JuegoDeCondiciones } from '../../types/indice'
import { traerLaHoja } from '../../lib/indice'
import type { DatosLinea } from '../../lib/indice'

/** Una condición mientras se edita: título separado del texto. */
type CondicionForm = {
  titulo: string
  texto: string
  /** Si sale en la hoja del cliente. Las internas no. */
  imprime: boolean
  /**
   * El texto tal como viene de la lista, antes de completarle la fecha.
   *
   * Se guarda para poder rehacer la condición cuando cambian los días de
   * validez. En cuanto alguien la edita a mano deja de rehacerse: lo escrito
   * por una persona manda.
   */
  plantilla?: string
  aMano?: boolean
}

/** Completa la fecha hasta la que vale la oferta. */
function conLaFecha(texto: string, vence: string): string {
  if (!texto.includes('{vence}')) return texto

  if (vence) return texto.replaceAll('{vence}', vence)

  // Sin fecha todavía: se saca el hueco y la frase arranca en mayúscula.
  const limpio = texto.replaceAll('{vence}', '').replace(/^[,\s]+/, '')

  return limpio.charAt(0).toUpperCase() + limpio.slice(1)
}
import Relacionadas from './Relacionadas'
import CalculadoraDePeso, {
  calculadoraVacia,
  schedulesAgrupados,
  type EstadoCalculadora,
} from '../../components/CalculadoraDePeso'
import AlternativasDeLinea from '../../components/AlternativasDeLinea'
import { ModalContacto } from './modales'
import Revisiones from './Revisiones'
import Versiones from './Versiones'
import HojaPrevia from './HojaPrevia'
import { A_MILIMETROS, UNIDADES_MEDIDA, aMilimetros, calcularPeso } from '../../lib/calculadora'
import { armarDescripcion, armarDimensiones } from '../../lib/descripcion'
import type { Catalogos, Consulta, ConsultaLinea, Empresa, Forma } from '../../types/indice'

/* ---------------------------------------------------------------------------
   Carga y modificación de una cotización, un pedido o una observación.
--------------------------------------------------------------------------- */

/**
 * Los pasos de un borrador, en el orden de la hoja: "lo que pidio el cliente
 * debajo del encabezado y arriba de las lineas". La vista previa es el ultimo:
 * es como va a salir.
 */
type Paso = 'encabezado' | 'pedido' | 'lineas' | 'condiciones' | 'hoja'

const PASOS: { clave: Paso; titulo: string }[] = [
  { clave: 'encabezado', titulo: 'Encabezado' },
  { clave: 'pedido', titulo: 'Lo que pidió' },
  { clave: 'lineas', titulo: 'Líneas' },
  { clave: 'condiciones', titulo: 'Condiciones' },
  { clave: 'hoja', titulo: 'Vista previa' },
]

interface LineaForm extends DatosLinea {
  clave: string
  /** Lo cargado en la calculadora de peso de esta linea. */
  calc: EstadoCalculadora
  /**
   * Si el factor de esta linea lo puso una persona en vez de la cuenta, quién
   * y cuándo. Lo decide el servidor comparando contra lo que da la fórmula.
   */
  factorAMano?: { quien: string | null; cuando: string | null } | null
  /**
   * Si la medida y la descripción las escribió una persona.
   *
   * Mientras las arma el sistema se van actualizando con lo que se carga. En
   * cuanto alguien las corrige dejan de pisarse: la descripción es lo que sale
   * impreso y manda quien cotiza.
   */
  dimensionesAMano?: boolean
  descripcionAMano?: boolean
}

function lineaVacia(): LineaForm {
  return {
    clave: crypto.randomUUID(),
    descripcion: '',
    codigo_cliente: null,
    item_cliente: null,
    nota: null,
    igual_a_lo_pedido: true,
    cantidad: null,
    precio_unitario: null,
    calc: calculadoraVacia(),
  }
}

/**
 * Vuelve a poner en la calculadora lo que se habia cargado la vez anterior.
 *
 * Si la linea nunca pasó por la calculadora —las traidas del sistema anterior
 * no tienen foto del calculo— se arma con las medidas sueltas de la linea. Sin
 * esto, una cotizacion vieja se abria con el material y la forma puestos y las
 * medidas en blanco, y habia que volver a tipearlas mirando la descripcion.
 */
function calcGuardada(l: ConsultaLinea, forma: Forma | null): EstadoCalculadora {
  const guardado = l.calculo

  if (!guardado?.medidas) {
    const sueltas = medidasDeLaLinea(
      {
        diametro_mm: l.diametro_mm ? Number(l.diametro_mm) : null,
        espesor_mm: l.espesor_mm ? Number(l.espesor_mm) : null,
        ancho_mm: l.ancho_mm ? Number(l.ancho_mm) : null,
        largo_mm: l.largo_mm ? Number(l.largo_mm) : null,
      },
      forma,
    )

    const hayAlguna = Object.values(sueltas).some((m) => m.valor !== '')

    return hayAlguna
      ? { ...calculadoraVacia(), medidas: sueltas, piezas: String(l.cantidad ?? 1) }
      : calculadoraVacia()
  }

  const medidas: EstadoCalculadora['medidas'] = {}

  for (const [clave, m] of Object.entries(guardado.medidas)) {
    medidas[clave] = { valor: String(m.valor ?? ''), unidad: m.unidad ?? 'mm' }
  }

  return {
    medidas,
    piezas: String(guardado.piezas ?? 1),
    unidadResultado: guardado.unidad_resultado ?? 'kg',
    canoId: guardado.cano_id ?? null,
  }
}

function desdeConsulta(c: Consulta, catalogos: Catalogos | null): LineaForm[] {
  return (c.lineas ?? []).map((l) => {
    const forma = catalogos?.formas.find((f) => f.id === l.forma_id) ?? null
    const calc = calcGuardada(l, forma)
    const cano = catalogos?.canos?.find((x) => x.id === calc.canoId) ?? null

    // Se deduce si el texto guardado lo escribió una persona: si coincide con
    // lo que armaría el sistema, nadie lo tocó.
    const dimArmada = armarDimensiones(forma, calc.medidas, cano)
    const descArmada = armarDescripcion(l.material, forma, dimArmada)

    return {
    clave: String(l.id),
    descripcion: l.descripcion,
    codigo_cliente: l.codigo_cliente,
    item_cliente: l.item_cliente,
    nota: l.nota,
    material_id: l.material_id,
    forma_id: l.forma_id,
    dimensiones: l.dimensiones,
    cantidad: l.cantidad ? Number(l.cantidad) : null,
    // Las que quedaron guardadas. Estaban fijas en null: el desplegable abría
    // en "Sin elegir" aunque la línea tuviera su unidad, y como al guardar se
    // manda lo que muestra la pantalla, volver a guardar se la borraba.
    unidad_venta_id: l.unidad_venta_id ?? null,
    unidad_factura_id: l.unidad_factura_id ?? null,
    factor_conversion: l.factor_conversion ? Number(l.factor_conversion) : null,
    precio_unitario: l.precio_unitario ? Number(l.precio_unitario) : null,
    precio_por_kilo: l.precio_por_kilo ? Number(l.precio_por_kilo) : null,
    igual_a_lo_pedido: l.igual_a_lo_pedido,
    motivo_cambio: l.motivo_cambio,
    pedido_material: l.pedido?.material ?? null,
    pedido_forma: l.pedido?.forma ?? null,
    pedido_dimensiones: l.pedido?.dimensiones ?? null,
    pedido_medidas: l.pedido?.medidas ?? null,
    cantidad_pedida: l.pedido?.cantidad ? Number(l.pedido.cantidad) : null,
    unidad_pedida_id: l.pedido?.unidad_id ?? null,
    /*
      Marcas y datos de stock. Llegaban de la API y el formulario no las
      levantaba: no se borraban al guardar —el servidor conserva lo que no
      viene— pero quien editaba no las veia ni las podia corregir.
    */
    aprox: l.aprox ?? false,
    idem: l.idem ?? false,
    desde_stock: l.desde_stock ?? false,
    deposito: l.deposito ?? null,
    colada: l.colada ?? null,
    quitada: l.quitada,
    diametro_mm: l.diametro_mm ? Number(l.diametro_mm) : null,
    espesor_mm: l.espesor_mm ? Number(l.espesor_mm) : null,
    ancho_mm: l.ancho_mm ? Number(l.ancho_mm) : null,
    largo_mm: l.largo_mm ? Number(l.largo_mm) : null,
    largo_min_mm: l.largo_min_mm ? Number(l.largo_min_mm) : null,
    largo_max_mm: l.largo_max_mm ? Number(l.largo_max_mm) : null,
    calc,
    dimensionesAMano: Boolean(l.dimensiones) && l.dimensiones !== dimArmada,
    descripcionAMano: Boolean(l.descripcion) && l.descripcion !== descArmada,
    // Al reabrir, la línea llega tal cual quedó guardada: si se guarda sin
    // tocar el factor, conserva su procedencia.
    aplicar_calculo_al_factor: false,
    factorAMano:
      l.origen_factor === 'manual'
        ? { quien: l.factor_cargado_por, cuando: l.factor_cargado_el }
        : null,
    opciones: (l.opciones ?? []).map((o) => ({
      etiqueta: o.etiqueta,
      tipo: o.tipo,
      es_base: o.es_base,
      cantidad: o.cantidad ? Number(o.cantidad) : null,
      precio_unitario: o.precio_unitario ? Number(o.precio_unitario) : null,
      precio_por_kilo: o.precio_por_kilo ? Number(o.precio_por_kilo) : null,
      plazo_dias: o.plazo_dias,
      material_id: o.material_id,
      descripcion: o.descripcion,
      nota: o.nota,
      })),
    }
  })
}

export default function ConsultaEditor() {
  const { id, consultaId } = useParams<{ id?: string; consultaId?: string }>()
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const catalogos = useCatalogos()

  const esNueva = !consultaId

  const { datos: existente, cargando: cargandoConsulta, recargar } = useCarga(
    () => (consultaId ? traerConsulta(consultaId) : Promise.resolve(null)),
    [consultaId],
  )

  // Emitida es que ya salió: se lee, no se edita. Para cambiarla, una revisión.
  const emitida = existente?.emitida ?? false

  /*
    Que se muestra: la hoja, o los datos cargados.

    Una emitida abre en la hoja —es lo que tiene el cliente, y ya no hay nada
    que cargar—. Un borrador abre para editar, con la hoja a un clic para ver
    como va quedando. Se vuelve a elegir cada vez que cambia la cotizacion:
    al pasar de la R0 a una revision en borrador, lo que corresponde cambia.
  */
  const [vista, setVista] = useState<'hoja' | 'datos'>('datos')

  useEffect(() => {
    setVista(emitida ? 'hoja' : 'datos')
  }, [existente?.id, emitida])

  /*
    Un borrador se carga paso a paso.

    "Hay mucho scroll, o hacerlo paso a paso, y asi no saturar los datos". El
    editor era una pantalla larga con todo a la vez. Ahora cada parte va en su
    paso, y se puede saltar a cualquiera: no es un camino obligado, es no
    tener todo encima al mismo tiempo.

    Un borrador que se reabre ya tiene sus lineas: se sigue desde ahi, que es
    lo que se estaba haciendo. Uno nuevo arranca por el encabezado.
  */
  const [paso, setPaso] = useState<Paso>('encabezado')
  const barraDePasos = useRef<HTMLElement>(null)

  useEffect(() => {
    if (!existente) return

    setPaso((existente.lineas?.length ?? 0) > 0 ? 'lineas' : 'encabezado')
  }, [existente?.id])

  // Al abrir una cotización existente hay que traer la ficha completa de su
  // empresa: la consulta sólo trae el id y el nombre, y acá hacen falta los
  // contactos y las condiciones para poder editarla.
  const empresaId = id ?? (existente?.empresa?.id ? String(existente.empresa.id) : null)

  const {
    datos: empresa,
    cargando: cargandoEmpresa,
    recargar: recargarEmpresa,
  } = useCarga(() => (empresaId ? traerEmpresa(empresaId) : Promise.resolve(null)), [empresaId])

  const empresaActual = empresa

  const [tipo, setTipo] = useState(params.get('tipo') ?? 'Cotizacion')
  const [cabecera, setCabecera] = useState({
    fecha: hoy(),
    validez_dias: 7,
    contacto_id: '' as string | number,
    moneda_id: '' as string | number,
    tipo_cambio: '',
    ajuste_dif_cambio: false,
    ajuste_dif_cambio_detalle: '',
    nro_factura: '',
    id_sistema: '',
    condicion_pago: '',
    lista_precios: '',
    nota: '',
    texto: '',
    solicitud_via: '',
    // El pedido llegó hoy salvo que digan otra cosa: es lo que pasa casi
    // siempre, y tipear la fecha del día en cada cotización es trabajo al pedo.
    solicitud_fecha: hoy(),
    solicitud_texto: '',
    juego_condiciones: '' as '' | JuegoDeCondiciones,
  })
  const [lineas, setLineas] = useState<LineaForm[]>([lineaVacia()])
  const [condiciones, setCondiciones] = useState<CondicionForm[]>([])
  // Que juego de condiciones se cargó. Cambiar de juego recarga; volver al
  // mismo no pisa lo que la persona haya editado.
  const condicionesPropuestas = useRef<string | null>(null)

  /*
    Avisar antes de perder lo cargado.

    "Cerrar sin hacer nada seria CANCELAR?" — si, pero en silencio: cerrar la
    pestaña o apretar Cancelar con media cotizacion escrita la tiraba sin
    preguntar nada. Media hora de trabajo se va con un clic.

    En vez de marcar a mano cada campo que se toca, se compara el formulario
    entero contra la foto de como quedo al abrirlo. Una sola comparacion, y no
    hay forma de olvidarse de un campo nuevo.
  */
  const sinGuardar = useRef<string | null>(null)
  const ahora = JSON.stringify({ tipo, cabecera, lineas, condiciones })

  if (sinGuardar.current === null) sinGuardar.current = ahora

  const hayCambios = sinGuardar.current !== ahora

  /*
    El aviso del navegador al cerrar o recargar. El texto lo pone el
    navegador: no se puede elegir, pero el cartel aparece.
  */
  useEffect(() => {
    if (!hayCambios) return

    const avisar = (e: BeforeUnloadEvent) => e.preventDefault()

    window.addEventListener('beforeunload', avisar)

    return () => window.removeEventListener('beforeunload', avisar)
  }, [hayCambios])
  const [guardando, setGuardando] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)
  const [nuevaObs, setNuevaObs] = useState('')
  const [precargando, setPrecargando] = useState(false)

  // Al abrir una existente, se cargan sus datos. La moneda viene por nombre,
  // asi que hay que buscarle el id en el catalogo.
  useEffect(() => {
    if (!existente) return
    const monedaId = catalogos?.monedas.find((m) => m.nombre === existente.moneda)?.id ?? ''
    setTipo(existente.tipo)
    setCabecera({
      fecha: existente.fecha,
      validez_dias: existente.validez_dias,
      contacto_id: existente.contacto?.id ?? '',
      moneda_id: monedaId,
      tipo_cambio: existente.tipo_cambio ?? '',
      ajuste_dif_cambio: existente.ajuste_dif_cambio,
      ajuste_dif_cambio_detalle: existente.ajuste_dif_cambio_detalle ?? '',
      nro_factura: existente.nro_factura ?? '',
      id_sistema: existente.id_sistema ?? '',
      condicion_pago: existente.condicion_pago ?? '',
      lista_precios: existente.lista_precios ?? '',
      nota: existente.nota ?? '',
      texto: existente.texto ?? '',
      solicitud_via: existente.solicitud?.via ?? '',
      solicitud_fecha: existente.solicitud?.fecha ?? '',
      solicitud_texto: existente.solicitud?.texto ?? '',
      juego_condiciones: existente.juego_condiciones ?? '',
    })
    setLineas(desdeConsulta(existente, catalogos))
    // Se dejan afuera solo las sueltas que arma el sistema (la linea de
    // validez sin titulo). Los bloques con titulo son editables aunque hayan
    // entrado solos.
    setCondiciones(
      (existente.condiciones ?? [])
        .filter((c) => c.titulo !== null || c.origen !== 'Automatica')
        .map((c) => ({ titulo: c.titulo ?? '', texto: c.texto, imprime: c.imprime, aMano: true })),
    )
    condicionesPropuestas.current = existente.juego_condiciones ?? null
    // Recien cargado no hay nada cambiado: esta es la foto contra la que se
    // compara despues para saber si hay trabajo sin guardar.
    sinGuardar.current = null
  }, [existente, catalogos])

  // En una nueva, las condiciones de la ficha se proponen solas.
  useEffect(() => {
    if (!esNueva || !empresa) return
    const traer = (t: string) => (empresa.campos ?? []).find((c) => c.titulo === t && c.usar_al_cotizar)?.valor ?? ''
    setCabecera((c) => ({
      ...c,
      contacto_id: (empresa.contactos ?? []).find((x) => x.principal)?.id ?? '',
      condicion_pago: traer('Tipo de pago'),
      lista_precios: traer('Lista de precios'),
    }))
  }, [esNueva, empresa])

  const venceEl = useMemo(() => {
    if (!cabecera.fecha || !cabecera.validez_dias) return null
    const d = new Date(cabecera.fecha)
    d.setDate(d.getDate() + Number(cabecera.validez_dias))

    return d.toISOString().slice(0, 10)
  }, [cabecera.fecha, cabecera.validez_dias])

  // La fecha tal como se escribe en la hoja: 11-09-2026.
  const venceComoSeEscribe = venceEl ? venceEl.split('-').reverse().join('-') : ''

  /*
    Una cotización nueva arranca con las condiciones de siempre puestas.

    Son más de 2000 caracteres que la empresa repite en cada oferta; antes
    había que tipearlos o pegarlos. Se proponen y se editan: no son
    obligatorias, se sacan con la papelera como cualquier otra.

    La fecha del bloque de validez la completa el servidor al guardar, así que
    acá viaja el marcador y no una fecha que quedaría vieja si después cambian
    los días de validez.
  */
  useEffect(() => {
    if (!catalogos || !cabecera.juego_condiciones) return
    if (condicionesPropuestas.current === cabecera.juego_condiciones) return

    const delJuego = (catalogos.condiciones_habituales ?? []).filter(
      (c) => c.por_defecto && c.juego === cabecera.juego_condiciones,
    )

    if (delJuego.length === 0) return

    condicionesPropuestas.current = cabecera.juego_condiciones
    setCondiciones(
      delJuego.map((c) => ({
        titulo: c.titulo ?? '',
        texto: conLaFecha(c.texto, venceComoSeEscribe),
        imprime: true,
        plantilla: c.texto,
      })),
    )
  }, [catalogos, cabecera.juego_condiciones])

  /*
    Cambiar los días de validez actualiza la fecha escrita en las condiciones.

    Sólo en las que nadie editó: si alguien reescribió el párrafo, manda lo que
    escribió. Es la misma regla que con la medida y la descripción.
  */
  useEffect(() => {
    setCondiciones((previas) => {
      let cambio = false

      const nuevas = previas.map((c) => {
        if (c.aMano || !c.plantilla) return c

        const texto = conLaFecha(c.plantilla, venceComoSeEscribe)

        if (texto === c.texto) return c

        cambio = true

        return { ...c, texto }
      })

      return cambio ? nuevas : previas
    })
  }, [venceComoSeEscribe])

  // La moneda habitual de la ficha manda; si no tiene, la del sistema
  // (DOLAR BILLETE BNA VENDEDOR).
  const monedaPorNombre = useMemo(() => {
    const nombre = (empresa?.campos ?? []).find((c) => c.titulo === 'Moneda habitual')?.valor
    const deLaFicha = catalogos?.monedas.find((m) => m.nombre === nombre)?.id

    return deLaFicha ?? catalogos?.moneda_por_defecto ?? ''
  }, [catalogos, empresa])

  useEffect(() => {
    if (esNueva && monedaPorNombre && !cabecera.moneda_id) {
      setCabecera((c) => ({ ...c, moneda_id: monedaPorNombre }))
    }
  }, [esNueva, monedaPorNombre, cabecera.moneda_id])


  /*
    El total suma lo que se puede sacar. Las líneas sin cantidad —las traídas
    del sistema anterior, que tienen precio pero no dicen cuántos— no suman
    cero: quedan afuera y se avisa cuántas son, para que el total no se lea
    como si ya estuviera completo.
  */
  const [total, sinImporte] = useMemo(() => {
    const vivas = lineas.filter((l) => !l.quitada)
    const importes = vivas.map(calcularImporte)

    return [
      importes.reduce((suma: number, i) => suma + (i ?? 0), 0),
      importes.filter((i) => i === null).length,
    ] as const
  }, [lineas])

  const iguales = lineas.filter((l) => !l.quitada && l.igual_a_lo_pedido).length
  const vigentes = lineas.filter((l) => !l.quitada).length

  // Cuántos kilos suman las líneas que se facturan por peso.
  const kgId = catalogos?.unidades.find((u) => u.codigo === 'KG')?.id
  const totalKilos = useMemo(
    () =>
      lineas
        .filter((l) => !l.quitada && l.unidad_factura_id === kgId && l.factor_conversion)
        .reduce((s, l) => s + Number(l.cantidad ?? 0) * Number(l.factor_conversion ?? 0), 0),
    [lineas, kgId],
  )

  function cambiarLinea(clave: string, cambios: Partial<LineaForm>) {
    setLineas((prev) => prev.map((l) => (l.clave === clave ? { ...l, ...cambios } : l)))
  }

  async function guardar(imprimir = false, pestana: Window | null = null) {
    const conDatos = lineas.filter((l) => l.descripcion.trim() !== '')

    if (tipo !== 'Observacion' && conDatos.length === 0) {
      setError('Cargá al menos una línea con su descripción, que es lo que sale impreso.')

      return
    }

    if (tipo === 'Observacion' && !cabecera.texto.trim()) {
      setError('Escribí la observación.')

      return
    }

    setGuardando(true)
    setError(null)

    const datos = {
      tipo,
      fecha: cabecera.fecha,
      validez_dias: Number(cabecera.validez_dias) || 7,
      contacto_id: cabecera.contacto_id ? Number(cabecera.contacto_id) : null,
      moneda_id: cabecera.moneda_id ? Number(cabecera.moneda_id) : null,
      tipo_cambio: cabecera.tipo_cambio ? Number(cabecera.tipo_cambio) : null,
      ajuste_dif_cambio: cabecera.ajuste_dif_cambio,
      ajuste_dif_cambio_detalle: cabecera.ajuste_dif_cambio_detalle || null,
      nro_factura: cabecera.nro_factura || null,
      id_sistema: cabecera.id_sistema || null,
      condicion_pago: cabecera.condicion_pago || null,
      lista_precios: cabecera.lista_precios || null,
      nota: cabecera.nota || null,
      texto: cabecera.texto || null,
      solicitud_via: cabecera.solicitud_via || null,
      solicitud_fecha: cabecera.solicitud_fecha || null,
      solicitud_texto: cabecera.solicitud_texto || null,
      // De la calculadora viajan las medidas, no el peso: el peso lo rehace el
      // servidor y lo guarda con la densidad y la formula que uso ese dia.
      lineas: conDatos.map(({
        clave: _clave,
        calc,
        factorAMano: _fam,
        dimensionesAMano: _dam,
        descripcionAMano: _desam,
        ...l
      }) => ({
        ...l,
        // Con el id el servidor actualiza la línea en vez de recrearla, y así
        // no pierde de dónde vino su factor.
        id: /^\d+$/.test(_clave) ? Number(_clave) : null,
        calc_medidas: Object.keys(calc.medidas).length > 0 ? calc.medidas : null,
        calc_piezas: Number(calc.piezas) || null,
        calc_cano_id: calc.canoId,
      })),
      juego_condiciones: cabecera.juego_condiciones || null,
      condiciones: condiciones
        .filter((c) => c.texto.trim())
        .map((c) => ({ titulo: c.titulo.trim() || null, texto: c.texto, imprime: c.imprime })),
    }

    try {
      const id = esNueva
        ? (await crearConsulta(empresaActual!.id, datos)).id
        : Number(consultaId)

      if (esNueva) {
        setAviso('Guardado. Ya figura en el historial de la empresa.')
        navigate(`/consultas/${id}`, { replace: true })
      } else {
        await actualizarConsulta(id, datos)
        setAviso('Cambios guardados. Quedaron en el control de cambios.')
        recargar()
      }

      // Recien acá: si el guardado falla, los cambios siguen sin guardar y el
      // aviso tiene que seguir apareciendo.
      sinGuardar.current = null

      if (imprimir) {
        /*
          Emitir es mandarla: se numera y se congela ANTES de pedir la hoja.

          Se guarda primero, así sale con lo último que se cargó; después se
          emite, así la hoja sale con su número y no como borrador; y recién
          ahí se pide. Emitida, no se edita más: para cambiarla se hace una
          revisión.
        */
        const emitida = await emitirConsulta(id)

        const donde = await traerLaHoja(
          id,
          pestana,
          `${tipo}-${emitida.numero_con_revision ?? empresaActual!.nombre}`,
        )

        setAviso(
          `Emitida como ${emitida.numero_con_revision}. ` +
            (donde === 'pestana'
              ? 'La hoja se abrió en otra pestaña.'
              : 'La hoja se descargó: el navegador no dejó abrir la pestaña.'),
        )

        if (!esNueva) recargar()
      }
    } catch (err) {
      // Si algo falló no queda una pestaña en blanco dando vueltas.
      pestana?.close()
      setError(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  /** Vuelve a sacar la hoja de una emitida. No guarda nada: no hay qué. */
  async function reimprimir(pestana: Window | null) {
    try {
      await traerLaHoja(
        Number(consultaId),
        pestana,
        `${tipo}-${existente?.numero_con_revision ?? empresaActual!.nombre}`,
      )
    } catch (err) {
      pestana?.close()
      setError(mensajeDeError(err))
    }
  }

  /**
   * Hace una revisión: un borrador nuevo con todo lo de ésta.
   *
   * "Si está emitida no deja editar, pero sí te deja como base, y hace un
   * borrador". Si ya había una revisión a medio hacer, se abre esa: dos a la
   * vez de la misma cotización terminan emitidas con cambios que se pisan.
   */
  async function hacerRevision() {
    setGuardando(true)
    setError(null)

    try {
      const id = await nuevaRevision(Number(consultaId))

      navigate(`/consultas/${id}`)
    } catch (err) {
      setError(mensajeDeError(err))
    } finally {
      setGuardando(false)
    }
  }

  /**
   * Precarga: se pega el texto del cliente y el sistema propone las líneas.
   * Se agregan a lo que ya hay; después se revisan y se corrigen.
   */
  async function precargarDesdeTexto() {
    const texto = cabecera.solicitud_texto.trim()

    if (!texto) {
      setError('Pegá primero el texto del mail o del WhatsApp en "Lo que pidió el cliente".')

      return
    }

    setPrecargando(true)
    setError(null)

    try {
      const r = await interpretarTexto(texto)

      if (r.lineas.length === 0) {
        // El aviso dice POR QUE no salio: sin eso, un problema de la IA se lee
        // como si el texto del cliente estuviera mal escrito.
        setError([r.mensaje, r.aviso].filter(Boolean).join(' '))

        return
      }

      const nuevas: LineaForm[] = r.lineas.map((l) => {
        // Las medidas que se entendieron, en los campos de su forma.
        const medidas = medidasDeLaLinea(
          { diametro_mm: l.diametro_mm, espesor_mm: l.espesor_mm, ancho_mm: l.ancho_mm, largo_mm: l.largo_mm },
          catalogos?.formas.find((f) => f.id === l.forma_id) ?? null,
        )

        return {
        clave: crypto.randomUUID(),
        descripcion: l.descripcion,
        material_id: l.material_id,
        forma_id: l.forma_id,
        dimensiones: l.dimensiones,
        diametro_mm: l.diametro_mm,
        cantidad: l.cantidad,
        unidad_venta_id: l.unidad_venta_id,
        // Lo que dijo el lector: si no reconoció el material la línea queda sin
        // dar por buena, para que alguien la mire antes de mandarla.
        igual_a_lo_pedido: l.igual_a_lo_pedido ?? true,
        // Lo que el cliente escribió queda a la vista al lado de lo que se
        // cotiza: es la única forma de notar que pidió 316L y hay 316.
        pedido_material: l.pedido_material,
        // De una ficha de la web viene la forma que pidio: con ella el bloque
        // amarillo abre con Diametro, Largo y Piezas en vez de una caja suelta.
        pedido_forma: l.pedido_forma ?? null,
        pedido_dimensiones: l.dimensiones,
        /*
          Si la linea ya viene distinta, lo que pidio arranca con las medidas
          que se leyeron: salieron del pedido, asi que son las del cliente.
          Sin esto el bloque amarillo abria con Diametro y Largo vacios aunque
          el cliente hubiera escrito "Ø10mm x 3 metros". Destildar a mano ya
          las copiaba; venir distinta desde el lector no.
        */
        pedido_medidas:
          l.igual_a_lo_pedido === false && Object.keys(medidas).length > 0 ? { ...medidas } : null,
        precio_unitario: null,
        // Las variantes que pidió el cliente vienen armadas: quedan las
        // etiquetas y las cantidades, y solo hay que poner los precios.
        opciones: l.alternativas ?? [],
        // Las medidas que se entendieron ya quedan puestas en la calculadora.
        calc: {
          ...calculadoraVacia(),
          // Las piezas son las que se piden: 3 barras pesan 3 barras. Con
          // piezas en 1 el peso de la linea sale por una sola.
          piezas: String(l.cantidad ?? 1),
          medidas,
        },
        }
      })

      // Reemplaza las líneas vacías; conserva las que ya tenían datos.
      setLineas((prev) => [...prev.filter((l) => l.descripcion.trim() !== ''), ...nuevas])

      // Lo que sigue es revisarlas y ponerles precio.
      setPaso('lineas')

      // Se avisa si lo leyó la IA o las reglas, para que sepan qué revisar.
      const como = r.con_ia ? 'Lo leyó la IA' : 'Se leyó con las reglas'
      const pendientes =
        r.sin_reconocer.length > 0
          ? ` ${r.sin_reconocer.length} renglones no se reconocieron: revisalos.`
          : ''

      // La lectura a veces repite un renglon. No se borra —el cliente puede
      // haber pedido dos iguales— pero tiene que saltar a la vista.
      const repetidas =
        (r.repetidas?.length ?? 0) > 0
          ? ` OJO: ${r.repetidas.length === 1 ? 'hay una linea repetida' : `hay ${r.repetidas.length} lineas repetidas`}. Fijate si el cliente pidio dos o si se duplico.`
          : ''

      setAviso(`${como}: ${r.lineas.length} lineas cargadas.${pendientes}${repetidas} Revisalas antes de guardar.`)
    } catch (err) {
      setError(mensajeDeError(err))
    } finally {
      setPrecargando(false)
    }
  }

  async function sumarObservacion() {
    if (!nuevaObs.trim() || !consultaId) return

    try {
      await agregarObservacion(Number(consultaId), nuevaObs)
      setNuevaObs('')
      setAviso('Observación agregada. Es de uso interno: no se imprime.')
      recargar()
    } catch (err) {
      setError(mensajeDeError(err))
    }
  }

  if (cargandoEmpresa || cargandoConsulta) return <Cargando texto="Abriendo…" />

  if (!empresaActual) {
    return (
      <SinResultados
        titulo="No encontramos la empresa"
        detalle="Volvé a la lista y entrá desde la ficha."
        accion={
          <Link to="/empresas/registros">
            <Boton variante="suave">Volver a la lista</Boton>
          </Link>
        }
      />
    )
  }

  const idFicha = empresaActual.id

  // Un borrador se recorre por pasos; una emitida o una observacion, no.
  const porPasos = !emitida && tipo !== 'Observacion'
  // Sin guardar no hay hoja todavia: la vista previa aparece despues.
  const pasosVisibles = PASOS.filter((p) => p.clave !== 'hoja' || !esNueva)
  const ver = (seccion: Paso) => !porPasos || paso === seccion
  const mostrarHoja =
    !esNueva && tipo !== 'Observacion' && (emitida ? vista === 'hoja' : paso === 'hoja')
  const enCual = pasosVisibles.findIndex((p) => p.clave === paso)
  const anterior = pasosVisibles[enCual - 1]
  const siguiente = pasosVisibles[enCual + 1]

  /** Cambia de paso y lo pone arriba: el boton de siguiente queda abajo de todo. */
  function irAlPaso(destino: Paso) {
    setPaso(destino)
    barraDePasos.current?.scrollIntoView({ block: 'start', behavior: 'smooth' })
  }

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb={
          <span className="flex items-center gap-1.5">
            <Link to="/empresas" className="hover:text-brand-600">
              Empresas
            </Link>
            <span>›</span>
            <Link to={`/empresas/${idFicha}`} className="hover:text-brand-600">
              {empresaActual.nombre}
            </Link>
          </span>
        }
        titulo={
          esNueva
            ? `${tipo} para ${empresa.nombre}`
            : `${tipo} de ${empresa.nombre}`
        }
        chips={
          // Una observación no se emite: no es borrador ni emitida.
          esNueva || tipo === 'Observacion' ? undefined : emitida ? (
            <Chip tono="verde">{existente?.numero_con_revision ?? 'Emitida'}</Chip>
          ) : (
            <Chip tono="ambar">
              {existente?.revision_de_id ? 'Revisión en borrador' : 'Borrador'}
            </Chip>
          )
        }
        bajada="Se carga lo que pidió el cliente y lo que se le cotiza. Cuando es lo mismo, se completa solo. Lo que se escribe en NOTA y en las observaciones queda para adentro."
        acciones={
          emitida ? (
            /*
              Emitida: se lee, se vuelve a imprimir, o se revisa. No hay
              "Guardar" porque no hay nada que guardar.
            */
            <>
              <Boton variante="suave" onClick={() => navigate(`/empresas/${idFicha}`)}>
                Volver
              </Boton>
              <Boton
                variante="suave"
                // La pestaña se abre dentro del clic, o el navegador la bloquea.
                onClick={() => reimprimir(window.open('', '_blank'))}
              >
                Imprimir
              </Boton>
              <Boton variante="primario" onClick={hacerRevision} disabled={guardando}>
                {guardando ? 'Abriendo…' : 'Hacer una revisión'}
              </Boton>
            </>
          ) : (
          <>
            {/*
              Cancelar es salir sin guardar, que es lo que preguntaban. Con
              algo escrito, pregunta antes: es la misma tecla que cierra la
              pestaña de al lado.
            */}
            <Boton
              variante="suave"
              onClick={() => {
                const seguro =
                  !hayCambios ||
                  window.confirm('Hay cambios sin guardar en esta cotizacion. ¿Los descartamos?')

                if (seguro) navigate(`/empresas/${idFicha}`)
              }}
            >
              Cancelar
            </Boton>
            {/* Una observación no se emite: guardarla es terminarla. */}
            <Boton
              variante={tipo === 'Observacion' ? 'primario' : 'suave'}
              onClick={() => guardar()}
              disabled={guardando}
            >
              {guardando ? 'Guardando…' : 'Guardar'}
            </Boton>
            {tipo !== 'Observacion' && <>
            {/*
              Al terminar una cotización lo que sigue es mandarla, así que este
              es el botón principal. Se llama "Emitir" y no "Guardar" porque
              no se puede deshacer: la numera y la congela. Por eso pregunta
              antes. "Guardar" a secas queda para cuando se deja a medias.
            */}
            <Boton
              variante="primario"
              onClick={() => {
                const seguro = window.confirm(
                  'Se va a emitir: toma su número y ya no se puede modificar. ' +
                    'Para cambiarla después hay que hacer una revisión. ¿Emitimos?',
                )

                // La pestaña se abre acá, dentro del clic. Si se abriera después
                // de guardar, el navegador la bloquearía por emergente.
                if (seguro) guardar(true, window.open('', '_blank'))
              }}
              disabled={guardando}
            >
              {guardando ? 'Emitiendo…' : 'Emitir e imprimir'}
            </Boton>
            </>}
          </>
          )
        }
      />

      {error && <Aviso tono="ambar">{error}</Aviso>}

      {/* R0, R1, R2: las versiones de esta cotización, como solapas. */}
      {!esNueva && <Versiones consultaId={Number(consultaId)} />}

      {/*
        Emitida: se dice por qué no se puede tocar y qué hacer en su lugar.
        Un formulario que no responde sin explicar por qué parece roto.
      */}
      {emitida && (
        <Aviso tono="verde">
          <span className="inline-flex items-center gap-1.5 font-semibold">
            <Lock size={13} strokeWidth={2.4} />
            {/* Las del Access no tienen numero propio: no se deja el hueco. */}
            {existente?.numero_con_revision ? `Emitida como ${existente.numero_con_revision}` : 'Emitida'}
          </span>
          {existente?.emitida_el && ` el ${fmtFecha(existente.emitida_el)}`}
          {existente?.emitida_por && ` por ${existente.emitida_por}`}. Así la tiene el cliente, y
          así queda: no se modifica. Para cambiarla, hacé una revisión.
        </Aviso>
      )}

      {/*
        Lo emitido se ve pero no se toca. Un fieldset deshabilitado apaga
        todos los campos y botones de adentro de una vez: no hay que acordarse
        de marcar cada uno, y uno nuevo que se agregue mañana ya nace
        bloqueado. El servidor lo rechaza igual; esto es para que no se
        pueda empezar a escribir algo que después no se va a guardar.
      */}
      {/*
        Como se recorre la cotizacion. Emitida: la hoja o los datos cargados.
        Borrador: paso a paso, con la vista previa al final. Una observacion es
        un solo texto: no tiene pasos ni hoja.
      */}
      {emitida && tipo !== 'Observacion' && (
        <div className="flex flex-wrap items-center gap-2.5">
          <div role="tablist" aria-label="Como ver la cotizacion" className="inline-flex rounded-[10px] border border-line-strong bg-white p-1">
            {(
              [
                ['hoja', emitida ? 'La hoja' : 'Vista previa'],
                ['datos', emitida ? 'Datos cargados' : 'Editar'],
              ] as const
            ).map(([clave, texto]) => (
              <button
                key={clave}
                type="button"
                role="tab"
                aria-selected={vista === clave}
                onClick={() => setVista(clave)}
                className={`rounded-[7px] px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors ${
                  vista === clave ? 'bg-brand text-white' : 'text-slate-600 hover:bg-app'
                }`}
              >
                {texto}
              </button>
            ))}
          </div>
          <span className="text-[11.5px] text-muted">
            {vista === 'hoja'
              ? emitida
                ? 'Así la tiene el cliente.'
                : hayCambios
                  ? 'Muestra lo último guardado: tenés cambios sin guardar.'
                  : 'Así va a salir. Dice BORRADOR hasta que se emita.'
              : emitida
                ? 'Todo lo que se cargó, sin poder cambiarlo.'
                : 'Se guarda con Guardar; la hoja sale al emitir.'}
          </span>
        </div>
      )}

      {porPasos && (
        <nav ref={barraDePasos} aria-label="Pasos de la cotizacion" className="flex flex-wrap items-center gap-1.5">
          {pasosVisibles.map((p, n) => {
            const actual = paso === p.clave
            // Cuantas hay, para saber de un vistazo si el paso tiene algo. La
            // linea en blanco con que nace una cotizacion no cuenta.
            const cuenta =
              p.clave === 'lineas'
                ? lineas.filter((l) => !l.quitada && l.descripcion.trim() !== '').length
                : p.clave === 'condiciones'
                  ? condiciones.filter((c) => c.texto.trim()).length
                  : 0

            return (
              <button
                key={p.clave}
                type="button"
                onClick={() => irAlPaso(p.clave)}
                aria-current={actual ? 'step' : undefined}
                className={`inline-flex items-center gap-2 rounded-[9px] border px-3 py-1.5 text-[12.5px] font-semibold transition-colors ${
                  actual ? 'border-brand bg-brand text-white' : 'border-line-strong bg-white text-slate-600 hover:bg-app'
                }`}
              >
                <span
                  className={`grid h-5 w-5 place-items-center rounded-full text-[11px] tabular-nums ${
                    actual ? 'bg-white/20' : 'bg-app text-muted'
                  }`}
                >
                  {n + 1}
                </span>
                {p.titulo}
                {cuenta > 0 && <span className={actual ? 'text-white/75' : 'text-faint'}>· {cuenta}</span>}
              </button>
            )
          })}
        </nav>
      )}

      {mostrarHoja && (
        <>
          {/* La hoja sale de lo guardado: si hay cambios, todavia no los muestra. */}
          {!emitida && hayCambios && (
            <Aviso tono="ambar">
              La hoja muestra lo último guardado y tenés cambios sin guardar.{' '}
              <button type="button" className="font-semibold underline" onClick={() => guardar()}>
                Guardar y actualizarla
              </button>
            </Aviso>
          )}
          <HojaPrevia consultaId={Number(consultaId)} recarga={existente} />
        </>
      )}

      {/*
        Las partes se ocultan, no se sacan: lo escrito en un paso sigue ahi al
        volver, y el guardado de arriba guarda todo, este donde este.
      */}
      <fieldset disabled={emitida} hidden={mostrarHoja} className="m-0 flex min-w-0 flex-col gap-[18px] border-0 p-0">
      <div hidden={!ver('encabezado')}>
        {/* tipo */}
        <div className="flex flex-wrap items-center gap-2.5">
          {(['Cotizacion', 'Pedido', 'Observacion'] as const).map((t) => (
            <button
              key={t}
              type="button"
              onClick={() => setTipo(t)}
              className={`rounded-[9px] border px-4 py-2.5 text-[13px] font-semibold transition-colors ${
                tipo === t
                  ? 'border-brand bg-brand text-white'
                  : 'border-line-strong bg-white text-slate-600 hover:bg-app'
              }`}
            >
              {t}
            </button>
          ))}
          <span className="ml-auto text-[11.5px] text-faint">
            Los tres se guardan en el historial de la empresa, separados.
          </span>
        </div>
      </div>

      {tipo === 'Observacion' ? (
        <Card className="p-[22px]">
          <AreaTexto
            etiqueta="Observacion"
            ayuda="lo que pasó y no es una cotización"
            filas={5}
            value={cabecera.texto}
            onChange={(e) => setCabecera({ ...cabecera, texto: e.target.value })}
            placeholder="Llamó por el pedido de níquel. Pasar a verlo el mes que viene."
          />
        </Card>
      ) : (
        <>
          <div hidden={!ver('encabezado')}>
            <Encabezado
              tipo={tipo}
              cabecera={cabecera}
              setCabecera={setCabecera}
              empresa={empresaActual}
              catalogos={catalogos}
              venceEl={venceEl}
              onContactoNuevo={recargarEmpresa}
            />
          </div>
          <div hidden={!ver('pedido')}>
            <Solicitud
              cabecera={cabecera}
              setCabecera={setCabecera}
              catalogos={catalogos}
              onPrecargar={precargarDesdeTexto}
              precargando={precargando}
            />
          </div>

          {/*
            En el paso de las lineas, lo que pidio el cliente sigue a la vista.

            Para eso lo subieron arriba de las lineas: para compararlo mientras
            se cotiza, sin ir a buscarlo. Un paso que lo escondiera desharia
            justo eso.
          */}
          {porPasos && paso === 'lineas' && cabecera.solicitud_texto.trim() !== '' && (
            <Card className="border-brand-200 bg-[#f3f9fe] px-[22px] py-3">
              <div className="flex items-center gap-2.5">
                <span className="text-[10.5px] font-bold uppercase tracking-wide text-brand-600">
                  Lo que pidió el cliente
                </span>
                <button
                  type="button"
                  onClick={() => irAlPaso('pedido')}
                  className="ml-auto text-[11.5px] font-medium text-brand-600 hover:underline"
                >
                  Verlo entero o cambiarlo
                </button>
              </div>
              <p className="mt-1.5 max-h-[132px] overflow-y-auto whitespace-pre-wrap text-[12.5px] leading-relaxed text-slate-700">
                {cabecera.solicitud_texto}
              </p>
            </Card>
          )}

          <div hidden={!ver('lineas')}>
            <Lineas
              lineas={lineas}
              setLineas={setLineas}
              cambiarLinea={cambiarLinea}
              catalogos={catalogos}
              total={total}
              sinImporte={sinImporte}
              totalKilos={totalKilos}
              iguales={iguales}
              vigentes={vigentes}
            />
          </div>
          <div hidden={!ver('condiciones')}>
            <Condiciones
              condiciones={condiciones}
              setCondiciones={setCondiciones}
              validez={cabecera.validez_dias}
              vence={venceComoSeEscribe}
              juego={cabecera.juego_condiciones}
              onJuego={(j) => setCabecera({ ...cabecera, juego_condiciones: j })}
              nota={cabecera.nota}
              onNota={(v) => setCabecera({ ...cabecera, nota: v })}
              catalogos={catalogos}
            />
          </div>
        </>
      )}

      </fieldset>

      {/* Anterior y siguiente, abajo, donde termina lo que se estaba mirando. */}
      {porPasos && (anterior || siguiente) && (
        <div className="flex flex-wrap items-center gap-2.5">
          {anterior && (
            <Boton variante="suave" onClick={() => irAlPaso(anterior.clave)}>
              ← {anterior.titulo}
            </Boton>
          )}
          {siguiente && (
            <div className="ml-auto">
              <Boton variante="primario" onClick={() => irAlPaso(siguiente.clave)}>
                {siguiente.titulo} →
              </Boton>
            </div>
          )}
        </div>
      )}

      {/*
        De acá para abajo sigue habilitado aunque esté emitida: las
        observaciones son el seguimiento —"quedó en confirmar el lunes"— y no
        cambian lo que se le mandó al cliente.
      */}
      {!esNueva && <Relacionadas consultaId={Number(consultaId)} />}

      {/* Qué se cambió después de la primera vez, y quién. */}
      {!esNueva && <Revisiones consultaId={Number(consultaId)} />}

      {/* Observaciones internas — sólo cuando la consulta ya existe. */}
      {!esNueva && existente && (
        <Card className="overflow-hidden">
          <CardHeader
            titulo="Observaciones de esta cotizacion"
            cuenta={existente.observaciones?.length ?? 0}
            chips={<Chip tono="violeta">uso interno</Chip>}
            ayuda="Se van enlistando una debajo de la otra, con la fecha y quién la escribió. Así queda el hilo de cómo sigue."
          />

          <ul className="border-t border-[#eef2f6]">
            {(existente.observaciones ?? []).map((o) => (
              <li key={o.id} className="flex flex-col gap-1.5 border-b border-[#eef2f6] px-[22px] py-3">
                <div className="flex flex-wrap items-center gap-2.5">
                  <Chip tono="violeta">Observacion {o.numero}</Chip>
                  <span className="text-[11.5px] text-muted">{o.fecha}</span>
                  <span className="text-[11px] text-[#c3cdd6]">·</span>
                  <span className="text-[11.5px] text-muted">Quien lo escribio  {o.quien}</span>
                </div>
                <div className="rounded-lg border border-[#e4dcf7] bg-[#fbfafe] px-3.5 py-2.5 text-[12.5px] leading-relaxed text-slate-700">
                  {o.texto}
                </div>
              </li>
            ))}
          </ul>

          <div className="flex flex-wrap items-end gap-3 px-[22px] py-4">
            <AreaTexto
              className="min-w-[280px] flex-1"
              etiqueta="Agregar una observacion"
              filas={2}
              value={nuevaObs}
              onChange={(e) => setNuevaObs(e.target.value)}
              placeholder="Quedó en confirmar la semana que viene."
            />
            <Boton variante="suave" onClick={sumarObservacion} disabled={!nuevaObs.trim()}>
              <Plus size={15} strokeWidth={2.4} />
              Agregar
            </Boton>
          </div>

          <NotaPie>
            La empresa además tiene su propia observación general, que se ve arriba de todo en la
            ficha. Éstas son sólo de esta cotización y no se imprimen.
          </NotaPie>
        </Card>
      )}

      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </div>
  )
}

/* -------------------------------------------------- lo que pidió el cliente */

function Solicitud({
  cabecera,
  setCabecera,
  catalogos,
  onPrecargar,
  precargando,
}: {
  cabecera: Record<string, unknown>
  setCabecera: (v: never) => void
  catalogos: Catalogos | null
  onPrecargar: () => void
  precargando: boolean
}) {
  const c = cabecera as {
    solicitud_via: string
    solicitud_fecha: string
    solicitud_texto: string
  }
  const set = (k: string, v: string) => setCabecera({ ...cabecera, [k]: v } as never)

  return (
    <Card className="flex flex-col gap-3.5 border-brand-200 bg-[#f3f9fe] p-[22px]">
      {/*
        El título dice de quién son estas palabras, y el renglón de abajo dice
        contra qué se comparan. Sin eso, un recuadro con texto del cliente
        arriba de las líneas se lee como una nota más.
      */}
      <div>
        <div className="flex flex-wrap items-center gap-2.5">
          <h2 className="text-[16px] font-semibold text-brand-600">Lo que pidio el cliente</h2>
          <span className="ml-auto text-[11px] text-[#6c93ae]">
            Se guarda tal cual llegó, para poder volver a leerlo
          </span>
        </div>
        <p className="mt-1 text-[12px] leading-relaxed text-[#5a87a3]">
          Queda acá, pegado a las líneas: abajo va lo que le vamos a ofrecer contra esto.
        </p>
      </div>

      <div className="grid gap-3 lg:grid-cols-[180px_180px]">
        <Lista
          etiqueta="Como llego"
          value={c.solicitud_via}
          onChange={(e) => set('solicitud_via', e.target.value)}
          opciones={(catalogos?.solicitud_vias ?? []).map((v) => ({ valor: v, texto: v }))}
        />
        <Texto
          etiqueta="Fecha de la solicitud"
          type="date"
          value={c.solicitud_fecha}
          onChange={(e) => set('solicitud_fecha', e.target.value)}
        />
      </div>

      <AreaTexto
        etiqueta="Lo que mando el cliente"
        ayuda="pegalo tal cual, del mail o del WhatsApp"
        // Seis y no cuatro: ahora se lee mientras se cargan las líneas de
        // abajo, así que un pedido de tres ítems tiene que entrar sin scroll.
        filas={6}
        value={c.solicitud_texto}
        onChange={(e) => set('solicitud_texto', e.target.value)}
        placeholder={'Hola Roberto, necesito cotizar:\n6 UN HASTELLOY C-276 BAR RED 38.1 X 145MM\n4 un AISI 316TI barra DIA 65 X 145MM'}
      />

      <div className="flex flex-wrap items-center gap-3">
        <Boton variante="primario" onClick={onPrecargar} disabled={precargando}>
          <Wand2 size={15} strokeWidth={2.2} />
          {precargando ? 'Leyendo…' : 'Cargar las lineas desde el texto'}
        </Boton>
        {catalogos?.ia_activa ? (
          <Chip tono="violeta">con IA</Chip>
        ) : (
          <Chip tono="neutro">sin IA</Chip>
        )}
        <p className="min-w-[280px] flex-1 text-[11.5px] leading-relaxed text-[#6c93ae]">
          El sistema separa cantidad, unidad, material, forma y medida. Nada se guarda solo: son
          líneas propuestas para revisar y corregir.
        </p>
      </div>
    </Card>
  )
}

/* ------------------------------------------------------------- encabezado */

function Encabezado({
  tipo,
  cabecera,
  setCabecera,
  empresa,
  catalogos,
  venceEl,
  onContactoNuevo,
}: {
  /** Cotizacion, Pedido u Observacion: solo para mostrarlo. */
  tipo: string
  cabecera: Record<string, unknown>
  setCabecera: (v: never) => void
  empresa: Empresa
  catalogos: Catalogos | null
  venceEl: string | null
  /** Vuelve a traer la ficha: recién se cargó un contacto desde acá. */
  onContactoNuevo: () => void
}) {
  const c = cabecera as Record<string, string | number | boolean>
  const set = (k: string, v: string | number | boolean) =>
    setCabecera({ ...cabecera, [k]: v } as never)

  /*
    Dar de alta un contacto sin salir de la cotización.

    "Quiero seleccionar el contacto del cliente y no está cargado, no tengo
    opción de cargarlo sin salir de pantalla": había que ir a la ficha de la
    empresa, cargarlo, volver y empezar la cotización de nuevo.

    El modal es el mismo de la ficha. Al guardar se recarga la ficha y el
    contacto nuevo —el último— queda elegido, que es para lo que se cargó.
  */
  const [cargandoContacto, setCargandoContacto] = useState(false)
  const [eligeElNuevo, setEligeElNuevo] = useState(false)
  const contactos = empresa?.contactos ?? []

  useEffect(() => {
    if (!eligeElNuevo || contactos.length === 0) return

    const ultimo = contactos.reduce((a, b) => (b.id > a.id ? b : a))

    set('contacto_id', ultimo.id)
    setEligeElNuevo(false)
    // set() se rearma en cada render del padre: incluirlo dispararía de nuevo.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [eligeElNuevo, contactos.length])

  return (
    <Card className="flex flex-col gap-3.5 p-[22px]">
      <div className="flex flex-wrap items-center gap-2.5">
        <h2 className="text-[15px] font-semibold text-ink">Encabezado</h2>
        <span className="ml-auto text-[11px] text-faint">
          Los campos en celeste se tomaron de la ficha de esta empresa
        </span>
      </div>

      <ModalContacto
        abierto={cargandoContacto}
        empresaId={empresa.id}
        onCerrar={() => setCargandoContacto(false)}
        onGuardado={() => {
          setCargandoContacto(false)
          setEligeElNuevo(true)
          onContactoNuevo()
        }}
      />

      {/* Para quien es. Antes solo estaba en la miga de pan, chiquito: con
          varias cotizaciones abiertas no se sabia cual era cual. */}
      <div className="flex flex-wrap items-baseline gap-x-2.5 gap-y-1 rounded-[7px] border border-brand-200 bg-[#f3f9fe] px-[13px] py-2.5">
        <span className="text-[11px] uppercase tracking-[.3px] text-faint">{tipo} para</span>
        <span className="text-[15px] font-semibold text-ink">{empresa.nombre}</span>
        {empresa.localidad && (
          <span className="text-[12px] text-muted">
            · {empresa.localidad}
            {empresa.provincia ? `, ${empresa.provincia}` : ''}
          </span>
        )}
      </div>

      <div className="grid gap-3 lg:grid-cols-5">
        <div className="flex min-w-0 flex-col lg:col-span-2">
          <Lista
            etiqueta="Contactar a:"
            value={c.contacto_id as string}
            vacio="Sin elegir"
            onChange={(e) => set('contacto_id', e.target.value)}
            opciones={contactos.map((k) => ({
              valor: k.id,
              texto: k.sector ? `${k.nombre} · ${k.sector}` : k.nombre,
            }))}
          />
          <button
            type="button"
            onClick={() => setCargandoContacto(true)}
            className="mt-1 self-start text-[11px] font-semibold text-brand-600 hover:text-brand"
          >
            + Cargar un contacto nuevo
          </button>
        </div>
        <Texto
          etiqueta="Fecha"
          type="date"
          value={c.fecha as string}
          onChange={(e) => set('fecha', e.target.value)}
        />
        <Texto
          etiqueta="Validez"
          ayuda="en dias"
          type="number"
          min={0}
          value={c.validez_dias as number}
          onChange={(e) => set('validez_dias', Number(e.target.value))}
        />
        <div className="flex min-w-0 flex-col">
          <Etiqueta>Vence el</Etiqueta>
          <div className="flex h-[36px] items-center rounded-[7px] border border-line-strong bg-app px-[11px] text-[12.5px] text-muted">
            {venceEl ? fmtFecha(venceEl) : '—'}
          </div>
        </div>
      </div>

      <div className="grid gap-3 lg:grid-cols-5">
        <Lista
          etiqueta="Moneda"
          className="lg:col-span-2"
          value={c.moneda_id as string}
          onChange={(e) => set('moneda_id', e.target.value)}
          opciones={(catalogos?.monedas ?? []).map((m) => ({ valor: m.id, texto: m.nombre }))}
        />
        <Texto
          etiqueta="Tipo de cambio"
          ayuda="si es venta"
          value={c.tipo_cambio as string}
          onChange={(e) => set('tipo_cambio', e.target.value)}
          placeholder="1412,00"
        />
        <Texto
          etiqueta="Nro. de Factura"
          value={c.nro_factura as string}
          onChange={(e) => set('nro_factura', e.target.value)}
          placeholder="Si corresponde"
        />
        <Texto
          etiqueta="ID del sistema"
          value={c.id_sistema as string}
          onChange={(e) => set('id_sistema', e.target.value)}
          placeholder="C-2026-0741"
        />
      </div>

      <div className="grid gap-3 lg:grid-cols-4">
        {/*
          Se elige de la lista o se escribe. "No hay cargado, no deja agregar":
          la lista estaba vacia y era un desplegable, asi que no habia por
          donde. Lo que se escriba queda guardado para la proxima cotizacion.
        */}
        <Combo
          id="condicion-pago"
          etiqueta="Condicion de pago"
          value={(c.condicion_pago as string) ?? ''}
          onChange={(e) => set('condicion_pago', e.target.value)}
          placeholder="Elegi una o escribi la que acordaron"
          opciones={(catalogos?.condiciones_pago ?? []).map((x) => x.nombre)}
        />
        <Texto
          etiqueta="Lista de precios"
          value={c.lista_precios as string}
          onChange={(e) => set('lista_precios', e.target.value)}
        />
        <div className="flex flex-col gap-2 lg:col-span-2">
          <Etiqueta>Ajuste por diferencia de cambio</Etiqueta>
          <div className="flex flex-wrap items-center gap-2.5">
            <Casilla
              marcada={Boolean(c.ajuste_dif_cambio)}
              onChange={(v) => set('ajuste_dif_cambio', v)}
            >
              Lleva ajuste
            </Casilla>
            {Boolean(c.ajuste_dif_cambio) && (
              <Lista
                aria-label="Se ajusta"
                className="w-[220px]"
                value={c.ajuste_dif_cambio_detalle as string}
                vacio="Contra qué se ajusta"
                onChange={(e) => set('ajuste_dif_cambio_detalle', e.target.value)}
                opciones={(catalogos?.ajuste_dif_cambio ?? []).map((a) => ({ valor: a, texto: a }))}
              />
            )}
          </div>
        </div>
      </div>

      <Aviso tono="info">
        La validez arranca en 7 días y el sistema calcula solo la fecha de vencimiento. La línea
        &quot;Validez de la oferta&quot; se arma sola al imprimir.
      </Aviso>
    </Card>
  )
}

/* ------------------------------------------------------------------ líneas */

/**
 * Los kilos que pesa un metro de esa forma.
 *
 * Es la misma calculadora de peso, pidiendole el peso de un metro. Antes esta
 * pantalla tenia su propia copia de las cuentas y podia terminar diciendo algo
 * distinto que el servidor para la misma barra.
 *
 * Si la forma no tiene formula o faltan medidas devuelve null: no se inventa
 * un numero.
 */
/**
 * Las medidas sueltas de la linea, puestas donde las espera cada forma.
 *
 * La linea guarda diametro, espesor, ancho y largo porque es lo que se carga a
 * mano. Cada forma las nombra a su manera: el "diametro" de una cuadrada es el
 * lado, y el de una hexagonal la distancia entre caras.
 */
function medidasDeLaLinea(
  l: { diametro_mm?: number | null; espesor_mm?: number | null; ancho_mm?: number | null; largo_mm?: number | null },
  forma: Forma | null,
  largoForzadoMm?: number,
): Record<string, { valor: string; unidad: string }> {
  const medidas: Record<string, { valor: string; unidad: string }> = {}

  for (const campo of forma?.campos ?? []) {
    const valor =
      campo.clave === 'length'
        ? (largoForzadoMm ?? l.largo_mm)
        : ['diameter', 'outer', 'side', 'across'].includes(campo.clave)
          ? l.diametro_mm
          : ['wall', 'height'].includes(campo.clave)
            ? l.espesor_mm
            : campo.clave === 'width'
              ? l.ancho_mm
              : null

    medidas[campo.clave] = { valor: valor ? String(valor) : '', unidad: 'mm' }
  }

  return medidas
}

/** Donde guarda la linea, en milimetros, cada medida de la forma. */
const COLUMNA_DE_MEDIDA: Record<string, 'diametro_mm' | 'espesor_mm' | 'ancho_mm' | 'largo_mm'> = {
  diameter: 'diametro_mm',
  outer: 'diametro_mm',
  side: 'diametro_mm',
  across: 'diametro_mm',
  wall: 'espesor_mm',
  height: 'espesor_mm',
  width: 'ancho_mm',
  length: 'largo_mm',
}

/**
 * Las medidas de la calculadora, pasadas a las columnas sueltas de la linea.
 *
 * La linea las guarda en milimetros porque es lo que lee el resto del sistema
 * —el factor que saca el servidor, la busqueda, los reportes— mientras que la
 * calculadora las tiene con la unidad en que se escribieron ("25,4 mm", "1 in").
 *
 * Se llenan mientras se escribe y no al guardar: antes el servidor era el unico
 * que las completaba, asi que hasta el primer guardado la linea decia no tener
 * largo aunque estuviera a la vista.
 */
function planasDeLasMedidas(
  medidas: EstadoCalculadora['medidas'],
): Pick<LineaForm, 'diametro_mm' | 'espesor_mm' | 'ancho_mm' | 'largo_mm'> {
  const planas: Record<string, number | null> = {
    diametro_mm: null,
    espesor_mm: null,
    ancho_mm: null,
    largo_mm: null,
  }

  for (const [clave, m] of Object.entries(medidas)) {
    const columna = COLUMNA_DE_MEDIDA[clave]

    if (!columna) continue

    const mm = aMilimetros(m.valor, m.unidad)
    planas[columna] = mm > 0 ? mm : null
  }

  return planas
}

/** Milímetros que mide una unidad de largo. */
const LARGO_EN_MM: Record<string, number> = { MT: 1000, M: 1000, FT: 304.8, INCH: 25.4 }

/** Kilos que pesa una unidad de peso. */
const PESO_EN_KG: Record<string, number> = { KG: 1, TN: 1000, G: 0.001, LB: 0.45359237 }

/**
 * Cuánto entra de la unidad en que se factura dentro de UNA unidad de venta.
 *
 * Esto es lo que dice el rótulo del campo —"MT por UN"— y durante un tiempo no
 * fue lo que devolvía: la cuenta daba kilos siempre, sin mirar en qué unidad se
 * facturaba. Vendiendo por UN y facturando en MT, una barra de 3,21 m devolvía
 * 2,0169, que eran sus kilos rotulados como metros, y de ahí salían mal la
 * cantidad a facturar, el precio unitario y el importe.
 */
function factorAutomatico(
  l: LineaForm,
  catalogos: Catalogos | null,
): { factor: number | null; motivo: string | null } {
  const material = catalogos?.materiales.find((m) => m.id === l.material_id)
  const forma = catalogos?.formas.find((f) => f.id === l.forma_id)

  if (!forma) return { factor: null, motivo: 'Falta elegir la forma' }

  const codigo = (id: number | null | undefined) =>
    catalogos?.unidades.find((u) => u.id === id)?.codigo ?? ''
  const venta = codigo(l.unidad_venta_id)
  const factura = codigo(l.unidad_factura_id)

  /*
    Las medidas salen de la calculadora de la linea —lo que se esta
    escribiendo— y no de las columnas sueltas diametro_mm/largo_mm, que el
    servidor recien completa al guardar. Leyendolas de ahi, la pantalla avisaba
    "falta el largo de la pieza" con el largo cargado a la vista, y el factor
    no se calculaba nunca hasta despues de guardar.
  */
  const enMm = (clave: string) => {
    const m = l.calc.medidas[clave]

    return m ? aMilimetros(m.valor, m.unidad) : 0
  }

  // Milimetros que mide una unidad de venta, cuando se vende por largo.
  const largoDeUnaVenta = LARGO_EN_MM[venta] ?? null

  // Se factura por largo: el factor es el largo de la pieza, no su peso.
  if (LARGO_EN_MM[factura]) {
    if (largoDeUnaVenta) {
      return { factor: largoDeUnaVenta / LARGO_EN_MM[factura], motivo: null }
    }

    const largo = enMm('length')

    return largo
      ? { factor: largo / LARGO_EN_MM[factura], motivo: null }
      : { factor: null, motivo: 'Falta el largo de la pieza' }
  }

  if (!PESO_EN_KG[factura]) {
    return {
      factor: null,
      motivo: `No sabemos cuantos ${factura} entran en un ${venta}: escribi el factor a mano`,
    }
  }

  if (!largoDeUnaVenta && !enMm('length') && necesitaLargo(forma)) {
    return { factor: null, motivo: 'Falta el largo de la pieza' }
  }

  const medidas = largoDeUnaVenta
    ? { ...l.calc.medidas, length: { valor: String(largoDeUnaVenta), unidad: 'mm' } }
    : l.calc.medidas

  const r = calcularPeso({
    densidad: material?.densidad ? Number(material.densidad) : null,
    expresion: forma.expresion,
    campos: forma.campos ?? [],
    medidas,
    piezas: '1',
    unidadResultado: 'kg',
    usaCano: Boolean(forma.usa_cano),
    cano: null,
  })

  // Una sola pieza: su peso ES el factor, pasado a la unidad que se factura.
  return {
    factor: r.pesoPorPiezaKg === null ? null : r.pesoPorPiezaKg / PESO_EN_KG[factura],
    motivo: r.motivo,
  }
}

/** Si la forma lleva largo entre sus medidas. Un disco o una arandela no. */
function necesitaLargo(forma: Forma): boolean {
  return (forma.campos ?? []).some((c) => c.clave === 'length')
}

/**
 * El importe de la línea, o null si no hay con qué sacarlo.
 *
 * Sin cantidad no hay importe. Antes se tomaba la cantidad vacía como cero y
 * la pantalla mostraba "0,00", que no es lo mismo: las líneas traídas del
 * sistema anterior tienen su precio unitario pero no dicen cuántos, y la
 * cotización aparecía valiendo cero. El cero hay que ganárselo.
 */
function calcularImporte(l: LineaForm): number | null {
  if (l.cantidad === null || l.cantidad === undefined) return null

  const cant = Number(l.cantidad)

  if (!Number.isFinite(cant)) return null

  if (l.unidad_factura_id && l.unidad_venta_id && l.unidad_factura_id !== l.unidad_venta_id) {
    const factor = Number(l.factor_conversion ?? 0)
    const kilos = cant * factor

    if (l.precio_por_kilo) return Math.round(kilos * Number(l.precio_por_kilo) * 100) / 100
  }

  return Math.round(cant * Number(l.precio_unitario ?? 0) * 100) / 100
}

function Lineas({
  lineas,
  setLineas,
  cambiarLinea,
  catalogos,
  total,
  sinImporte,
  totalKilos,
  iguales,
  vigentes,
}: {
  lineas: LineaForm[]
  setLineas: (f: (p: LineaForm[]) => LineaForm[]) => void
  cambiarLinea: (clave: string, cambios: Partial<LineaForm>) => void
  catalogos: Catalogos | null
  total: number
  sinImporte: number
  totalKilos: number
  iguales: number
  vigentes: number
}) {
  const agregar = () => setLineas((p) => [...p, lineaVacia()])

  /*
    Diez barras del mismo material y distinto largo son diez líneas casi
    iguales. Se copia todo menos el precio: el precio depende de la medida y
    arrastrarlo en silencio es cotizar mal.
  */
  const copiarLaUltima = () =>
    setLineas((p) => {
      const ultima = p[p.length - 1]

      if (!ultima) return [...p, lineaVacia()]

      return [
        ...p,
        {
          ...ultima,
          clave: crypto.randomUUID(),
          precio_unitario: null,
          precio_por_kilo: null,
          quitada: false,
          // Las alternativas son de esa línea, no de la copia.
          opciones: [],
        },
      ]
    })

  return (
    <Card className="overflow-hidden">
      <CardHeader
        titulo="Lineas"
        chips={
          vigentes > 0 ? <Chip tono="neutro">{`${iguales} de ${vigentes} iguales a lo pedido`}</Chip> : undefined
        }
        ayuda="La tilde verde quiere decir que se cotiza igual a lo que pidieron. Al destildarla se abre lo que se cotiza y el motivo del cambio."
        acciones={
          <Accion onClick={agregar}>+ Agregar linea</Accion>
        }
      />

      <div className="flex flex-col gap-3 border-t border-[#eef2f6] px-[22px] py-4">
        {lineas.map((l, i) => (
          <LineaFila
            key={l.clave}
            numero={i + 1}
            linea={l}
            catalogos={catalogos}
            onCambio={(c) => cambiarLinea(l.clave, c)}
            onQuitar={() => setLineas((p) => p.filter((x) => x.clave !== l.clave))}
          />
        ))}

        {/*
          Los mismos dos botones abajo. Con diez líneas cargadas, agregar la
          once obligaba a subir hasta el título y volver a bajar.
        */}
        <div className="flex flex-wrap items-center gap-3 pt-0.5">
          <Accion onClick={agregar}>+ Agregar linea</Accion>
          {lineas.length > 0 && (
            <Accion onClick={copiarLaUltima}>+ Copiar la anterior, sin precio</Accion>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3 border-t border-[#eef2f6] bg-[#f8fafc] px-[22px] py-3">
        <span className="min-w-[240px] flex-1 text-[11.5px] text-muted">
          {vigentes - iguales > 0
            ? `${vigentes - iguales} ${vigentes - iguales === 1 ? 'línea se cotiza' : 'líneas se cotizan'} distinto a lo que pidieron. En la hoja del cliente sale aclarado.`
            : 'Todas las líneas se cotizan tal cual lo pidieron.'}
        </span>

        {/* Cuántos kilos suman las líneas que se facturan por peso. */}
        {totalKilos > 0 && (
          <span className="inline-flex items-center gap-2 rounded-lg border border-brand-200 bg-[#f3f9fe] px-3 py-1.5">
            <Scale size={14} strokeWidth={2} className="text-brand-600" />
            <span className="text-[11px] font-medium text-brand-600">Total de kilos</span>
            <span className="text-[13px] font-bold text-brand-600">{fmtCantidad(totalKilos)} KG</span>
          </span>
        )}

        {sinImporte > 0 && (
          <span className="text-[11px] text-warning-ink">
            {sinImporte === 1
              ? '1 línea sin cantidad: no suma al total'
              : `${sinImporte} líneas sin cantidad: no suman al total`}
          </span>
        )}

        <span className="text-[11.5px] font-medium text-muted">Total</span>
        <span className="w-28 text-right text-[14px] font-bold text-ink">{plata(total)}</span>
      </div>
    </Card>
  )
}

/**
 * Las medidas que pide la forma elegida.
 *
 * Aparecen y desaparecen con la forma: si es ALAMBRE pide diámetro y largo, si
 * es CHAPA pide ancho, espesor y largo. Cada una con su unidad, porque el
 * cliente manda pulgadas tan seguido como milímetros.
 *
 * Las definiciones vienen de la tabla de formas: agregar una forma nueva o
 * cambiarle las medidas no toca esta pantalla.
 */
/**
 * El material de la linea: se elige de la lista o se escribe.
 *
 * El catalogo del sistema anterior todavia no esta cargado, asi que la lista no
 * tiene todo lo que piden los clientes: "Titanio Grado 7" no figura y la linea
 * quedaba en "Sin elegir" — sin densidad, sin peso, sin factor y sin importe,
 * aunque el pedido dijera clarito que material era.
 *
 * Ahora lo escrito queda elegido igual. Al guardar, el servidor da de alta ese
 * material SIN densidad (no se inventa ninguna) y a partir de ahi aparece en la
 * lista para la proxima cotizacion. El peso sigue sin calcularse hasta que la
 * empresa cargue la densidad, y la pantalla lo dice en lugar de callarselo.
 */
/** "Ti-Ta" y "tita" se comparan igual: sin acentos, sin simbolos, en minuscula. */
function sinAcentos(t: string): string {
  return t
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]/g, '')
}

function ElegirMaterial({
  linea,
  catalogos,
  onCambio,
}: {
  linea: LineaForm
  catalogos: Catalogos | null
  onCambio: (c: Partial<LineaForm>) => void
}) {
  const materiales = catalogos?.materiales ?? []
  const elegido = materiales.find((m) => m.id === linea.material_id) ?? null
  const escrito = (linea.material_nuevo ?? '').trim()
  const esNuevo = !elegido && escrito !== ''
  const sinDensidad = Boolean(elegido) && !elegido?.densidad

  function escribir(valor: string) {
    /*
      Puede ser uno de la lista escrito a mano. Se compara ignorando mayusculas
      y acentos —igual que la base— para no dar de alta "titanio gr2" al lado
      del "TITANIO GR2" que ya existe.
    */
    const enLista = materiales.find(
      (m) => m.nombre.localeCompare(valor.trim(), 'es', { sensitivity: 'base' }) === 0,
    )

    onCambio(
      enLista
        ? { material_id: enLista.id, material_nuevo: null }
        : { material_id: null, material_nuevo: valor.trim() === '' ? null : valor },
    )
  }

  /*
    Al salir del campo, lo escrito a medias se engancha al de la lista.

    Escribir "tita" y pasar al siguiente campo daba de alta un material
    llamado "tita". El desplegable filtra mientras se escribe, pero si nadie
    elige, lo tipeado queda como esta. Ahora, si lo escrito aparece en UNO
    solo del catalogo, se toma ese; si aparece en varios o en ninguno, queda
    como material nuevo con su aviso, que es lo que se decidio en su momento.
  */
  function alSalir() {
    if (elegido || escrito === '') return

    const parecidos = materiales.filter((m) => sinAcentos(m.nombre).includes(sinAcentos(escrito)))

    if (parecidos.length === 1) {
      onCambio({ material_id: parecidos[0].id, material_nuevo: null })
    }
  }

  return (
    <Combo
      id={`material-${linea.clave}`}
      etiqueta="MATERIAL"
      onBlur={alSalir}
      ayuda={esNuevo ? 'no esta en el catalogo: se da de alta al guardar' : undefined}
      aviso={
        esNuevo
          ? 'Material nuevo, sin densidad: no se calcula el peso hasta que la carguen.'
          : sinDensidad
            ? `${elegido?.nombre} no tiene densidad cargada: no se calcula el peso.`
            : undefined
      }
      value={elegido?.nombre ?? linea.material_nuevo ?? ''}
      onChange={(e) => escribir(e.target.value)}
      placeholder="Elegi de la lista o escribi el que pidieron"
      opciones={materiales.map((m) => m.nombre)}
    />
  )
}

function MedidasDeLaForma({
  linea,
  catalogos,
  onCambio,
}: {
  linea: LineaForm
  catalogos: Catalogos | null
  onCambio: (c: Partial<LineaForm>) => void
}) {
  const forma = catalogos?.formas.find((f) => f.id === linea.forma_id) ?? null
  const campos = forma?.campos ?? []
  const cano = catalogos?.canos?.find((c) => c.id === linea.calc.canoId) ?? null

  // Una entrada por medida nominal, con su diametro exterior.
  const medidasDeCano = useMemo(() => {
    const vistas = new Map<string, CanoEstandar>()

    for (const c of catalogos?.canos ?? []) {
      if (!vistas.has(c.nombre)) vistas.set(c.nombre, c)
    }

    return [...vistas.values()]
  }, [catalogos])

  const schedulesDeLaMedida = useMemo(
    () => schedulesAgrupados((catalogos?.canos ?? []).filter((c) => c.nombre === cano?.nombre)),
    [catalogos, cano],
  )

  function cambiarMedida(clave: string, cambios: Partial<{ valor: string; unidad: string }>) {
    const actual = linea.calc.medidas[clave] ?? { valor: '', unidad: 'mm' }
    const medidas = { ...linea.calc.medidas, [clave]: { ...actual, ...cambios } }

    onCambio({
      calc: { ...linea.calc, medidas },
      // Las columnas sueltas van con la calculadora: son la misma medida.
      ...planasDeLasMedidas(medidas),
    })
  }

  /*
    Largos variables.

    Las barras y los caños no vienen todos del mismo largo: se ofrecen "de 2,80
    a 3,20 m". El peso, el factor y la cantidad a facturar necesitan UN largo, y
    el que corresponde es el promedio: por arriba se cobra de más y por abajo se
    entrega de más.

    Que el largo sea variable es tener los dos extremos cargados; no hay un
    campo aparte que pueda quedar marcado con el rango vacío.
  */
  const largoVariable = linea.largo_min_mm !== null || linea.largo_max_mm !== null

  /** Pasa milímetros a la unidad en la que se está escribiendo. */
  const desdeMm = (mm: number | null, unidad: string) =>
    mm === null ? '' : String(Number((mm / (A_MILIMETROS[unidad] ?? 1)).toFixed(4)))

  /**
   * Deja el rango y el promedio de una sola vez.
   *
   * Los extremos se guardan siempre en milímetros y el promedio va a la
   * calculadora en la unidad que se está usando, así cambiar de mm a m no
   * mueve el largo: sólo cambia cómo se lee.
   */
  function ponerRango(minMm: number | null, maxMm: number | null, unidad: string) {
    const promedioMm = minMm !== null && maxMm !== null ? (minMm + maxMm) / 2 : (minMm ?? maxMm)

    const medidas =
      promedioMm === null
        ? linea.calc.medidas
        : {
            ...linea.calc.medidas,
            length: {
              valor: String(Number((promedioMm / (A_MILIMETROS[unidad] ?? 1)).toFixed(4))),
              unidad,
            },
          }

    onCambio({
      largo_min_mm: minMm,
      largo_max_mm: maxMm,
      calc: { ...linea.calc, medidas },
      ...planasDeLasMedidas(medidas),
    })
  }

  function alternarLargoVariable(prende: boolean) {
    if (!prende) {
      onCambio({ largo_min_mm: null, largo_max_mm: null })

      return
    }

    // Arranca con el largo que ya estaba en los dos extremos: el promedio no
    // se mueve y sólo queda abrir el rango.
    const cargada = linea.calc.medidas.length
    const mm = cargada ? aMilimetros(cargada.valor, cargada.unidad) : 0

    ponerRango(mm, mm, cargada?.unidad ?? 'mm')
  }

  /** Elegir un caño completa el diámetro exterior y la pared. */
  /** Cambiar de medida elige el primer schedule de esa medida. */
  function elegirMedidaDeCano(nombre: string) {
    if (!nombre) return elegirCano(null)

    const primero = schedulesAgrupados(
      (catalogos?.canos ?? []).filter((c) => c.nombre === nombre),
    )[0]

    elegirCano(primero?.id ?? null)
  }

  function elegirCano(id: number | null) {
    const elegido = catalogos?.canos?.find((c) => c.id === id) ?? null

    const medidas = elegido
      ? {
          ...linea.calc.medidas,
          outer: { valor: String(elegido.diametro_mm), unidad: 'mm' },
          wall: { valor: String(elegido.pared_mm), unidad: 'mm' },
        }
      : linea.calc.medidas

    onCambio({
      calc: { ...linea.calc, canoId: id, medidas },
      ...planasDeLasMedidas(medidas),
    })
  }

  if (!forma) {
    return (
      <p className="mt-2.5 text-[11.5px] text-faint">
        Elegí la forma y aparecen las medidas que le corresponden.
      </p>
    )
  }

  if (campos.length === 0) {
    return (
      <p className="mt-2.5 text-[11.5px] text-warning-ink">
        {forma.nombre} no tiene medidas configuradas: la medida va en DIMENSIONES, como texto.
      </p>
    )
  }

  return (
    <div className="mt-2.5">
      <div className="mb-1.5 flex items-center gap-2">
        <Ruler size={12} strokeWidth={2.2} className="text-faint" />
        <span className="text-[10.5px] font-semibold uppercase tracking-wide text-faint">
          Medidas de {forma.nombre.toLowerCase()}
        </span>
      </div>

      {/*
        Dos pasos: primero la medida, después el schedule. En una sola lista son
        324 renglones. Y los schedules que comparten pared van juntos —para 2"
        el STD, el 40 y el 40S son los mismos 3,91 mm—, así que la lista de una
        medida pasa de diez renglones a seis.
      */}
      {forma.usa_cano && (
        <div className="mb-2 grid gap-2 sm:grid-cols-2 lg:w-2/3">
          <select
            aria-label="Medida del caño"
            value={cano?.nombre ?? ''}
            onChange={(e) => elegirMedidaDeCano(e.target.value)}
            className="h-[36px] w-full rounded-[7px] border border-line-strong bg-white px-2.5 text-[12.5px] outline-none focus:border-brand"
          >
            <option value="">Caño de medida especial — cargo las medidas yo</option>
            {medidasDeCano.map((m) => (
              <option key={m.nombre} value={m.nombre}>
                {m.nombre} · Ø{m.diametro_mm} mm
              </option>
            ))}
          </select>

          <select
            aria-label="Schedule del caño"
            value={linea.calc.canoId ?? ''}
            disabled={!cano}
            onChange={(e) => elegirCano(e.target.value ? Number(e.target.value) : null)}
            className="h-[36px] w-full rounded-[7px] border border-line-strong bg-white px-2.5 text-[12.5px] outline-none focus:border-brand disabled:bg-soft disabled:text-faint"
          >
            <option value="">{cano ? 'Elegí el schedule' : '—'}</option>
            {schedulesDeLaMedida.map((g) => (
              <option key={g.id} value={g.id}>
                SCH {g.etiqueta} · pared {g.pared} mm
              </option>
            ))}
          </select>
        </div>
      )}

      <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
        {campos.map((campo) => {
          const cargada = linea.calc.medidas[campo.clave] ?? { valor: '', unidad: 'mm' }
          const puestoPorCano = Boolean(cano) && (campo.clave === 'outer' || campo.clave === 'wall')

          // Con largo variable, en lugar del largo van los dos extremos.
          if (campo.clave === 'length' && largoVariable) {
            const leer = (v: string) => (v === '' ? null : aMilimetros(v, cargada.unidad))

            return (
              <div key={campo.clave} className="lg:col-span-2">
                <Etiqueta ayuda="el peso se saca del promedio">Largo, de mínimo a máximo</Etiqueta>
                <div className="flex">
                  <input
                    type="number"
                    step="any"
                    min="0"
                    aria-label="Largo mínimo"
                    value={desdeMm(linea.largo_min_mm ?? null, cargada.unidad)}
                    onChange={(e) =>
                      ponerRango(leer(e.target.value), linea.largo_max_mm ?? null, cargada.unidad)
                    }
                    className="h-[36px] min-w-0 flex-1 rounded-l-[7px] border border-line-strong bg-white px-[11px] text-[12.5px] tabular-nums outline-none focus:border-brand"
                  />
                  <span className="flex h-[36px] items-center border-y border-line-strong bg-app px-2 text-[11px] text-muted">
                    a
                  </span>
                  <input
                    type="number"
                    step="any"
                    min="0"
                    aria-label="Largo máximo"
                    value={desdeMm(linea.largo_max_mm ?? null, cargada.unidad)}
                    onChange={(e) =>
                      ponerRango(linea.largo_min_mm ?? null, leer(e.target.value), cargada.unidad)
                    }
                    className="h-[36px] min-w-0 flex-1 border-y border-line-strong bg-white px-[11px] text-[12.5px] tabular-nums outline-none focus:border-brand"
                  />
                  <select
                    aria-label="Unidad del largo"
                    value={cargada.unidad}
                    onChange={(e) =>
                      ponerRango(
                        linea.largo_min_mm ?? null,
                        linea.largo_max_mm ?? null,
                        e.target.value,
                      )
                    }
                    className="h-[36px] rounded-r-[7px] border border-l-0 border-line-strong bg-white px-1.5 text-[11px] text-muted outline-none focus:border-brand"
                  >
                    {UNIDADES_MEDIDA.map((u) => (
                      <option key={u} value={u}>
                        {u}
                      </option>
                    ))}
                  </select>
                </div>
              </div>
            )
          }

          return (
            <div key={campo.clave}>
              <Etiqueta ayuda={puestoPorCano ? 'lo pone el caño' : undefined}>{campo.label}</Etiqueta>
              <div className="flex">
                <input
                  type="number"
                  step="any"
                  min="0"
                  value={cargada.valor}
                  onChange={(e) => cambiarMedida(campo.clave, { valor: e.target.value })}
                  disabled={puestoPorCano}
                  className="h-[36px] min-w-0 flex-1 rounded-l-[7px] border border-line-strong bg-white px-[11px] text-[12.5px] tabular-nums outline-none focus:border-brand disabled:bg-[#f1f5f9] disabled:text-muted"
                />
                <select
                  value={cargada.unidad}
                  onChange={(e) => cambiarMedida(campo.clave, { unidad: e.target.value })}
                  disabled={puestoPorCano}
                  className="h-[36px] rounded-r-[7px] border border-l-0 border-line-strong bg-white px-1.5 text-[11px] text-muted outline-none focus:border-brand disabled:bg-[#f1f5f9]"
                >
                  {UNIDADES_MEDIDA.map((u) => (
                    <option key={u} value={u}>
                      {u}
                    </option>
                  ))}
                </select>
              </div>
            </div>
          )
        })}

        <div>
          <Etiqueta>Piezas</Etiqueta>
          <input
            type="number"
            step="any"
            min="0"
            value={linea.calc.piezas}
            onChange={(e) => onCambio({ calc: { ...linea.calc, piezas: e.target.value } })}
            className="h-[36px] w-full rounded-[7px] border border-line-strong bg-white px-[11px] text-[12.5px] tabular-nums outline-none focus:border-brand"
          />
        </div>
      </div>

      {/* Sólo donde tiene sentido: un disco o una esfera no llevan largo. */}
      {campos.some((c) => c.clave === 'length') && (
        <div className="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1">
          <Casilla marcada={largoVariable} onChange={alternarLargoVariable}>
            Largos variables
          </Casilla>
          {linea.largo_min_mm != null && linea.largo_max_mm != null && (
            <span className="text-[11px] text-muted">
              Se calcula con {fmtCantidad((linea.largo_min_mm + linea.largo_max_mm) / 2 / 1000)} m, el
              promedio del rango. El rango sale impreso en la hoja del cliente.
            </span>
          )}
        </div>
      )}
    </div>
  )
}

function LineaFila({
  numero,
  linea,
  catalogos,
  onCambio,
  onQuitar,
}: {
  numero: number
  linea: LineaForm
  catalogos: Catalogos | null
  onCambio: (c: Partial<LineaForm>) => void
  onQuitar: () => void
}) {
  const cambiaUnidad =
    Boolean(linea.unidad_factura_id) &&
    Boolean(linea.unidad_venta_id) &&
    linea.unidad_factura_id !== linea.unidad_venta_id

  const unidades = catalogos?.unidades ?? []
  const codigo = (id?: number | null) => unidades.find((u) => u.id === id)?.codigo ?? ''
  const [calculando, setCalculando] = useState(false)

  /*
    La medida y la descripción se van armando con lo que se carga: material,
    forma y medidas. Es lo que hoy se escribe a mano en cada línea.

    Se dejan de pisar en cuanto alguien las corrige — la descripción es lo que
    sale impreso y manda quien cotiza.
  */
  const formaElegida = catalogos?.formas.find((f) => f.id === linea.forma_id) ?? null
  const canoElegido = catalogos?.canos?.find((c) => c.id === linea.calc.canoId) ?? null
  const material = catalogos?.materiales.find((m) => m.id === linea.material_id) ?? null
  // El nombre manda, este o no en el catalogo: es lo que sale impreso.
  const nombreMaterial = material?.nombre ?? linea.material_nuevo ?? null

  const dimArmada = armarDimensiones(formaElegida, linea.calc.medidas, canoElegido)
  const descArmada = armarDescripcion(nombreMaterial, formaElegida, dimArmada)

  useEffect(() => {
    const cambios: Partial<LineaForm> = {}

    if (!linea.dimensionesAMano && dimArmada && dimArmada !== linea.dimensiones) {
      cambios.dimensiones = dimArmada
    }

    if (!linea.descripcionAMano && descArmada && descArmada !== linea.descripcion) {
      cambios.descripcion = descArmada
    }

    if (Object.keys(cambios).length > 0) onCambio(cambios)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dimArmada, descArmada, linea.dimensionesAMano, linea.descripcionAMano])

  // Se vende al peso: los kilos calculados SON la cantidad.
  const seVendeAlPeso = ['KG', 'TN'].includes(codigo(linea.unidad_venta_id))

  // El factor lo propone el sistema segun la forma; se puede corregir a mano.
  const automatico = cambiaUnidad ? factorAutomatico(linea, catalogos) : { factor: null, motivo: null }
  // Solo se anuncia si el servidor ya lo guardó como cargado a mano.
  const factorAMano = cambiaUnidad && linea.factor_conversion ? linea.factorAMano : null
  const sinFactor = cambiaUnidad && !linea.factor_conversion && automatico.factor === null

  useEffect(() => {
    if (cambiaUnidad && automatico.factor !== null && !linea.factor_conversion) {
      // Vista previa de lo que va a aplicar el servidor.
      onCambio({ factor_conversion: automatico.factor, aplicar_calculo_al_factor: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cambiaUnidad, automatico.factor])

  const importe = sinFactor ? null : calcularImporte(linea)
  const kilos = cambiaUnidad
    ? Number(linea.cantidad ?? 0) * Number(linea.factor_conversion ?? 0)
    : null

  /*
    Destildar "igual a lo que pidio" copia la linea al bloque de abajo.

    Al reves no tenia sentido y confundia: el lector carga la linea CON LO QUE
    PIDIO EL CLIENTE —lo saca de su mail—, asi que al destildar el bloque
    amarillo aparecia vacio y habia que volver a tipear ahi lo que ya estaba
    escrito arriba. Ahora se copia solo, y arriba queda para cambiar lo que se
    va a cotizar, que es lo unico que falta.

    Lo ya escrito a mano no se pisa: solo se completa lo que esta vacio.
  */
  /*
    Los campos de medida de la forma que pidio el cliente.

    pedido_forma es un nombre y no un id —se puede escribir una forma que no
    esta en el catalogo— asi que se busca por nombre. Si no aparece, no
    sabemos que medidas lleva y queda la caja de texto libre.
  */
  const formaPedida =
    catalogos?.formas.find((f) => f.nombre === linea.pedido_forma) ?? null
  const camposDeLoPedido = formaPedida?.campos ?? []

  /**
   * Cambia una medida de lo pedido y rearma el texto que sale impreso.
   *
   * pedido_dimensiones sigue siendo lo que se imprime: se arma con la misma
   * funcion que el de la linea de arriba, asi las dos mitades se escriben
   * igual y se pueden comparar.
   */
  function cambiarMedidaPedida(clave: string, cambios: Partial<{ valor: string; unidad: string }>) {
    const actual = linea.pedido_medidas?.[clave] ?? { valor: '', unidad: 'mm' }
    const medidas = { ...(linea.pedido_medidas ?? {}), [clave]: { ...actual, ...cambios } }

    onCambio({
      pedido_medidas: medidas,
      pedido_dimensiones: armarDimensiones(formaPedida, medidas, null) || null,
    })
  }

  function alternarIgualALoPedido() {
    // Estaba en distinto: vuelve a ser igual y no hay nada que copiar.
    if (! linea.igual_a_lo_pedido) {
      onCambio({ igual_a_lo_pedido: true })

      return
    }

    const material = catalogos?.materiales.find((m) => m.id === linea.material_id)
    const forma = catalogos?.formas.find((f) => f.id === linea.forma_id)

    onCambio({
      igual_a_lo_pedido: false,
      pedido_material: linea.pedido_material ?? material?.nombre ?? linea.material_nuevo ?? null,
      pedido_forma: linea.pedido_forma ?? forma?.nombre ?? null,
      pedido_dimensiones: linea.pedido_dimensiones ?? linea.dimensiones ?? null,
      // Las medidas arrancan en las que ya estaban cargadas: lo que leyó el
      // lector del pedido ES lo que pidió el cliente. Sólo hay que corregir
      // lo que se vaya a cotizar distinto.
      pedido_medidas:
        linea.pedido_medidas ??
        (Object.keys(linea.calc.medidas).length > 0 ? linea.calc.medidas : null),
      cantidad_pedida: linea.cantidad_pedida ?? linea.cantidad ?? null,
      unidad_pedida_id: linea.unidad_pedida_id ?? linea.unidad_venta_id ?? null,
    })
  }

  return (
    <div
      className={`rounded-[10px] border p-3 ${
        linea.quitada ? 'border-line bg-[#fcfaf6] opacity-70' : 'border-line bg-white'
      }`}
    >
      <div className="mb-2.5 flex flex-wrap items-center gap-2.5">
        <span className="grid h-6 w-6 place-items-center rounded-md bg-slate-100 text-[11px] font-bold text-slate-500">
          {numero}
        </span>

        <button
          type="button"
          onClick={alternarIgualALoPedido}
          className={`inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-[12px] font-semibold transition-colors ${
            linea.igual_a_lo_pedido
              ? 'border-[#cdebd8] bg-[#f4fbf6] text-success-ink'
              : 'border-[#f3d9a6] bg-[#fff8ee] text-warning-ink'
          }`}
        >
          <span
            className={`grid h-4 w-4 place-items-center rounded-[5px] border ${
              linea.igual_a_lo_pedido
                ? 'border-success-ink bg-success-ink text-white'
                : 'border-[#e0b57a] bg-white'
            }`}
          >
            {linea.igual_a_lo_pedido && <Check size={10} strokeWidth={3.5} />}
          </span>
          {linea.igual_a_lo_pedido ? 'Igual a lo que pidio' : 'Distinto a lo que pidio'}
        </button>

        {linea.quitada && <Chip tono="neutro">quitada</Chip>}

        <div className="ml-auto flex items-center gap-3">
          <Accion onClick={() => onCambio({ quitada: !linea.quitada })} apagado={!linea.quitada}>
            {linea.quitada ? 'Volver a poner' : 'Quitar de la cotizacion'}
          </Accion>
          <button
            type="button"
            aria-label={`Borrar la linea ${numero}`}
            onClick={onQuitar}
            className="text-faint transition-colors hover:text-danger"
          >
            <Trash2 size={14} strokeWidth={2} />
          </button>
        </div>
      </div>

      <div className="grid items-end gap-2.5 lg:grid-cols-2">
        <ElegirMaterial linea={linea} catalogos={catalogos} onCambio={onCambio} />
        <Lista
          etiqueta="FORMA"
          value={linea.forma_id ?? ''}
          onChange={(e) =>
            onCambio({
              forma_id: e.target.value ? Number(e.target.value) : null,
              // Otra forma pide otras medidas: las anteriores se van con ella,
              // el caño elegido tambien, y la previsualizacion arranca de cero.
              //
              // No se conserva nada aunque el nombre coincida: el "diametro" de
              // una barra redonda y el "lado" de una cuadrada son el mismo
              // numero con otro significado, y arrastrarlo en silencio saca un
              // peso equivocado que nadie revisa.
              calc: calculadoraVacia(),
              diametro_mm: null,
              espesor_mm: null,
              ancho_mm: null,
              factor_conversion: null,
            })
          }
          opciones={(catalogos?.formas ?? []).map((f) => ({ valor: f.id, texto: f.nombre }))}
        />
      </div>

      {/*
        Las medidas las pone la forma, no esta pantalla: una barra redonda pide
        diametro y largo, una chapa ancho, espesor y largo. Antes estaban los
        tres campos fijos —diametro, espesor, ancho— para todas las formas, y
        habia que adivinar cual llenar.
      */}
      <MedidasDeLaForma
        linea={linea}
        catalogos={catalogos}
        onCambio={onCambio}
      />

      <div className="mt-2.5">
        <Texto
          etiqueta="Descripcion — es lo que sale impreso"
          ayuda={
            linea.descripcionAMano
              ? 'la escribiste vos: ya no se actualiza sola'
              : 'se arma con el material, la forma y las medidas'
          }
          obligatorio
          value={linea.descripcion}
          onChange={(e) => onCambio({ descripcion: e.target.value, descripcionAMano: true })}
          placeholder="HASTELLOY C-276 BAR RED 38.1 X 145MM"
        />
        {linea.descripcionAMano && descArmada && descArmada !== linea.descripcion && (
          <button
            type="button"
            onClick={() =>
              onCambio({
                descripcion: descArmada,
                descripcionAMano: false,
                dimensiones: dimArmada,
                dimensionesAMano: false,
              })
            }
            className="mt-1 text-[11px] font-semibold text-brand-600 hover:text-brand"
          >
            Volver a armarla con los datos cargados: {descArmada}
          </button>
        )}
      </div>

      {/*
        Con qué reconoce el cliente este ítem. Sale de su requerimiento y va
        impreso: es lo que le permite comparar la oferta renglón por renglón
        contra lo que pidió, y después contra lo que recibe.
      */}
      <div className="mt-2.5 grid items-end gap-2.5 lg:grid-cols-[130px_1fr]">
        <Texto
          etiqueta="Item del cliente"
          value={linea.item_cliente ?? ''}
          onChange={(e) => onCambio({ item_cliente: e.target.value || null })}
          placeholder="21"
        />
        <Texto
          etiqueta="Codigo de articulo del cliente"
          value={linea.codigo_cliente ?? ''}
          onChange={(e) => onCambio({ codigo_cliente: e.target.value || null })}
          placeholder="SUO1413884-21"
        />
      </div>

      <div className="mt-2.5">
        <AreaTexto
          etiqueta="Nota del articulo — sale impresa"
          ayuda="plano, posicion, tratamiento: lo que haya que aclarar de este item"
          rows={2}
          value={linea.nota ?? ''}
          onChange={(e) => onCambio({ nota: e.target.value || null })}
          placeholder="Plano SUO1413884/1 posicion 21."
        />
      </div>

      <div className="mt-2.5 grid items-end gap-2.5 lg:grid-cols-6">
        <Texto
          etiqueta="Cantidad"
          type="number"
          value={linea.cantidad ?? ''}
          onChange={(e) => {
            const cantidad = e.target.value ? Number(e.target.value) : null

            // Las piezas de la calculadora siguen a la cantidad: si se cotizan
            // 3 barras, el peso de la linea es el de 3. Quedan editables por si
            // alguna vez no coinciden.
            onCambio({
              cantidad,
              calc: { ...linea.calc, piezas: String(cantidad ?? 1) },
            })
          }}
        />
        <Lista
          etiqueta="Unidad de venta"
          value={linea.unidad_venta_id ?? ''}
          onChange={(e) => {
            const venta = e.target.value ? Number(e.target.value) : null

            // Se factura en lo mismo que se vende, que es el caso normal. Si
            // hace falta otra —cotizar por metro y facturar por kilo— se
            // cambia al lado.
            onCambio({
              unidad_venta_id: venta,
              unidad_factura_id: linea.unidad_factura_id ?? venta,
            })
          }}
          opciones={unidades
            .filter((u) => u.sirve_para_vender)
            .map((u) => ({ valor: u.id, texto: u.codigo }))}
        />
        <Lista
          etiqueta="Se factura en"
          ayuda="si es distinta"
          value={linea.unidad_factura_id ?? ''}
          onChange={(e) =>
            onCambio({ unidad_factura_id: e.target.value ? Number(e.target.value) : null })
          }
          opciones={unidades
            .filter((u) => u.sirve_para_facturar)
            .map((u) => ({ valor: u.id, texto: u.codigo }))}
        />
        {cambiaUnidad ? (
          <>
            <Texto
              etiqueta="Factor"
              ayuda={`${codigo(linea.unidad_factura_id)} por ${codigo(linea.unidad_venta_id)}`}
              type="number"
              step="0.0001"
              value={linea.factor_conversion ?? ''}
              onChange={(e) =>
                onCambio({
                  factor_conversion: e.target.value ? Number(e.target.value) : null,
                  // Lo escribió una persona: ya no es el de la calculadora.
                  aplicar_calculo_al_factor: false,
                })
              }
            />
            <Texto
              etiqueta={`Precio por ${codigo(linea.unidad_factura_id).toLowerCase() || 'kilo'}`}
              type="number"
              step="0.01"
              value={linea.precio_por_kilo ?? ''}
              onChange={(e) =>
                onCambio({ precio_por_kilo: e.target.value ? Number(e.target.value) : null })
              }
            />
          </>
        ) : (
          <Texto
            etiqueta="Precio unitario"
            className="lg:col-span-2"
            type="number"
            step="0.01"
            value={linea.precio_unitario ?? ''}
            onChange={(e) =>
              onCambio({ precio_unitario: e.target.value ? Number(e.target.value) : null })
            }
          />
        )}
        <div className="flex min-w-0 flex-col">
          <Etiqueta>Importe</Etiqueta>
          <div
            className={`flex h-[36px] items-center justify-end rounded-[7px] border px-[11px] text-[12.5px] font-semibold ${
              sinFactor || importe === null
                ? 'border-[#f3d9a6] bg-[#fff8ee] text-warning-ink'
                : 'border-line-strong bg-app text-ink'
            }`}
          >
            {sinFactor ? 'no disponible' : importe === null ? 'sin cantidad' : plata(importe)}
          </div>
        </div>
      </div>

      {sinFactor && (
        <div className="mt-2 flex flex-wrap items-center gap-2 rounded-lg border border-[#f3d9a6] bg-[#fff8ee] px-3 py-2">
          <span className="text-[9.5px] font-bold text-warning-ink">Sin factor</span>
          <span className="min-w-0 flex-1 text-[11.5px] text-[#7a5a1e]">
            {automatico.motivo}. Se puede cargar el factor a mano, o cobrar por{' '}
            {codigo(linea.unidad_venta_id) || 'la unidad de venta'}, que no necesita el peso.
          </span>
          {/*
            Sin densidad no hay peso, y sin peso no hay kilos que cobrar. Pero
            cobrar por la unidad en que se vende no necesita ninguna de las dos
            cosas: importe = cantidad x precio. Antes habia que darse cuenta de
            que la salida era vaciar "se factura en", que dice "si es distinta".
          */}
          {linea.unidad_venta_id && (
            <Accion onClick={() => onCambio({ unidad_factura_id: null, factor_conversion: null })}>
              Cobrar por {codigo(linea.unidad_venta_id)}
            </Accion>
          )}
        </div>
      )}

      {/*
        Un factor cargado a mano queda identificado como tal, con quién lo puso
        y cuándo. Cuando un peso a mano termina en una factura y el número no
        cierra, hay que poder preguntarle a esa persona de dónde lo sacó.
      */}
      {factorAMano && (
        <div className="mt-2 flex flex-wrap items-center gap-2 rounded-lg border border-line bg-app px-3 py-1.5">
          <span className="text-[9.5px] font-bold uppercase tracking-wide text-muted">
            Factor cargado a mano
          </span>
          <span className="text-[11.5px] text-muted">
            No lo sacó la cuenta
            {factorAMano.quien && ` · lo puso ${factorAMano.quien}`}
            {factorAMano.cuando && ` el ${fmtFecha(factorAMano.cuando)}`}
          </span>
        </div>
      )}

      {cambiaUnidad && !sinFactor && (
        <p className="mt-2 text-[11px] text-brand-600">
          {fmtCantidad(linea.cantidad)} {codigo(linea.unidad_venta_id)} ×{' '}
          {fmtCantidad(linea.factor_conversion)} = {fmtCantidad(kilos)}{' '}
          {codigo(linea.unidad_factura_id)} × {plata(linea.precio_por_kilo)} por{' '}
          {codigo(linea.unidad_factura_id).toLowerCase()} = {plata(importe)}
        </p>
      )}

      {/*
        Lo que habia pedido el cliente, DEBAJO de lo que se cotiza.

        Estaba arriba, y en el orden al reves: primero lo que pidio y despues
        lo que se ofrece. Para leer una linea habia que arrancar por lo que no
        se va a vender. Ahora arriba esta lo que se cotiza —que es el trabajo—
        y abajo, en amarillo, contra que se lo compara.

        Los campos son los mismos y en el mismo orden que arriba: material,
        forma, medidas, cantidad y unidad. Antes eran cajas de texto libre, asi
        que no se podia elegir de la lista.
      */}
      {!linea.igual_a_lo_pedido && (
        <div className="mt-2.5 grid gap-2.5 rounded-lg border border-[#f3d9a6] bg-[#fff8ee] p-2.5 lg:grid-cols-6">
          <p className="text-[10.5px] font-bold uppercase tracking-wide text-warning-ink lg:col-span-6">
            Lo que habia pedido el cliente
          </p>
          <Combo
            id={`pedido-material-${linea.clave}`}
            etiqueta="Material"
            className="lg:col-span-2"
            value={linea.pedido_material ?? ''}
            onChange={(e) => onCambio({ pedido_material: e.target.value || null })}
            placeholder="Elegi de la lista o escribi lo que pidio"
            opciones={(catalogos?.materiales ?? []).map((m) => m.nombre)}
          />
          <Combo
            id={`pedido-forma-${linea.clave}`}
            etiqueta="Forma"
            value={linea.pedido_forma ?? ''}
            onChange={(e) => onCambio({ pedido_forma: e.target.value || null })}
            placeholder="BARRA REDONDA"
            opciones={(catalogos?.formas ?? []).map((f) => f.nombre)}
          />
          {/*
            Las medidas de lo pedido, con los nombres que les pone la forma.

            Era una caja llamada "Medidas" donde cada uno escribia lo que le
            parecia —"DIA 65 X 145", "Ø65x145mm"— mientras que arriba, en lo
            que se cotiza, hay un campo por medida: Diametro y Largo para una
            barra, Ancho y Largo para una chapa. Las dos mitades de la misma
            linea no se podian comparar de un vistazo, que es para lo que esta
            este bloque.

            Con una forma que no esta en el catalogo no sabemos que campos
            lleva: ahi sigue la caja de texto, y las lineas viejas tambien.
          */}
          {camposDeLoPedido.length > 0 ? (
            camposDeLoPedido.map((campo) => {
              const cargada = linea.pedido_medidas?.[campo.clave] ?? { valor: '', unidad: 'mm' }

              return (
                <div key={campo.clave}>
                  <Etiqueta>{campo.label}</Etiqueta>
                  <div className="flex">
                    <input
                      type="number"
                      step="any"
                      min="0"
                      aria-label={`${campo.label} pedido`}
                      value={cargada.valor}
                      onChange={(e) => cambiarMedidaPedida(campo.clave, { valor: e.target.value })}
                      className="h-[36px] min-w-0 flex-1 rounded-l-[7px] border border-line-strong bg-white px-[11px] text-[12.5px] tabular-nums outline-none focus:border-brand"
                    />
                    <select
                      aria-label={`Unidad de ${campo.label.toLowerCase()} pedido`}
                      value={cargada.unidad}
                      onChange={(e) => cambiarMedidaPedida(campo.clave, { unidad: e.target.value })}
                      className="h-[36px] rounded-r-[7px] border border-l-0 border-line-strong bg-white px-1.5 text-[11px] text-muted outline-none focus:border-brand"
                    >
                      {UNIDADES_MEDIDA.map((u) => (
                        <option key={u} value={u}>
                          {u}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
              )
            })
          ) : (
            <Texto
              etiqueta="Medidas"
              value={linea.pedido_dimensiones ?? ''}
              onChange={(e) => onCambio({ pedido_dimensiones: e.target.value || null })}
              placeholder="DIA 65 X 145 MM"
            />
          )}
          <Texto
            etiqueta="Cantidad"
            type="number"
            step="0.01"
            value={linea.cantidad_pedida ?? ''}
            onChange={(e) =>
              onCambio({ cantidad_pedida: e.target.value ? Number(e.target.value) : null })
            }
          />
          <Lista
            etiqueta="Unidad"
            value={linea.unidad_pedida_id ?? ''}
            onChange={(e) =>
              onCambio({ unidad_pedida_id: e.target.value ? Number(e.target.value) : null })
            }
            opciones={unidades.map((u) => ({ valor: u.id, texto: u.codigo }))}
          />
          {/*
            El motivo se elige o se escribe, y lo escrito queda en la lista.

            "Agregar mas opciones de motivos de cambio, debe ser mas
            administrable". Era un desplegable cerrado con siete opciones:
            agregar una octava era tocar el codigo. Igual que la condicion de
            pago, la primera vez se escribe y de ahi en mas esta en la lista.
          */}
          <Combo
            id={`motivo-${linea.clave}`}
            etiqueta="Motivo del cambio"
            className="lg:col-span-6"
            value={linea.motivo_cambio ?? ''}
            onChange={(e) => onCambio({ motivo_cambio: e.target.value || null })}
            placeholder="Elegi de la lista o escribi por que se cotiza distinto"
            opciones={catalogos?.motivos_cambio ?? []}
          />
        </div>
      )}

      {/*
        Marcas y stock. Se guardaban desde siempre y salian impresas, pero no
        habia donde verlas ni corregirlas al editar: la unica forma de poner
        una colada era el sistema anterior.
      */}
      <div className="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-line bg-app px-3 py-2">
        <Casilla marcada={Boolean(linea.aprox)} onChange={(v) => onCambio({ aprox: v })}>
          Medida y peso aproximados
        </Casilla>
        <Casilla marcada={Boolean(linea.idem)} onChange={(v) => onCambio({ idem: v })}>
          Idem al anterior
        </Casilla>
        <Casilla
          marcada={Boolean(linea.desde_stock)}
          onChange={(v) => onCambio({ desde_stock: v, ...(v ? {} : { deposito: null, colada: null }) })}
        >
          Sale de stock
        </Casilla>

        {/* Deposito y colada solo tienen sentido si sale de stock. */}
        {linea.desde_stock && (
          <div className="flex flex-1 flex-wrap items-end gap-2.5">
            <Texto
              etiqueta="Codigo de stock"
              className="min-w-[150px] flex-1"
              value={linea.deposito ?? ''}
              onChange={(e) => onCambio({ deposito: e.target.value || null })}
              placeholder="A-1204"
            />
            <Texto
              etiqueta="Colada"
              className="min-w-[150px] flex-1"
              value={linea.colada ?? ''}
              onChange={(e) => onCambio({ colada: e.target.value || null })}
              placeholder="H19798"
            />
          </div>
        )}
      </div>

      <div className="mt-2.5">
        <AlternativasDeLinea
          opciones={linea.opciones ?? []}
          linea={{
            cantidad: linea.cantidad,
            precio_unitario: linea.precio_unitario,
            material_id: linea.material_id,
          }}
          materiales={catalogos?.materiales ?? []}
          onCambio={(opciones) => onCambio({ opciones })}
        />
      </div>

      <div className="mt-2.5">
        <button
          type="button"
          onClick={() => setCalculando((v) => !v)}
          className="flex items-center gap-1.5 text-[11.5px] font-semibold text-brand-600 hover:text-brand"
        >
          <Calculator size={13} strokeWidth={2.2} />
          {calculando ? 'Cerrar la calculadora' : 'Calcular el peso'}
          {linea.calc.medidas && Object.keys(linea.calc.medidas).length > 0 && !calculando && (
            <span className="text-[10.5px] font-normal text-faint">· hay medidas cargadas</span>
          )}
        </button>

        {calculando && (
          <div className="mt-2">
            <CalculadoraDePeso
              catalogos={catalogos}
              materialId={linea.material_id ?? null}
              formaId={linea.forma_id ?? null}
              estado={linea.calc}
              medidasArriba
              onCambio={(calc) => onCambio({ calc })}
              aprox={linea.aprox}
              onAprox={(v) => onCambio({ aprox: v })}
              /*
                El boton solo aparece si los kilos tienen adonde ir: como
                factor cuando se factura en otra unidad, o como cantidad cuando
                se vende al peso. Un caño que se vende por metro y se factura
                por metro no lleva kilos a ningun lado — antes el boton pisaba
                los metros con los kilos y quedaba una cotizacion sin sentido.
              */
              onUsarPeso={cambiaUnidad || seVendeAlPeso ? (pesoKg) => {
                /*
                  El factor son los kilos de UNA unidad de venta.

                  Si se vende por metro, los kilos de un metro. Si se vende por
                  unidad, los de una pieza. No da igual: una barra Ø127 de
                  titanio pesa 57,13 kg el metro y 1,451 kg si la pieza mide
                  25,4 mm — cuarenta veces de diferencia en la factura.
                */
                const piezas = Number(linea.calc.piezas) || 1
                const largo = linea.calc.medidas.length
                const metros = largo ? aMilimetros(largo.valor, largo.unidad) / 1000 : 0
                const unidadVenta = catalogos?.unidades.find((u) => u.id === linea.unidad_venta_id)
                const porMetro = unidadVenta?.codigo === 'MT'
                const porUnidad = porMetro ? piezas * metros : piezas

                if (cambiaUnidad && porUnidad > 0) {
                  // Se le pide al servidor que aplique el cálculo. El número que
                  // se muestra es una vista previa: el que se guarda lo saca él,
                  // y por eso queda marcado como venido de la calculadora.
                  onCambio({
                    aplicar_calculo_al_factor: true,
                    factor_conversion: Math.round((pesoKg / porUnidad) * 10000) / 10000,
                  })

                  return
                }

                onCambio({ cantidad: pesoKg })
              } : undefined}
            />
          </div>
        )}
      </div>
    </div>
  )
}

/* -------------------------------------------------------- condiciones y nota */

function Condiciones({
  condiciones,
  setCondiciones,
  validez,
  vence,
  juego,
  onJuego,
  nota,
  onNota,
  catalogos,
}: {
  condiciones: CondicionForm[]
  setCondiciones: (f: CondicionForm[]) => void
  validez: number
  vence: string
  juego: '' | JuegoDeCondiciones
  onJuego: (j: '' | JuegoDeCondiciones) => void
  nota: string
  onNota: (v: string) => void
  catalogos: Catalogos | null
}) {
  const cambiar = (i: number, campo: keyof CondicionForm, valor: string) =>
    setCondiciones(
      // aMano: a partir de acá la escribió una persona y deja de rehacerse.
      condiciones.map((c, k) => (k === i ? { ...c, [campo]: valor, aMano: true } : c)),
    )

  /*
    Qué queda por agregar.

    Sólo lo que falta y sólo lo del juego elegido: los dos juegos tienen los
    mismos títulos, así que sin filtrar aparecía cada condición dos veces. Las
    que no son de ningún juego —como el ensayo, que se agrega cuando el cliente
    lo pide— se ofrecen siempre.
  */
  const porAgregar = (catalogos?.condiciones_habituales ?? []).filter(
    (c) =>
      c.titulo !== null &&
      !condiciones.some((puesta) => puesta.titulo === c.titulo) &&
      (c.juego === null || c.juego === juego),
  )

  // El bloque de validez ya dice hasta cuándo vale; la línea que arma el
  // sistema sería lo mismo escrito dos veces.
  const hayBloqueDeValidez = condiciones.some((c) => c.titulo.toLowerCase().includes('validez'))

  return (
    <div className="grid gap-[18px] lg:grid-cols-[1fr_520px]">
      <Card className="flex flex-col gap-3 p-[22px]">
        <div className="flex flex-wrap items-center gap-2.5">
          <h2 className="text-[15px] font-semibold text-ink">Condiciones</h2>
          <span className="ml-auto text-[11px] text-faint">Estas sí salen en el papel</span>
        </div>

        {/*
          Importación o stock: cambian el plazo de entrega y la forma de pago,
          así que no hay uno por defecto. Elegir carga los ocho bloques de ese
          juego; cambiar de juego los reemplaza.
        */}
        <div className="flex flex-wrap items-center gap-2 rounded-[7px] border border-line bg-soft px-[11px] py-2">
          <span className="text-[12px] text-muted">Condiciones de</span>
          {JUEGOS_DE_CONDICIONES.map((j) => (
            <button
              key={j}
              type="button"
              onClick={() => onJuego(j)}
              aria-pressed={juego === j}
              className={
                juego === j
                  ? 'rounded-[5px] bg-brand-600 px-[10px] py-1 text-[12px] font-semibold text-white'
                  : 'rounded-[5px] border border-line px-[10px] py-1 text-[12px] text-ink transition-colors hover:border-brand-200'
              }
            >
              {j === 'Importacion' ? 'Importación' : 'Stock'}
            </button>
          ))}
          {!juego && (
            <span className="text-[11px] text-warning-ink">
              Elegí uno: cambian el plazo de entrega y la forma de pago.
            </span>
          )}
        </div>

        {condiciones.length === 0 && (
          <p className="text-[12px] text-muted">
            Al elegir arriba entran los ocho párrafos que la empresa manda en cada oferta.
            Después se corrige cualquiera, o se saca con la papelera.
          </p>
        )}

        {condiciones.map((condicion, i) => (
          <div key={i} className="flex items-start gap-2">
            <div className="flex flex-1 flex-col gap-1.5">
              <Texto
                aria-label={`Titulo de la condicion ${i + 1}`}
                placeholder="Título (Entrega, Precios, Forma de Pago…)"
                className="font-semibold"
                value={condicion.titulo}
                onChange={(e) => cambiar(i, 'titulo', e.target.value)}
              />
              <AreaTexto
                aria-label={`Condicion ${i + 1}`}
                rows={condicion.texto.length > 220 ? 5 : 2}
                value={condicion.texto}
                onChange={(e) => cambiar(i, 'texto', e.target.value)}
              />
              {!condicion.imprime && (
                <span className="text-[11px] text-warning-ink">
                  Para uso interno: no sale en la hoja del cliente.
                </span>
              )}
            </div>
            <div className="mt-2 flex flex-col items-center gap-2">
              <button
                type="button"
                aria-label="Quitar condicion"
                onClick={() => setCondiciones(condiciones.filter((_, k) => k !== i))}
                className="text-faint transition-colors hover:text-danger"
              >
                <Trash2 size={14} strokeWidth={2} />
              </button>
              {/*
                Hay renglones que no son condiciones comerciales sino
                referencias que la empresa cruza con su otro sistema, como
                "1RA FILA X 1.10". Se guardan pero no salen impresas.
              */}
              <button
                type="button"
                aria-pressed={!condicion.imprime}
                title={
                  condicion.imprime
                    ? 'Sale en la hoja del cliente. Tocá para que no salga.'
                    : 'No sale en la hoja: es para uso interno.'
                }
                onClick={() =>
                  setCondiciones(
                    condiciones.map((c, k) => (k === i ? { ...c, imprime: !c.imprime } : c)),
                  )
                }
                className={
                  condicion.imprime
                    ? 'text-faint transition-colors hover:text-brand-600'
                    : 'text-warning-ink'
                }
              >
                {condicion.imprime ? <Eye size={14} strokeWidth={2} /> : <EyeOff size={14} strokeWidth={2} />}
              </button>
            </div>
          </div>
        ))}

        {!hayBloqueDeValidez && (
          <div className="flex items-center justify-between rounded-[7px] border border-brand-200 bg-[#f3f9fe] px-[11px] py-2">
            <span className="text-[12px] text-brand-600">
              Validez de la oferta: {validez} dias{vence ? ` — vence el ${vence}` : ''}
            </span>
            <Chip tono="brand">se agrega sola</Chip>
          </div>
        )}

        <div className="flex flex-wrap items-center gap-2">
          <Accion onClick={() => setCondiciones([...condiciones, { titulo: '', texto: '', imprime: true, aMano: true }])}>
            + Escribir una condicion
          </Accion>
          {porAgregar.map((c) => (
            <Accion
              key={c.id}
              apagado
              onClick={() =>
                setCondiciones([
                  ...condiciones,
                  {
                    titulo: c.titulo ?? '',
                    texto: conLaFecha(c.texto, vence),
                    imprime: true,
                    plantilla: c.texto,
                  },
                ])
              }
            >
              {/* El texto puede tener 700 caracteres: en el botón va el título. */}
              + {c.titulo}
            </Accion>
          ))}
        </div>
      </Card>

      <Card className="flex flex-col gap-3 border-[#f3d9a6] bg-[#fffdf7] p-[22px]">
        <div className="flex flex-wrap items-center gap-2.5">
          <h2 className="text-[15px] font-semibold text-warning-ink">NOTA</h2>
          <div className="ml-auto">
            <Chip tono="ambar">no se imprime</Chip>
          </div>
        </div>

        <AreaTexto
          aria-label="NOTA"
          filas={4}
          value={nota}
          onChange={(e) => onNota(e.target.value)}
          placeholder="(stock) bn — Volpor + Forest. Pagina 1 de 3."
        />

        <p className="text-[11.5px] leading-relaxed text-warning-ink">
          Es el mismo campo NOTA de hoy. El motivo de haber cotizado otra medida ya no va acá: tiene
          su propio campo en la línea.
        </p>
      </Card>
    </div>
  )
}

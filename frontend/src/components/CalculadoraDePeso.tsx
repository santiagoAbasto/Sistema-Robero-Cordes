import { useMemo } from 'react'
import { AlertTriangle, Calculator, RotateCcw, Check } from 'lucide-react'
import type { CanoEstandar, Catalogos, Forma } from '../types/indice'
import {
  UNIDADES_MEDIDA,
  UNIDADES_PESO,
  calcularPeso,
  type MedidaCargada,
} from '../lib/calculadora'
import { Boton } from './ui'

/* ---------------------------------------------------------------------------
   Calculadora de peso.

   Los campos los pone la forma, no esta pantalla: barra redonda pide diametro
   y largo, un anillo pide exterior, interior y espesor. Si mañana se agrega
   una forma con otras medidas, aparecen solas.

   Cada medida se carga en la unidad que venga —milimetros, pulgadas, pies— y
   se convierte antes de la cuenta. El resultado se rehace en cada tecla.
--------------------------------------------------------------------------- */

export interface EstadoCalculadora {
  medidas: Record<string, MedidaCargada>
  piezas: string
  unidadResultado: string
  canoId: number | null
}

/**
 * Los schedules de una medida, agrupados por pared.
 *
 * Para 2" el STD, el 40 y el 40S son la misma pared de 3,91 mm: mostrarlos por
 * separado son tres renglones que dicen lo mismo. Se agrupan como los agrupa
 * cualquier tabla de caños —"STD 40 40s"— y la lista pasa de diez renglones a
 * seis.
 *
 * El id que se guarda es el del schedule numerico del grupo, que es el que la
 * empresa escribe en la hoja ("CANO 4\" SCH 40").
 */
export function schedulesAgrupados(canos: CanoEstandar[]) {
  const porPared = new Map<string, CanoEstandar[]>()

  for (const c of canos) {
    const clave = String(c.pared_mm)
    porPared.set(clave, [...(porPared.get(clave) ?? []), c])
  }

  return [...porPared.values()]
    .map((grupo) => {
      const numerico = grupo.find((c) => /^\d+$/.test(c.schedule))

      return {
        id: (numerico ?? grupo[0]).id,
        etiqueta: grupo.map((c) => c.schedule).join(' '),
        pared: grupo[0].pared_mm,
        ids: grupo.map((c) => c.id),
      }
    })
    .sort((a, b) => Number(a.pared) - Number(b.pared))
}

export function calculadoraVacia(): EstadoCalculadora {
  return { medidas: {}, piezas: '1', unidadResultado: 'kg', canoId: null }
}

export default function CalculadoraDePeso({
  catalogos,
  materialId,
  formaId,
  estado,
  onCambio,
  onUsarPeso,
  aprox,
  onAprox,
  medidasArriba = false,
}: {
  catalogos: Catalogos | null
  materialId: number | null
  formaId: number | null
  estado: EstadoCalculadora
  onCambio: (e: EstadoCalculadora) => void
  /** Lleva los kilos a la linea: cantidad a facturar, o factor por metro. */
  onUsarPeso?: (pesoKg: number) => void
  /**
   * Si el peso y la medida se cotizan con tolerancia.
   *
   * El material real nunca sale exacto: una barra de Ø127 puede venir con
   * décimas de más o de menos, y el peso se corre. Marcado, la hoja dice
   * "(aprox.)" al lado de la descripción.
   */
  aprox?: boolean
  onAprox?: (v: boolean) => void
  /**
   * Las medidas se cargan arriba, en la linea. Acá solo se muestra el
   * resultado: repetir los mismos campos en dos lugares confunde y da lugar a
   * que uno quede desactualizado respecto del otro.
   */
  medidasArriba?: boolean
}) {
  const material = catalogos?.materiales.find((m) => m.id === materialId) ?? null
  const forma = (catalogos?.formas.find((f) => f.id === formaId) as Forma | undefined) ?? null
  const cano = catalogos?.canos?.find((c) => c.id === estado.canoId) ?? null
  const campos = useMemo(() => forma?.campos ?? [], [forma])

  const resultado = useMemo(
    () =>
      calcularPeso({
        densidad: material?.densidad ? Number(material.densidad) : null,
        expresion: forma?.expresion ?? null,
        campos,
        medidas: estado.medidas,
        piezas: estado.piezas,
        unidadResultado: estado.unidadResultado,
        usaCano: Boolean(forma?.usa_cano),
        cano,
      }),
    [material, forma, campos, estado, cano],
  )

  function cambiarMedida(clave: string, cambios: Partial<MedidaCargada>) {
    const actual = estado.medidas[clave] ?? { valor: '', unidad: 'mm' }

    onCambio({ ...estado, medidas: { ...estado.medidas, [clave]: { ...actual, ...cambios } } })
  }

  // La medida nominal sale del caño elegido: "2\"", "4\""...
  const medidaDelCano = cano?.nombre ?? ''

  // Una entrada por medida, con su diametro exterior.
  const medidasDeCano = useMemo(() => {
    const vistas = new Map<string, CanoEstandar>()

    for (const c of catalogos?.canos ?? []) {
      if (!vistas.has(c.nombre)) vistas.set(c.nombre, c)
    }

    return [...vistas.values()]
  }, [catalogos])

  const schedulesDeLaMedida = useMemo(
    () => schedulesAgrupados((catalogos?.canos ?? []).filter((c) => c.nombre === medidaDelCano)),
    [catalogos, medidaDelCano],
  )

  /** Cambiar de medida deja el schedule sin elegir: hay que volver a decirlo. */
  function elegirMedida(nombre: string) {
    if (!nombre) return elegirCano(null)

    const primero = schedulesAgrupados((catalogos?.canos ?? []).filter((c) => c.nombre === nombre))[0]

    elegirCano(primero?.id ?? null)
  }

  /** Elegir un caño completa el diametro exterior y la pared. */
  function elegirCano(id: number | null) {
    const elegido = catalogos?.canos?.find((c) => c.id === id) ?? null

    onCambio({
      ...estado,
      canoId: id,
      medidas: elegido
        ? {
            ...estado.medidas,
            outer: { valor: String(elegido.diametro_mm), unidad: 'mm' },
            wall: { valor: String(elegido.pared_mm), unidad: 'mm' },
          }
        : estado.medidas,
    })
  }

  if (!forma) {
    return (
      <Aviso>Elegí la forma y aparecen las medidas que hacen falta para pesarla.</Aviso>
    )
  }

  // Sin fórmula no hay peso que confirmar. Se dice con todas las letras y no
  // se muestra ningún resultado: un cero acá se copiaría al flete y a los
  // kilos de la cotización sin que nadie lo note.
  if (!forma.expresion) {
    return (
      <div className="rounded-[10px] border border-[#f3d9a6] bg-[#fff8ee] px-3.5 py-3">
        <div className="flex items-start gap-2.5">
          <AlertTriangle size={15} strokeWidth={2.2} className="mt-0.5 shrink-0 text-warning" />
          <div>
            <p className="text-[12.5px] font-semibold text-warning-ink">
              Esta forma no tiene cálculo de peso configurado
            </p>
            <p className="mt-1 text-[11.5px] leading-relaxed text-[#7a5a1e]">
              {forma.nombre} no es un sólido simple: el peso depende del plano. El sistema{' '}
              <strong>no calcula ni propone ningún peso</strong> — no se muestra cero.
            </p>
            <p className="mt-1.5 text-[11.5px] leading-relaxed text-[#7a5a1e]">
              La línea se cotiza igual por cantidad y precio. Si hace falta el peso —para el flete o
              para facturar por kilo— se carga a mano en el factor de la línea, y queda marcado como
              cargado a mano, no como calculado.
            </p>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-[10px] border border-line bg-app/40 p-3.5">
      <div className="mb-3 flex items-center gap-2">
        <Calculator size={14} strokeWidth={2.2} className="text-brand" />
        <span className="text-[12px] font-bold uppercase tracking-wide text-muted">
          Calculo de peso
        </span>
        {material?.densidad && (
          <span className="text-[10.5px] text-faint">
            {material.nombre} · {Number(material.densidad)} g/cm³
            {material.uns && ` · UNS ${material.uns}`}
            {material.w_nr && ` · W.Nr. ${material.w_nr}`}
          </span>
        )}
        <button
          type="button"
          onClick={() => onCambio(calculadoraVacia())}
          className="ml-auto flex items-center gap-1 text-[11px] font-semibold text-faint hover:text-ink"
        >
          <RotateCcw size={11} strokeWidth={2.2} /> Limpiar
        </button>
      </div>

      {/* caño de medida comercial */}
      {!medidasArriba && forma.usa_cano && (
        <label className="mb-3 block">
          <span className="mb-1 block text-[10.5px] font-semibold uppercase tracking-wide text-faint">
            Caño comercial <span className="normal-case text-faint">· o cargá las medidas abajo</span>
          </span>
          <div className="grid grid-cols-2 gap-2">
            <select
              aria-label="Medida nominal del caño"
              value={medidaDelCano}
              onChange={(e) => elegirMedida(e.target.value)}
              className="h-[32px] w-full rounded-[6px] border border-line-strong bg-white px-2 text-[12px] outline-none focus:border-brand"
            >
              <option value="">Medida especial (la cargo yo)</option>
              {medidasDeCano.map((m) => (
                <option key={m.nombre} value={m.nombre}>
                  {m.nombre} · Ø{m.diametro_mm} mm
                </option>
              ))}
            </select>

            <select
              aria-label="Schedule del caño"
              value={estado.canoId ?? ''}
              disabled={!medidaDelCano}
              onChange={(e) => elegirCano(e.target.value ? Number(e.target.value) : null)}
              className="h-[32px] w-full rounded-[6px] border border-line-strong bg-white px-2 text-[12px] outline-none focus:border-brand disabled:bg-soft disabled:text-faint"
            >
              <option value="">{medidaDelCano ? 'Elegí el schedule' : '—'}</option>
              {schedulesDeLaMedida.map((g) => (
                <option key={g.id} value={g.id}>
                  SCH {g.etiqueta} · pared {g.pared} mm
                </option>
              ))}
            </select>
          </div>
        </label>
      )}

      {/* las medidas de esta forma */}
      {!medidasArriba && (
      <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
        {campos.map((campo) => {
          const cargada = estado.medidas[campo.clave] ?? { valor: '', unidad: 'mm' }
          const puestoPorCano = Boolean(cano) && (campo.clave === 'outer' || campo.clave === 'wall')

          return (
            <label key={campo.clave} className="block">
              <span className="mb-1 block truncate text-[10.5px] font-semibold uppercase tracking-wide text-faint">
                {campo.label}
              </span>
              <div className="flex">
                <input
                  type="number"
                  step="any"
                  min="0"
                  value={cargada.valor}
                  onChange={(e) => cambiarMedida(campo.clave, { valor: e.target.value })}
                  disabled={puestoPorCano}
                  className="h-[32px] min-w-0 flex-1 rounded-l-[6px] border border-line-strong bg-white px-2 text-[12.5px] tabular-nums outline-none focus:border-brand disabled:bg-[#f1f5f9] disabled:text-muted"
                />
                <select
                  value={cargada.unidad}
                  onChange={(e) => cambiarMedida(campo.clave, { unidad: e.target.value })}
                  disabled={puestoPorCano}
                  className="h-[32px] rounded-r-[6px] border border-l-0 border-line-strong bg-white px-1 text-[11px] text-muted outline-none focus:border-brand disabled:bg-[#f1f5f9]"
                >
                  {UNIDADES_MEDIDA.map((u) => (
                    <option key={u} value={u}>
                      {u}
                    </option>
                  ))}
                </select>
              </div>
            </label>
          )
        })}

        <label className="block">
          <span className="mb-1 block text-[10.5px] font-semibold uppercase tracking-wide text-faint">
            Piezas
          </span>
          <input
            type="number"
            step="any"
            min="0"
            value={estado.piezas}
            onChange={(e) => onCambio({ ...estado, piezas: e.target.value })}
            className="h-[32px] w-full rounded-[6px] border border-line-strong bg-white px-2 text-[12.5px] tabular-nums outline-none focus:border-brand"
          />
        </label>
      </div>
      )}

      {/* resultado */}
      <div className="mt-3 flex flex-wrap items-center gap-3 rounded-[8px] border border-line bg-white px-3 py-2.5">
        {resultado.ok ? (
          <>
            <div className="min-w-0">
              <p className="text-[10.5px] font-semibold uppercase tracking-wide text-faint">Pesa</p>
              <p className="text-[19px] font-bold leading-tight tabular-nums text-ink">
                {resultado.resultado?.toLocaleString('es-AR', { maximumFractionDigits: 3 })}{' '}
                <span className="text-[12px] font-semibold text-muted">
                  {estado.unidadResultado}
                </span>
              </p>
            </div>

            <select
              value={estado.unidadResultado}
              onChange={(e) => onCambio({ ...estado, unidadResultado: e.target.value })}
              className="h-[28px] rounded-[6px] border border-line-strong bg-white px-1.5 text-[11px] text-muted outline-none focus:border-brand"
            >
              {UNIDADES_PESO.map((u) => (
                <option key={u} value={u}>
                  {u}
                </option>
              ))}
            </select>

            {/* El unitario se compara contra una tabla; el total va al flete. */}
            <div className="text-[11px] leading-relaxed text-faint">
              <p>
                Cada pieza:{' '}
                <span className="font-semibold text-muted tabular-nums">
                  {resultado.pesoPorPiezaKg?.toLocaleString('es-AR', { maximumFractionDigits: 3 })}{' '}
                  kg
                </span>{' '}
                · {resultado.volumenPorPiezaCm3?.toLocaleString('es-AR', { maximumFractionDigits: 2 })}{' '}
                cm³
              </p>
              <p>
                Las {Number(estado.piezas) || 1}:{' '}
                {resultado.volumenTotalCm3?.toLocaleString('es-AR', { maximumFractionDigits: 2 })} cm³
              </p>
            </div>

            {onAprox && (
              <label className="flex cursor-pointer items-center gap-1.5 text-[11px] text-muted">
                <input
                  type="checkbox"
                  checked={Boolean(aprox)}
                  onChange={(e) => onAprox(e.target.checked)}
                  className="h-3.5 w-3.5 accent-[var(--brand)]"
                />
                Es aproximado — sale “(aprox.)” en la hoja
              </label>
            )}

            {onUsarPeso && resultado.pesoTotalKg !== null && (
              <Boton
                variante="suave"
                onClick={() => onUsarPeso(resultado.pesoTotalKg as number)}
                className="ml-auto"
              >
                <Check size={13} strokeWidth={2.4} /> Usar estos kilos
              </Boton>
            )}
          </>
        ) : (
          <p className="text-[12px] text-warning-ink">{resultado.motivo}</p>
        )}
      </div>
    </div>
  )
}

function Aviso({ children }: { children: React.ReactNode }) {
  return (
    <div className="rounded-[10px] border border-line bg-app/40 px-3.5 py-3">
      <p className="text-[11.5px] leading-relaxed text-faint">{children}</p>
    </div>
  )
}

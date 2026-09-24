import { useEffect, useState } from 'react'
import { Plus, Trash2, Layers } from 'lucide-react'
import type { DatosOpcion } from '../lib/indice'
import { plata } from '../lib/indice'
import { traerSugerencias, type SugerenciasDeAlternativas } from '../lib/formas'
import { TIPOS_DE_OPCION } from '../types/indice'

/* ---------------------------------------------------------------------------
   Alternativas de una línea.

   Es el mismo ítem cotizado de otra manera, dentro de la misma cotización:

     · por transporte — marítimo 201 USD y 80 días, aéreo 210 USD y 40 días
     · por cantidad   — 36,6 m a 141,60; 73,2 m a 96,10; 109,8 m a 81,00
     · por material   — el mismo aporte en ALLOY 20 o en INCONEL 625

   Cada alternativa completa solo lo que cambia. Lo que se deja vacío lo hereda
   de la línea, así que una alternativa de precio no repite la medida.

   Una es la base: es la que cuenta para el total. Las demás se imprimen abajo
   como opciones, para que el cliente elija.
--------------------------------------------------------------------------- */

/** Lo que sale una alternativa, con lo que hereda de la línea ya resuelto. */
function importeDe(
  o: DatosOpcion,
  linea: { cantidad?: number | null; precio_unitario?: number | null },
): number {
  const cantidad = o.cantidad ?? linea.cantidad ?? 0
  const precio = o.precio_unitario ?? linea.precio_unitario ?? 0

  return Math.round(Number(cantidad) * Number(precio) * 100) / 100
}

/** Un atajo que arma las filas ya cargadas. */
function Atajo({ onClick, children }: { onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="rounded-full border border-line-strong bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-600 transition-colors hover:border-brand hover:bg-brand-50"
    >
      {children}
    </button>
  )
}

export default function AlternativasDeLinea({
  opciones,
  linea,
  onCambio,
}: {
  opciones: DatosOpcion[]
  linea: { cantidad?: number | null; precio_unitario?: number | null }
  onCambio: (o: DatosOpcion[]) => void
}) {
  const [sugerencias, setSugerencias] = useState<SugerenciasDeAlternativas | null>(null)

  useEffect(() => {
    let vivo = true

    traerSugerencias().then((s) => vivo && setSugerencias(s))

    return () => {
      vivo = false
    }
  }, [])

  function cambiar(i: number, cambios: Partial<DatosOpcion>) {
    onCambio(opciones.map((o, j) => (j === i ? { ...o, ...cambios } : o)))
  }

  /** La base es una sola: marcar otra desmarca la anterior. */
  function marcarBase(i: number) {
    onCambio(opciones.map((o, j) => ({ ...o, es_base: j === i })))
  }

  function agregar() {
    onCambio([
      ...opciones,
      {
        etiqueta: '',
        tipo: opciones[0]?.tipo ?? 'Transporte',
        es_base: opciones.length === 0,
        cantidad: null,
        precio_unitario: null,
        plazo_dias: null,
      },
    ])
  }

  /**
   * Las dos vías, con el plazo que se usó la última vez.
   *
   * Es lo que más se repite: sale del historial y queda listo para corregir.
   * Si nunca se cargó un plazo, viene en blanco — un plazo inventado se
   * convierte en un compromiso que nadie asumió.
   */
  function porTransporte() {
    const vias = sugerencias?.transporte ?? []

    onCambio(
      (vias.length > 0
        ? vias
        : [
            { etiqueta: 'Maritimo', plazo_dias: null },
            { etiqueta: 'Aereo', plazo_dias: null },
          ]
      ).map((v, i) => ({
        etiqueta: v.etiqueta,
        tipo: 'Transporte',
        es_base: i === 0,
        cantidad: null,
        precio_unitario: null,
        plazo_dias: v.plazo_dias ?? null,
      })),
    )
  }

  /**
   * Tres tramos de cantidad, a partir de lo que ya tiene la línea.
   *
   * Los múltiplos son un punto de partida: casi siempre se corrigen. Pero es
   * más rápido corregir tres números que escribir tres filas.
   */
  function porCantidad() {
    const base = Number(linea.cantidad) || 1
    const redondear = (n: number) => Math.round(n * 100) / 100

    onCambio(
      [1, 2, 3].map((x, i) => ({
        etiqueta: `${redondear(base * x)}`.replace('.', ','),
        tipo: 'Cantidad',
        es_base: i === 0,
        cantidad: redondear(base * x),
        precio_unitario: null,
        plazo_dias: null,
      })),
    )
  }

  function quitar(i: number) {
    const quedan = opciones.filter((_, j) => j !== i)

    // Si se fue la base, la primera que quede ocupa su lugar: algo tiene que
    // contar para el total.
    if (quedan.length > 0 && !quedan.some((o) => o.es_base)) quedan[0].es_base = true

    onCambio(quedan)
  }

  // Sin alternativas cargadas se ofrecen los dos casos que más se repiten,
  // ya armados. Escribirlos a mano cada vez es el trabajo que hay que evitar.
  if (opciones.length === 0) {
    const conPlazos = (sugerencias?.transporte ?? []).filter((v) => v.plazo_dias != null)

    return (
      <div className="flex flex-wrap items-center gap-2">
        <span className="flex items-center gap-1.5 text-[11.5px] text-faint">
          <Layers size={13} strokeWidth={2.2} /> Alternativas:
        </span>

        <Atajo onClick={porTransporte}>
          Aerea y maritima
          {conPlazos.length > 0 && (
            <span className="ml-1 font-normal text-faint">
              ({conPlazos.map((v) => `${v.plazo_dias} d`).join(' / ')})
            </span>
          )}
        </Atajo>

        <Atajo onClick={porCantidad}>
          Tramos de cantidad
          {linea.cantidad ? (
            <span className="ml-1 font-normal text-faint">
              ({[1, 2, 3].map((x) => Math.round(Number(linea.cantidad) * x * 100) / 100).join(' / ')})
            </span>
          ) : null}
        </Atajo>

        <button
          type="button"
          onClick={agregar}
          className="text-[11.5px] text-faint underline-offset-2 hover:text-ink hover:underline"
        >
          otra
        </button>
      </div>
    )
  }

  return (
    <div className="rounded-[10px] border border-line bg-app/40 p-3.5">
      <div className="mb-2.5 flex items-center gap-2">
        <Layers size={14} strokeWidth={2.2} className="text-brand" />
        <span className="text-[12px] font-bold uppercase tracking-wide text-muted">
          Alternativas
        </span>
        <span className="text-[10.5px] text-faint">
          lo que se deja vacío se toma de la línea
        </span>
        <button
          type="button"
          onClick={agregar}
          className="ml-auto flex items-center gap-1 text-[11px] font-semibold text-brand-600 hover:text-brand"
        >
          <Plus size={11} strokeWidth={2.6} /> Agregar
        </button>
      </div>

      <div className="grid grid-cols-[24px_1fr_110px_90px_100px_90px_90px_28px] items-center gap-2 px-1 pb-1 text-[10px] font-semibold uppercase tracking-wide text-faint">
        <span title="La que cuenta para el total">Base</span>
        <span>Como se llama</span>
        <span>Por que cambia</span>
        <span className="text-right">Cantidad</span>
        <span className="text-right">P. unitario</span>
        <span className="text-right">Entrega</span>
        <span className="text-right">Importe</span>
        <span />
      </div>

      <div className="flex flex-col gap-1.5">
        {opciones.map((o, i) => (
          <div
            key={i}
            className={`grid grid-cols-[24px_1fr_110px_90px_100px_90px_90px_28px] items-center gap-2 rounded-[7px] border px-1.5 py-1.5 ${
              o.es_base ? 'border-brand-200 bg-[#f3f9fe]' : 'border-line bg-white'
            }`}
          >
            <button
              type="button"
              onClick={() => marcarBase(i)}
              title="Esta es la que cuenta para el total"
              className={`mx-auto grid h-3.5 w-3.5 place-items-center rounded-full border ${
                o.es_base ? 'border-brand bg-brand' : 'border-slate-300 bg-white'
              }`}
            >
              {o.es_base && <span className="h-1.5 w-1.5 rounded-full bg-white" />}
            </button>

            <input
              value={o.etiqueta}
              onChange={(e) => cambiar(i, { etiqueta: e.target.value })}
              placeholder="Aereo, 73,2 m, INCONEL 625…"
              className="h-[28px] min-w-0 rounded-[6px] border border-line-strong bg-white px-2 text-[12px] outline-none focus:border-brand"
            />

            <select
              value={o.tipo}
              onChange={(e) => cambiar(i, { tipo: e.target.value })}
              className="h-[28px] rounded-[6px] border border-line-strong bg-white px-1 text-[11.5px] text-muted outline-none focus:border-brand"
            >
              {TIPOS_DE_OPCION.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </select>

            <Numero
              valor={o.cantidad}
              heredado={linea.cantidad}
              onChange={(v) => cambiar(i, { cantidad: v })}
            />
            <Numero
              valor={o.precio_unitario}
              heredado={linea.precio_unitario}
              onChange={(v) => cambiar(i, { precio_unitario: v })}
            />

            <div className="flex items-center">
              <input
                type="number"
                min="0"
                value={o.plazo_dias ?? ''}
                onChange={(e) =>
                  cambiar(i, { plazo_dias: e.target.value ? Number(e.target.value) : null })
                }
                placeholder="—"
                className="h-[28px] w-full rounded-[6px] border border-line-strong bg-white px-2 text-right text-[12px] tabular-nums outline-none placeholder:text-faint focus:border-brand"
              />
              <span className="ml-1 text-[10px] text-faint">d</span>
            </div>

            <span className="text-right text-[12px] font-semibold tabular-nums text-ink">
              {plata(importeDe(o, linea))}
            </span>

            <button
              type="button"
              onClick={() => quitar(i)}
              className="rounded p-1 text-faint hover:bg-app hover:text-danger"
            >
              <Trash2 size={12} strokeWidth={2.2} />
            </button>
          </div>
        ))}
      </div>

      <p className="mt-2 text-[10.5px] leading-relaxed text-faint">
        La marcada como base es la que suma al total de la cotización. Las otras salen impresas
        debajo, como opciones para que el cliente elija.
      </p>
    </div>
  )
}

/** Un número que, vacío, muestra lo que heredaría de la línea. */
function Numero({
  valor,
  heredado,
  onChange,
}: {
  valor: number | null | undefined
  heredado: number | null | undefined
  onChange: (v: number | null) => void
}) {
  return (
    <input
      type="number"
      step="any"
      min="0"
      value={valor ?? ''}
      onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}
      placeholder={heredado != null ? String(heredado) : '—'}
      className="h-[28px] w-full rounded-[6px] border border-line-strong bg-white px-2 text-right text-[12px] tabular-nums outline-none placeholder:text-faint placeholder:italic focus:border-brand"
    />
  )
}

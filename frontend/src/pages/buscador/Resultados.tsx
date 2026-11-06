import { Link } from 'react-router-dom'
import { Accion, Card, Chip, Tabla, Td, Th, cx } from '../../components/ui'
import {
  cantidad,
  estadoDeConsulta,
  fecha as fmtFecha,
  FILA_INACTIVA,
  plata,
} from '../../lib/indice'
import type {
  EmpresaDeFila,
  FilaComprobante,
  FilaConsulta,
  FilaEmpresa,
  FilaRenglon,
  Grano,
  ImporteDeMoneda,
  ResumenDelBuscador,
} from '../../types/buscador'

/* ---------------------------------------------------------------------------
   Lo que devuelve el buscador, una tabla por vista.

   Una empresa que cerró se ve igual que en todo el sistema: fila rosada,
   nombre rojo y la marca "Inactiva". El servidor ya las manda al final.
--------------------------------------------------------------------------- */

const SIN_DATO = <span className="text-faint">—</span>

function NombreDeLaEmpresa({ empresa }: { empresa: EmpresaDeFila }) {
  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      <Link
        to={`/empresas/${empresa.id}`}
        className={cx(
          'font-semibold',
          empresa.activa ? 'text-ink hover:text-brand-600' : 'text-[#b91c1c] hover:text-[#991b1b]',
        )}
      >
        {empresa.nombre}
      </Link>
      {!empresa.activa && <Chip tono="rojo">Inactiva</Chip>}
    </span>
  )
}

const clase = (activa: boolean) => `transition-colors ${activa ? 'hover:bg-[#f8fcfe]' : FILA_INACTIVA}`

/* ----------------------------------------------------------------- empresas */

export function TablaDeEmpresas({ filas }: { filas: FilaEmpresa[] }) {
  return (
    <Tabla>
      <thead>
        <tr>
          <Th>EMPRESA</Th>
          <Th ancho="190px">Contactar a:</Th>
          <Th ancho="140px">Localidad</Th>
          <Th ancho="140px">Rubro</Th>
          <Th ancho="70px" derecha>Cotiz.</Th>
          <Th ancho="110px">Última</Th>
          <Th ancho="230px" />
        </tr>
      </thead>
      <tbody>
        {filas.map((e) => (
          <tr key={e.id} className={clase(e.activa)}>
            <Td>
              <div className="flex flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2">
                  <NombreDeLaEmpresa empresa={e} />
                  {e.relaciones.map((r) => (
                    <Chip key={r} tono={r === 'Cliente' ? 'verde' : r === 'Proveedor' ? 'brand' : 'neutro'}>
                      {r}
                    </Chip>
                  ))}
                </div>
                {e.tambien_factura_como && (
                  <span className="text-[10.5px] text-faint">
                    También factura como {e.tambien_factura_como}
                    {e.otras_razones > 0 && `  +${e.otras_razones}`}
                  </span>
                )}
              </div>
            </Td>
            <Td>
              {e.contacto_principal ? (
                <span className="inline-flex items-center gap-1.5">
                  {e.contacto_principal.nombre}
                  {e.otros_contactos > 0 && (
                    <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[9.5px] font-semibold text-slate-500">
                      +{e.otros_contactos}
                    </span>
                  )}
                </span>
              ) : (
                SIN_DATO
              )}
            </Td>
            <Td>{e.localidad ?? SIN_DATO}</Td>
            <Td>{e.rubro ?? SIN_DATO}</Td>
            <Td derecha className="font-semibold text-ink">{e.cotizaciones}</Td>
            <Td className="whitespace-nowrap">{e.ultima_cotizacion ? fmtFecha(e.ultima_cotizacion) : SIN_DATO}</Td>
            <Td>
              <div className="flex items-center justify-end gap-3.5">
                <Accion to={`/empresas/${e.id}`}>Ver ficha</Accion>
                <Accion to={`/empresas/${e.id}#cotizaciones`}>Cotizaciones</Accion>
                <Accion to={`/imprimir?empresa=${e.id}`}>Imprimir</Accion>
              </div>
            </Td>
          </tr>
        ))}
      </tbody>
    </Tabla>
  )
}

/* ------------------------------------------------------------ cotizaciones */

export function TablaDeConsultas({
  filas,
  veImportes,
  puedeDescartar,
  alDescartar,
}: {
  filas: FilaConsulta[]
  veImportes: boolean
  /** Sólo los administradores descartan borradores y guardadas sin emitir. */
  puedeDescartar: boolean
  alDescartar: (consulta: { id: number; empresa: string; sinEmitir?: boolean }) => void
}) {
  return (
    <Tabla>
      <thead>
        <tr>
          <Th ancho="95px">FECHA</Th>
          <Th ancho="105px">N°</Th>
          <Th ancho="220px">EMPRESA</Th>
          <Th>Qué se cotizó</Th>
          {veImportes && <Th ancho="120px" derecha>Importe</Th>}
          <Th ancho="60px">La hizo</Th>
          <Th ancho="150px">Estado</Th>
          <Th ancho="150px" />
        </tr>
      </thead>
      <tbody>
        {filas.map((c) => {
          const estado = estadoDeConsulta(c)
          const datosDeLaVenta = [
            c.solicitud_nro && `OC ${c.solicitud_nro}`,
            c.nro_pedido_interno && `Pedido ${c.nro_pedido_interno}`,
            c.nro_factura && `Factura ${c.nro_factura}`,
            c.nro_remito && `Remito ${c.nro_remito}`,
          ].filter(Boolean)

          return (
            <tr key={c.id} className={clase(c.empresa.activa)}>
              <Td className="whitespace-nowrap font-semibold text-ink">{fmtFecha(c.fecha)}</Td>
              <Td className="whitespace-nowrap">{c.numero ?? SIN_DATO}</Td>
              <Td><NombreDeLaEmpresa empresa={c.empresa} /></Td>
              <Td>
                {c.detalle.map((d, i) => (
                  <div key={i} className="max-w-[420px] truncate">{d}</div>
                ))}
                {c.cantidad_de_lineas > c.detalle.length && (
                  <span className="text-[10.5px] text-faint">
                    +{c.cantidad_de_lineas - c.detalle.length} líneas más
                  </span>
                )}
                {datosDeLaVenta.length > 0 && (
                  <div className="mt-0.5 text-[10.5px] text-muted">{datosDeLaVenta.join(' · ')}</div>
                )}
              </Td>
              {veImportes && (
                <Td derecha className="whitespace-nowrap font-semibold text-ink">
                  {c.total === null ? SIN_DATO : <>{plata(c.total)}{c.moneda && <span className="ml-1 text-[10px] font-medium text-faint">{abreviar(c.moneda)}</span>}</>}
                </Td>
              )}
              <Td className="text-brand-600">{c.usuario ?? SIN_DATO}</Td>
              <Td><Chip tono={estado.tono}>{estado.texto}</Chip></Td>
              <Td>
                <div className="flex items-center justify-end gap-3">
                  <Accion to={`/consultas/${c.id}`}>Abrir</Accion>
                  <Accion to={`/imprimir?empresa=${c.empresa.id}&consulta=${c.id}`}>Imprimir</Accion>
                  {puedeDescartar && (c.descartable ?? c.estado === 'Borrador') && (
                    <Accion
                      apagado
                      onClick={() => alDescartar({ id: c.id, empresa: c.empresa.nombre, sinEmitir: c.estado !== 'Borrador' })}
                    >
                      Descartar
                    </Accion>
                  )}
                </div>
              </Td>
            </tr>
          )
        })}
      </tbody>
    </Tabla>
  )
}

/* ---------------------------------------------------------------- renglones */

/** "Ø20 · largo 1.000 mm": las medidas que tenga, en el orden en que se leen. */
function medidas(r: FilaRenglon): string {
  const partes = [
    r.diametro_mm !== null && `Ø${cantidad(r.diametro_mm)}`,
    r.espesor_mm !== null && `esp. ${cantidad(r.espesor_mm)}`,
    r.ancho_mm !== null && `ancho ${cantidad(r.ancho_mm)}`,
    r.largo_mm !== null && `largo ${cantidad(r.largo_mm)}`,
  ].filter(Boolean)

  return partes.length ? `${partes.join(' · ')} mm` : ''
}

export function TablaDeRenglones({ filas, veImportes }: { filas: FilaRenglon[]; veImportes: boolean }) {
  return (
    <Tabla>
      <thead>
        <tr>
          <Th ancho="95px">FECHA</Th>
          <Th ancho="210px">EMPRESA</Th>
          <Th>Qué se cotizó</Th>
          <Th ancho="105px" derecha>Cantidad</Th>
          {veImportes && <Th ancho="110px" derecha>Precio</Th>}
          {veImportes && <Th ancho="120px" derecha>Importe</Th>}
          <Th ancho="140px">Cotización</Th>
          <Th ancho="70px" />
        </tr>
      </thead>
      <tbody>
        {filas.map((r) => {
          const estado = estadoDeConsulta(r.consulta)
          const ficha = [r.material ?? r.pedido_material, r.forma, medidas(r)].filter(Boolean).join(' · ')

          return (
            <tr key={r.id} className={clase(r.empresa.activa)}>
              <Td className="whitespace-nowrap font-semibold text-ink">{fmtFecha(r.consulta.fecha)}</Td>
              <Td><NombreDeLaEmpresa empresa={r.empresa} /></Td>
              <Td>
                <div className="flex flex-wrap items-center gap-1.5">
                  <span className="max-w-[420px] truncate font-medium text-ink">{r.descripcion ?? ficha}</span>
                  {r.es_alternativa && <Chip tono="violeta">Alternativa</Chip>}
                  {r.comprada && <Chip tono="verde">Comprada</Chip>}
                  {r.no_cotizada && <Chip tono="ambar">No se cotizó</Chip>}
                </div>
                {r.descripcion && ficha && <div className="text-[10.5px] text-muted">{ficha}</div>}
                {r.item_cliente && <div className="text-[10.5px] text-faint">Ítem {r.item_cliente} del cliente</div>}
              </Td>
              <Td derecha className="whitespace-nowrap">
                {r.cantidad !== null ? <>{cantidad(r.cantidad)} {r.unidad}</> : SIN_DATO}
              </Td>
              {veImportes && (
                <Td derecha className="whitespace-nowrap">{r.precio_unitario !== null ? plata(r.precio_unitario) : SIN_DATO}</Td>
              )}
              {veImportes && (
                <Td derecha className="whitespace-nowrap font-semibold text-ink">
                  {r.importe !== null ? <>{plata(r.importe)}{r.moneda && <span className="ml-1 text-[10px] font-medium text-faint">{abreviar(r.moneda)}</span>}</> : SIN_DATO}
                </Td>
              )}
              <Td>
                <div className="flex flex-col gap-1">
                  {/* Las cotizaciones del sistema anterior no tienen número: no se pone un guion suelto. */}
                  {r.consulta.numero && <span className="whitespace-nowrap">{r.consulta.numero}</span>}
                  <Chip tono={estado.tono}>{estado.texto}</Chip>
                </div>
              </Td>
              <Td><Accion to={`/consultas/${r.consulta.id}`}>Abrir</Accion></Td>
            </tr>
          )
        })}
      </tbody>
    </Tabla>
  )
}

/* ------------------------------------------------------------- comprobantes */

export function TablaDeComprobantes({ filas, veImportes }: { filas: FilaComprobante[]; veImportes: boolean }) {
  return (
    <Tabla>
      <thead>
        <tr>
          <Th ancho="95px">FECHA</Th>
          <Th ancho="170px">Tipo</Th>
          <Th ancho="150px">Número</Th>
          {veImportes && <Th ancho="130px" derecha>Importe</Th>}
          <Th ancho="230px">EMPRESA</Th>
          <Th>Cotización</Th>
          <Th ancho="70px" />
        </tr>
      </thead>
      <tbody>
        {filas.map((k) => {
          const estado = estadoDeConsulta(k.consulta)

          return (
            <tr key={k.id} className={clase(k.empresa.activa)}>
              <Td className="whitespace-nowrap font-semibold text-ink">{k.fecha ? fmtFecha(k.fecha) : SIN_DATO}</Td>
              <Td>{k.tipo}</Td>
              <Td className="whitespace-nowrap font-medium text-ink">{k.numero ?? SIN_DATO}</Td>
              {veImportes && (
                <Td derecha className="whitespace-nowrap font-semibold text-ink">
                  {k.importe !== null ? <>{plata(k.importe)}{k.moneda && <span className="ml-1 text-[10px] font-medium text-faint">{abreviar(k.moneda)}</span>}</> : SIN_DATO}
                </Td>
              )}
              <Td><NombreDeLaEmpresa empresa={k.empresa} /></Td>
              <Td>
                <div className="flex flex-wrap items-center gap-2">
                  {k.consulta.numero && <span className="whitespace-nowrap">{k.consulta.numero}</span>}
                  <Chip tono={estado.tono}>{estado.texto}</Chip>
                  {k.consulta.solicitud_nro && (
                    <span className="text-[10.5px] text-muted">OC {k.consulta.solicitud_nro}</span>
                  )}
                </div>
                {k.nota && <div className="mt-0.5 text-[10.5px] text-muted">{k.nota}</div>}
              </Td>
              <Td><Accion to={`/consultas/${k.consulta.id}`}>Abrir</Accion></Td>
            </tr>
          )
        })}
      </tbody>
    </Tabla>
  )
}

/** "DOLAR BILLETE BNA VENDEDOR" -> "DOLAR": lo que se lee de un vistazo al lado de un importe. */
function abreviar(moneda: string): string {
  return moneda.split(' ')[0]
}

/* ------------------------------------------------------------------- resumen */

function Dato({ valor, etiqueta, sub, tono }: { valor: string; etiqueta: string; sub?: string; tono?: string }) {
  return (
    <Card className="flex flex-col gap-0.5 px-4 py-3.5">
      <span className={cx('text-[20px] font-bold leading-tight', tono ?? 'text-ink')}>{valor}</span>
      <span className="text-[11.5px] font-medium text-muted">{etiqueta}</span>
      {sub && <span className="text-[10.5px] text-faint">{sub}</span>}
    </Card>
  )
}

/**
 * Lo que dicen los números de TODO el resultado, no de la página que se ve.
 * Sin importes para quien no los ve: el servidor ni los manda.
 */
export function Resumen({
  grano,
  resumen,
  total,
}: {
  grano: Grano
  resumen: ResumenDelBuscador
  total: number
}) {
  const importes: ImporteDeMoneda[] = resumen.importe_por_moneda ?? []
  // [singular, plural, ¿femenino?]: "1 cotización encontrada", "13.070 renglones encontrados".
  const [uno, varios, femenino] = {
    empresas: ['empresa', 'empresas', true],
    consultas: ['cotización', 'cotizaciones', true],
    renglones: ['renglón', 'renglones', false],
    comprobantes: ['comprobante', 'comprobantes', false],
  }[grano] as [string, string, boolean]
  const encontradas = `${total === 1 ? uno : varios} ${femenino ? 'encontrada' : 'encontrado'}${total === 1 ? '' : 's'}`
  const porEstado = Object.entries(resumen.por_estado ?? {})
  const porTipo = Object.entries(resumen.por_tipo ?? {})

  return (
    <div className="flex flex-col gap-2.5">
      <div className="grid gap-2.5 sm:grid-cols-2 xl:grid-cols-4">
        <Dato valor={total.toLocaleString('es-AR')} etiqueta={`${encontradas[0].toUpperCase()}${encontradas.slice(1)}`} />

        {grano === 'empresas' && (
          <>
            <Dato valor={(resumen.activas ?? 0).toLocaleString('es-AR')} etiqueta="Activas" tono="text-success-ink" />
            {(resumen.inactivas ?? 0) > 0 && (
              <Dato valor={(resumen.inactivas ?? 0).toLocaleString('es-AR')} etiqueta="Inactivas" tono="text-[#b91c1c]" />
            )}
          </>
        )}

        {importes.map((i) => {
          const cuenta = i.cotizaciones ?? i.renglones ?? i.comprobantes
          const [una, varias] = i.cotizaciones !== undefined
            ? ['cotización', 'cotizaciones']
            : i.renglones !== undefined
              ? ['renglón', 'renglones']
              : ['comprobante', 'comprobantes']
          const unidad = cuenta === 1 ? una : varias

          return (
            <Dato
              key={i.moneda ?? 'sin-moneda'}
              valor={plata(i.importe)}
              etiqueta={grano === 'comprobantes' ? `Facturado · ${i.moneda ?? 'sin moneda'}` : `Cotizado · ${i.moneda ?? 'sin moneda'}`}
              sub={cuenta !== undefined ? `${cuenta.toLocaleString('es-AR')} ${unidad}` : undefined}
            />
          )
        })}
      </div>

      {(porEstado.length > 0 || porTipo.length > 0) && (
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="mr-1 text-[11px] text-faint">{porEstado.length > 0 ? 'Por estado:' : 'Por tipo:'}</span>
          {[...porEstado, ...porTipo].map(([clave, n]) => (
            <Chip key={clave}>
              {clave} · {n.toLocaleString('es-AR')}
            </Chip>
          ))}
        </div>
      )}
    </div>
  )
}

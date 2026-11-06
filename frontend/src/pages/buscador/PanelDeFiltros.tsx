import { Casilla, Lista, Texto } from '../../components/ui/form'
import { GRUPOS, filtrosActivos, type Grupo } from '../../lib/buscador'
import type { Pedido } from '../../types/buscador'
import type { Catalogos } from '../../types/indice'
import { ComboDiferido, Seccion, TextoDiferido } from './Campos'

/* ---------------------------------------------------------------------------
   Los filtros, en cuatro grupos según de qué cosa son.

   Valen para cualquier vista: elegir un material con "Empresas" mostrando
   quiere decir "las empresas a las que les cotizamos ese material". Los grupos
   no cambian según la vista; sólo se abre primero el que corresponde.
--------------------------------------------------------------------------- */

export default function PanelDeFiltros({
  pedido,
  cambiar,
  catalogos,
  abiertos,
  alternar,
}: {
  pedido: Pedido
  cambiar: (cambios: Record<string, string>) => void
  catalogos: Catalogos | null
  abiertos: Set<Grupo>
  alternar: (grupo: Grupo) => void
}) {
  const v = (k: string) => pedido[k] ?? ''
  const poner = (k: string) => (e: { target: { value: string } }) => cambiar({ [k]: e.target.value })
  const marcar = (k: string) => (si: boolean) => cambiar({ [k]: si ? '1' : '' })
  const cuantos = (grupo: Grupo) => filtrosActivos(pedido, catalogos).filter((f) => f.grupo === grupo).length

  // El material se escribe o se elige de la lista; si es uno del catálogo, se pide por su número.
  const materialElegido = v('material_id')
    ? (catalogos?.materiales.find((m) => String(m.id) === v('material_id'))?.nombre ?? '')
    : v('material')

  const seccion = (grupo: Grupo) => {
    const g = GRUPOS.find((x) => x.clave === grupo)!
    return {
      titulo: g.titulo,
      ayuda: g.ayuda,
      cuantos: cuantos(grupo),
      abierta: abiertos.has(grupo),
      alternar: () => alternar(grupo),
    }
  }

  const condicionElegida = catalogos?.condiciones_de_la_ficha?.find((t) => String(t.id) === v('condicion_id'))

  const localidades = (catalogos?.localidades ?? []).filter(
    (l) => !v('provincia_id') || l.provincia_id === Number(v('provincia_id')),
  )

  return (
    <div className="flex flex-col gap-2.5">
      <Seccion {...seccion('empresa')}>
        <TextoDiferido
          etiqueta="Nombre de la empresa"
          valor={v('empresa')}
          alCambiar={(x) => cambiar({ empresa: x })}
          placeholder="parte del nombre"
        />
        <Lista
          etiqueta="Relación" vacio="Todas" value={v('relacion')} onChange={poner('relacion')}
          opciones={(catalogos?.relaciones ?? []).map((r) => ({ valor: r, texto: r }))}
        />
        <Lista
          etiqueta="Rubro" vacio="Todos" value={v('rubro_id')} onChange={poner('rubro_id')}
          opciones={(catalogos?.rubros ?? []).map((r) => ({ valor: r.id, texto: r.nombre }))}
        />
        <Lista
          etiqueta="Provincia" vacio="Todas" value={v('provincia_id')}
          onChange={(e) => cambiar({ provincia_id: e.target.value, localidad_id: '' })}
          opciones={(catalogos?.provincias ?? []).map((p) => ({ valor: p.id, texto: p.nombre }))}
        />
        <Lista
          etiqueta="Localidad" vacio="Todas" value={v('localidad_id')} onChange={poner('localidad_id')}
          opciones={localidades.map((l) => ({ valor: l.id, texto: l.nombre }))}
        />
        <Lista
          etiqueta="Mostrar" vacio="Todas (las inactivas, al final)" value={v('activa')} onChange={poner('activa')}
          opciones={[
            { valor: 'activas', texto: 'Sólo las activas' },
            { valor: 'inactivas', texto: 'Sólo las inactivas' },
          ]}
        />
        <Lista
          etiqueta="Última cotización" vacio="Cualquiera" value={v('sin_cotizar_meses')} onChange={poner('sin_cotizar_meses')}
          opciones={[
            { valor: 3, texto: 'Hace más de 3 meses' },
            { valor: 6, texto: 'Hace más de 6 meses' },
            { valor: 12, texto: 'Hace más de un año' },
            { valor: 24, texto: 'Hace más de dos años' },
          ]}
        />
        <Lista
          etiqueta="Condición de IVA" vacio="Todas" value={v('condicion_iva')} onChange={poner('condicion_iva')}
          // Las de la ficha de la empresa (no las de las razones sociales, que son otras).
          opciones={(catalogos?.condiciones_iva_empresa ?? []).map((x) => ({ valor: x, texto: x }))}
        />
        <TextoDiferido
          etiqueta="Condición de pago" valor={v('condicion_pago')}
          alCambiar={(x) => cambiar({ condicion_pago: x })} placeholder="30 días…"
        />
        <Lista
          etiqueta="Condición de la ficha" vacio="Cualquiera" value={v('condicion_id')}
          onChange={(e) => cambiar({ condicion_id: e.target.value, opcion_id: '' })}
          opciones={(catalogos?.condiciones_de_la_ficha ?? []).map((t) => ({ valor: t.id, texto: t.nombre }))}
        />
        <Lista
          etiqueta="Con este dato" vacio="Cualquiera" value={v('opcion_id')}
          disabled={!condicionElegida}
          onChange={poner('opcion_id')}
          opciones={(condicionElegida?.opciones ?? []).map((o) => ({ valor: o.id, texto: o.texto }))}
        />
        <TextoDiferido
          etiqueta="Texto en la observación" valor={v('observacion')}
          alCambiar={(x) => cambiar({ observacion: x })} placeholder="recortes, barra, planta…"
        />
        <div className="flex flex-wrap items-end gap-2.5">
          <Casilla marcada={!!v('con_whatsapp')} onChange={marcar('con_whatsapp')}>Tiene WhatsApp</Casilla>
          <Casilla marcada={!!v('con_mail')} onChange={marcar('con_mail')}>Tiene mail</Casilla>
        </div>
      </Seccion>

      <Seccion {...seccion('cotizacion')}>
        <Lista
          etiqueta="Tipo" vacio="Todos" value={v('tipo')} onChange={poner('tipo')}
          opciones={(catalogos?.tipos_consulta ?? []).map((t) => ({ valor: t, texto: t }))}
        />
        <Lista
          etiqueta="Estado" vacio="Todos" value={v('estado')} onChange={poner('estado')}
          opciones={[
            // Lo que todavía no salió: borradores y guardadas que faltan emitir (lo que cuenta el Dashboard).
            { valor: 'Sin emitir', texto: 'Sin emitir' },
            ...(catalogos?.estados ?? []).map((x) => ({ valor: x, texto: x })),
          ]}
        />
        <Lista
          etiqueta="La hizo" vacio="Cualquiera" value={v('usuario_id')} onChange={poner('usuario_id')}
          opciones={(catalogos?.usuarios ?? []).map((u) => ({ valor: u.id, texto: u.iniciales ?? u.name }))}
        />
        <Lista
          etiqueta="Moneda" vacio="Todas" value={v('moneda_id')} onChange={poner('moneda_id')}
          opciones={(catalogos?.monedas ?? []).map((m) => ({ valor: m.id, texto: m.nombre }))}
        />
        <Texto etiqueta="Desde" type="date" value={v('desde')} onChange={poner('desde')} />
        <Texto etiqueta="Hasta" type="date" value={v('hasta')} onChange={poner('hasta')} />
        <Lista
          etiqueta="Qué fecha se mira" vacio="La que corresponde" value={v('fecha_de')} onChange={poner('fecha_de')}
          opciones={[
            { valor: 'cotizacion', texto: 'La de la cotización' },
            { valor: 'factura', texto: 'La de la factura' },
            { valor: 'remito', texto: 'La del remito' },
            { valor: 'pedido', texto: 'La del pedido' },
            { valor: 'comprobante', texto: 'La de una factura o remito' },
          ]}
        />
        <Lista
          etiqueta="Compra" vacio="Cualquiera" value={v('compra')} onChange={poner('compra')}
          opciones={[
            { valor: 'vendida', texto: 'Vendidas (cualquier compra)' },
            { valor: 'parcial', texto: 'Compra parcial' },
            { valor: 'total', texto: 'Compra total' },
          ]}
        />
        <TextoDiferido
          etiqueta="Orden de compra" ayuda="la del cliente" valor={v('orden_de_compra')}
          alCambiar={(x) => cambiar({ orden_de_compra: x })}
        />
        <Lista
          etiqueta="Vencen" vacio="Cualquiera" value={v('por_vencer')} onChange={poner('por_vencer')}
          opciones={[
            { valor: 7, texto: 'En los próximos 7 días' },
            { valor: 15, texto: 'En los próximos 15 días' },
            { valor: 30, texto: 'En los próximos 30 días' },
          ]}
        />
        <div className="flex flex-wrap items-end gap-2.5 sm:col-span-2">
          <Casilla marcada={!!v('sin_respuesta')} onChange={marcar('sin_respuesta')}>
            Sin respuesta
          </Casilla>
          <Casilla marcada={!!v('incluir_borradores')} onChange={marcar('incluir_borradores')}>
            Mostrar también los borradores
          </Casilla>
        </div>
      </Seccion>

      <Seccion {...seccion('material')}>
        <ComboDiferido
          id="buscador-material"
          etiqueta="Material" ayuda="elegí uno o escribilo"
          valor={materialElegido}
          // Si se borra, se borran los dos: el número y el texto.
          alCambiar={(x) => cambiar({ material: x, material_id: '' })}
          opciones={(catalogos?.materiales ?? []).map((m) => m.nombre)}
          placeholder="Monel, titanio, 316…"
        />
        <Lista
          etiqueta="Forma" vacio="Cualquiera" value={v('forma_id')} onChange={poner('forma_id')}
          opciones={(catalogos?.formas ?? []).map((f) => ({ valor: f.id, texto: f.nombre }))}
        />
        <TextoDiferido
          etiqueta="Diámetro desde" ayuda="mm" type="number" valor={v('diametro_desde')}
          alCambiar={(x) => cambiar({ diametro_desde: x })}
        />
        <TextoDiferido
          etiqueta="Diámetro hasta" ayuda="mm" type="number" valor={v('diametro_hasta')}
          alCambiar={(x) => cambiar({ diametro_hasta: x })}
        />
        <div className="flex items-end sm:col-span-2">
          <Casilla marcada={!!v('incluir_no_cotizadas')} onChange={marcar('incluir_no_cotizadas')}>
            Mostrar también lo que no se cotizó
          </Casilla>
        </div>
      </Seccion>

      <Seccion {...seccion('comprobante')}>
        <TextoDiferido
          etiqueta="Número" valor={v('comprobante')} alCambiar={(x) => cambiar({ comprobante: x })}
          placeholder="0001-00001234"
        />
        <Lista
          etiqueta="Tipo" vacio="Todos" value={v('tipo_comprobante')} onChange={poner('tipo_comprobante')}
          opciones={(catalogos?.tipos_comprobante ?? []).map((t) => ({ valor: t, texto: t }))}
        />
        <p className="self-end text-[11.5px] leading-relaxed text-faint sm:col-span-2">
          Las fechas de la factura o el remito se eligen en &quot;De la cotización&quot;, con &quot;Qué fecha se mira&quot;.
        </p>
      </Seccion>
    </div>
  )
}

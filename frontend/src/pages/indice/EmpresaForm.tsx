import { useMemo, useState } from 'react'
import { Aviso } from '../../components/ui'
import { AreaTexto, Casilla, Etiqueta, Lista, Texto } from '../../components/ui/form'
import { useCatalogos } from '../../lib/indice'
import BuscadorDireccion from '../../components/BuscadorDireccion'
import type { DireccionElegida } from '../../components/BuscadorDireccion'
import type { DatosEmpresa } from '../../lib/indice'
import type { Empresa, Relacion } from '../../types/indice'

const RELACIONES: Relacion[] = ['Cliente', 'Proveedor', 'Servicio', 'Empleado', 'Agenda general']

export interface EstadoEmpresaForm extends DatosEmpresa {
  relaciones: string[]
}

export function estadoInicial(empresa?: Empresa | null): EstadoEmpresaForm {
  return {
    nombre: empresa?.nombre ?? '',
    codigo_indice: empresa?.codigo_indice ?? '',
    codigo_isis: empresa?.codigo_isis ?? '',
    cuit: empresa?.cuit ?? '',
    direccion: empresa?.direccion ?? '',
    localidad_id: empresa?.localidad_id ?? null,
    provincia_id: empresa?.provincia_id ?? null,
    codigo_postal: empresa?.codigo_postal ?? '',
    pais_id: empresa?.pais_id ?? null,
    rubro_id: empresa?.rubro_id ?? null,
    observacion_general: empresa?.observacion_general ?? '',
    relaciones: empresa?.relaciones.filter((r) => r.activa).map((r) => r.relacion) ?? [],
  }
}

/**
 * Los datos de la empresa. Se usa igual para el alta y para modificar.
 *
 * Lo único obligatorio es el nombre: si un dato no lo tienen, queda en blanco
 * y se completa cuando aparezca.
 */
export default function EmpresaForm({
  valores,
  onChange,
  errorNombre,
}: {
  valores: EstadoEmpresaForm
  onChange: (v: EstadoEmpresaForm) => void
  errorNombre?: string
}) {
  const catalogos = useCatalogos()
  const [filtroLocalidad, setFiltroLocalidad] = useState('')

  const set = <K extends keyof EstadoEmpresaForm>(clave: K, valor: EstadoEmpresaForm[K]) =>
    onChange({ ...valores, [clave]: valor })

  // Al elegir provincia sólo se ofrecen sus localidades.
  const localidades = useMemo(() => {
    const todas = catalogos?.localidades ?? []
    const deLaProvincia = valores.provincia_id
      ? todas.filter((l) => l.provincia_id === valores.provincia_id)
      : todas

    return filtroLocalidad
      ? deLaProvincia.filter((l) => l.nombre.toLowerCase().includes(filtroLocalidad.toLowerCase()))
      : deLaProvincia
  }, [catalogos, valores.provincia_id, filtroLocalidad])

  /** Al elegir una dirección de la lista se completa el resto de los campos. */
  function completarDesdeDireccion(d: DireccionElegida) {
    onChange({
      ...valores,
      direccion: d.direccion ?? valores.direccion,
      codigo_postal: d.codigo_postal ?? valores.codigo_postal,
      // Lo que no está en nuestras listas queda como estaba: se elige a mano.
      provincia_id: d.provincia_id ?? valores.provincia_id,
      localidad_id: d.localidad_id ?? valores.localidad_id,
      pais_id: d.pais_id ?? valores.pais_id,
    })
  }

  function alternarRelacion(relacion: string) {
    const marcadas = valores.relaciones.includes(relacion)
      ? valores.relaciones.filter((r) => r !== relacion)
      : [...valores.relaciones, relacion]

    set('relaciones', marcadas)
  }

  return (
    <div className="flex flex-col gap-3.5">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[2fr_1fr]">
        <Texto
          etiqueta="EMPRESA"
          obligatorio
          value={valores.nombre}
          onChange={(e) => set('nombre', e.target.value)}
          placeholder="Nombre con el que la buscan"
          error={errorNombre}
        />
        <Lista
          etiqueta="Rubro"
          value={valores.rubro_id ?? ''}
          onChange={(e) => set('rubro_id', e.target.value ? Number(e.target.value) : null)}
          opciones={(catalogos?.rubros ?? []).map((r) => ({ valor: r.id, texto: r.nombre }))}
        />
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <Texto
          etiqueta="CUIT"
          ayuda="se puede buscar por acá"
          value={valores.cuit ?? ''}
          onChange={(e) => set('cuit', e.target.value)}
          placeholder="30-71028456-3"
        />
        <Texto
          etiqueta="Codigo ISIS"
          ayuda="tal cual viene"
          value={valores.codigo_isis ?? ''}
          onChange={(e) => set('codigo_isis', e.target.value)}
          placeholder="IS-04872"
        />
        <Texto
          etiqueta="ID del indice"
          value={valores.codigo_indice ?? ''}
          onChange={(e) => set('codigo_indice', e.target.value)}
          placeholder="7402"
        />
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <BuscadorDireccion
          className="lg:col-span-2"
          activo={catalogos?.direcciones_activas ?? false}
          valor={valores.direccion ?? ''}
          onCambio={(v) => set('direccion', v)}
          onElegir={completarDesdeDireccion}
        />
        <Lista
          etiqueta="Provincia"
          value={valores.provincia_id ?? ''}
          onChange={(e) => {
            const id = e.target.value ? Number(e.target.value) : null
            onChange({ ...valores, provincia_id: id, localidad_id: null })
          }}
          opciones={(catalogos?.provincias ?? []).map((p) => ({ valor: p.id, texto: p.nombre }))}
        />
        <div className="flex min-w-0 flex-col">
          <Etiqueta ayuda={valores.provincia_id ? undefined : 'elegí la provincia primero'}>
            Localidad
          </Etiqueta>
          <Lista
            aria-label="Localidad"
            value={valores.localidad_id ?? ''}
            onChange={(e) => set('localidad_id', e.target.value ? Number(e.target.value) : null)}
            opciones={localidades.map((l) => ({ valor: l.id, texto: l.nombre }))}
            onKeyDown={(e) => {
              // Escribir filtra la lista sin tener que abrir otro control.
              if (e.key.length === 1) setFiltroLocalidad((f) => f + e.key)
              if (e.key === 'Backspace') setFiltroLocalidad((f) => f.slice(0, -1))
            }}
          />
        </div>
        <Texto
          etiqueta="C.P."
          value={valores.codigo_postal ?? ''}
          onChange={(e) => set('codigo_postal', e.target.value)}
        />
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <Lista
          etiqueta="Pais"
          value={valores.pais_id ?? ''}
          onChange={(e) => set('pais_id', e.target.value ? Number(e.target.value) : null)}
          opciones={(catalogos?.paises ?? []).map((p) => ({ valor: p.id, texto: p.nombre }))}
        />
        <div className="lg:col-span-4 flex items-end">
          <p className="pb-2 text-[11px] leading-relaxed text-faint">
            El enlace al mapa se arma solo con la direccion, la localidad y la provincia. No hace
            falta pegar nada de Google Maps.
          </p>
        </div>
      </div>

      <div className="flex flex-col gap-2">
        <Etiqueta ayuda="se puede marcar mas de una">Relacion</Etiqueta>
        <div className="flex flex-wrap gap-2.5">
          {RELACIONES.map((r) => (
            <Casilla
              key={r}
              marcada={valores.relaciones.includes(r)}
              onChange={() => alternarRelacion(r)}
            >
              {r}
            </Casilla>
          ))}
        </div>
      </div>

      <Aviso tono="info">
        Una misma empresa puede ser cliente y proveedor a la vez. Cuando no es ninguna de las
        comerciales —la usan sólo como agenda— va marcada como Agenda general.
      </Aviso>

      <AreaTexto
        etiqueta="Observacion general de la empresa"
        ayuda="es la que se ve siempre arriba de la ficha"
        filas={3}
        value={valores.observacion_general ?? ''}
        onChange={(e) => set('observacion_general', e.target.value)}
        placeholder="Lo que hay que saber de esta empresa antes de atenderla."
      />
    </div>
  )
}

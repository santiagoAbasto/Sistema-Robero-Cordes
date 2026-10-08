import { useState } from 'react'
import { Link } from 'react-router-dom'
import { RotateCcw, Trash2 } from 'lucide-react'
import {
  Accion,
  Aviso,
  Card,
  CardHeader,
  Cargando,
  Chip,
  PageHeader,
  SinResultados,
  Tabla,
  Td,
  Th,
} from '../../components/ui'
import { Guardado } from '../../components/ui/form'
import { useAuth } from '../../lib/auth'
import {
  fecha as fmtFecha,
  mensajeDeError,
  plata,
  restaurarBorrador,
  traerEliminados,
  useCarga,
} from '../../lib/indice'

/* ---------------------------------------------------------------------------
   La papelera de borradores.

   Lo que se descartó no se pierde de una: queda acá 30 días por si hubo un
   error. Se restaura con un clic y vuelve a figurar donde estaba. Pasados los
   30 días se borra para siempre —eso lo limpia el servidor al abrir esta
   pantalla—. Todo movimiento queda en el control de cambios.
--------------------------------------------------------------------------- */

export default function BorradoresEliminados() {
  const { user } = useAuth()
  const esAdmin = user?.role === 'Administrador'
  const [aviso, setAviso] = useState<string | null>(null)
  const [restaurando, setRestaurando] = useState<number | null>(null)

  const { datos, cargando, recargar } = useCarga(
    () => (esAdmin ? traerEliminados() : Promise.resolve([])),
    [esAdmin],
  )

  async function restaurar(id: number) {
    setRestaurando(id)

    try {
      await restaurarBorrador(id)
      setAviso('Borrador restaurado. Ya volvió a figurar donde estaba.')
      recargar()
    } catch (err) {
      setAviso(mensajeDeError(err, 'No se pudo restaurar. Probá de nuevo.'))
    } finally {
      setRestaurando(null)
    }
  }

  if (!esAdmin) {
    return (
      <div className="flex flex-col gap-[18px]">
        <PageHeader
          breadcrumb={<Link to="/dashboard" className="hover:text-brand-600">Dashboard</Link>}
          titulo="Borradores eliminados"
        />
        <Aviso tono="ambar">La papelera es solo para administradores.</Aviso>
      </div>
    )
  }

  const lista = datos ?? []

  return (
    <div className="flex flex-col gap-[18px]">
      <PageHeader
        breadcrumb={<Link to="/dashboard" className="hover:text-brand-600">Dashboard</Link>}
        titulo="Borradores eliminados"
        chips={<Chip tono="neutro">{lista.length}</Chip>}
        bajada="Lo que se descartó queda acá 30 días. Se puede restaurar; pasados los 30 días se borra para siempre."
      />

      <Card className="overflow-hidden">
        <CardHeader
          titulo="En la papelera"
          cuenta={lista.length}
          ayuda="Quién lo descartó, cuándo, y cuántos días le quedan."
        />

        {cargando && <Cargando texto="Buscando la papelera…" />}

        {!cargando && lista.length === 0 && (
          <SinResultados
            titulo="La papelera está vacía"
            detalle="Acá aparecen los borradores que se descarten."
          />
        )}

        {!cargando && lista.length > 0 && (
          <Tabla>
            <thead>
              <tr>
                <Th>EMPRESA</Th>
                <Th ancho="110px">Fecha</Th>
                <Th ancho="120px" derecha>Importe</Th>
                <Th ancho="140px">Lo descartó</Th>
                <Th ancho="150px">Hace</Th>
                <Th ancho="130px">Le quedan</Th>
                <Th ancho="120px" />
              </tr>
            </thead>
            <tbody>
              {lista.map((b) => (
                <tr key={b.id} className="hover:bg-[#f8fcfe]">
                  <Td>
                    <Link to={`/empresas/${b.empresa_id}`} className="font-semibold text-ink hover:text-brand-600">
                      {b.empresa ?? '—'}
                    </Link>
                  </Td>
                  <Td>{fmtFecha(b.fecha)}</Td>
                  <Td derecha className="font-semibold text-ink">{plata(b.total)}</Td>
                  <Td className="text-brand-600">{b.eliminada_por ?? 'el sistema'}</Td>
                  <Td className="text-muted">{fmtFecha(b.eliminada_el)}</Td>
                  <Td>
                    <Chip tono={b.dias_restantes <= 5 ? 'ambar' : 'neutro'}>
                      {b.dias_restantes} {b.dias_restantes === 1 ? 'día' : 'días'}
                    </Chip>
                  </Td>
                  <Td>
                    <div className="flex items-center justify-end">
                      <Accion onClick={() => restaurar(b.id)}>
                        <span className="inline-flex items-center gap-1">
                          <RotateCcw size={12} strokeWidth={2.4} />
                          {restaurando === b.id ? 'Restaurando…' : 'Restaurar'}
                        </span>
                      </Accion>
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Tabla>
        )}
      </Card>

      <Card className="flex items-start gap-2.5 p-[18px] text-[12px] leading-relaxed text-muted">
        <Trash2 size={15} strokeWidth={2.2} className="mt-0.5 shrink-0 text-faint" />
        <span>
          Un borrador descartado no se le manda a nadie ni cuenta en ningún número. Si le quedan pocos
          días y todavía sirve, restauralo: vuelve a estar como antes.
        </span>
      </Card>

      <Guardado mensaje={aviso} onCerrar={() => setAviso(null)} />
    </div>
  )
}

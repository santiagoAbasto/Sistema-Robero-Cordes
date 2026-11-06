import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './lib/auth'
import RequireAuth from './components/RequireAuth'
import AppShell from './layouts/AppShell'
import Login from './pages/Login'
import Dashboard from './pages/Dashboard'
import Placeholder from './pages/Placeholder'
import { NAV_ROUTES } from './data/navigation'

// Módulo 1 — Índice Telefónico
import IndiceMenu from './pages/indice/IndiceMenu'
import NuevaEmpresa from './pages/indice/NuevaEmpresa'
import FichaEmpresa from './pages/indice/FichaEmpresa'
import ConsultaEditor from './pages/indice/ConsultaEditor'
import ControlDeCambios from './pages/indice/ControlDeCambios'
import CopiarCotizacion from './pages/indice/CopiarCotizacion'
import Borradores from './pages/indice/Borradores'
import Empresas from './pages/indice/Empresas'
import BorradoresEliminados from './pages/indice/BorradoresEliminados'
import Imprimir from './pages/indice/Imprimir'
import QuienVeQue from './pages/indice/QuienVeQue'
import FormasYFormulas from './pages/indice/FormasYFormulas'
import ListasEditables from './pages/indice/ListasEditables'
import CondicionesHabituales from './pages/indice/CondicionesHabituales'
import CatalogoDeCondiciones from './pages/indice/CatalogoDeCondiciones'
import DatosDelSistemaAnterior from './pages/indice/DatosDelSistemaAnterior'
// TEMPORAL: se saca junto con la pantalla cuando CORDES revise la carga.
import AntesYAhora from './pages/indice/AntesYAhora'
import Seguimiento from './pages/indice/Seguimiento'
import Reportes from './pages/reportes/Reportes'
import EnvioDeCotizaciones from './pages/indice/EnvioDeCotizaciones'
import Buscador from './pages/buscador/Buscador'
import RedirigirAlBuscador, {
  DESDE_BUSCADOR_IA,
  DESDE_BUSCAR_MATERIAL,
  DESDE_CONSULTAS_POR_CONDICION,
  DESDE_CONSULTAS_POR_FECHA,
  DESDE_COTIZACIONES_A_CLIENTES,
  DESDE_FACTURAS_Y_REMITOS,
  DESDE_EMPRESAS_POR_CONDICION,
} from './pages/buscador/Redirigir'

/** Las que ya están hechas: el resto sigue mostrando el placeholder. */
const HECHAS = [
  '/buscador',
  '/compras/facturas',
  '/cotizaciones/buscar',
  '/cotizaciones/clientes',
  '/config/antes-y-ahora',
  '/config/condiciones',
  '/config/condiciones-de-la-ficha',
  '/config/envios',
  '/config/formas',
  '/config/listas',
  '/config/migracion',
  '/config/usuarios',
  '/cotizaciones/seguimiento',
  '/dashboard',
  '/empresas',
  '/empresas/nueva',
  '/imprimir',
  '/reportes',
]

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<Login />} />

          <Route element={<RequireAuth />}>
            <Route element={<AppShell />}>
              <Route path="/dashboard" element={<Dashboard />} />

              {/* Buscador */}
              <Route path="/buscador" element={<Buscador />} />
              <Route path="/ia" element={<RedirigirAlBuscador traducir={DESDE_BUSCADOR_IA} />} />
              <Route path="/cotizaciones/buscar" element={<RedirigirAlBuscador traducir={DESDE_BUSCAR_MATERIAL} />} />

              {/* Empresas */}
              <Route path="/empresas" element={<IndiceMenu />} />
              {/* Los cuatro buscadores viejos son el Buscador: sus direcciones siguen andando. */}
              {/* "Nueva cotización" del Dashboard: la lista de empresas de siempre, sin el bot. */}
              <Route path="/empresas/registros" element={<Empresas />} />
              <Route path="/empresas/condicion" element={<RedirigirAlBuscador traducir={DESDE_EMPRESAS_POR_CONDICION} />} />
              {/* "nueva" va antes que ":id": si no, entra por la ficha. */}
              <Route path="/empresas/nueva" element={<NuevaEmpresa />} />
              <Route path="/empresas/:id" element={<FichaEmpresa />} />
              <Route path="/empresas/:id/agregar" element={<ConsultaEditor />} />
              <Route path="/empresas/:id/cambios" element={<ControlDeCambios />} />

              {/* Consultas */}
              <Route path="/consultas/fecha" element={<RedirigirAlBuscador traducir={DESDE_CONSULTAS_POR_FECHA} />} />
              <Route path="/consultas/condicion" element={<RedirigirAlBuscador traducir={DESDE_CONSULTAS_POR_CONDICION} />} />
              <Route path="/consultas/eliminados" element={<BorradoresEliminados />} />
              {/* Los menús que ya tienen su pantalla real en el Buscador (Roberto no encontraba los comprobantes). */}
              <Route path="/compras/facturas" element={<RedirigirAlBuscador traducir={DESDE_FACTURAS_Y_REMITOS} />} />
              <Route path="/cotizaciones/clientes" element={<RedirigirAlBuscador traducir={DESDE_COTIZACIONES_A_CLIENTES} />} />
              <Route path="/consultas/:consultaId" element={<ConsultaEditor />} />
              <Route path="/consultas/:consultaId/copiar" element={<CopiarCotizacion />} />
              <Route path="/consultas/:consultaId/borradores" element={<Borradores />} />

              {/* Imprimir y configuración */}
              <Route path="/imprimir" element={<Imprimir />} />
              <Route path="/config/usuarios" element={<QuienVeQue />} />
              <Route path="/config/formas" element={<FormasYFormulas />} />
              <Route path="/config/listas" element={<ListasEditables />} />
              <Route path="/config/condiciones" element={<CondicionesHabituales />} />
              <Route path="/config/condiciones-de-la-ficha" element={<CatalogoDeCondiciones />} />
              <Route path="/config/envios" element={<EnvioDeCotizaciones />} />
              <Route path="/config/migracion" element={<DatosDelSistemaAnterior />} />
              <Route path="/cotizaciones/seguimiento" element={<Seguimiento />} />
              <Route path="/reportes" element={<Reportes />} />
              <Route path="/config/antes-y-ahora" element={<AntesYAhora />} />

              {NAV_ROUTES.filter((r) => !HECHAS.includes(r.to)).map((r) => (
                <Route key={r.to} path={r.to} element={<Placeholder />} />
              ))}
            </Route>
          </Route>

          <Route path="/" element={<Navigate to="/dashboard" replace />} />
          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
}

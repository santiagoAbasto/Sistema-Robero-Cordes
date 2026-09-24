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
import Empresas from './pages/indice/Empresas'
import NuevaEmpresa from './pages/indice/NuevaEmpresa'
import FichaEmpresa from './pages/indice/FichaEmpresa'
import ConsultaEditor from './pages/indice/ConsultaEditor'
import ControlDeCambios from './pages/indice/ControlDeCambios'
import CopiarCotizacion from './pages/indice/CopiarCotizacion'
import Borradores from './pages/indice/Borradores'
import Imprimir from './pages/indice/Imprimir'
import QuienVeQue from './pages/indice/QuienVeQue'
import FormasYFormulas from './pages/indice/FormasYFormulas'
import DatosDelSistemaAnterior from './pages/indice/DatosDelSistemaAnterior'
// TEMPORAL: se saca junto con la pantalla cuando CORDES revise la carga.
import AntesYAhora from './pages/indice/AntesYAhora'
import Seguimiento from './pages/indice/Seguimiento'
import Reportes from './pages/indice/Reportes'
import { BuscarConsultas, ConsultasPorFecha, EmpresasPorCondicion } from './pages/indice/Busquedas'

/** Las que ya están hechas: el resto sigue mostrando el placeholder. */
const HECHAS = [
  '/config/antes-y-ahora',
  '/config/formas',
  '/config/migracion',
  '/config/usuarios',
  '/consultas/condicion',
  '/consultas/fecha',
  '/cotizaciones/seguimiento',
  '/dashboard',
  '/empresas',
  '/empresas/condicion',
  '/empresas/nueva',
  '/empresas/registros',
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

              {/* Empresas */}
              <Route path="/empresas" element={<IndiceMenu />} />
              <Route path="/empresas/registros" element={<Empresas />} />
              <Route path="/empresas/condicion" element={<EmpresasPorCondicion />} />
              {/* "nueva" va antes que ":id": si no, entra por la ficha. */}
              <Route path="/empresas/nueva" element={<NuevaEmpresa />} />
              <Route path="/empresas/:id" element={<FichaEmpresa />} />
              <Route path="/empresas/:id/agregar" element={<ConsultaEditor />} />
              <Route path="/empresas/:id/cambios" element={<ControlDeCambios />} />

              {/* Consultas */}
              <Route path="/consultas/fecha" element={<ConsultasPorFecha />} />
              <Route path="/consultas/condicion" element={<BuscarConsultas />} />
              <Route path="/consultas/:consultaId" element={<ConsultaEditor />} />
              <Route path="/consultas/:consultaId/copiar" element={<CopiarCotizacion />} />
              <Route path="/consultas/:consultaId/borradores" element={<Borradores />} />

              {/* Imprimir y configuración */}
              <Route path="/imprimir" element={<Imprimir />} />
              <Route path="/config/usuarios" element={<QuienVeQue />} />
              <Route path="/config/formas" element={<FormasYFormulas />} />
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

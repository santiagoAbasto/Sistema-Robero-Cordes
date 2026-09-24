import { useState } from 'react'
import type { FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { motion } from 'motion/react'
import { isAxiosError } from 'axios'
import { Check, Eye, EyeOff, Lock, Mail } from 'lucide-react'
import Logo from '../components/Logo'
import { useAuth } from '../lib/auth'

const BULLETS = [
  'Historial de precios por proveedor y por material',
  'Cotizá a tus clientes reusando datos de proveedores',
  'Búsqueda rápida de empresas, contactos y materiales',
]

export default function Login() {
  const { user, loading, login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('roberto@cordes.com')
  const [password, setPassword] = useState('cordes2026')
  const [showPw, setShowPw] = useState(false)
  const [remember, setRemember] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  if (!loading && user) return <Navigate to="/dashboard" replace />

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await login(email, password)
      navigate('/dashboard', { replace: true })
    } catch (err) {
      if (isAxiosError(err)) {
        setError(err.response?.data?.message ?? 'No pudimos iniciar sesión. Intentá de nuevo.')
      } else {
        setError('Error de conexión con el servidor.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex h-screen w-screen overflow-hidden bg-white">
      {/* Brand panel */}
      <div
        className="relative hidden w-[46%] flex-col justify-between overflow-hidden p-12 lg:flex"
        style={{
          backgroundImage:
            'radial-gradient(900px circle at 18% 4%, rgba(0,125,195,0.38), transparent 52%), linear-gradient(158deg, #0f3f60 0%, #0a2e45 52%, #07223400 100%), linear-gradient(#0a2e45,#0a2e45)',
        }}
      >
        <div className="flex items-center gap-3">
          <Logo size={34} />
          <div className="flex flex-col">
            <span className="text-[17px] font-bold leading-tight tracking-[0.34px] text-white">
              CORDES
            </span>
            <span className="text-[10px] font-medium leading-tight tracking-[0.2px] text-navy-muted">
              Sistema Comercial
            </span>
          </div>
        </div>

        <div className="max-w-md">
          <h1 className="text-[31px] font-extrabold leading-[1.18] tracking-tight text-white">
            Cotizaciones que cierran negocios.
          </h1>
          <p className="mt-4 text-[14px] leading-relaxed text-navy-group/85">
            Desde 1938 al servicio de la Industria. Empresas, materiales y cotizaciones, unificados
            en una sola plataforma.
          </p>
          <ul className="mt-7 flex flex-col gap-3.5">
            {BULLETS.map((b) => (
              <li key={b} className="flex items-center gap-3 text-[13.5px] text-navy-group">
                <span className="grid h-5 w-5 shrink-0 place-items-center rounded-md bg-brand/25 text-[#7fd0f3]">
                  <Check size={13} strokeWidth={3} />
                </span>
                {b}
              </li>
            ))}
          </ul>
        </div>
      </div>

      {/* Form panel */}
      <div className="flex flex-1 flex-col items-center justify-center px-6 py-10">
        <motion.div
          initial={{ opacity: 0, y: 14 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.35, ease: 'easeOut' }}
          className="w-full max-w-[368px]"
        >
          <div className="mb-7 lg:hidden">
            <Logo size={36} />
          </div>

          <h2 className="text-[22px] font-bold tracking-tight text-ink">Iniciar sesión</h2>
          <p className="mt-1.5 text-[14px] text-muted">
            Ingresá tus credenciales para acceder al sistema.
          </p>

          <form onSubmit={handleSubmit} className="mt-7 flex flex-col gap-[18px]">
            <div>
              <label htmlFor="email" className="mb-1.5 block text-[12.5px] font-medium text-muted">
                Usuario o email
              </label>
              <div className="relative">
                <Mail
                  size={17}
                  strokeWidth={2}
                  className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-faint"
                />
                <input
                  id="email"
                  type="email"
                  autoComplete="username"
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="jroberti@cordes.com.ar"
                  className="h-11 w-full rounded-lg border border-line-strong pl-10 pr-3.5 text-[14px] text-ink outline-none transition-shadow placeholder:text-faint focus:border-brand focus:ring-4 focus:ring-brand-50"
                />
              </div>
            </div>

            <div>
              <label htmlFor="password" className="mb-1.5 block text-[12.5px] font-medium text-muted">
                Contraseña
              </label>
              <div className="relative">
                <Lock
                  size={17}
                  strokeWidth={2}
                  className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-faint"
                />
                <input
                  id="password"
                  type={showPw ? 'text' : 'password'}
                  autoComplete="current-password"
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••"
                  className="h-11 w-full rounded-lg border border-line-strong pl-10 pr-11 text-[14px] text-ink outline-none transition-shadow placeholder:text-faint focus:border-brand focus:ring-4 focus:ring-brand-50"
                />
                <button
                  type="button"
                  onClick={() => setShowPw((s) => !s)}
                  className="absolute right-2 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-md text-faint hover:text-muted"
                  aria-label={showPw ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                >
                  {showPw ? <EyeOff size={17} strokeWidth={2} /> : <Eye size={17} strokeWidth={2} />}
                </button>
              </div>
            </div>

            {error && (
              <div className="rounded-lg border border-[#f3c9c9] bg-danger-bg px-3.5 py-2.5 text-[13px] font-medium text-danger">
                {error}
              </div>
            )}

            <div className="flex items-center justify-between">
              <button
                type="button"
                onClick={() => setRemember((r) => !r)}
                className="flex items-center gap-2 text-[13px] text-muted"
              >
                <span
                  className={`grid h-[18px] w-[18px] place-items-center rounded-[5px] border transition-colors ${
                    remember ? 'border-brand bg-brand text-white' : 'border-line-strong bg-white'
                  }`}
                >
                  {remember && <Check size={12} strokeWidth={3} />}
                </span>
                Recordarme
              </button>
              <button type="button" className="text-[13px] font-semibold text-brand hover:text-brand-600">
                ¿Olvidaste tu contraseña?
              </button>
            </div>

            <button
              type="submit"
              disabled={submitting}
              className="flex h-11 items-center justify-center gap-2 rounded-lg bg-brand text-[14px] font-semibold text-white shadow-[0_4px_14px_rgba(0,125,195,0.35)] transition-colors hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {submitting ? (
                <>
                  <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
                  Ingresando…
                </>
              ) : (
                'Ingresar'
              )}
            </button>
          </form>

          <p className="mt-7 text-center text-[12px] text-faint">
            © 2026 Roberto Cordes S.A. · Sistema Comercial v1.0
          </p>
        </motion.div>
      </div>
    </div>
  )
}

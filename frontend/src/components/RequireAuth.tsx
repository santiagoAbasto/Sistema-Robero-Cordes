import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../lib/auth'
import Logo from './Logo'

export default function RequireAuth() {
  const { user, loading } = useAuth()

  if (loading) {
    return (
      <div className="grid h-screen w-screen place-items-center bg-app">
        <div className="flex flex-col items-center gap-4">
          <span className="animate-pulse">
            <Logo size={40} />
          </span>
          <span className="h-1 w-24 overflow-hidden rounded-full bg-line">
            <span className="block h-full w-1/2 animate-[loadbar_1.1s_ease-in-out_infinite] rounded-full bg-brand" />
          </span>
        </div>
        <style>{`@keyframes loadbar{0%{transform:translateX(-100%)}100%{transform:translateX(220%)}}`}</style>
      </div>
    )
  }

  if (!user) return <Navigate to="/login" replace />

  return <Outlet />
}

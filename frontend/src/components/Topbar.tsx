import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AnimatePresence, motion } from 'motion/react'
import { ChevronDown, HelpCircle, LogOut, Menu, Search } from 'lucide-react'
import { useAuth } from '../lib/auth'
import Campanita from './Campanita'
import PaletaRapida from './PaletaRapida'

export default function Topbar({ onMenu }: { onMenu?: () => void }) {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [menuOpen, setMenuOpen] = useState(false)
  const [paleta, setPaleta] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)

  // ⌘K / Ctrl+K desde cualquier pantalla: el cartelito ya lo prometia.
  useEffect(() => {
    function atajo(e: KeyboardEvent) {
      if (e.key.toLowerCase() === 'k' && (e.metaKey || e.ctrlKey)) {
        e.preventDefault()
        setPaleta(true)
      }
    }
    document.addEventListener('keydown', atajo)

    return () => document.removeEventListener('keydown', atajo)
  }, [])

  useEffect(() => {
    function onClick(e: MouseEvent) {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) setMenuOpen(false)
    }
    document.addEventListener('mousedown', onClick)
    return () => document.removeEventListener('mousedown', onClick)
  }, [])

  async function handleLogout() {
    await logout()
    navigate('/login', { replace: true })
  }

  return (
    <>
    <PaletaRapida abierta={paleta} onCerrar={() => setPaleta(false)} />
    <header className="flex h-16 shrink-0 items-center gap-2 border-b border-line bg-white px-4 sm:gap-4 sm:px-6">
      {/* Mobile menu */}
      <button
        type="button"
        onClick={onMenu}
        className="grid h-10 w-10 shrink-0 place-items-center rounded-lg text-muted transition-colors hover:bg-app hover:text-ink lg:hidden"
        aria-label="Abrir menú"
      >
        <Menu size={20} strokeWidth={2} />
      </button>

      {/* Search */}
      <button
        type="button"
        onClick={() => setPaleta(true)}
        className="flex h-10 w-full max-w-[460px] items-center gap-2.5 rounded-[10px] border border-line bg-[#f3f6f9] px-3.5 text-left text-faint transition-colors hover:border-brand-200 hover:bg-white"
      >
        <Search size={17} strokeWidth={2} className="shrink-0" />
        <span className="w-full min-w-0 truncate text-[13.5px]">
          Buscar empresa, material o cotización…
        </span>
        <kbd className="hidden shrink-0 rounded-md border border-line bg-white px-1.5 py-0.5 text-[11px] font-medium text-faint sm:inline-flex">
          ⌘K
        </kbd>
      </button>

      <div className="flex-1" />

      <Campanita />
      <button
        type="button"
        className="hidden h-10 w-10 place-items-center rounded-lg text-faint transition-colors hover:bg-app hover:text-ink sm:grid"
      >
        <HelpCircle size={19} strokeWidth={1.9} />
      </button>

      <div className="mx-1 hidden h-7 w-px bg-line sm:block" />

      {/* User menu */}
      <div className="relative" ref={menuRef}>
        <button
          type="button"
          onClick={() => setMenuOpen((o) => !o)}
          className="flex items-center gap-2.5 rounded-lg py-1 pl-1 pr-2 transition-colors hover:bg-app"
        >
          <span className="grid h-9 w-9 place-items-center rounded-full bg-gradient-to-br from-brand to-brand-700 text-[12.5px] font-bold text-white ring-2 ring-brand-100">
            {user?.initials ?? 'U'}
          </span>
          <span className="hidden text-left leading-tight sm:block">
            <span className="block text-[13px] font-semibold text-ink">{user?.name}</span>
            <span className="block text-[11px] text-faint">{user?.role}</span>
          </span>
          <ChevronDown size={16} strokeWidth={2} className="hidden text-faint sm:block" />
        </button>

        <AnimatePresence>
          {menuOpen && (
            <motion.div
              initial={{ opacity: 0, y: -6, scale: 0.98 }}
              animate={{ opacity: 1, y: 0, scale: 1 }}
              exit={{ opacity: 0, y: -6, scale: 0.98 }}
              transition={{ duration: 0.16, ease: 'easeOut' }}
              className="absolute right-0 top-[calc(100%+8px)] z-50 w-56 overflow-hidden rounded-xl border border-line bg-white shadow-[var(--shadow-pop)]"
            >
              <div className="border-b border-line px-4 py-3">
                <p className="text-[13px] font-semibold text-ink">{user?.name}</p>
                <p className="truncate text-[12px] text-faint">{user?.email}</p>
              </div>
              <button
                type="button"
                onClick={handleLogout}
                className="flex w-full items-center gap-2.5 px-4 py-2.5 text-[13px] font-medium text-ink transition-colors hover:bg-app"
              >
                <LogOut size={16} strokeWidth={2} className="text-faint" />
                Cerrar sesión
              </button>
            </motion.div>
          )}
        </AnimatePresence>
      </div>
    </header>
    </>
  )
}

import { useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { AnimatePresence, motion } from 'motion/react'
import Sidebar from '../components/Sidebar'
import Topbar from '../components/Topbar'

export default function AppShell() {
  const location = useLocation()
  const [drawerOpen, setDrawerOpen] = useState(false)

  return (
    <div className="flex h-screen w-screen overflow-hidden bg-app">
      {/* Static sidebar (lg and up) */}
      <div className="hidden lg:flex">
        <Sidebar />
      </div>

      {/* Mobile drawer */}
      <AnimatePresence>
        {drawerOpen && (
          <>
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              transition={{ duration: 0.2 }}
              onClick={() => setDrawerOpen(false)}
              className="fixed inset-0 z-40 bg-navy/50 backdrop-blur-[1px] lg:hidden"
            />
            <motion.div
              initial={{ x: -260 }}
              animate={{ x: 0 }}
              exit={{ x: -260 }}
              transition={{ duration: 0.26, ease: [0.22, 1, 0.36, 1] }}
              className="fixed inset-y-0 left-0 z-50 lg:hidden"
            >
              <Sidebar onNavigate={() => setDrawerOpen(false)} />
            </motion.div>
          </>
        )}
      </AnimatePresence>

      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar onMenu={() => setDrawerOpen(true)} />
        <main className="flex-1 overflow-y-auto">
          {/* Sólo desplazamiento, sin fundido: si la animación se traba —por
              ejemplo con la pestaña en segundo plano— el contenido queda igual
              de legible, apenas unos píxeles corrido. */}
          <motion.div
            key={location.pathname}
            initial={{ y: 10 }}
            animate={{ y: 0 }}
            transition={{ duration: 0.24, ease: 'easeOut' }}
            className="w-full px-5 py-6 sm:px-7 lg:px-8 lg:py-7"
          >
            <Outlet />
          </motion.div>
        </main>
      </div>
    </div>
  )
}

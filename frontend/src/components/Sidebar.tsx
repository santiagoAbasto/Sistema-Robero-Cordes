import { useEffect, useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { AnimatePresence, motion } from 'motion/react'
import { ChevronRight } from 'lucide-react'
import Logo from './Logo'
import { NAVIGATION } from '../data/navigation'
import type { NavNode } from '../types'

const ROW =
  'flex w-full items-center gap-[11px] rounded-lg py-[9px] pl-3 pr-2.5 text-[13.5px] transition-colors'

function NavBadge({ label }: { label: string }) {
  return (
    <span className="ml-auto rounded-full bg-ia px-[7px] py-[2px] text-[9.5px] font-bold leading-none tracking-[0.38px] text-white">
      {label}
    </span>
  )
}

function DirectItem({ node, onNavigate }: { node: NavNode; onNavigate?: () => void }) {
  const Icon = node.icon
  return (
    <NavLink
      to={node.to!}
      end
      onClick={onNavigate}
      className={({ isActive }) =>
        `${ROW} ${
          isActive
            ? 'bg-brand font-semibold text-white'
            : 'font-medium text-navy-group hover:bg-white/[0.06] hover:text-white'
        }`
      }
    >
      <Icon size={18} strokeWidth={1.85} className="shrink-0" />
      <span className="flex-1 text-left">{node.label}</span>
      {node.badge && <NavBadge label={node.badge} />}
    </NavLink>
  )
}

function Group({ node, onNavigate }: { node: NavNode; onNavigate?: () => void }) {
  const { pathname } = useLocation()
  const Icon = node.icon
  const childActive = node.children!.some((c) => pathname === c.to)
  const [open, setOpen] = useState(childActive)

  useEffect(() => {
    if (childActive) setOpen(true)
  }, [childActive])

  return (
    <div>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className={`${ROW} ${
          childActive
            ? 'font-medium text-white'
            : 'font-medium text-navy-group hover:bg-white/[0.06] hover:text-white'
        }`}
      >
        <Icon size={18} strokeWidth={1.85} className="shrink-0" />
        <span className="flex-1 text-left">{node.label}</span>
        <motion.span
          animate={{ rotate: open ? 90 : 0 }}
          transition={{ duration: 0.2, ease: 'easeOut' }}
          className="shrink-0 text-navy-group/80"
        >
          <ChevronRight size={15} strokeWidth={2} />
        </motion.span>
      </button>

      <AnimatePresence initial={false}>
        {open && (
          <motion.div
            key="submenu"
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.24, ease: [0.22, 1, 0.36, 1] }}
            className="overflow-hidden"
          >
            <div className="flex flex-col gap-px pb-0.5 pt-px">
              {node.children!.map((child) => (
                <NavLink
                  key={child.key}
                  to={child.to}
                  end
                  onClick={onNavigate}
                  className={({ isActive }) =>
                    `flex h-[30px] items-center rounded-lg pl-[41px] pr-2.5 text-[13px] transition-colors ${
                      isActive
                        ? 'bg-brand font-semibold text-white'
                        : 'font-medium text-[#9fb6cc] hover:bg-white/[0.06] hover:text-white'
                    }`
                  }
                >
                  {child.label}
                </NavLink>
              ))}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}

export default function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  return (
    <aside className="flex h-full w-[248px] shrink-0 flex-col gap-0.5 overflow-y-auto bg-navy px-3.5 py-[18px]">
      {/* Brand */}
      <div className="flex w-full items-center gap-2.5 pb-3 pl-2 pt-0.5">
        <Logo size={28} className="shrink-0" />
        <div className="flex flex-col">
          <span className="text-[16px] font-bold leading-tight tracking-[0.32px] text-white">
            CORDES
          </span>
          <span className="text-[9.5px] font-medium leading-tight tracking-[0.19px] text-navy-muted">
            Sistema Comercial
          </span>
        </div>
      </div>

      {NAVIGATION.map((node) =>
        node.children ? (
          <Group key={node.key} node={node} onNavigate={onNavigate} />
        ) : (
          <DirectItem key={node.key} node={node} onNavigate={onNavigate} />
        ),
      )}
    </aside>
  )
}

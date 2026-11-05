import { useEffect, useId, useRef, type CSSProperties } from 'react'
import './CordesBot.css'

/* ---------------------------------------------------------------------------
   Cordes IA, el robot del Buscador.

   Es 3D de verdad, no un dibujo con sombra: cada parte (cuerpo, cabeza, visor,
   antena) es una capa SVG a distinta distancia dentro de una escena con
   perspectiva. Al mover el mouse la escena se inclina y las capas se separan
   —como mirar un muñeco desde un costado—, y los ojos siguen al cursor.

   Estados:
     reposo     parpadea, flota, mira alrededor
     pensando   ojos que escanean, antena con ondas, barras en el visor
     contento   ojos felices, mejillas, brillitos, un saltito
     confundido ojo desparejo, la cabeza ladeada y un "?"
     preguntando cabeza ladeada para el otro lado y un "?": espera que elijas

   Con "reducir movimiento" activado en el sistema, queda quieto.
--------------------------------------------------------------------------- */

export type EstadoDelRobot = 'reposo' | 'pensando' | 'contento' | 'confundido' | 'preguntando'

export default function CordesBot({
  estado = 'reposo',
  tamano = 120,
  className,
}: {
  estado?: EstadoDelRobot
  /** Alto en píxeles; el ancho acompaña. */
  tamano?: number
  className?: string
}) {
  const raiz = useRef<HTMLDivElement>(null)
  const id = useId().replace(/:/g, '')

  // Hacia dónde mira: -1 (izquierda / arriba) a 1 (derecha / abajo), para toda la pantalla.
  useEffect(() => {
    const el = raiz.current
    if (!el || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return

    let cuadro = 0
    let ultimoMovimiento = 0
    const mirar = (x: number, y: number) => {
      el.style.setProperty('--px', x.toFixed(3))
      el.style.setProperty('--py', y.toFixed(3))
    }

    const alMover = (e: PointerEvent) => {
      ultimoMovimiento = Date.now()
      cancelAnimationFrame(cuadro)
      cuadro = requestAnimationFrame(() => {
        const r = el.getBoundingClientRect()
        const dx = (e.clientX - (r.left + r.width / 2)) / (window.innerWidth / 2)
        const dy = (e.clientY - (r.top + r.height / 2)) / (window.innerHeight / 2)
        mirar(Math.max(-1, Math.min(1, dx)), Math.max(-1, Math.min(1, dy)))
      })
    }

    // Sin mouse un rato, mira a su alrededor.
    const curioso = setInterval(() => {
      if (Date.now() - ultimoMovimiento > 5000) mirar((Math.random() - 0.5) * 0.9, (Math.random() - 0.5) * 0.5)
    }, 3200)

    window.addEventListener('pointermove', alMover, { passive: true })

    return () => {
      window.removeEventListener('pointermove', alMover)
      clearInterval(curioso)
      cancelAnimationFrame(cuadro)
    }
  }, [])

  const ancho = tamano * (200 / 220)
  const estilo = { width: ancho, height: tamano, '--u': ancho / 200 } as CSSProperties

  return (
    <div
      ref={raiz}
      className={`bot bot--${estado} ${className ?? ''}`}
      style={estilo}
      role="img"
      aria-label={`Cordes IA, el asistente de búsqueda${estado === 'pensando' ? ', pensando' : ''}`}
    >
      <div className="bot__flota">
        <div className="bot__escena">
          {/* Sombra en el piso */}
          <svg className="bot__capa bot__capa--piso" viewBox="0 0 200 220" aria-hidden="true">
            <defs>
              <radialGradient id={`${id}-sombra`}>
                <stop offset="0" stopColor="#0a2e45" stopOpacity=".38" />
                <stop offset="1" stopColor="#0a2e45" stopOpacity="0" />
              </radialGradient>
            </defs>
            <ellipse className="bot__sombra" cx="100" cy="212" rx="58" ry="8" fill={`url(#${id}-sombra)`} />
          </svg>

          {/* Cuerpo */}
          <svg className="bot__capa bot__capa--cuerpo" viewBox="0 0 200 220" aria-hidden="true">
            <defs>
              <linearGradient id={`${id}-torso`} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stopColor="#f6fafd" />
                <stop offset=".55" stopColor="#c3d5e6" />
                <stop offset="1" stopColor="#8ea9c4" />
              </linearGradient>
              <radialGradient id={`${id}-hombro`} cx=".35" cy=".3" r=".8">
                <stop offset="0" stopColor="#ffffff" />
                <stop offset=".55" stopColor="#c9d9e8" />
                <stop offset="1" stopColor="#8aa6c2" />
              </radialGradient>
              <linearGradient id={`${id}-cuello`} x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" stopColor="#1a2f44" />
                <stop offset=".5" stopColor="#3d5a76" />
                <stop offset="1" stopColor="#1a2f44" />
              </linearGradient>
              <linearGradient id={`${id}-pecho`} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stopColor="#12395c" />
                <stop offset="1" stopColor="#0a2e45" />
              </linearGradient>
            </defs>
            <rect x="86" y="146" width="28" height="22" rx="6" fill={`url(#${id}-cuello)`} />
            <path d="M50 216 C50 178 70 160 100 160 C130 160 150 178 150 216 Z" fill={`url(#${id}-torso)`} stroke="#0a2e45" strokeOpacity=".2" />
            <circle cx="52" cy="192" r="15" fill={`url(#${id}-hombro)`} stroke="#0a2e45" strokeOpacity=".2" />
            <circle cx="148" cy="192" r="15" fill={`url(#${id}-hombro)`} stroke="#0a2e45" strokeOpacity=".2" />
            <rect x="76" y="178" width="48" height="28" rx="9" fill={`url(#${id}-pecho)`} stroke="#04182b" strokeOpacity=".5" />
            <circle className="bot__luz bot__luz--1" cx="90" cy="192" r="4" fill="#5be3ff" />
            <circle className="bot__luz bot__luz--2" cx="100" cy="192" r="4" fill="#a78bfa" />
            <circle className="bot__luz bot__luz--3" cx="110" cy="192" r="4" fill="#ffffff" />
            <path d="M80 183 Q100 179 120 183" stroke="#fff" strokeOpacity=".25" strokeWidth="1.5" fill="none" strokeLinecap="round" />
          </svg>

          {/* Cabeza */}
          <svg className="bot__capa bot__capa--cabeza" viewBox="0 0 200 220" aria-hidden="true">
            <defs>
              <linearGradient id={`${id}-cabeza`} x1=".1" y1="0" x2=".9" y2="1">
                <stop offset="0" stopColor="#ffffff" />
                <stop offset=".5" stopColor="#e4edf6" />
                <stop offset="1" stopColor="#b3c9de" />
              </linearGradient>
              <linearGradient id={`${id}-oreja`} x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" stopColor="#38b2f0" />
                <stop offset=".6" stopColor="#007dc3" />
                <stop offset="1" stopColor="#0a5c8a" />
              </linearGradient>
              <filter id={`${id}-suave`} x="-30%" y="-30%" width="160%" height="160%">
                <feGaussianBlur stdDeviation="3" />
              </filter>
            </defs>
            <rect x="22" y="78" width="20" height="46" rx="10" fill={`url(#${id}-oreja)`} stroke="#0a2e45" strokeOpacity=".3" />
            <rect x="158" y="78" width="20" height="46" rx="10" fill={`url(#${id}-oreja)`} stroke="#0a2e45" strokeOpacity=".3" />
            <circle className="bot__luz bot__luz--oreja" cx="32" cy="101" r="3.2" fill="#c9f2ff" />
            <circle className="bot__luz bot__luz--oreja" cx="168" cy="101" r="3.2" fill="#c9f2ff" />
            <rect x="36" y="38" width="128" height="116" rx="40" fill={`url(#${id}-cabeza)`} stroke="#0a2e45" strokeOpacity=".28" strokeWidth="1.5" />
            <ellipse cx="78" cy="55" rx="34" ry="8" transform="rotate(-10 78 55)" fill="#fff" opacity=".75" filter={`url(#${id}-suave)`} />
            <path d="M62 46 Q100 36 138 46" stroke="#fff" strokeOpacity=".7" strokeWidth="2" fill="none" strokeLinecap="round" />
            <circle cx="52" cy="62" r="2.4" fill="#8aa6c2" />
            <circle cx="148" cy="62" r="2.4" fill="#8aa6c2" />
            <circle cx="52" cy="134" r="2.4" fill="#8aa6c2" />
            <circle cx="148" cy="134" r="2.4" fill="#8aa6c2" />
          </svg>

          {/* Cara: el visor con los ojos */}
          <svg className="bot__capa bot__capa--cara" viewBox="0 0 200 220" aria-hidden="true">
            <defs>
              <linearGradient id={`${id}-visor`} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stopColor="#0b2742" />
                <stop offset="1" stopColor="#154067" />
              </linearGradient>
              <clipPath id={`${id}-recorte`}>
                <rect x="52" y="64" width="96" height="66" rx="26" />
              </clipPath>
              <filter id={`${id}-brillo`} x="-60%" y="-60%" width="220%" height="220%">
                <feGaussianBlur stdDeviation="4.5" />
              </filter>
            </defs>
            <rect x="52" y="64" width="96" height="66" rx="26" fill={`url(#${id}-visor)`} stroke="#04182b" strokeWidth="2.5" />
            <rect x="55" y="67" width="90" height="60" rx="23" fill="none" stroke="#5be3ff" strokeOpacity=".22" />
            <g clipPath={`url(#${id}-recorte)`}>
              <path d="M58 64 L100 64 L72 130 L50 130 Z" fill="#fff" opacity=".09" />
              {/* Barras del visor: mientras piensa */}
              <g className="bot__barras">
                {[0, 1, 2, 3, 4].map((i) => (
                  <rect key={i} className="bot__barra" style={{ animationDelay: `${i * 0.12}s` }} x={84 + i * 7} y="112" width="4" height="10" rx="2" fill="#5be3ff" />
                ))}
              </g>
            </g>

            {/* Mejillas */}
            <circle className="bot__mejilla" cx="68" cy="112" r="7" fill="#ff7aa8" filter={`url(#${id}-brillo)`} />
            <circle className="bot__mejilla" cx="132" cy="112" r="7" fill="#ff7aa8" filter={`url(#${id}-brillo)`} />

            <g className="bot__ojos">
              {/* Ojos normales */}
              <g className="bot__ojos-normales">
                <g className="bot__ojo bot__ojo--izq">
                  <rect x="72" y="80" width="18" height="28" rx="9" fill="#5be3ff" opacity=".8" filter={`url(#${id}-brillo)`} />
                  <rect x="72" y="80" width="18" height="28" rx="9" fill="#7defff" />
                  <circle cx="78" cy="87" r="3" fill="#fff" />
                </g>
                <g className="bot__ojo bot__ojo--der">
                  <rect x="110" y="80" width="18" height="28" rx="9" fill="#5be3ff" opacity=".8" filter={`url(#${id}-brillo)`} />
                  <rect x="110" y="80" width="18" height="28" rx="9" fill="#7defff" />
                  <circle cx="116" cy="87" r="3" fill="#fff" />
                </g>
              </g>
              {/* Ojos contentos: dos arcos */}
              <g className="bot__ojos-contentos" fill="none" stroke="#7defff" strokeWidth="6" strokeLinecap="round">
                <path d="M73 102 Q81 82 89 102" />
                <path d="M111 102 Q119 82 127 102" />
              </g>
            </g>

            {/* Sonrisa */}
            <path className="bot__sonrisa" d="M91 118 Q100 124 109 118" stroke="#7defff" strokeOpacity=".85" strokeWidth="2.6" strokeLinecap="round" fill="none" />
          </svg>

          {/* Antena y adornos */}
          <svg className="bot__capa bot__capa--antena" viewBox="0 0 200 220" aria-hidden="true">
            <defs>
              <radialGradient id={`${id}-bulbo`} cx=".35" cy=".3" r=".85">
                <stop offset="0" stopColor="#e4d4ff" />
                <stop offset=".5" stopColor="#8b5cf6" />
                <stop offset="1" stopColor="#4c1d95" />
              </radialGradient>
              <linearGradient id={`${id}-tallo`} x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" stopColor="#8aa6c2" />
                <stop offset=".5" stopColor="#e4edf6" />
                <stop offset="1" stopColor="#8aa6c2" />
              </linearGradient>
            </defs>
            <rect x="97.5" y="22" width="5" height="20" rx="2.5" fill={`url(#${id}-tallo)`} stroke="#0a2e45" strokeOpacity=".25" />
            <circle className="bot__onda bot__onda--1" cx="100" cy="17" r="9" fill="none" stroke="#7c3aed" strokeWidth="2" />
            <circle className="bot__onda bot__onda--2" cx="100" cy="17" r="9" fill="none" stroke="#7c3aed" strokeWidth="2" />
            <circle className="bot__halo" cx="100" cy="17" r="15" fill="#8b5cf6" opacity=".28" />
            <circle className="bot__bulbo" cx="100" cy="17" r="9" fill={`url(#${id}-bulbo)`} stroke="#3b1678" strokeOpacity=".5" />
            <circle cx="96.5" cy="13.5" r="2.6" fill="#fff" opacity=".85" />

            {/* Brillitos de contento */}
            <g className="bot__brillos" fill="#fbbf24">
              <path className="bot__brillo bot__brillo--1" d="M164 40 l3 8 8 3 -8 3 -3 8 -3 -8 -8 -3 8 -3z" />
              <path className="bot__brillo bot__brillo--2" d="M30 52 l2 6 6 2 -6 2 -2 6 -2 -6 -6 -2 6 -2z" />
            </g>

            {/* La duda */}
            <text className="bot__duda" x="150" y="38" fontSize="30" fontWeight="800" fill="#7c3aed" fontFamily="Inter, system-ui, sans-serif">
              ?
            </text>
          </svg>
        </div>
      </div>
    </div>
  )
}

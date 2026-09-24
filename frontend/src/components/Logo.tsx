import markUrl from '../assets/cordes-mark.svg'

interface LogoProps {
  size?: number
  className?: string
}

/** Official CORDES "rc" brand mark (vector, from the Figma design). */
export default function Logo({ size = 28, className }: LogoProps) {
  return (
    <img
      src={markUrl}
      width={size}
      height={size}
      className={className}
      alt="CORDES"
      draggable={false}
    />
  )
}

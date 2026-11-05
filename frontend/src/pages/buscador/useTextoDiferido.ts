import { useEffect, useRef, useState } from 'react'

/**
 * Un texto que sólo se manda al buscador cuando se deja de escribir.
 *
 * El valor de la dirección y lo que se está tecleando pueden diferir un
 * momento: si la dirección se actualizara con lo viejo mientras se sigue
 * escribiendo, pisaría lo tecleado. Por eso se recuerda lo último que se
 * mandó y sólo se vuelve a copiar de la dirección cuando cambió por otro
 * motivo (por ejemplo "Quitar los filtros").
 */
export function useTextoDiferido(valor: string, alCambiar: (v: string) => void, ms = 350) {
  const [local, setLocal] = useState(valor)
  const mandado = useRef(valor)
  const avisar = useRef(alCambiar)
  avisar.current = alCambiar

  useEffect(() => {
    if (valor !== mandado.current) {
      mandado.current = valor
      setLocal(valor)
    }
  }, [valor])

  useEffect(() => {
    if (local === mandado.current) return
    const id = setTimeout(() => {
      mandado.current = local
      avisar.current(local)
    }, ms)

    return () => clearTimeout(id)
  }, [local, ms])

  return [local, setLocal] as const
}

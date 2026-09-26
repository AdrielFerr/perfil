import { useCallback, useEffect, useRef, useState } from 'react'

/**
 * Regra 65: mantém a tela ligada durante a partida.
 * Se o aparelho não tiver a API, não faz nada e não reclama.
 */
export function useTelaAcesa(ativo = true) {
  const trava = useRef(null)

  useEffect(() => {
    if (!ativo) return undefined
    if (typeof navigator === 'undefined' || !('wakeLock' in navigator)) return undefined

    let cancelado = false

    const pedir = async () => {
      try {
        const nova = await navigator.wakeLock.request('screen')
        if (cancelado) {
          nova.release().catch(() => {})
          return
        }
        trava.current = nova
      } catch {
        /* recusado ou sem bateria: seguimos sem trava */
      }
    }

    // O Android solta a trava quando a aba sai de foco: pedimos de novo.
    const aoVoltar = () => {
      if (document.visibilityState === 'visible') pedir()
    }

    pedir()
    document.addEventListener('visibilitychange', aoVoltar)

    return () => {
      cancelado = true
      document.removeEventListener('visibilitychange', aoVoltar)
      if (trava.current) {
        trava.current.release().catch(() => {})
        trava.current = null
      }
    }
  }, [ativo])
}

/** Regra 66: vibração curta no acerto e no erro. */
export function useVibrar() {
  return useCallback((padrao) => {
    try {
      if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
        navigator.vibrate(padrao)
      }
    } catch {
      /* aparelho sem motor de vibração */
    }
  }, [])
}

export const VIBRACAO = {
  toque: 12,
  acerto: [30, 50, 90],
  erro: [140],
  troca: [18, 40, 18],
}

/**
 * Regra 64: descobre a altura do teclado virtual para o campo de palpite
 * continuar visível. Usa visualViewport, que funciona no Chrome do Android
 * e no Safari do iPhone.
 */
export function useTecladoVirtual() {
  const [altura, definirAltura] = useState(0)

  useEffect(() => {
    const vv = typeof window !== 'undefined' ? window.visualViewport : null
    if (!vv) return undefined

    const medir = () => {
      const sobra = window.innerHeight - vv.height - vv.offsetTop
      definirAltura(sobra > 100 ? Math.round(sobra) : 0)
    }

    medir()
    vv.addEventListener('resize', medir)
    vv.addEventListener('scroll', medir)

    return () => {
      vv.removeEventListener('resize', medir)
      vv.removeEventListener('scroll', medir)
    }
  }, [])

  useEffect(() => {
    document.documentElement.style.setProperty('--altura-teclado', `${altura}px`)
  }, [altura])

  return altura
}

/** Avisa quando o aparelho fica sem internet (regra 76). */
export function useConexao() {
  const [online, definirOnline] = useState(
    typeof navigator === 'undefined' ? true : navigator.onLine !== false
  )

  useEffect(() => {
    const ligou = () => definirOnline(true)
    const caiu = () => definirOnline(false)

    window.addEventListener('online', ligou)
    window.addEventListener('offline', caiu)

    return () => {
      window.removeEventListener('online', ligou)
      window.removeEventListener('offline', caiu)
    }
  }, [])

  return online
}

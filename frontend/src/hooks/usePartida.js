import { useCallback, useEffect, useRef, useState } from 'react'
import { api, ErroApi, memoriaLocal } from '../services/api'

/**
 * Guarda todo o estado da partida e expõe as ações da tela de jogo.
 * O estado verdadeiro mora no banco; aqui é só uma cópia para desenhar.
 */
export function usePartida(partidaId) {
  const [estado, definirEstado] = useState(null)
  const [carregando, definirCarregando] = useState(true)
  const [ocupado, definirOcupado] = useState(false)
  const [erro, definirErro] = useState(null)

  // Resultado da última jogada, para animar acerto/erro e mostrar a resposta.
  const [ultimaJogada, definirUltimaJogada] = useState(null)

  const montado = useRef(true)

  useEffect(() => {
    montado.current = true
    return () => {
      montado.current = false
    }
  }, [])

  const aplicar = useCallback((novoEstado) => {
    if (!montado.current) return
    definirEstado(novoEstado)
    definirErro(null)
  }, [])

  const tratarErro = useCallback((e) => {
    if (!montado.current) return
    if (e?.name === 'AbortError') return
    definirErro(e instanceof ErroApi ? e : new ErroApi(e?.message || 'Algo deu errado.'))
  }, [])

  const recarregar = useCallback(
    async (sinal) => {
      try {
        const dados = await api.estadoPartida(partidaId, sinal)
        aplicar(dados)
      } catch (e) {
        tratarErro(e)
      } finally {
        if (montado.current) definirCarregando(false)
      }
    },
    [partidaId, aplicar, tratarErro]
  )

  useEffect(() => {
    if (!partidaId) return undefined

    const controlador = new AbortController()
    definirCarregando(true)
    recarregar(controlador.signal)

    return () => controlador.abort()
  }, [partidaId, recarregar])

  /** Se a tela voltou do bloqueio, o estado pode ter mudado noutro lugar. */
  useEffect(() => {
    if (!partidaId) return undefined

    const aoVoltar = () => {
      if (document.visibilityState === 'visible') recarregar()
    }

    document.addEventListener('visibilitychange', aoVoltar)
    return () => document.removeEventListener('visibilitychange', aoVoltar)
  }, [partidaId, recarregar])

  /** Envolve qualquer ação: trava botões, trata erro e atualiza o estado. */
  const executar = useCallback(
    async (acao) => {
      if (!montado.current) return null
      definirOcupado(true)
      definirErro(null)

      try {
        return await acao()
      } catch (e) {
        tratarErro(e)
        return null
      } finally {
        if (montado.current) definirOcupado(false)
      }
    },
    [tratarErro]
  )

  const revelarDica = useCallback(
    (numero) =>
      executar(async () => {
        const dados = await api.revelarDica(partidaId, numero)
        aplicar(dados.estado)
        definirUltimaJogada(null)
        return dados.dica
      }),
    [partidaId, executar, aplicar]
  )

  const enviarPalpite = useCallback(
    (palpite) =>
      executar(async () => {
        const dados = await api.palpitar(partidaId, palpite)
        aplicar(dados.estado)
        definirUltimaJogada(dados)
        return dados
      }),
    [partidaId, executar, aplicar]
  )

  const passarVez = useCallback(
    () =>
      executar(async () => {
        const dados = await api.passarVez(partidaId)
        aplicar(dados.estado)
        definirUltimaJogada(dados)
        return dados
      }),
    [partidaId, executar, aplicar]
  )

  const revelarResposta = useCallback(
    () =>
      executar(async () => {
        const dados = await api.revelarResposta(partidaId)
        aplicar(dados.estado)
        definirUltimaJogada({
          acertou: false,
          pontos_ganhos: 0,
          resposta: dados.resposta,
          url_fonte: dados.url_fonte,
          revelada: true,
        })
        return dados
      }),
    [partidaId, executar, aplicar]
  )

  const proximaCarta = useCallback(
    () =>
      executar(async () => {
        const dados = await api.proximaCarta(partidaId)
        aplicar(dados)
        definirUltimaJogada(null)
        return dados
      }),
    [partidaId, executar, aplicar]
  )

  const desfazer = useCallback(
    () =>
      executar(async () => {
        const dados = await api.desfazer(partidaId)
        aplicar(dados)
        definirUltimaJogada(null)
        return dados
      }),
    [partidaId, executar, aplicar]
  )

  const encerrar = useCallback(
    () =>
      executar(async () => {
        const dados = await api.encerrarPartida(partidaId)
        aplicar(dados)
        memoriaLocal.esquecerPartida()
        return dados
      }),
    [partidaId, executar, aplicar]
  )

  const limparUltimaJogada = useCallback(() => definirUltimaJogada(null), [])
  const limparErro = useCallback(() => definirErro(null), [])

  return {
    estado,
    carregando,
    ocupado,
    erro,
    ultimaJogada,
    recarregar,
    revelarDica,
    enviarPalpite,
    passarVez,
    revelarResposta,
    proximaCarta,
    desfazer,
    encerrar,
    limparUltimaJogada,
    limparErro,
  }
}

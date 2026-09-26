import { useEffect, useRef, useState } from 'react'

/**
 * Placar compacto do topo (regra 61).
 * Mostra avatar, nome, cor e pontos, destaca de quem é a vez e apaga
 * quem já caiu fora da carta atual (regra 25).
 */
export function Placar({ jogadores, jogadorVezId, pontuacaoVitoria, eliminados = [] }) {
  const pontosAnteriores = useRef({})
  const [subindo, definirSubindo] = useState({})

  useEffect(() => {
    const mudaram = {}

    jogadores.forEach((jogador) => {
      const antes = pontosAnteriores.current[jogador.id]
      if (antes !== undefined && jogador.pontos > antes) {
        mudaram[jogador.id] = true
      }
      pontosAnteriores.current[jogador.id] = jogador.pontos
    })

    if (Object.keys(mudaram).length === 0) return undefined

    definirSubindo(mudaram)
    const tempo = setTimeout(() => definirSubindo({}), 700)
    return () => clearTimeout(tempo)
  }, [jogadores])

  return (
    <div className="placar" role="list" aria-label={`Placar, vitória com ${pontuacaoVitoria} pontos`}>
      {jogadores.map((jogador) => {
        const ehAVez = jogador.id === jogadorVezId
        const estaFora = eliminados.includes(jogador.id)

        return (
          <div
            key={jogador.id}
            role="listitem"
            className={`placar__jogador${ehAVez ? ' placar__jogador--vez' : ''}${
              estaFora ? ' placar__jogador--fora' : ''
            }`}
            style={{ '--cor-jogador': jogador.cor }}
          >
            <div className="placar__avatar" aria-hidden="true">
              {estaFora ? '✗' : jogador.avatar}
            </div>
            <div className="placar__nome">{jogador.nome}</div>
            <div
              className={`placar__pontos${subindo[jogador.id] ? ' placar__pontos--subindo' : ''}`}
              style={{ color: ehAVez ? jogador.cor : undefined }}
            >
              {jogador.pontos}
            </div>
            <span className="so-leitor">
              {jogador.nome}, {jogador.pontos} pontos
              {estaFora ? ', fora desta carta' : ''}
              {ehAVez ? ', é a vez dele' : ''}
            </span>
          </div>
        )
      })}
    </div>
  )
}

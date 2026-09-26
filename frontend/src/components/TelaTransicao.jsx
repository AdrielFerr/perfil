import { useEffect } from 'react'
import { useVibrar, VIBRACAO } from '../hooks/useDispositivo'

/**
 * Regra 28: tela cheia de passagem do celular.
 * "Vez da Ana! Toque para jogar"
 */
export function TelaTransicao({ jogador, pontuacaoVitoria, numeroCarta, aoTocar }) {
  const vibrar = useVibrar()

  useEffect(() => {
    vibrar(VIBRACAO.troca)
  }, [vibrar])

  if (!jogador) return null

  const faltam = Math.max(0, pontuacaoVitoria - jogador.pontos)

  return (
    <div
      className="transicao"
      style={{ '--cor-jogador': jogador.cor }}
      onClick={aoTocar}
      onKeyDown={(evento) => {
        if (evento.key === 'Enter' || evento.key === ' ') aoTocar()
      }}
      role="button"
      tabIndex={0}
      aria-label={`Vez de ${jogador.nome}. Toque para jogar.`}
    >
      <div className="transicao__avatar" aria-hidden="true">
        {jogador.avatar}
      </div>

      {/* "Vez de" em vez de "Vez da/do": funciona com qualquer nome. */}
      <div className="transicao__chamada">Vez de {jogador.nome}!</div>

      <div className="transicao__pontos">
        {jogador.pontos} ponto{jogador.pontos === 1 ? '' : 's'}
        {faltam > 0 ? ` · faltam ${faltam} para vencer` : ' · pode vencer agora!'}
      </div>

      {numeroCarta ? (
        <div className="transicao__pontos apagado">Carta {numeroCarta}</div>
      ) : null}

      <div className="transicao__instrucao">Toque para jogar</div>
    </div>
  )
}

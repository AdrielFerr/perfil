import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { api, memoriaLocal } from '../services/api'
import { Botao, Carregando, MensagemErro } from '../components/Basicos'
import { useVibrar, VIBRACAO } from '../hooks/useDispositivo'

/** Regra 74: quem venceu, o placar final e a chance de jogar de novo. */
export function TelaFim() {
  const { id } = useParams()
  const navegar = useNavigate()
  const vibrar = useVibrar()

  const [estado, definirEstado] = useState(null)
  const [carregando, definirCarregando] = useState(true)
  const [erro, definirErro] = useState(null)

  const carregar = async () => {
    definirCarregando(true)
    definirErro(null)

    try {
      const dados = await api.estadoPartida(Number(id))
      definirEstado(dados)
      memoriaLocal.esquecerPartida()
      vibrar(VIBRACAO.acerto)
    } catch (e) {
      definirErro(e)
    } finally {
      definirCarregando(false)
    }
  }

  useEffect(() => {
    carregar()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  if (carregando) {
    return (
      <div className="tela centralizado">
        <Carregando texto="Fechando o placar..." />
      </div>
    )
  }

  if (!estado) {
    return (
      <div className="tela centralizado pilha">
        <MensagemErro erro={erro} aoTentarDeNovo={carregar} />
        <Botao variante="fantasma" onClick={() => navegar('/')}>
          Voltar para o começo
        </Botao>
      </div>
    )
  }

  const classificacao = [...estado.jogadores].sort((a, b) => b.pontos - a.pontos)
  const campeao =
    classificacao.find((jogador) => jogador.id === estado.partida.vencedor_id) ?? classificacao[0]

  const houveEmpateNoTopo =
    classificacao.length > 1 && classificacao[0].pontos === classificacao[1].pontos

  return (
    <div className="tela tela--rolavel">
      <div className="tela__corpo pilha--larga">
        <h1 className="titulo-tela">Fim de jogo!</h1>

        {campeao && campeao.pontos > 0 ? (
          <div className="campeao" style={{ '--cor-jogador': campeao.cor }}>
            <div className="campeao__coroa" aria-hidden="true">👑</div>
            <div className="campeao__avatar" aria-hidden="true">{campeao.avatar}</div>
            <div className="campeao__nome">{campeao.nome}</div>
            <div className="campeao__pontos">
              {campeao.pontos} ponto{campeao.pontos === 1 ? '' : 's'}
            </div>

            {houveEmpateNoTopo && (
              <p className="pequeno suave" style={{ marginTop: 8 }}>
                Empate no placar: ganhou quem acertou a última carta.
              </p>
            )}
          </div>
        ) : (
          <div className="cartao centro">
            <p className="negrito">A partida terminou sem ninguém pontuar.</p>
          </div>
        )}

        <div className="pilha pilha--apertada">
          <div className="campo__rotulo">Placar final</div>
          <div className="classificacao">
            {classificacao.map((jogador, indice) => (
              <div
                key={jogador.id}
                className="classificacao__linha"
                style={{ '--cor-jogador': jogador.cor }}
              >
                <span className="classificacao__posicao">{indice + 1}º</span>
                <span className="classificacao__avatar" aria-hidden="true">{jogador.avatar}</span>
                <span className="classificacao__nome">{jogador.nome}</span>
                <span className="classificacao__pontos">{jogador.pontos}</span>
              </div>
            ))}
          </div>
        </div>

        <p className="pequeno apagado centro">
          {estado.partida.numero_carta} carta{estado.partida.numero_carta === 1 ? '' : 's'} jogada
          {estado.partida.numero_carta === 1 ? '' : 's'} · meta de {estado.partida.pontuacao_vitoria} pontos
        </p>

        <div className="pilha">
          <Botao variante="principal" largo icone="🔁" onClick={() => navegar('/novo-jogo')}>
            Jogar de novo
          </Botao>
          <Botao variante="fantasma" largo onClick={() => navegar('/')}>
            Voltar para o começo
          </Botao>
        </div>
      </div>
    </div>
  )
}

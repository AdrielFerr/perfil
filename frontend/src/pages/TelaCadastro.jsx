import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, memoriaLocal } from '../services/api'
import { Botao, MensagemErro } from '../components/Basicos'
import { useVibrar, VIBRACAO } from '../hooks/useDispositivo'

// Oito tintas de carimbo: todas legíveis sobre o papel.
const CORES = [
  '#B3202E', '#26406E', '#1D5C4F', '#A06A10',
  '#6B2D5C', '#1C1A16', '#8A4B1F', '#3E6B2F',
]

const AVATARES = [
  '🦊', '🐸', '🦁', '🐼', '🐯', '🐵',
  '🦄', '🐙', '🦉', '🐨', '🦖', '🐷',
  '👽', '🤖', '🎩', '🌟', '🍕', '⚡',
]

const PONTUACOES = [30, 50, 70, 100]

function novoJogador(indice) {
  return {
    chave: `jogador-${Date.now()}-${indice}`,
    nome: '',
    cor: CORES[indice % CORES.length],
    avatar: AVATARES[indice % AVATARES.length],
  }
}

/** Regras 20, 29 e 73: quem joga e com qual cara. O palpite e sempre digitado. */
export function TelaCadastro() {
  const navegar = useNavigate()
  const vibrar = useVibrar()

  const [jogadores, definirJogadores] = useState([novoJogador(0), novoJogador(1)])
  const [pontuacaoVitoria, definirPontuacaoVitoria] = useState(50)
  const [editando, definirEditando] = useState(null)
  const [enviando, definirEnviando] = useState(false)
  const [erro, definirErro] = useState(null)

  const mudarJogador = (chave, campo, valor) => {
    definirJogadores((atuais) =>
      atuais.map((jogador) => (jogador.chave === chave ? { ...jogador, [campo]: valor } : jogador))
    )
  }

  const adicionar = () => {
    if (jogadores.length >= 6) return
    vibrar(VIBRACAO.toque)
    definirJogadores((atuais) => [...atuais, novoJogador(atuais.length)])
  }

  const remover = (chave) => {
    if (jogadores.length <= 2) return
    vibrar(VIBRACAO.toque)
    definirJogadores((atuais) => atuais.filter((jogador) => jogador.chave !== chave))
    definirEditando(null)
  }

  const comecar = async () => {
    definirErro(null)

    const limpos = jogadores.map((jogador) => ({
      nome: jogador.nome.trim(),
      cor: jogador.cor,
      avatar: jogador.avatar,
    }))

    const vazio = limpos.findIndex((jogador) => jogador.nome === '')
    if (vazio >= 0) {
      definirErro({ mensagem: `Falta o nome do jogador ${vazio + 1}.` })
      return
    }

    const nomes = limpos.map((jogador) => jogador.nome.toLowerCase())
    if (new Set(nomes).size !== nomes.length) {
      definirErro({ mensagem: 'Dois jogadores estão com o mesmo nome. Deixe cada um diferente.' })
      return
    }

    definirEnviando(true)

    try {
      const estado = await api.criarPartida({
        jogadores: limpos,
        pontuacaoVitoria,
      })

      memoriaLocal.guardarPartida(estado.partida.id)
      navegar(`/partida/${estado.partida.id}`, { replace: true })
    } catch (e) {
      definirErro(e)
      definirEnviando(false)
    }
  }

  const jogadorEditado = jogadores.find((jogador) => jogador.chave === editando)

  return (
    <div className="tela tela--rolavel">
      <div className="tela__corpo">
        <div className="admin__topo">
          <Botao pequeno variante="fantasma" icone="←" onClick={() => navegar('/')}>
            Voltar
          </Botao>
          <h1 className="titulo-tela crescer">Quem vai jogar?</h1>
        </div>

        <MensagemErro erro={erro} aoFechar={() => definirErro(null)} />

        {/* ---------------- Jogadores ---------------- */}
        <div className="pilha pilha--apertada">
          <div className="campo__rotulo">
            Jogadores ou equipes ({jogadores.length} de 6)
          </div>

          <div className="lista-jogadores">
            {jogadores.map((jogador, indice) => (
              <div
                key={jogador.chave}
                className="jogador-linha"
                style={{ '--cor-jogador': jogador.cor }}
              >
                <button
                  type="button"
                  className="jogador-linha__seletor"
                  style={{ borderColor: jogador.cor }}
                  onClick={() => {
                    vibrar(VIBRACAO.toque)
                    definirEditando(editando === jogador.chave ? null : jogador.chave)
                  }}
                  aria-label={`Trocar a cor e o avatar do jogador ${indice + 1}`}
                >
                  {jogador.avatar}
                </button>

                <input
                  className="entrada jogador-linha__nome"
                  type="text"
                  inputMode="text"
                  autoComplete="off"
                  maxLength={40}
                  placeholder={`Jogador ${indice + 1}`}
                  value={jogador.nome}
                  onChange={(evento) => mudarJogador(jogador.chave, 'nome', evento.target.value)}
                  aria-label={`Nome do jogador ${indice + 1}`}
                />

                <button
                  type="button"
                  className="jogador-linha__remover"
                  onClick={() => remover(jogador.chave)}
                  disabled={jogadores.length <= 2}
                  aria-label={`Tirar o jogador ${indice + 1}`}
                >
                  ✕
                </button>
              </div>
            ))}
          </div>

          {/* Paleta aberta abaixo do jogador escolhido */}
          {jogadorEditado && (
            <div className="cartao cartao--compacto pilha pilha--apertada">
              <div className="campo__rotulo">Cor</div>
              <div className="paleta">
                {CORES.map((cor) => (
                  <button
                    key={cor}
                    type="button"
                    className={`paleta__opcao${jogadorEditado.cor === cor ? ' paleta__opcao--marcada' : ''}`}
                    style={{ background: cor }}
                    onClick={() => mudarJogador(jogadorEditado.chave, 'cor', cor)}
                    aria-label={`Cor ${cor}`}
                    aria-pressed={jogadorEditado.cor === cor}
                  />
                ))}
              </div>

              <div className="campo__rotulo" style={{ marginTop: 6 }}>
                Avatar
              </div>
              <div className="paleta">
                {AVATARES.map((avatar) => (
                  <button
                    key={avatar}
                    type="button"
                    className={`paleta__opcao${jogadorEditado.avatar === avatar ? ' paleta__opcao--marcada' : ''}`}
                    style={{ background: 'var(--superficie-alta)' }}
                    onClick={() => mudarJogador(jogadorEditado.chave, 'avatar', avatar)}
                    aria-label={`Avatar ${avatar}`}
                    aria-pressed={jogadorEditado.avatar === avatar}
                  >
                    {avatar}
                  </button>
                ))}
              </div>

              <Botao pequeno variante="fantasma" largo onClick={() => definirEditando(null)}>
                Pronto
              </Botao>
            </div>
          )}

          <Botao
            variante="fantasma"
            largo
            icone="＋"
            onClick={adicionar}
            disabled={jogadores.length >= 6}
          >
            Adicionar jogador
          </Botao>
        </div>

        {/* ---------------- Pontuação (regra 27) ---------------- */}
        <div className="pilha pilha--apertada">
          <div className="campo__rotulo">Vence com quantos pontos</div>
          <div className="opcoes">
            {PONTUACOES.map((valor) => (
              <button
                key={valor}
                type="button"
                className={`opcao${pontuacaoVitoria === valor ? ' opcao--marcada' : ''}`}
                style={{ minWidth: 72, alignItems: 'center' }}
                onClick={() => definirPontuacaoVitoria(valor)}
                aria-pressed={pontuacaoVitoria === valor}
              >
                <span className="opcao__titulo">{valor}</span>
              </button>
            ))}
          </div>
          <p className="pequeno apagado">
            Com {pontuacaoVitoria} pontos a partida costuma durar{' '}
            {pontuacaoVitoria <= 30 ? 'uns 15 minutos' : pontuacaoVitoria <= 50 ? 'uns 25 minutos' : 'mais de 40 minutos'}.
          </p>
        </div>

        <Botao
          variante="principal"
          largo
          icone="🎲"
          onClick={comecar}
          disabled={enviando}
          style={{ marginTop: 4 }}
        >
          {enviando ? 'Embaralhando...' : 'Começar a partida'}
        </Botao>
      </div>
    </div>
  )
}

import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { usePartida } from '../hooks/usePartida'
import { useConexao, useTecladoVirtual, useTelaAcesa, useVibrar, VIBRACAO } from '../hooks/useDispositivo'
import { memoriaLocal } from '../services/api'
import { Botao, Carregando, EmblemaCategoria, MensagemErro, ModalConfirmacao } from '../components/Basicos'
import { Placar } from '../components/Placar'
import { GradeDicas } from '../components/GradeDicas'
import { TelaTransicao } from '../components/TelaTransicao'

const TOTAL_DICAS = 20

/**
 * Tela da partida. Cabe inteira no celular, sem rolagem (regra 61).
 *
 * Fases:
 *   transicao -> passa o celular para o próximo jogador (regra 28)
 *   jogando   -> escolhe a dica e chuta
 *   resultado -> mostra acertou/errou e, quando a carta acaba, a resposta
 */
export function TelaPartida() {
  const { id } = useParams()
  const navegar = useNavigate()
  const partidaId = Number(id)

  const jogo = usePartida(partidaId)
  const vibrar = useVibrar()
  const online = useConexao()

  useTelaAcesa(true)
  useTecladoVirtual()

  const [fase, definirFase] = useState('transicao')
  const [palpite, definirPalpite] = useState('')
  const [confirmando, definirConfirmando] = useState(null)

  const jogadorVistoPorUltimo = useRef(null)
  const campoPalpite = useRef(null)

  const estado = jogo.estado
  const partida = estado?.partida
  const carta = estado?.carta
  const jogadores = estado?.jogadores ?? []

  const jogadorDaVez = useMemo(
    () => jogadores.find((jogador) => jogador.id === partida?.jogador_vez_id) ?? null,
    [jogadores, partida?.jogador_vez_id]
  )

  const numerosUsados = useMemo(
    () => (carta?.dicas ?? []).map((dica) => dica.numero),
    [carta]
  )

  const dicaAtual = useMemo(() => {
    const lista = carta?.dicas ?? []
    return lista.length > 0 ? lista[lista.length - 1] : null
  }, [carta])

  // Guarda a partida no aparelho para dar para voltar depois (regra 56).
  useEffect(() => {
    if (partida?.status === 'em_andamento') {
      memoriaLocal.guardarPartida(partidaId)
    }
  }, [partidaId, partida?.status])

  // Fim de jogo leva para a tela do campeão (regra 74).
  useEffect(() => {
    if (partida?.status === 'encerrada') {
      navegar(`/fim/${partidaId}`, { replace: true })
    }
  }, [partida?.status, partidaId, navegar])

  // Trocou o jogador da vez? Mostra a tela de passar o celular.
  useEffect(() => {
    if (!partida?.jogador_vez_id) return

    if (jogadorVistoPorUltimo.current === null) {
      jogadorVistoPorUltimo.current = partida.jogador_vez_id
      definirFase('transicao')
      return
    }

    if (jogadorVistoPorUltimo.current !== partida.jogador_vez_id) {
      jogadorVistoPorUltimo.current = partida.jogador_vez_id
      definirPalpite('')
    }
  }, [partida?.jogador_vez_id])

  if (jogo.carregando) {
    return (
      <div className="tela centralizado">
        <Carregando texto="Puxando a carta..." />
      </div>
    )
  }

  if (!estado) {
    return (
      <div className="tela centralizado pilha">
        <MensagemErro erro={jogo.erro} aoTentarDeNovo={() => jogo.recarregar()} />
        <Botao variante="fantasma" onClick={() => navegar('/')}>
          Voltar para o começo
        </Botao>
      </div>
    )
  }

  const precisaAbrirDica = partida.dica_da_vez === null
  const cartaAcabou = partida.fim_de_carta
  const valorDoAcerto = estado.progresso.valor_do_acerto

  // Regra 25: quem erra ou desiste fica fora ate a carta virar.
  const eliminados = partida.eliminados_carta ?? []
  const todosForam = jogadores.length > 0 && eliminados.length >= jogadores.length

  // ------------------------------------------------------------------
  // Ações
  // ------------------------------------------------------------------

  const abrirDica = async (numero) => {
    vibrar(VIBRACAO.toque)
    await jogo.revelarDica(numero)

    // Espera a animação para o teclado não brigar com o layout.
    setTimeout(() => campoPalpite.current?.focus(), 260)
  }

  const passar = async () => {
    const resposta = await jogo.passarVez()
    if (resposta) definirFase(resposta.estado.partida.fim_de_carta ? 'resultado' : 'transicao')
  }

  const chutar = async (evento) => {
    evento?.preventDefault()

    const texto = palpite.trim()
    if (texto === '') return

    campoPalpite.current?.blur()

    const resposta = await jogo.enviarPalpite(texto)
    definirPalpite('')

    if (resposta) {
      vibrar(resposta.acertou ? VIBRACAO.acerto : VIBRACAO.erro)
      definirFase('resultado')
    }
  }

  const mostrarResposta = async () => {
    const resposta = await jogo.revelarResposta()
    if (resposta) definirFase('resultado')
  }

  const seguir = async () => {
    jogo.limparUltimaJogada()

    if (cartaAcabou) {
      const novo = await jogo.proximaCarta()
      if (!novo) return
    }

    definirFase('transicao')
  }

  const desfazer = async () => {
    vibrar(VIBRACAO.toque)
    const novo = await jogo.desfazer()

    if (novo) {
      jogadorVistoPorUltimo.current = novo.partida.jogador_vez_id
      definirPalpite('')
      definirFase('jogando')
    }
  }

  // ------------------------------------------------------------------
  // Tela de passar o celular (regra 28)
  // ------------------------------------------------------------------

  if (fase === 'transicao') {
    return (
      <>
        {!online && <FitaOffline />}
        <TelaTransicao
          jogador={jogadorDaVez}
          pontuacaoVitoria={partida.pontuacao_vitoria}
          numeroCarta={partida.numero_carta}
          aoTocar={() => definirFase('jogando')}
        />
      </>
    )
  }

  // ------------------------------------------------------------------
  // Resultado da jogada
  // ------------------------------------------------------------------

  if (fase === 'resultado' && jogo.ultimaJogada) {
    return (
      <>
        {!online && <FitaOffline />}
        <PainelResultado
          jogada={jogo.ultimaJogada}
          jogador={jogadorDaVez}
          cartaAcabou={cartaAcabou}
          todosForam={todosForam}
          ocupado={jogo.ocupado}
          erro={jogo.erro}
          aoSeguir={seguir}
          aoDesfazer={partida.pode_desfazer ? desfazer : null}
        />
      </>
    )
  }

  // ------------------------------------------------------------------
  // Partida
  // ------------------------------------------------------------------

  return (
    <div className="tela tela--fixa">
      {!online && <FitaOffline />}

      <Placar
        jogadores={jogadores}
        jogadorVezId={partida.jogador_vez_id}
        pontuacaoVitoria={partida.pontuacao_vitoria}
        eliminados={eliminados}
      />

      <div className="barra-carta">
        <EmblemaCategoria categoria={carta?.categoria} />
        <div className="barra-carta__valor">
          <strong>{valorDoAcerto}</strong>
          <span>pontos se acertar</span>
        </div>
      </div>

      <div className={`dica${dicaAtual ? ' dica--nova' : ''}`} key={dicaAtual?.numero ?? 'vazia'}>
        {dicaAtual ? (
          <>
            <div className="dica__cabecalho">
              <span className="dica__numero">{dicaAtual.numero}</span>
              <span className="dica__dificuldade">
                {dicaAtual.dificuldade === 'dificil' && 'difícil'}
                {dicaAtual.dificuldade === 'media' && 'média'}
                {dicaAtual.dificuldade === 'facil' && 'fácil'}
              </span>
            </div>
            <p className="dica__texto">{dicaAtual.texto}</p>
          </>
        ) : (
          <div className="dica__vazia">
            <strong>{jogadorDaVez?.avatar} {jogadorDaVez?.nome}</strong>
            Escolha um número de 1 a 20
          </div>
        )}
      </div>

      <GradeDicas
        total={TOTAL_DICAS}
        usados={numerosUsados}
        numeroAtual={partida.dica_da_vez}
        desabilitado={!precisaAbrirDica || jogo.ocupado || cartaAcabou}
        aoEscolher={abrirDica}
      />

      <MensagemErro erro={jogo.erro} aoFechar={jogo.limparErro} />

      <div className="acoes">
        {cartaAcabou ? (
          <Botao variante="principal" largo icone="🃏" onClick={seguir} disabled={jogo.ocupado}>
            Próxima carta
          </Botao>
        ) : precisaAbrirDica ? (
          <div className="aviso aviso--atencao" style={{ justifyContent: 'center' }}>
            <span aria-hidden="true">👆</span>
            <span>Toque em um número para abrir a dica</span>
          </div>
        ) : (
          <form className="forma-palpite" onSubmit={chutar}>
            <input
              ref={campoPalpite}
              className="entrada"
              type="text"
              inputMode="text"
              autoComplete="off"
              autoCorrect="off"
              autoCapitalize="words"
              spellCheck="false"
              maxLength={120}
              placeholder="Escreva sua resposta"
              value={palpite}
              onChange={(evento) => definirPalpite(evento.target.value)}
              aria-label="Sua resposta"
            />
            <Botao
              tipo="submit"
              variante="principal"
              disabled={jogo.ocupado || palpite.trim() === ''}
            >
              Chutar
            </Botao>
          </form>
        )}

        <div className="acoes__secundarias">
          <Botao
            variante="fantasma"
            pequeno
            onClick={desfazer}
            disabled={!partida.pode_desfazer || jogo.ocupado}
            title="Voltar a última ação"
          >
            ↩ Desfazer
          </Botao>

          {!cartaAcabou && !precisaAbrirDica && (
            <Botao
              variante="fantasma"
              pequeno
              onClick={passar}
              disabled={jogo.ocupado}
              title="Gasta a dica e passa o celular. Você continua na carta."
            >
              Passar a vez
            </Botao>
          )}

          {!cartaAcabou && (
            <Botao
              variante="fantasma"
              pequeno
              onClick={() => definirConfirmando('revelar')}
              disabled={jogo.ocupado}
            >
              👁 Ver resposta
            </Botao>
          )}

          <Botao
            variante="fantasma"
            pequeno
            onClick={() => definirConfirmando('sair')}
            disabled={jogo.ocupado}
          >
            ✕ Sair
          </Botao>
        </div>
      </div>

      {/* ---------------- Confirmações (regra 32) ---------------- */}

      <ModalConfirmacao
        aberto={confirmando === 'revelar'}
        titulo="Mostrar a resposta?"
        texto="Todo mundo vai ver qual era a carta e ninguém pontua nela. Depois entra uma carta nova."
        rotuloConfirmar="Mostrar"
        varianteConfirmar="perigo"
        aoConfirmar={() => {
          definirConfirmando(null)
          mostrarResposta()
        }}
        aoCancelar={() => definirConfirmando(null)}
      />

      <ModalConfirmacao
        aberto={confirmando === 'sair'}
        titulo="Sair da partida?"
        texto="A partida vai ser encerrada e o placar de agora vale como resultado final."
        rotuloConfirmar="Sair mesmo assim"
        varianteConfirmar="perigo"
        aoConfirmar={async () => {
          definirConfirmando(null)
          await jogo.encerrar()
          navegar(`/fim/${partidaId}`, { replace: true })
        }}
        aoCancelar={() => definirConfirmando(null)}
      />
    </div>
  )
}

// =====================================================================

function FitaOffline() {
  return (
    <div className="fita-offline" role="status">
      📵 Sem internet. As jogadas voltam assim que a conexão voltar.
    </div>
  )
}

function PainelResultado({
  jogada,
  jogador,
  cartaAcabou,
  todosForam,
  ocupado,
  erro,
  aoSeguir,
  aoDesfazer,
}) {
  const acertou = jogada.acertou === true
  const revelada = jogada.revelada === true

  // Regra 25: so o chute errado tira o jogador da carta. Passar a vez, nao.
  const eliminado = jogada.eliminado === true

  return (
    <div className="resultado">
      {revelada ? (
        <div className="resultado__selo resultado__selo--revelacao">A resposta era...</div>
      ) : (
        <div
          className={`resultado__selo resultado__selo--${
            acertou ? 'acerto' : eliminado ? 'erro' : 'neutro'
          }`}
        >
          {acertou ? 'Acertou! 🎉' : eliminado ? 'Errou 😅' : 'Passou a vez 🤐'}
        </div>
      )}

      {acertou && (
        <div className="resultado__pontos">
          +{jogada.pontos_ganhos} ponto{jogada.pontos_ganhos === 1 ? '' : 's'} para {jogador?.nome}
        </div>
      )}

      {jogada.resposta && (
        <div className="resultado__resposta">
          <div className="resultado__rotulo">Resposta</div>
          <div className="resultado__valor">{jogada.resposta}</div>

          {/* Regra 75: o link do artigo só aparece depois da revelação. */}
          {jogada.url_fonte && (
            <a
              className="resultado__link"
              href={jogada.url_fonte}
              target="_blank"
              rel="noreferrer noopener"
            >
              Ler na Wikipédia ↗
            </a>
          )}
        </div>
      )}

      {!acertou && !revelada && (
        <p className="suave">
          {cartaAcabou
            ? todosForam
              ? 'Todo mundo errou o chute nesta carta. Ninguém pontua: vem carta nova.'
              : 'Acabaram as 20 dicas. Ninguém pontua: vem carta nova.'
            : eliminado
              ? 'Você errou, então está fora desta carta. Passe o celular para o próximo.'
              : 'A carta continua. Passe o celular para o próximo.'}
        </p>
      )}

      <MensagemErro erro={erro} />

      <div className="pilha" style={{ width: '100%', maxWidth: 360 }}>
        <Botao variante="principal" largo onClick={aoSeguir} disabled={ocupado}>
          {cartaAcabou ? '🃏 Próxima carta' : '📱 Passar o celular'}
        </Botao>

        {aoDesfazer && (
          <Botao variante="fantasma" largo pequeno onClick={aoDesfazer} disabled={ocupado}>
            ↩ Desfazer, alguém tocou errado
          </Botao>
        )}
      </div>
    </div>
  )
}

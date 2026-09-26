import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../services/api'
import { Botao, Carregando, MensagemErro, ModalConfirmacao } from '../components/Basicos'

const CATEGORIAS = [
  { chave: 'pessoa', nome: 'Pessoa', cor: '#FF4D6D', icone: '🧑' },
  { chave: 'lugar', nome: 'Lugar', cor: '#20C997', icone: '🌍' },
  { chave: 'ano', nome: 'Ano', cor: '#FFB020', icone: '📅' },
  { chave: 'coisa', nome: 'Coisa', cor: '#7C5CFF', icone: '📦' },
]

const corDaCategoria = (chave) =>
  CATEGORIAS.find((categoria) => categoria.chave === chave)?.cor ?? '#4C2E8F'

/** Regras 58 e 59: painel protegido por sessão PHP. */
export function TelaAdmin() {
  const [logado, definirLogado] = useState(null)

  useEffect(() => {
    api.admin
      .sessao()
      .then((dados) => definirLogado(dados.logado))
      .catch(() => definirLogado(false))
  }, [])

  if (logado === null) {
    return (
      <div className="tela centralizado">
        <Carregando texto="Conferindo o acesso..." />
      </div>
    )
  }

  return logado ? (
    <Painel aoSair={() => definirLogado(false)} />
  ) : (
    <Login aoEntrar={() => definirLogado(true)} />
  )
}

// =====================================================================

function Login({ aoEntrar }) {
  const navegar = useNavigate()
  const [usuario, definirUsuario] = useState('')
  const [senha, definirSenha] = useState('')
  const [enviando, definirEnviando] = useState(false)
  const [erro, definirErro] = useState(null)

  const entrar = async (evento) => {
    evento.preventDefault()
    definirErro(null)
    definirEnviando(true)

    try {
      await api.admin.login(usuario.trim(), senha)
      aoEntrar()
    } catch (e) {
      definirErro(e)
      definirEnviando(false)
    }
  }

  return (
    <div className="tela centralizado">
      <form className="cartao pilha" style={{ width: '100%', maxWidth: 360 }} onSubmit={entrar}>
        <h1 className="titulo-tela">Painel de cartas</h1>
        <p className="subtitulo">Entre para gerar, revisar e aprovar as cartas.</p>

        <MensagemErro erro={erro} />

        <label className="campo">
          <span className="campo__rotulo">Usuário</span>
          <input
            className="entrada"
            type="text"
            autoComplete="username"
            value={usuario}
            onChange={(evento) => definirUsuario(evento.target.value)}
            required
          />
        </label>

        <label className="campo">
          <span className="campo__rotulo">Senha</span>
          <input
            className="entrada"
            type="password"
            autoComplete="current-password"
            value={senha}
            onChange={(evento) => definirSenha(evento.target.value)}
            required
          />
        </label>

        <Botao tipo="submit" variante="principal" largo disabled={enviando}>
          {enviando ? 'Entrando...' : 'Entrar'}
        </Botao>

        <Botao variante="fantasma" largo pequeno onClick={() => navegar('/')}>
          Voltar para o jogo
        </Botao>
      </form>
    </div>
  )
}

// =====================================================================

function Painel({ aoSair }) {
  const navegar = useNavigate()

  const [resumo, definirResumo] = useState(null)
  const [listagem, definirListagem] = useState(null)
  const [filtros, definirFiltros] = useState({ status: 'pendente', categoria: '', busca: '', pagina: 1 })
  const [carregando, definirCarregando] = useState(true)
  const [erro, definirErro] = useState(null)

  const [gerando, definirGerando] = useState(false)
  const [quantidade, definirQuantidade] = useState(10)
  const [categoriaGeracao, definirCategoriaGeracao] = useState('')
  const [registroGeracao, definirRegistroGeracao] = useState(null)

  const [cartaAberta, definirCartaAberta] = useState(null)
  const [confirmando, definirConfirmando] = useState(null)

  const carregar = useCallback(async () => {
    definirCarregando(true)
    definirErro(null)

    try {
      const [dadosResumo, dadosLista] = await Promise.all([
        api.admin.resumo(),
        api.admin.listarCartas(filtros),
      ])
      definirResumo(dadosResumo)
      definirListagem(dadosLista)
    } catch (e) {
      definirErro(e)
      if (e.precisaLogin) aoSair()
    } finally {
      definirCarregando(false)
    }
  }, [filtros, aoSair])

  useEffect(() => {
    carregar()
  }, [carregar])

  const gerar = async () => {
    definirGerando(true)
    definirErro(null)
    definirRegistroGeracao(null)

    try {
      const dados = await api.admin.gerarCartas(quantidade, categoriaGeracao || null)
      definirRegistroGeracao(dados)
      await carregar()
    } catch (e) {
      definirErro(e)
    } finally {
      definirGerando(false)
    }
  }

  const abrirCarta = async (id) => {
    try {
      definirCartaAberta(await api.admin.carta(id))
    } catch (e) {
      definirErro(e)
    }
  }

  const agirNaCarta = async (acao, id) => {
    try {
      await acao(id)
      if (cartaAberta?.id === id) {
        if (acao === api.admin.excluirCarta) {
          definirCartaAberta(null)
        } else {
          definirCartaAberta(await api.admin.carta(id))
        }
      }
      await carregar()
    } catch (e) {
      definirErro(e)
    }
  }

  if (carregando && !listagem) {
    return (
      <div className="tela centralizado">
        <Carregando texto="Abrindo o painel..." />
      </div>
    )
  }

  return (
    <div className="tela tela--rolavel tela--admin">
      <div className="tela__corpo admin">
        <div className="admin__topo">
          <h1>🛠️ Painel de cartas</h1>
          <Botao pequeno variante="fantasma" onClick={() => navegar('/')}>
            Ir para o jogo
          </Botao>
          <Botao
            pequeno
            variante="fantasma"
            onClick={async () => {
              await api.admin.logout().catch(() => {})
              aoSair()
            }}
          >
            Sair
          </Botao>
        </div>

        <MensagemErro erro={erro} aoFechar={() => definirErro(null)} />

        {/* ---------------- Resumo ---------------- */}
        {resumo && (
          <div className="admin__cartoes">
            <div className="cartao cartao--compacto">
              <div className="admin__numero" style={{ color: 'var(--sucesso)' }}>
                {resumo.cartas.aprovadas}
              </div>
              <div className="admin__rotulo">Aprovadas</div>
            </div>
            <div className="cartao cartao--compacto">
              <div className="admin__numero" style={{ color: 'var(--destaque)' }}>
                {resumo.cartas.pendentes}
              </div>
              <div className="admin__rotulo">Pendentes</div>
            </div>
            <div className="cartao cartao--compacto">
              <div className="admin__numero" style={{ color: 'var(--perigo)' }}>
                {resumo.cartas.rejeitadas}
              </div>
              <div className="admin__rotulo">Rejeitadas</div>
            </div>
            <div className="cartao cartao--compacto">
              <div className="admin__numero">{resumo.cartas.total}</div>
              <div className="admin__rotulo">No total</div>
            </div>
          </div>
        )}

        {resumo && (
          <div className="admin__cartoes">
            {resumo.categorias.map((categoria) => (
              <div
                key={categoria.chave}
                className="cartao cartao--compacto"
                style={{ borderLeft: `7px solid ${categoria.cor}` }}
              >
                <div className="admin__numero" style={{ fontSize: '1.4rem' }}>
                  {categoria.icone} {categoria.aprovadas}
                </div>
                <div className="admin__rotulo">
                  {categoria.nome} · {categoria.pendentes} pendente(s)
                </div>
              </div>
            ))}
          </div>
        )}

        {/* ---------------- Gerar (regra 45) ---------------- */}
        <div className="cartao pilha pilha--apertada">
          <h2 style={{ fontSize: '1.15rem' }}>Gerar cartas novas</h2>
          <p className="pequeno suave">
            Busca temas no Wikidata e monta as dicas com os modelos de frase. Pode levar alguns
            minutos: existe uma pausa entre as consultas para respeitar o limite de uso.
          </p>

          <div className="admin__filtros" style={{ margin: 0 }}>
            <select
              className="entrada"
              value={quantidade}
              onChange={(evento) => definirQuantidade(Number(evento.target.value))}
              aria-label="Quantidade de cartas"
            >
              {[4, 10, 20, 30].map((valor) => (
                <option key={valor} value={valor}>
                  {valor} cartas
                </option>
              ))}
            </select>

            <select
              className="entrada"
              value={categoriaGeracao}
              onChange={(evento) => definirCategoriaGeracao(evento.target.value)}
              aria-label="Categoria"
            >
              <option value="">Todas as categorias</option>
              {CATEGORIAS.map((categoria) => (
                <option key={categoria.chave} value={categoria.chave}>
                  {categoria.icone} {categoria.nome}
                </option>
              ))}
            </select>

            <Botao variante="principal" onClick={gerar} disabled={gerando}>
              {gerando ? 'Gerando...' : `Gerar ${quantidade} cartas`}
            </Botao>
          </div>

          {gerando && <Carregando texto="Conversando com o Wikidata..." />}

          {registroGeracao && (
            <>
              <div
                className={`aviso ${registroGeracao.geradas > 0 ? 'aviso--ok' : 'aviso--atencao'}`}
              >
                <span aria-hidden="true">{registroGeracao.geradas > 0 ? '✅' : '🤔'}</span>
                <span>
                  {registroGeracao.geradas} de {registroGeracao.pedidas} carta(s) criada(s).
                </span>
              </div>
              {registroGeracao.registro?.length > 0 && (
                <div className="registro-geracao">{registroGeracao.registro.join('\n')}</div>
              )}
            </>
          )}
        </div>

        {/* ---------------- Filtros ---------------- */}
        <div className="admin__filtros">
          <select
            className="entrada"
            value={filtros.status}
            onChange={(evento) =>
              definirFiltros({ ...filtros, status: evento.target.value, pagina: 1 })
            }
            aria-label="Filtrar por situação"
          >
            <option value="">Todas as situações</option>
            <option value="pendente">Pendentes</option>
            <option value="aprovada">Aprovadas</option>
            <option value="rejeitada">Rejeitadas</option>
          </select>

          <select
            className="entrada"
            value={filtros.categoria}
            onChange={(evento) =>
              definirFiltros({ ...filtros, categoria: evento.target.value, pagina: 1 })
            }
            aria-label="Filtrar por categoria"
          >
            <option value="">Todas as categorias</option>
            {CATEGORIAS.map((categoria) => (
              <option key={categoria.chave} value={categoria.chave}>
                {categoria.icone} {categoria.nome}
              </option>
            ))}
          </select>

          <input
            className="entrada"
            type="search"
            placeholder="Buscar pela resposta"
            value={filtros.busca}
            onChange={(evento) =>
              definirFiltros({ ...filtros, busca: evento.target.value, pagina: 1 })
            }
            aria-label="Buscar"
          />
        </div>

        {/* ---------------- Lista ---------------- */}
        {listagem && listagem.cartas.length === 0 ? (
          <div className="aviso aviso--atencao">
            <span aria-hidden="true">📭</span>
            <span>Nenhuma carta com esses filtros.</span>
          </div>
        ) : (
          <div className="lista-cartas">
            {listagem?.cartas.map((carta) => (
              <div
                key={carta.id}
                className="carta-item"
                style={{ '--cor-categoria': corDaCategoria(carta.categoria.chave) }}
              >
                <div className="carta-item__topo">
                  <span aria-hidden="true">{carta.categoria.icone}</span>
                  <span className="carta-item__resposta">{carta.resposta}</span>
                  <span className={`selo selo--${carta.status}`}>{carta.status}</span>
                </div>

                <div className="pequeno apagado">
                  {carta.total_dicas} dicas · jogada {carta.vezes_jogada}x
                  {carta.qid ? ` · ${carta.qid}` : ''}
                </div>

                <div className="carta-item__acoes">
                  <Botao pequeno variante="fantasma" onClick={() => abrirCarta(carta.id)}>
                    Revisar
                  </Botao>

                  {carta.status !== 'aprovada' && (
                    <Botao
                      pequeno
                      variante="sucesso"
                      onClick={() => agirNaCarta(api.admin.aprovarCarta, carta.id)}
                    >
                      Aprovar
                    </Botao>
                  )}

                  {carta.status !== 'rejeitada' && (
                    <Botao
                      pequeno
                      variante="fantasma"
                      onClick={() => agirNaCarta(api.admin.rejeitarCarta, carta.id)}
                    >
                      Rejeitar
                    </Botao>
                  )}

                  <Botao
                    pequeno
                    variante="perigo"
                    onClick={() => definirConfirmando({ tipo: 'excluir-carta', id: carta.id })}
                  >
                    Excluir
                  </Botao>
                </div>
              </div>
            ))}
          </div>
        )}

        {listagem && listagem.paginacao.paginas > 1 && (
          <div className="linha-botoes">
            <Botao
              variante="fantasma"
              disabled={filtros.pagina <= 1}
              onClick={() => definirFiltros({ ...filtros, pagina: filtros.pagina - 1 })}
            >
              ← Anterior
            </Botao>
            <span className="centro suave" style={{ alignSelf: 'center' }}>
              {listagem.paginacao.pagina} de {listagem.paginacao.paginas}
            </span>
            <Botao
              variante="fantasma"
              disabled={filtros.pagina >= listagem.paginacao.paginas}
              onClick={() => definirFiltros({ ...filtros, pagina: filtros.pagina + 1 })}
            >
              Próxima →
            </Botao>
          </div>
        )}
      </div>

      {cartaAberta && (
        <EditorCarta
          carta={cartaAberta}
          aoFechar={() => definirCartaAberta(null)}
          aoAtualizar={async () => {
            definirCartaAberta(await api.admin.carta(cartaAberta.id))
            await carregar()
          }}
          aoErro={definirErro}
        />
      )}

      <ModalConfirmacao
        aberto={confirmando?.tipo === 'excluir-carta'}
        titulo="Excluir a carta?"
        texto="A carta e todas as dicas dela somem para sempre."
        rotuloConfirmar="Excluir"
        aoConfirmar={() => {
          agirNaCarta(api.admin.excluirCarta, confirmando.id)
          definirConfirmando(null)
        }}
        aoCancelar={() => definirConfirmando(null)}
      />
    </div>
  )
}

// =====================================================================

/** Regra 59: revisar e editar as dicas antes de aprovar. */
function EditorCarta({ carta, aoFechar, aoAtualizar, aoErro }) {
  const [resposta, definirResposta] = useState(carta.resposta)
  const [novaAlternativa, definirNovaAlternativa] = useState('')
  const [salvando, definirSalvando] = useState(false)

  const salvarCarta = async () => {
    definirSalvando(true)
    try {
      await api.admin.atualizarCarta(carta.id, { resposta })
      await aoAtualizar()
    } catch (e) {
      aoErro(e)
    } finally {
      definirSalvando(false)
    }
  }

  const salvarDica = async (dica, texto, dificuldade) => {
    try {
      await api.admin.atualizarDica(dica.id, { texto, dificuldade })
      await aoAtualizar()
    } catch (e) {
      aoErro(e)
    }
  }

  return (
    <div className="modal-fundo" role="dialog" aria-modal="true" aria-label={`Revisar ${carta.resposta}`}>
      <div className="modal" style={{ maxWidth: 640, maxHeight: '86dvh', overflowY: 'auto' }}>
        <div className="admin__topo">
          <h2 className="modal__titulo crescer">
            {carta.categoria.icone} {carta.resposta}
          </h2>
          <Botao pequeno variante="fantasma" onClick={aoFechar}>
            ✕
          </Botao>
        </div>

        <label className="campo" style={{ marginBottom: 12 }}>
          <span className="campo__rotulo">Resposta</span>
          <div className="forma-palpite">
            <input
              className="entrada"
              value={resposta}
              onChange={(evento) => definirResposta(evento.target.value)}
              maxLength={180}
            />
            <Botao
              variante="principal"
              pequeno
              onClick={salvarCarta}
              disabled={salvando || resposta.trim() === '' || resposta === carta.resposta}
            >
              Salvar
            </Botao>
          </div>
        </label>

        {carta.url_fonte && (
          <p className="pequeno" style={{ marginBottom: 12 }}>
            <a href={carta.url_fonte} target="_blank" rel="noreferrer noopener">
              Ver o artigo na Wikipédia ↗
            </a>
          </p>
        )}

        {/* Respostas alternativas (regra 42) */}
        <div className="campo__rotulo">Respostas também aceitas</div>
        <div className="opcoes" style={{ margin: '8px 0 12px' }}>
          {carta.respostas_alternativas.length === 0 && (
            <span className="pequeno apagado">Nenhuma ainda.</span>
          )}
          {carta.respostas_alternativas.map((alternativa) => (
            <span key={alternativa.id} className="alternativa">
              <span className="alternativa__texto">{alternativa.texto}</span>
              <button
                type="button"
                className="alternativa__tirar"
                onClick={async () => {
                  try {
                    await api.admin.excluirAlternativa(alternativa.id)
                    await aoAtualizar()
                  } catch (e) {
                    aoErro(e)
                  }
                }}
                aria-label={`Tirar ${alternativa.texto}`}
              >
                ✕
              </button>
            </span>
          ))}
        </div>

        <div className="forma-palpite" style={{ marginBottom: 16 }}>
          <input
            className="entrada"
            placeholder="Acrescentar outra resposta aceita"
            value={novaAlternativa}
            onChange={(evento) => definirNovaAlternativa(evento.target.value)}
          />
          <Botao
            pequeno
            variante="fantasma"
            disabled={novaAlternativa.trim() === ''}
            onClick={async () => {
              try {
                await api.admin.criarAlternativa(carta.id, novaAlternativa.trim())
                definirNovaAlternativa('')
                await aoAtualizar()
              } catch (e) {
                aoErro(e)
              }
            }}
          >
            Somar
          </Botao>
        </div>

        {/* Dicas */}
        <div className="campo__rotulo">
          Dicas ({carta.total_dicas} de 20)
        </div>

        <div className="lista-dicas">
          {carta.dicas.map((dica) => (
            <LinhaDica
              key={dica.id}
              dica={dica}
              aoSalvar={salvarDica}
              aoExcluir={async () => {
                try {
                  await api.admin.excluirDica(dica.id)
                  await aoAtualizar()
                } catch (e) {
                  aoErro(e)
                }
              }}
            />
          ))}
        </div>

        <div className="linha-botoes" style={{ marginTop: 16 }}>
          <Botao variante="fantasma" onClick={aoFechar}>
            Fechar
          </Botao>
          {carta.status !== 'aprovada' && (
            <Botao
              variante="sucesso"
              onClick={async () => {
                try {
                  await api.admin.aprovarCarta(carta.id)
                  await aoAtualizar()
                } catch (e) {
                  aoErro(e)
                }
              }}
            >
              Aprovar carta
            </Botao>
          )}
        </div>
      </div>
    </div>
  )
}

function LinhaDica({ dica, aoSalvar, aoExcluir }) {
  const [texto, definirTexto] = useState(dica.texto)
  const [dificuldade, definirDificuldade] = useState(dica.dificuldade)

  const mudou = texto !== dica.texto || dificuldade !== dica.dificuldade

  return (
    <div className="dica-item">
      <span className="dica-item__numero">{dica.numero}</span>

      <div className="dica-item__corpo">
        <input
          className="entrada"
          value={texto}
          onChange={(evento) => definirTexto(evento.target.value)}
          maxLength={255}
          aria-label={`Texto da dica ${dica.numero}`}
        />

        <div className="dica-item__linha">
          <select
            className="entrada"
            style={{ flex: '0 0 130px' }}
            value={dificuldade}
            onChange={(evento) => definirDificuldade(evento.target.value)}
            aria-label={`Dificuldade da dica ${dica.numero}`}
          >
            <option value="dificil">Difícil</option>
            <option value="media">Média</option>
            <option value="facil">Fácil</option>
          </select>

          <span className="pequeno apagado crescer">{dica.propriedade_origem}</span>

          {mudou && (
            <Botao
              pequeno
              variante="principal"
              onClick={() => aoSalvar(dica, texto.trim(), dificuldade)}
            >
              Salvar
            </Botao>
          )}

          <Botao pequeno variante="fantasma" onClick={aoExcluir}>
            Excluir
          </Botao>
        </div>
      </div>
    </div>
  )
}

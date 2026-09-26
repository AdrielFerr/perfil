import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api, memoriaLocal } from '../services/api'
import { Botao, Carregando, MensagemErro } from '../components/Basicos'

/**
 * Porta de entrada. No celular e uma capa de fichario empilhada; no
 * desktop vira capa de verdade, com o baralho aberto ao lado e os tres
 * passos logo abaixo, que e como jogo de festa se apresenta hoje.
 */

const PASSOS = [
  {
    numero: '01',
    titulo: 'Cadastre a mesa',
    texto: 'De 2 a 6 jogadores ou equipes. Cada um escolhe cor e avatar.',
  },
  {
    numero: '02',
    titulo: 'Abra uma dica',
    texto: 'Na sua vez, escolha um número de 1 a 20 e leia a dica em voz alta.',
  },
  {
    numero: '03',
    titulo: 'Chute ou passe',
    texto: 'Acertou, ganha um ponto por dica fechada. Errou, está fora da carta.',
  },
]

export function TelaInicio() {
  const navegar = useNavigate()

  const [categorias, definirCategorias] = useState([])
  const [acervo, definirAcervo] = useState(null)
  const [carregando, definirCarregando] = useState(true)
  const [erro, definirErro] = useState(null)
  const [partidaSalva, definirPartidaSalva] = useState(null)

  const carregar = async () => {
    definirCarregando(true)
    definirErro(null)

    try {
      const [dadosCategorias, dadosAcervo] = await Promise.all([
        api.categorias(),
        api.acervo(),
      ])

      definirCategorias(dadosCategorias.categorias)
      definirAcervo(dadosAcervo)

      // Regra 56: dá para voltar para a partida que ficou aberta.
      const id = memoriaLocal.lerPartida()
      if (id) {
        try {
          const estado = await api.estadoPartida(id)
          if (estado.partida.status === 'em_andamento') {
            definirPartidaSalva(estado)
          } else {
            memoriaLocal.esquecerPartida()
          }
        } catch {
          memoriaLocal.esquecerPartida()
        }
      }
    } catch (e) {
      definirErro(e)
    } finally {
      definirCarregando(false)
    }
  }

  useEffect(() => {
    carregar()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (carregando) {
    return (
      <div className="tela centralizado">
        <Carregando texto="Preparando o baralho..." />
      </div>
    )
  }

  const pronto = acervo?.pronto_para_jogar

  return (
    <div className="capa">
      <div className="capa__folha">
        {/* ---------------- Hero ---------------- */}
        <header className="capa__hero">
          <div className="capa__texto">
            <p className="capa__etiqueta">Jogo de mesa · Fichário nº 1</p>

            <h1 className="marca__nome">PERFIL</h1>

            <p className="capa__chamada">
              20 dicas, uma resposta secreta e um celular só, passando de mão em mão.
            </p>

            <div className="marca__fichas">
              {categorias.map((categoria) => (
                <span
                  key={categoria.chave}
                  className="ficha-categoria"
                  style={{ color: categoria.cor }}
                >
                  <span aria-hidden="true">{categoria.icone}</span>
                  {categoria.nome}
                </span>
              ))}
            </div>

            <MensagemErro erro={erro} aoTentarDeNovo={carregar} />

            {acervo && !pronto && (
              <div className="aviso aviso--atencao">
                <span aria-hidden="true">📭</span>
                <div>
                  Ainda não existe nenhuma carta aprovada. Entre no{' '}
                  <Link to="/admin">painel</Link> e gere as primeiras cartas.
                </div>
              </div>
            )}

            <div className="capa__acoes">
              {partidaSalva && (
                <Botao
                  variante="sucesso"
                  largo
                  icone="▶"
                  onClick={() => navegar(`/partida/${partidaSalva.partida.id}`)}
                >
                  Continuar a partida
                </Botao>
              )}

              <Botao
                variante="principal"
                largo
                icone="🎲"
                disabled={!pronto}
                onClick={() => navegar('/novo-jogo')}
              >
                {partidaSalva ? 'Começar outra partida' : 'Começar a jogar'}
              </Botao>

              <Botao variante="fantasma" largo icone="🛠️" onClick={() => navegar('/admin')}>
                Painel de cartas
              </Botao>
            </div>

            {pronto && (
              <p className="capa__acervo">
                <strong>{acervo.total_aprovadas}</strong> carta
                {acervo.total_aprovadas === 1 ? '' : 's'} no baralho ·{' '}
                {acervo.total_aprovadas * 20} dicas conferidas
              </p>
            )}
          </div>

          {/* A carta de mostruário: mesma linguagem da tela de jogo. */}
          <div className="capa__mostruario" aria-hidden="true">
            <div className="mostruario__carta mostruario__carta--fundo" />
            <div className="mostruario__carta mostruario__carta--meio" />

            <div className="mostruario__carta mostruario__carta--frente">
              <div className="mostruario__topo">
                <span className="ficha-categoria" style={{ color: 'var(--cat-lugar)' }}>
                  🌍 Lugar
                </span>
                <span className="mostruario__valor">
                  <strong>19</strong> pts
                </span>
              </div>

              <div className="mostruario__dica">
                <span className="dica__numero">7</span>
                <p>Fico neste país: Japão. Quem me vê de longe, vê neve no meu topo.</p>
              </div>

              <div className="mostruario__grade">
                {Array.from({ length: 20 }, (_, i) => (
                  <span
                    key={i}
                    className={
                      'mostruario__numero' +
                      (i === 6 ? ' mostruario__numero--atual' : '') +
                      ([1, 3, 9].includes(i) ? ' mostruario__numero--usado' : '')
                    }
                  >
                    {i + 1}
                  </span>
                ))}
              </div>
            </div>
          </div>
        </header>

        {/* ---------------- Como se joga ---------------- */}
        <section className="passos" aria-label="Como se joga">
          <h2 className="passos__titulo">Como se joga</h2>

          <ol className="passos__lista">
            {PASSOS.map((passo) => (
              <li key={passo.numero} className="passo">
                <span className="passo__numero">{passo.numero}</span>
                <h3 className="passo__titulo">{passo.titulo}</h3>
                <p className="passo__texto">{passo.texto}</p>
              </li>
            ))}
          </ol>

          <p className="passos__nota">
            Vence quem chegar primeiro na pontuação combinada. Nenhuma carta se repete na
            mesma partida, e as respostas saem do Wikidata e da Wikipédia, conferidas uma a uma.
          </p>
        </section>
      </div>
    </div>
  )
}

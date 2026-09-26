/**
 * Todas as chamadas a API passam por aqui (regra 18).
 * Nenhum componente usa fetch direto.
 */

/**
 * Caminho publico da API (vem do .env, veja .env.exemplo).
 * No XAMPP em subpasta: /perfil/api/public
 * Com "Alias /api" no Apache: /api
 */
const BASE = (import.meta.env.VITE_API_BASE || '/api').replace(/\/+$/, '')

/** Erro com mensagem pronta para mostrar na tela. */
export class ErroApi extends Error {
  constructor(mensagem, status = 0, detalhes = null) {
    super(mensagem)
    this.name = 'ErroApi'
    this.status = status
    this.detalhes = detalhes
  }

  get semInternet() {
    return this.status === 0
  }

  get semCartas() {
    return this.status === 503
  }

  get precisaLogin() {
    return this.status === 401
  }
}

async function pedir(caminho, { metodo = 'GET', corpo = null, sinal = null } = {}) {
  const opcoes = {
    method: metodo,
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
    signal: sinal,
  }

  if (corpo !== null) {
    opcoes.headers['Content-Type'] = 'application/json'
    opcoes.body = JSON.stringify(corpo)
  }

  let resposta
  try {
    resposta = await fetch(BASE + caminho, opcoes)
  } catch (erro) {
    if (erro.name === 'AbortError') throw erro
    throw new ErroApi(
      'Não consegui falar com o servidor. Confira se o Apache está ligado e se o celular está na mesma rede.',
      0
    )
  }

  let dados = null
  try {
    dados = await resposta.json()
  } catch {
    throw new ErroApi('O servidor respondeu em um formato que não entendi.', resposta.status)
  }

  if (!resposta.ok || dados?.sucesso === false) {
    throw new ErroApi(
      dados?.erro?.mensagem || 'Algo deu errado.',
      resposta.status,
      dados?.erro?.detalhes || null
    )
  }

  return dados.dados
}

// ---------------------------------------------------------------------
// Público
// ---------------------------------------------------------------------

export const api = {
  saude: () => pedir('/saude'),
  categorias: () => pedir('/categorias'),
  acervo: () => pedir('/acervo'),

  // -------------------------------------------------------------------
  // Partida
  // -------------------------------------------------------------------

  criarPartida: ({ jogadores, pontuacaoVitoria, categorias = [] }) =>
    pedir('/partidas', {
      metodo: 'POST',
      corpo: {
        jogadores,
        pontuacao_vitoria: pontuacaoVitoria,
        categorias,
      },
    }),

  estadoPartida: (id, sinal) => pedir(`/partidas/${id}`, { sinal }),

  partidaPorCodigo: (codigo) => pedir(`/partidas/codigo/${codigo}`),

  revelarDica: (id, numero) => pedir(`/partidas/${id}/dicas/${numero}`, { metodo: 'POST' }),

  palpitar: (id, palpite) =>
    pedir(`/partidas/${id}/palpite`, { metodo: 'POST', corpo: { palpite } }),

  passarVez: (id) => pedir(`/partidas/${id}/passar`, { metodo: 'POST' }),

  revelarResposta: (id) => pedir(`/partidas/${id}/revelar`, { metodo: 'POST' }),

  proximaCarta: (id) => pedir(`/partidas/${id}/proxima-carta`, { metodo: 'POST' }),

  desfazer: (id) => pedir(`/partidas/${id}/desfazer`, { metodo: 'POST' }),

  encerrarPartida: (id) => pedir(`/partidas/${id}/encerrar`, { metodo: 'POST' }),

  jogadores: (id) => pedir(`/partidas/${id}/jogadores`),

  atualizarJogador: (partidaId, jogadorId, dados) =>
    pedir(`/partidas/${partidaId}/jogadores/${jogadorId}`, { metodo: 'PUT', corpo: dados }),

  // -------------------------------------------------------------------
  // Painel administrativo
  // -------------------------------------------------------------------

  admin: {
    login: (usuario, senha) =>
      pedir('/admin/login', { metodo: 'POST', corpo: { usuario, senha } }),

    logout: () => pedir('/admin/logout', { metodo: 'POST' }),

    sessao: () => pedir('/admin/sessao'),

    resumo: () => pedir('/admin/resumo'),

    listarCartas: (filtros = {}) => {
      const parametros = new URLSearchParams()
      Object.entries(filtros).forEach(([chave, valor]) => {
        if (valor !== '' && valor !== null && valor !== undefined) {
          parametros.set(chave, valor)
        }
      })
      const consulta = parametros.toString()
      return pedir('/admin/cartas' + (consulta ? `?${consulta}` : ''))
    },

    carta: (id) => pedir(`/admin/cartas/${id}`),

    atualizarCarta: (id, dados) => pedir(`/admin/cartas/${id}`, { metodo: 'PUT', corpo: dados }),

    aprovarCarta: (id) => pedir(`/admin/cartas/${id}/aprovar`, { metodo: 'POST' }),

    rejeitarCarta: (id) => pedir(`/admin/cartas/${id}/rejeitar`, { metodo: 'POST' }),

    excluirCarta: (id) => pedir(`/admin/cartas/${id}`, { metodo: 'DELETE' }),

    atualizarDica: (id, dados) => pedir(`/admin/dicas/${id}`, { metodo: 'PUT', corpo: dados }),

    excluirDica: (id) => pedir(`/admin/dicas/${id}`, { metodo: 'DELETE' }),

    criarAlternativa: (cartaId, texto) =>
      pedir('/admin/alternativas', { metodo: 'POST', corpo: { carta_id: cartaId, texto } }),

    excluirAlternativa: (id) => pedir(`/admin/alternativas/${id}`, { metodo: 'DELETE' }),

    gerarCartas: (quantidade, categoria = null) =>
      pedir('/admin/gerar', { metodo: 'POST', corpo: { quantidade, categoria } }),
  },
}

// ---------------------------------------------------------------------
// Partida guardada no aparelho, para "Continuar" depois de recarregar
// ---------------------------------------------------------------------

const CHAVE_PARTIDA = 'perfil:partida-atual'

export const memoriaLocal = {
  guardarPartida(id) {
    try {
      localStorage.setItem(CHAVE_PARTIDA, String(id))
    } catch {
      /* navegador anônimo bloqueia: seguimos sem guardar */
    }
  },

  lerPartida() {
    try {
      const valor = localStorage.getItem(CHAVE_PARTIDA)
      return valor ? Number(valor) : null
    } catch {
      return null
    }
  },

  esquecerPartida() {
    try {
      localStorage.removeItem(CHAVE_PARTIDA)
    } catch {
      /* nada a fazer */
    }
  },
}

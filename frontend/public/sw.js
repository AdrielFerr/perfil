/**
 * Service worker do Perfil (regra 67).
 *
 * Estratégia:
 *   - /api  -> sempre pela rede. Partida é estado vivo, cache atrapalharia.
 *   - resto -> cache primeiro, rede como reserva (abre rápido e offline).
 *
 * Troque a versão abaixo sempre que publicar um build novo.
 */

// A raiz sai do proprio endereco do sw.js: '/' na raiz, '/perfil/' na subpasta.
const RAIZ = new URL('./', self.location).pathname

const VERSAO = 'perfil-v2'
const CASCA = 'casca-' + VERSAO

const ARQUIVOS_BASE = [
  RAIZ,
  RAIZ + 'index.html',
  RAIZ + 'manifest.webmanifest',
  RAIZ + 'icone.svg',
  RAIZ + 'icone-192.png',
  RAIZ + 'icone-512.png',
]

self.addEventListener('install', (evento) => {
  evento.waitUntil(
    caches
      .open(CASCA)
      .then((cache) => cache.addAll(ARQUIVOS_BASE))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting())
  )
})

self.addEventListener('activate', (evento) => {
  evento.waitUntil(
    caches
      .keys()
      .then((nomes) =>
        Promise.all(nomes.filter((nome) => nome !== CASCA).map((nome) => caches.delete(nome)))
      )
      .then(() => self.clients.claim())
  )
})

self.addEventListener('fetch', (evento) => {
  const requisicao = evento.request

  if (requisicao.method !== 'GET') return

  const url = new URL(requisicao.url)

  // Só cuidamos do nosso próprio domínio.
  if (url.origin !== self.location.origin) return

  // A API nunca entra em cache.
  if (url.pathname.startsWith(RAIZ + 'api')) {
    evento.respondWith(fetch(requisicao))
    return
  }

  // Navegação: tenta a rede e cai para o index.html guardado.
  if (requisicao.mode === 'navigate') {
    evento.respondWith(
      fetch(requisicao)
        .then((resposta) => {
          const copia = resposta.clone()
          caches.open(CASCA).then((cache) => cache.put(RAIZ + 'index.html', copia)).catch(() => {})
          return resposta
        })
        .catch(() => caches.match(RAIZ + 'index.html'))
    )
    return
  }

  // Arquivos estáticos: cache primeiro.
  evento.respondWith(
    caches.match(requisicao).then((guardado) => {
      if (guardado) return guardado

      return fetch(requisicao)
        .then((resposta) => {
          if (resposta && resposta.status === 200 && resposta.type === 'basic') {
            const copia = resposta.clone()
            caches.open(CASCA).then((cache) => cache.put(requisicao, copia)).catch(() => {})
          }
          return resposta
        })
        .catch(() => guardado)
    })
  )
})

import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

/**
 * Durante o desenvolvimento, tudo que comeca com o caminho da API e
 * repassado para o Apache. Como o navegador so fala com o Vite, nao
 * existe problema de CORS.
 *
 * O Vite sobe com --host (veja o script "dev" no package.json), entao da
 * para abrir no celular pelo IP do computador, ex.: http://192.168.0.10:5173
 *
 * Ajuste tudo pelo arquivo .env (veja .env.exemplo):
 *   VITE_API_ALVO=http://localhost:8080   Apache fora da porta 80
 *   VITE_BASE=/perfil/                    subpasta servida pelo Apache
 *   VITE_API_BASE=/perfil/api/public      caminho publico da API
 */
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const alvoApi = env.VITE_API_ALVO || 'http://localhost'

  // Sempre com barra no inicio e no fim: o Vite exige esse formato.
  //
  // O caso da raiz precisa de tratamento proprio. Com VITE_BASE=/ a
  // limpeza devolve string vazia e a conta virava '/' + '' + '/' = '//'.
  // O Vite entao escrevia src="//assets/index.js" no HTML, que o
  // navegador le como protocolo relativo: ele tenta resolver um host
  // chamado "assets" e a pagina abre em branco com ERR_NAME_NOT_RESOLVED.
  const caminhoBase = (env.VITE_BASE || '/').replace(/^\/+|\/+$/g, '')
  const base = caminhoBase === '' ? '/' : '/' + caminhoBase + '/'

  const caminhoApi = (env.VITE_API_BASE || '/api').replace(/^\/+|\/+$/g, '')
  const baseApi = caminhoApi === '' ? '/api' : '/' + caminhoApi

  const proxy = {
    [baseApi]: {
      target: alvoApi,
      changeOrigin: true,
      secure: false,
    },
  }

  return {
    base,

    plugins: [react()],

    server: {
      host: true,
      port: 5173,
      strictPort: false,
      proxy,
    },

    preview: {
      host: true,
      port: 4173,
      proxy,
    },

    build: {
      outDir: 'dist',
      emptyOutDir: true,
      // Alvo compativel com Safari do iPhone um pouco mais antigo.
      target: ['es2020', 'safari14'],
      sourcemap: false,
      chunkSizeWarningLimit: 700,
    },
  }
})

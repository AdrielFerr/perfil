import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App'
import './styles/global.css'

/** Subpasta em que o jogo esta publicado (vem do "base" do Vite). */
const RAIZ = import.meta.env.BASE_URL || '/'

createRoot(document.getElementById('raiz')).render(
  <StrictMode>
    <BrowserRouter basename={RAIZ}>
      <App />
    </BrowserRouter>
  </StrictMode>
)

/**
 * Regra 67: PWA. O service worker só entra no ar depois do build,
 * para não atrapalhar o recarregamento do Vite durante o desenvolvimento.
 */
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register(RAIZ + 'sw.js').catch(() => {
      /* sem service worker o jogo funciona igual, só não instala */
    })
  })
}

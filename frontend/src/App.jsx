import { Navigate, Route, Routes } from 'react-router-dom'
import { TelaInicio } from './pages/TelaInicio'
import { TelaCadastro } from './pages/TelaCadastro'
import { TelaPartida } from './pages/TelaPartida'
import { TelaFim } from './pages/TelaFim'
import { TelaAdmin } from './pages/TelaAdmin'

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<TelaInicio />} />
      <Route path="/novo-jogo" element={<TelaCadastro />} />
      <Route path="/partida/:id" element={<TelaPartida />} />
      <Route path="/fim/:id" element={<TelaFim />} />
      <Route path="/admin" element={<TelaAdmin />} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}

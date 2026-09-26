import { forwardRef, useEffect, useRef } from 'react'

/** Botão com área de toque garantida (regra 62). */
export const Botao = forwardRef(function Botao(
  {
    children,
    variante = 'neutro',
    largo = false,
    pequeno = false,
    icone = null,
    className = '',
    tipo = 'button',
    ...resto
  },
  referencia
) {
  const classes = [
    'botao',
    variante === 'principal' && 'botao--principal',
    variante === 'sucesso' && 'botao--sucesso',
    variante === 'perigo' && 'botao--perigo',
    variante === 'fantasma' && 'botao--fantasma',
    largo && 'botao--largo',
    pequeno && 'botao--pequeno',
    className,
  ]
    .filter(Boolean)
    .join(' ')

  return (
    <button ref={referencia} type={tipo} className={classes} {...resto}>
      {icone && (
        <span className="botao__icone" aria-hidden="true">
          {icone}
        </span>
      )}
      {children}
    </button>
  )
})

export function Carregando({ texto = 'Carregando...' }) {
  return (
    <div className="carregando" role="status" aria-live="polite">
      <div className="carregando__roda" aria-hidden="true" />
      <span>{texto}</span>
    </div>
  )
}

/** Mensagem de erro em linguagem de gente (regra 76). */
export function MensagemErro({ erro, aoTentarDeNovo = null, aoFechar = null }) {
  if (!erro) return null

  const texto = typeof erro === 'string' ? erro : erro.mensagem || erro.message

  return (
    <div className="aviso aviso--erro" role="alert">
      <span aria-hidden="true">⚠️</span>
      <div className="crescer">
        <div>{texto}</div>
        {(aoTentarDeNovo || aoFechar) && (
          <div className="linha-botoes" style={{ marginTop: 10 }}>
            {aoTentarDeNovo && (
              <Botao pequeno variante="fantasma" onClick={aoTentarDeNovo}>
                Tentar de novo
              </Botao>
            )}
            {aoFechar && (
              <Botao pequeno variante="fantasma" onClick={aoFechar}>
                Fechar
              </Botao>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

/** Confirmação antes de sair ou reiniciar (regra 32). */
export function ModalConfirmacao({
  aberto,
  titulo,
  texto,
  rotuloConfirmar = 'Confirmar',
  rotuloCancelar = 'Voltar',
  varianteConfirmar = 'perigo',
  aoConfirmar,
  aoCancelar,
}) {
  const referenciaCancelar = useRef(null)

  useEffect(() => {
    if (!aberto) return undefined

    referenciaCancelar.current?.focus()

    const aoTeclar = (evento) => {
      if (evento.key === 'Escape') aoCancelar()
    }

    document.addEventListener('keydown', aoTeclar)
    return () => document.removeEventListener('keydown', aoTeclar)
  }, [aberto, aoCancelar])

  if (!aberto) return null

  return (
    <div className="modal-fundo" role="dialog" aria-modal="true" aria-label={titulo}>
      <div className="modal">
        <h2 className="modal__titulo">{titulo}</h2>
        <p className="modal__texto">{texto}</p>
        <div className="linha-botoes">
          <Botao ref={referenciaCancelar} variante="fantasma" onClick={aoCancelar}>
            {rotuloCancelar}
          </Botao>
          <Botao variante={varianteConfirmar} onClick={aoConfirmar}>
            {rotuloConfirmar}
          </Botao>
        </div>
      </div>
    </div>
  )
}

/** Emblema com a cor e o ícone da categoria (regra 71). */
export function EmblemaCategoria({ categoria }) {
  if (!categoria) return null

  return (
    <span className="emblema-categoria" style={{ '--cor-categoria': categoria.cor }}>
      <span className="emblema-categoria__icone" aria-hidden="true">
        {categoria.icone}
      </span>
      {categoria.nome}
    </span>
  )
}

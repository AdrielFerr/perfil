/**
 * Grade de 1 a 20 em 5 colunas por 4 linhas (regra 61).
 * Cada número tem no mínimo 48px de altura (regra 62).
 */
export function GradeDicas({
  total = 20,
  usados = [],
  numeroAtual = null,
  desabilitado = false,
  aoEscolher,
}) {
  const numeros = Array.from({ length: total }, (_, indice) => indice + 1)

  return (
    <div className="grade" role="group" aria-label="Escolha uma dica de 1 a 20">
      {numeros.map((numero) => {
        const usado = usados.includes(numero)
        const atual = numero === numeroAtual

        return (
          <button
            key={numero}
            type="button"
            className={[
              'grade__numero',
              usado && !atual && 'grade__numero--usado',
              atual && 'grade__numero--atual',
            ]
              .filter(Boolean)
              .join(' ')}
            disabled={usado || desabilitado}
            onClick={() => aoEscolher(numero)}
            aria-label={
              atual
                ? `Dica ${numero}, aberta agora`
                : usado
                  ? `Dica ${numero}, já usada`
                  : `Abrir a dica ${numero}`
            }
          >
            {numero}
          </button>
        )
      })}
    </div>
  )
}

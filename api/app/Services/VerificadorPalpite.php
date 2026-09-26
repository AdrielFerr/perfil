<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Confere o palpite digitado contra a resposta oficial e as alternativas.
 * Roda SEMPRE no servidor (regra 55) - a resposta nunca viaja antes da hora.
 *
 * Aceita: diferenca de maiusculas, acentos, espacos, pontuacao,
 * artigo inicial e pequenos erros de digitacao.
 */
final class VerificadorPalpite
{
    /** Acima disso consideramos "erro de digitacao", nao resposta errada. */
    private const LIMITE_SEMELHANCA = 0.86;

    /** Palpites curtos exigem acerto exato: "rio" nao pode virar "rios". */
    private const TAMANHO_MINIMO_PARA_TOLERANCIA = 6;

    /**
     * @param array<int,string> $alternativas textos das respostas alternativas
     * @return array{acertou:bool, correspondencia:?string, exata:bool}
     */
    public function verificar(string $palpite, string $resposta, array $alternativas = []): array
    {
        $palpiteNormalizado = Normalizador::normalizar($palpite);

        if ($palpiteNormalizado === '') {
            return ['acertou' => false, 'correspondencia' => null, 'exata' => false];
        }

        $candidatos = array_merge([$resposta], $alternativas);

        // 1) Comparacao exata (ja normalizada) e sem artigo inicial.
        foreach ($candidatos as $candidato) {
            $alvo = Normalizador::normalizar((string) $candidato);

            if ($alvo === '') {
                continue;
            }

            if ($palpiteNormalizado === $alvo) {
                return ['acertou' => true, 'correspondencia' => (string) $candidato, 'exata' => true];
            }

            if (Normalizador::semArtigoInicial($palpiteNormalizado) === Normalizador::semArtigoInicial($alvo)) {
                return ['acertou' => true, 'correspondencia' => (string) $candidato, 'exata' => true];
            }

            // "joaogilberto" == "joao gilberto"
            if (Normalizador::compactar($palpite) === Normalizador::compactar((string) $candidato)) {
                return ['acertou' => true, 'correspondencia' => (string) $candidato, 'exata' => true];
            }
        }

        // 2) Tolerancia a erro de digitacao.
        if (mb_strlen($palpiteNormalizado) >= self::TAMANHO_MINIMO_PARA_TOLERANCIA) {
            foreach ($candidatos as $candidato) {
                $alvo = Normalizador::normalizar((string) $candidato);

                if ($alvo === '' || mb_strlen($alvo) < self::TAMANHO_MINIMO_PARA_TOLERANCIA) {
                    continue;
                }

                if (Normalizador::semelhanca($palpiteNormalizado, $alvo) >= self::LIMITE_SEMELHANCA) {
                    return ['acertou' => true, 'correspondencia' => (string) $candidato, 'exata' => false];
                }
            }
        }

        // 3) Sobrenome/nome unico de pessoa famosa: "pele", "shakespeare".
        //    So vale se o palpite bater com a ULTIMA palavra significativa
        //    e essa palavra for longa o bastante para nao ser ambigua.
        foreach ($candidatos as $candidato) {
            $palavras = Normalizador::palavrasSignificativas((string) $candidato, 5);

            if (count($palavras) < 2) {
                continue;
            }

            $ultima = $palavras[count($palavras) - 1];

            if ($palpiteNormalizado === $ultima && mb_strlen($ultima) >= 6) {
                return ['acertou' => true, 'correspondencia' => (string) $candidato, 'exata' => false];
            }
        }

        return ['acertou' => false, 'correspondencia' => null, 'exata' => false];
    }

    /**
     * Usado pelo gerador: o texto da dica entrega a resposta?
     * Bloqueia a resposta inteira, a versao sem acento, a versao compacta
     * e qualquer palavra significativa dela (regra 43).
     *
     * @param array<int,string> $alternativas
     */
    public function vazaResposta(string $texto, string $resposta, array $alternativas = []): bool
    {
        $textoNormalizado = Normalizador::normalizar($texto);

        if ($textoNormalizado === '') {
            return false;
        }

        $textoCompacto = str_replace(' ', '', $textoNormalizado);

        foreach (array_merge([$resposta], $alternativas) as $candidato) {
            $alvo = Normalizador::normalizar((string) $candidato);

            if ($alvo === '') {
                continue;
            }

            // Resposta numerica (categoria ANO): "1969" e curto demais para
            // as regras abaixo, entao ganha um tratamento proprio.
            if (preg_match('/^\d{1,4}$/', $alvo) === 1) {
                if (preg_match('/\b' . $alvo . '\b/', $textoNormalizado) === 1) {
                    return true;
                }
                if (str_contains($textoCompacto, $alvo)) {
                    return true;
                }
                continue;
            }

            // Resposta inteira dentro do texto.
            if (str_contains(' ' . $textoNormalizado . ' ', ' ' . $alvo . ' ')) {
                return true;
            }

            if (mb_strlen($alvo) >= 5 && str_contains($textoNormalizado, $alvo)) {
                return true;
            }

            // Versao sem espacos, pega "JoaoGilberto" escrito junto.
            $alvoCompacto = str_replace(' ', '', $alvo);
            if (mb_strlen($alvoCompacto) >= 6 && str_contains($textoCompacto, $alvoCompacto)) {
                return true;
            }

            // Pedacos significativos: "shakespeare" em "William Shakespeare".
            foreach (Normalizador::palavrasSignificativas((string) $candidato, 5) as $palavra) {
                if (preg_match('/\b' . preg_quote($palavra, '/') . '/u', $textoNormalizado) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}

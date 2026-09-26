<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Configuracao;
use App\Core\Database;
use App\Core\ExcecaoHttp;
use App\Models\AcaoPartida;
use App\Models\Carta;
use App\Models\CartaUsada;
use App\Models\Dica;
use App\Models\Jogador;
use App\Models\Partida;
use App\Models\RespostaAlternativa;
use App\Views\VisaoPartida;

/**
 * Todas as regras do jogo moram aqui.
 *
 *  20 - de 2 a 6 jogadores
 *  22 - 20 dicas por carta
 *  23 - o jogador da vez abre uma dica ainda fechada e escolhe se chuta ou
 *       passa a vez; passar nao custa nada alem da dica gasta
 *  24 - acertou: ganha 1 ponto por dica AINDA NAO revelada
 *  25 - chutou errado: esta fora desta carta e so volta na carta seguinte;
 *       a vez vai para o proximo que ainda esta vivo nela
 *  26 - todos os jogadores erraram o chute, ou as dicas acabaram: ninguem
 *       pontua e entra carta nova
 *  27 - vence quem chegar primeiro a N pontos; empate vai para quem
 *       acertou a ultima carta
 *  31 - "Desfazer" volta a ultima acao
 *  51 - carta nunca se repete na mesma partida
 *  56 - o estado inteiro fica no banco
 */
final class RegrasPartida
{
    private Partida $partidas;
    private Jogador $jogadores;
    private Carta $cartas;
    private Dica $dicas;
    private CartaUsada $cartasUsadas;
    private RespostaAlternativa $alternativas;
    private AcaoPartida $acoes;
    private VerificadorPalpite $verificador;

    public function __construct()
    {
        $this->partidas     = new Partida();
        $this->jogadores    = new Jogador();
        $this->cartas       = new Carta();
        $this->dicas        = new Dica();
        $this->cartasUsadas = new CartaUsada();
        $this->alternativas = new RespostaAlternativa();
        $this->acoes        = new AcaoPartida();
        $this->verificador  = new VerificadorPalpite();
    }

    private function totalDicas(): int
    {
        return (int) Configuracao::obter('jogo.dicas_por_carta', 20);
    }

    // =================================================================
    // Criacao
    // =================================================================

    /**
     * @param array<int,array{nome:string,cor:string,avatar:string}> $jogadores
     */
    /** @param array<int,string> $categorias temas escolhidos; vazio = todos */
    public function criar(array $jogadores, int $pontuacaoVitoria, array $categorias = []): array
    {
        $minimo = (int) Configuracao::obter('jogo.minimo_jogadores', 2);
        $maximo = (int) Configuracao::obter('jogo.maximo_jogadores', 6);

        if (count($jogadores) < $minimo || count($jogadores) > $maximo) {
            throw ExcecaoHttp::requisicaoInvalida(
                sprintf('A partida precisa ter de %d a %d jogadores.', $minimo, $maximo)
            );
        }

        if ($this->cartas->totalAprovadas() === 0) {
            throw ExcecaoHttp::indisponivel(
                'Ainda nao existe nenhuma carta aprovada. Gere cartas no painel /admin antes de jogar.'
            );
        }

        Database::iniciarTransacao();

        try {
            $partidaId = $this->partidas->criar($this->gerarCodigo(), $pontuacaoVitoria, $categorias);

            $primeiroId = null;
            foreach (array_values($jogadores) as $indice => $jogador) {
                $id = $this->jogadores->criar(
                    $partidaId,
                    $jogador['nome'],
                    $jogador['cor'],
                    $jogador['avatar'],
                    $indice + 1
                );
                $primeiroId ??= $id;
            }

            $this->partidas->atualizar($partidaId, ['jogador_vez_id' => $primeiroId]);
            $this->sortearNovaCarta($partidaId);

            Database::confirmar();
        } catch (\Throwable $e) {
            Database::desfazer();
            throw $e;
        }

        return $this->estado($partidaId);
    }

    private function gerarCodigo(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($tentativa = 0; $tentativa < 20; $tentativa++) {
            $codigo = '';
            for ($i = 0; $i < 8; $i++) {
                $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
            if (!$this->partidas->codigoExiste($codigo)) {
                return $codigo;
            }
        }

        throw new \RuntimeException('Nao foi possivel gerar um codigo de partida.');
    }

    // =================================================================
    // Leitura de estado
    // =================================================================

    public function estado(int $partidaId): array
    {
        $partida = $this->exigirPartida($partidaId);
        $jogadores = $this->jogadores->daPartida($partidaId);

        $carta = null;
        $dicasReveladas = [];

        if ($partida['carta_atual_id'] !== null) {
            $carta = $this->cartas->porIdComCategoria((int) $partida['carta_atual_id']);

            if ($carta !== null) {
                $todas = $this->dicas->daCartaPorNumero((int) $carta['id']);
                $carta['dicas'] = $todas;

                foreach ($partida['dicas_reveladas'] as $numero) {
                    if (isset($todas[$numero])) {
                        $dicasReveladas[$numero] = [
                            'texto'       => $todas[$numero]['texto'],
                            'dificuldade' => $todas[$numero]['dificuldade'],
                        ];
                    }
                }
            }
        }

        $partida['pode_desfazer'] = $partida['status'] === 'em_andamento'
            && $this->acoes->existe($partidaId);

        $partida['fim_de_carta'] = (bool) $partida['carta_revelada']
            || count($partida['dicas_reveladas']) >= $this->totalDicas();

        return VisaoPartida::estado($partida, $jogadores, $carta, $dicasReveladas);
    }

    private function exigirPartida(int $partidaId): array
    {
        $partida = $this->partidas->carregar($partidaId);

        if ($partida === null) {
            throw ExcecaoHttp::naoEncontrado('Partida nao encontrada. Ela pode ter sido apagada.');
        }

        return $partida;
    }

    private function exigirEmAndamento(int $partidaId): array
    {
        $partida = $this->exigirPartida($partidaId);

        if ($partida['status'] !== 'em_andamento') {
            throw ExcecaoHttp::conflito('Esta partida ja terminou.');
        }

        return $partida;
    }

    // =================================================================
    // Acoes da partida
    // =================================================================

    /** Regra 23: abre uma dica ainda fechada. */
    public function revelarDica(int $partidaId, int $numero): array
    {
        $partida = $this->exigirEmAndamento($partidaId);
        $total = $this->totalDicas();

        if ($numero < 1 || $numero > $total) {
            throw ExcecaoHttp::requisicaoInvalida(
                sprintf('Escolha um numero de 1 a %d.', $total)
            );
        }

        if ($partida['carta_atual_id'] === null) {
            throw ExcecaoHttp::conflito('Nao ha carta em jogo. Peca uma carta nova.');
        }

        if ((bool) $partida['carta_revelada']) {
            throw ExcecaoHttp::conflito('A resposta desta carta ja foi mostrada. Passe para a proxima carta.');
        }

        if (in_array($numero, $partida['dicas_reveladas'], true)) {
            throw ExcecaoHttp::conflito(sprintf('A dica %d ja foi usada. Escolha outra.', $numero));
        }

        if ($partida['dica_da_vez'] !== null) {
            throw ExcecaoHttp::conflito('Voce ja abriu uma dica nesta vez. Agora chute ou passe a vez.');
        }

        $dica = $this->dicas->porCartaENumero((int) $partida['carta_atual_id'], $numero);

        if ($dica === null) {
            throw ExcecaoHttp::naoEncontrado('Esta carta nao tem essa dica.');
        }

        Database::iniciarTransacao();

        try {
            $this->guardarParaDesfazer($partida, 'revelar_dica', sprintf('Abriu a dica %d', $numero));

            $reveladas = $partida['dicas_reveladas'];
            $reveladas[] = $numero;

            $this->partidas->atualizar($partidaId, [
                'dicas_reveladas' => $reveladas,
                'dica_da_vez'     => $numero,
            ]);

            Database::confirmar();
        } catch (\Throwable $e) {
            Database::desfazer();
            throw $e;
        }

        return [
            'dica' => [
                'numero'      => $numero,
                'texto'       => $dica['texto'],
                'dificuldade' => $dica['dificuldade'],
            ],
            'estado' => $this->estado($partidaId),
        ];
    }

    /** O jogador digita a resposta e o servidor confere (regra 30 / 55). */
    public function palpitarDigitado(int $partidaId, string $palpite): array
    {
        $partida = $this->exigirEmAndamento($partidaId);
        $this->exigirDicaAberta($partida);

        $carta = $this->cartas->porIdComCategoria((int) $partida['carta_atual_id']);

        if ($carta === null) {
            throw ExcecaoHttp::conflito('Nao ha carta em jogo.');
        }

        $alternativas = array_column($this->alternativas->daCarta((int) $carta['id']), 'texto');

        $resultado = $this->verificador->verificar($palpite, $carta['resposta'], $alternativas);

        return $this->aplicarResultado($partidaId, $resultado['acertou']);
    }

    /**
     * O jogador abriu a dica e preferiu nao chutar. Nao e erro: ele continua
     * vivo na carta e pode chutar quando a roda voltar nele (regra 23).
     * O que ele gastou foi a dica, e so.
     */
    public function passarVez(int $partidaId): array
    {
        $partida = $this->exigirEmAndamento($partidaId);
        $this->exigirDicaAberta($partida);

        return $this->aplicarResultado($partidaId, false, false);
    }

    private function exigirDicaAberta(array $partida): void
    {
        if ($partida['carta_atual_id'] === null) {
            throw ExcecaoHttp::conflito('Nao ha carta em jogo.');
        }

        if ((bool) $partida['carta_revelada']) {
            throw ExcecaoHttp::conflito('A resposta ja foi mostrada. Passe para a proxima carta.');
        }

        if ($partida['dica_da_vez'] === null) {
            throw ExcecaoHttp::conflito('Abra uma dica antes de chutar.');
        }
    }

    /**
     * Coracao do jogo: aplica a jogada e move o turno.
     * Regras 23, 24, 25, 26 e 27.
     *
     * $elimina diz se o erro custa a carta. Chute errado elimina; passar a
     * vez, nao.
     */
    private function aplicarResultado(int $partidaId, bool $acertou, bool $elimina = true): array
    {
        $partida = $this->exigirEmAndamento($partidaId);
        $jogadores = $this->jogadores->daPartida($partidaId);
        $jogadorVezId = (int) $partida['jogador_vez_id'];
        $total = $this->totalDicas();
        $reveladas = count($partida['dicas_reveladas']);

        Database::iniciarTransacao();

        try {
            $this->guardarParaDesfazer(
                $partida,
                $acertou ? 'acerto' : ($elimina ? 'erro' : 'passe'),
                $acertou ? 'Acertou' : ($elimina ? 'Errou o chute' : 'Passou a vez')
            );

            $pontosGanhos = 0;
            $cartaEncerrada = false;
            $eliminou = false;
            $carta = $this->cartas->porIdComCategoria((int) $partida['carta_atual_id']);

            if ($acertou) {
                // Regra 24: vale o numero de dicas que continuaram fechadas.
                $pontosGanhos = max(0, $total - $reveladas);

                $this->jogadores->somarPontos($jogadorVezId, $pontosGanhos);

                $this->partidas->atualizar($partidaId, [
                    'ultimo_acertador_id' => $jogadorVezId,
                    'carta_revelada'      => true,
                    'dica_da_vez'         => null,
                ]);

                $cartaEncerrada = true;

                // Regra 27: alguem chegou na pontuacao de vitoria?
                $this->verificarVitoria(
                    $partidaId,
                    $this->jogadores->daPartida($partidaId),
                    (int) $partida['pontuacao_vitoria']
                );
            } else {
                // Regra 25: so o chute errado elimina. Quem passa a vez continua
                // vivo na carta e chuta quando a roda voltar nele.
                $eliminados = $partida['eliminados_carta'];

                if ($elimina && !in_array($jogadorVezId, $eliminados, true)) {
                    $eliminados[] = $jogadorVezId;
                    $eliminou = true;
                }

                $proximoId = $this->proximoJogadorNaCarta($jogadores, $jogadorVezId, $eliminados);

                if ($proximoId === null || $reveladas >= $total) {
                    // Regra 26: ninguem mais pode chutar, seja porque todo mundo
                    // ja errou o chute, seja porque as dicas acabaram.
                    // Ninguem pontua. A vez NAO passa aqui: quem abre a proxima
                    // carta e o jogador seguinte, e isso acontece em proximaCarta().
                    $this->partidas->atualizar($partidaId, [
                        'eliminados_carta' => $eliminados,
                        'carta_revelada'   => true,
                        'dica_da_vez'      => null,
                    ]);

                    $cartaEncerrada = true;
                } else {
                    $this->partidas->atualizar($partidaId, [
                        'eliminados_carta' => $eliminados,
                        'jogador_vez_id'   => $proximoId,
                        'dica_da_vez'      => null,
                    ]);
                }
            }

            Database::confirmar();
        } catch (\Throwable $e) {
            Database::desfazer();
            throw $e;
        }

        $estado = $this->estado($partidaId);

        return VisaoPartida::resultadoPalpite(
            $acertou,
            $pontosGanhos,
            $estado,
            $cartaEncerrada && $carta !== null ? $carta['resposta'] : null,
            $cartaEncerrada && $carta !== null ? $carta['url_fonte'] : null,
            $eliminou
        );
    }

    /**
     * Proximo da roda que ainda esta vivo na carta atual.
     * Devolve null quando nao sobrou ninguem para chutar (regra 25).
     *
     * @param array<int,array> $jogadores
     * @param array<int,int>   $eliminados
     */
    private function proximoJogadorNaCarta(array $jogadores, int $atualId, array $eliminados): ?int
    {
        $ids = array_map(static fn (array $j): int => (int) $j['id'], $jogadores);
        $quantos = count($ids);

        if ($quantos === 0) {
            return null;
        }

        $posicao = array_search($atualId, $ids, true);
        $inicio = $posicao === false ? -1 : $posicao;

        for ($passo = 1; $passo <= $quantos; $passo++) {
            $candidato = $ids[($inicio + $passo) % $quantos];

            if (!in_array($candidato, $eliminados, true)) {
                return $candidato;
            }
        }

        return null;
    }

    /** @param array<int,array> $jogadores */
    private function proximoJogador(array $jogadores, int $atualId): int
    {
        $ids = array_map(static fn (array $j): int => (int) $j['id'], $jogadores);

        if ($ids === []) {
            throw new \RuntimeException('Partida sem jogadores.');
        }

        $posicao = array_search($atualId, $ids, true);

        if ($posicao === false) {
            return $ids[0];
        }

        return $ids[($posicao + 1) % count($ids)];
    }

    /** @param array<int,array> $jogadores */
    private function verificarVitoria(int $partidaId, array $jogadores, int $pontuacaoVitoria): bool
    {
        $candidatos = array_filter(
            $jogadores,
            static fn (array $j): bool => (int) $j['pontos'] >= $pontuacaoVitoria
        );

        if ($candidatos === []) {
            return false;
        }

        $partida = $this->partidas->carregar($partidaId);
        $ultimoAcertador = $partida['ultimo_acertador_id'] !== null
            ? (int) $partida['ultimo_acertador_id']
            : null;

        $vencedor = $this->escolherVencedor($candidatos, $ultimoAcertador);

        $this->partidas->atualizar($partidaId, [
            'status'      => 'encerrada',
            'vencedor_id' => $vencedor,
        ]);

        return true;
    }

    /**
     * Regra 27: maior pontuacao vence; empate vai para quem acertou
     * a ultima carta; se ainda empatar, vale a ordem de cadastro.
     *
     * @param array<int,array> $candidatos
     */
    private function escolherVencedor(array $candidatos, ?int $ultimoAcertador): int
    {
        $melhor = null;

        foreach ($candidatos as $jogador) {
            if ($melhor === null) {
                $melhor = $jogador;
                continue;
            }

            $pontosAtual = (int) $jogador['pontos'];
            $pontosMelhor = (int) $melhor['pontos'];

            if ($pontosAtual > $pontosMelhor) {
                $melhor = $jogador;
                continue;
            }

            if ($pontosAtual === $pontosMelhor && $ultimoAcertador === (int) $jogador['id']) {
                $melhor = $jogador;
            }
        }

        return (int) $melhor['id'];
    }

    /** Regra 75: mostra a resposta sem pontuar para ninguem. */
    public function revelarResposta(int $partidaId): array
    {
        $partida = $this->exigirEmAndamento($partidaId);

        if ($partida['carta_atual_id'] === null) {
            throw ExcecaoHttp::conflito('Nao ha carta em jogo.');
        }

        $carta = $this->cartas->porIdComCategoria((int) $partida['carta_atual_id']);

        if ($carta === null) {
            throw ExcecaoHttp::conflito('Nao ha carta em jogo.');
        }

        if (!(bool) $partida['carta_revelada']) {
            Database::iniciarTransacao();

            try {
                $this->guardarParaDesfazer($partida, 'revelar_resposta', 'Mostrou a resposta');
                $this->partidas->atualizar($partidaId, [
                    'carta_revelada' => true,
                    'dica_da_vez'    => null,
                ]);

                Database::confirmar();
            } catch (\Throwable $e) {
                Database::desfazer();
                throw $e;
            }
        }

        return [
            'resposta'  => $carta['resposta'],
            'url_fonte' => $carta['url_fonte'],
            'estado'    => $this->estado($partidaId),
        ];
    }

    /** Puxa a proxima carta. Usado depois de acerto, de carta esgotada ou ao pular. */
    public function proximaCarta(int $partidaId): array
    {
        $partida = $this->exigirEmAndamento($partidaId);

        Database::iniciarTransacao();

        try {
            $this->guardarParaDesfazer($partida, 'proxima_carta', 'Puxou uma carta nova');

            // Quem comeca a proxima carta e o jogador seguinte ao da vez.
            $jogadores = $this->jogadores->daPartida($partidaId);
            $proximoId = $this->proximoJogador($jogadores, (int) $partida['jogador_vez_id']);

            $this->partidas->atualizar($partidaId, ['jogador_vez_id' => $proximoId]);
            $this->sortearNovaCarta($partidaId);

            Database::confirmar();
        } catch (\Throwable $e) {
            Database::desfazer();
            throw $e;
        }

        return $this->estado($partidaId);
    }

    private function sortearNovaCarta(int $partidaId): void
    {
        // Respeita os temas escolhidos na criacao da partida.
        $partidaAtual = $this->partidas->carregar($partidaId);
        $temas = $partidaAtual['categorias'] ?? [];

        $carta = $this->cartas->sortearParaPartida($partidaId, $temas);

        if ($carta === null) {
            // Com tema escolhido a mensagem precisa dizer isso, senao o jogador
            // acha que o baralho inteiro acabou.
            throw ExcecaoHttp::indisponivel(
                $temas === []
                    ? 'Acabaram as cartas aprovadas disponiveis para esta partida. '
                      . 'Gere mais cartas no painel /admin.'
                    : sprintf(
                        'Acabaram as cartas destes temas: %s. Comece outra partida com mais temas '
                        . 'ou gere mais cartas no painel /admin.',
                        implode(', ', $temas)
                    )
            );
        }

        $cartaId = (int) $carta['id'];

        $this->cartasUsadas->registrar($partidaId, $cartaId);
        $this->cartas->contarJogada($cartaId);

        $partida = $this->partidas->carregar($partidaId);

        $this->partidas->atualizar($partidaId, [
            'carta_atual_id'   => $cartaId,
            'dicas_reveladas'  => [],
            'eliminados_carta' => [],
            'dica_da_vez'      => null,
            'carta_revelada'   => false,
            'numero_carta'     => (int) ($partida['numero_carta'] ?? 0) + 1,
        ]);
    }

    /** Regra 31: volta a ultima acao. */
    public function desfazer(int $partidaId): array
    {
        $this->exigirPartida($partidaId);

        $acao = $this->acoes->ultima($partidaId);

        if ($acao === null) {
            throw ExcecaoHttp::conflito('Nao ha nada para desfazer.');
        }

        $estado = $acao['estado_anterior'];

        Database::iniciarTransacao();

        try {
            $this->partidas->atualizar($partidaId, [
                'status'              => $estado['status'],
                'carta_atual_id'      => $estado['carta_atual_id'],
                'jogador_vez_id'      => $estado['jogador_vez_id'],
                'ultimo_acertador_id' => $estado['ultimo_acertador_id'],
                'vencedor_id'         => $estado['vencedor_id'],
                'dicas_reveladas'     => $estado['dicas_reveladas'],
                'eliminados_carta'    => $estado['eliminados_carta'] ?? [],
                'dica_da_vez'         => $estado['dica_da_vez'],
                'numero_carta'        => $estado['numero_carta'],
                'carta_revelada'      => $estado['carta_revelada'],
            ]);

            foreach ($estado['pontos'] as $jogadorId => $pontos) {
                $this->jogadores->definirPontos((int) $jogadorId, (int) $pontos);
            }

            // Se a acao desfeita puxou uma carta nova, devolvemos ela ao monte.
            $usadasAgora = $this->cartasUsadas->idsDaPartida($partidaId);
            $usadasAntes = array_map('intval', $estado['cartas_usadas']);

            foreach (array_diff($usadasAgora, $usadasAntes) as $cartaId) {
                $this->cartasUsadas->remover($partidaId, (int) $cartaId);
                $this->descontarJogada((int) $cartaId);
            }

            $this->acoes->desempilhar((int) $acao['id']);

            Database::confirmar();
        } catch (\Throwable $e) {
            Database::desfazer();
            throw $e;
        }

        return $this->estado($partidaId);
    }

    private function descontarJogada(int $cartaId): void
    {
        $carta = $this->cartas->porId($cartaId);

        if ($carta !== null && (int) $carta['vezes_jogada'] > 0) {
            $this->cartas->atualizarContagem($cartaId, (int) $carta['vezes_jogada'] - 1);
        }
    }

    /** Fotografa o estado atual antes de mexer em qualquer coisa. */
    private function guardarParaDesfazer(array $partida, string $tipo, string $descricao): void
    {
        $partidaId = (int) $partida['id'];

        $pontos = [];
        foreach ($this->jogadores->daPartida($partidaId) as $jogador) {
            $pontos[(int) $jogador['id']] = (int) $jogador['pontos'];
        }

        $this->acoes->empilhar($partidaId, $tipo, $descricao, [
            'status'              => $partida['status'],
            'carta_atual_id'      => $partida['carta_atual_id'],
            'jogador_vez_id'      => $partida['jogador_vez_id'],
            'ultimo_acertador_id' => $partida['ultimo_acertador_id'],
            'vencedor_id'         => $partida['vencedor_id'],
            'dicas_reveladas'     => $partida['dicas_reveladas'],
            'eliminados_carta'    => $partida['eliminados_carta'],
            'dica_da_vez'         => $partida['dica_da_vez'],
            'numero_carta'        => (int) $partida['numero_carta'],
            'carta_revelada'      => (int) $partida['carta_revelada'],
            'pontos'              => $pontos,
            'cartas_usadas'       => $this->cartasUsadas->idsDaPartida($partidaId),
        ]);
    }

    /** Regra 32: sair encerra a partida de propria vontade. */
    public function encerrar(int $partidaId): array
    {
        $partida = $this->exigirPartida($partidaId);

        if ($partida['status'] === 'encerrada') {
            return $this->estado($partidaId);
        }

        $jogadores = $this->jogadores->daPartida($partidaId);
        $ultimoAcertador = $partida['ultimo_acertador_id'] !== null
            ? (int) $partida['ultimo_acertador_id']
            : null;

        $maiorPontuacao = 0;
        foreach ($jogadores as $jogador) {
            $maiorPontuacao = max($maiorPontuacao, (int) $jogador['pontos']);
        }

        $lideres = array_filter(
            $jogadores,
            static fn (array $j): bool => (int) $j['pontos'] === $maiorPontuacao
        );

        $this->partidas->atualizar($partidaId, [
            'status'      => 'encerrada',
            'vencedor_id' => $maiorPontuacao > 0 ? $this->escolherVencedor($lideres, $ultimoAcertador) : null,
        ]);

        return $this->estado($partidaId);
    }
}

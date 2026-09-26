<?php

declare(strict_types=1);

/**
 * Importa cartas escritas a mao, a partir de um arquivo JSON.
 *
 * Uso:
 *   php api/bin/importar-cartas.php lote-1.json
 *   php api/bin/importar-cartas.php lote-1.json --pendente   (espera revisao)
 *   php api/bin/importar-cartas.php lote-1.json --so-conferir (nao grava nada)
 *
 * Formato aceito (o de fora pode ser a lista direta ou um objeto com "cartas"):
 *
 *   {
 *     "lote": 1,
 *     "cartas": [
 *       {
 *         "id": "pessoa-001",
 *         "tema": "pessoa",
 *         "resposta": "Santos Dumont",
 *         "respostas_aceitas": ["Alberto Santos Dumont"],
 *         "url_fonte": "https://...",
 *         "dicas": [ "Nasci em 1873.", "..." ]
 *       }
 *     ]
 *   }
 *
 * Detalhes:
 *   - "tema" e "categoria" valem a mesma coisa.
 *   - a dica pode ser texto puro ou {"texto": "...", "dificuldade": "facil"}.
 *   - sem dificuldade escrita, a dica entra como 'media'. Para rotular de
 *     verdade, mande a dica como objeto:
 *       {"texto": "...", "dificuldade": "facil"}
 *   - carta com dica que contem a resposta e RECUSADA inteira, e o relatorio
 *     mostra qual foi a dica. Nao da para so tirar a dica: a carta precisa ter
 *     exatamente 20.
 *   - importar o mesmo arquivo de novo atualiza a carta em vez de duplicar. A
 *     identidade e a resposta normalizada.
 *
 * Precisa do pdo_mysql. No XAMPP, chame o PHP do proprio XAMPP:
 *   C:\xampp\php\php.exe api\bin\importar-cartas.php lote-1.json
 */

define('RAIZ_API', dirname(__DIR__));

require RAIZ_API . '/vendor/autoload.php';

use App\Core\Configuracao;
use App\Core\Database;
use App\Models\Carta;
use App\Models\Categoria;
use App\Models\Dica;
use App\Models\RespostaAlternativa;
use App\Services\Normalizador;
use App\Services\VerificadorPalpite;

Configuracao::carregar(RAIZ_API . '/config/' . (getenv('PERFIL_ARQUIVO_CONFIG') ?: 'config.php'));

$argumentos = array_slice($argv, 1);
$opcoes = array_values(array_filter($argumentos, static fn (string $a): bool => str_starts_with($a, '--')));
$arquivos = array_values(array_filter($argumentos, static fn (string $a): bool => !str_starts_with($a, '--')));

if ($arquivos === []) {
    fwrite(STDERR, "Uso: php api/bin/importar-cartas.php arquivo.json [--pendente] [--so-conferir]\n");
    exit(1);
}

$arquivo = $arquivos[0];
$soConferir = in_array('--so-conferir', $opcoes, true);
$status = in_array('--pendente', $opcoes, true) ? 'pendente' : 'aprovada';

if (!is_file($arquivo)) {
    fwrite(STDERR, "Arquivo nao encontrado: {$arquivo}\n");
    exit(1);
}

$conteudo = json_decode((string) file_get_contents($arquivo), true);

if (!is_array($conteudo)) {
    fwrite(STDERR, "JSON invalido: " . json_last_error_msg() . "\n");
    exit(1);
}

$cartas = $conteudo['cartas'] ?? $conteudo;

if (!is_array($cartas) || $cartas === []) {
    fwrite(STDERR, "Nenhuma carta no arquivo.\n");
    exit(1);
}

$totalDicas = (int) Configuracao::obter('jogo.dicas_por_carta', 20);
$categoriasValidas = ['pessoa', 'lugar', 'ano', 'coisa'];

$modeloCategoria = new Categoria();
$modeloCarta = new Carta();
$modeloDica = new Dica();
$modeloAlternativa = new RespostaAlternativa();
$verificador = new VerificadorPalpite();

/** Mapa chave => id das categorias. */
$idsCategoria = [];

foreach ($modeloCategoria->todas() as $categoria) {
    $idsCategoria[(string) $categoria['chave']] = (int) $categoria['id'];
}

$importadas = 0;
$atualizadas = 0;
$recusadas = [];

fwrite(STDOUT, sprintf(
    "Lendo %d cartas de %s (status: %s%s)\n\n",
    count($cartas),
    basename($arquivo),
    $status,
    $soConferir ? ', so conferindo' : ''
));

foreach ($cartas as $indice => $bruta) {
    $rotulo = (string) ($bruta['id'] ?? ('carta ' . ($indice + 1)));

    $recusar = static function (string $motivo) use (&$recusadas, $rotulo): void {
        $recusadas[] = $rotulo . ': ' . $motivo;
    };

    if (!is_array($bruta)) {
        $recusar('nao e um objeto');
        continue;
    }

    $categoria = strtolower(trim((string) ($bruta['tema'] ?? $bruta['categoria'] ?? '')));

    if (!in_array($categoria, $categoriasValidas, true)) {
        $recusar(sprintf('tema "%s" nao existe (use %s)', $categoria, implode(', ', $categoriasValidas)));
        continue;
    }

    if (!isset($idsCategoria[$categoria])) {
        $recusar('categoria ' . $categoria . ' nao esta cadastrada no banco');
        continue;
    }

    $resposta = trim((string) ($bruta['resposta'] ?? ''));

    if ($resposta === '' || mb_strlen($resposta) > 180) {
        $recusar('resposta vazia ou longa demais');
        continue;
    }

    $alternativas = [];

    foreach ((array) ($bruta['respostas_aceitas'] ?? $bruta['alternativas'] ?? []) as $alternativa) {
        $alternativa = trim((string) $alternativa);

        if ($alternativa !== '' && mb_strlen($alternativa) <= 180) {
            $alternativas[] = $alternativa;
        }
    }

    $dicasBrutas = (array) ($bruta['dicas'] ?? []);

    if (count($dicasBrutas) !== $totalDicas) {
        $recusar(sprintf('tem %d dicas e precisa de exatamente %d', count($dicasBrutas), $totalDicas));
        continue;
    }

    $dicas = [];
    $vazou = null;
    $textosVistos = [];

    foreach (array_values($dicasBrutas) as $posicao => $dicaBruta) {
        $texto = is_array($dicaBruta)
            ? trim((string) ($dicaBruta['texto'] ?? ''))
            : trim((string) $dicaBruta);

        if ($texto === '') {
            $vazou = sprintf('dica %d esta vazia', $posicao + 1);
            break;
        }

        if (mb_strlen($texto) > 170) {
            $vazou = sprintf('dica %d passa de 170 caracteres', $posicao + 1);
            break;
        }

        // Regra 43: nenhuma dica pode entregar a resposta.
        if ($verificador->vazaResposta($texto, $resposta, $alternativas)) {
            $vazou = sprintf('a dica %d entrega a resposta -> "%s"', $posicao + 1, $texto);
            break;
        }

        $normalizada = Normalizador::normalizar($texto);

        if (isset($textosVistos[$normalizada])) {
            $vazou = sprintf('a dica %d repete a dica %d', $posicao + 1, $textosVistos[$normalizada]);
            break;
        }

        $textosVistos[$normalizada] = $posicao + 1;

        // Sem dificuldade escrita, fica 'media'.
        //
        // Ja tentei adivinhar pela posicao na lista, supondo que quem escreve
        // vai da dica mais vaga para a que quase entrega. Nao e verdade: no
        // lote 1, 'Sou chamado de Pai da Aviacao' estava na posicao 4 e 'Meu
        // pai era cafeicultor' na 17. O rotulo saia invertido na tela, o que e
        // pior do que nao ter rotulo nenhum. Para rotular de verdade, mande a
        // dica como objeto: {"texto": "...", "dificuldade": "facil"}.
        $dificuldade = is_array($dicaBruta) ? (string) ($dicaBruta['dificuldade'] ?? '') : '';

        if (!in_array($dificuldade, ['facil', 'media', 'dificil'], true)) {
            $dificuldade = 'media';
        }

        $dicas[] = [
            'numero'             => $posicao + 1,
            'texto'              => $texto,
            'texto_normalizado'  => $normalizada,
            'dificuldade'        => $dificuldade,
            'propriedade_origem' => 'manual',
        ];
    }

    if ($vazou !== null) {
        $recusar($vazou);
        continue;
    }

    if ($soConferir) {
        $importadas++;
        continue;
    }

    // Regra 39: as posicoes de 1 a 20 sao embaralhadas, senao quem conhece o
    // arquivo saberia que a dica 20 e sempre a que entrega.
    shuffle($dicas);

    foreach ($dicas as $posicao => $dica) {
        $dicas[$posicao]['numero'] = $posicao + 1;
    }

    $respostaNormalizada = Normalizador::normalizar($resposta);
    $jaExistia = $modeloCarta->existeRespostaNormalizada($respostaNormalizada);

    Database::iniciarTransacao();

    try {
        // Substitui a versao anterior desta mesma carta. As dicas e as
        // alternativas somem junto, pela FK com ON DELETE CASCADE.
        $modeloCarta->excluirPorRespostaNormalizada($respostaNormalizada);

        $cartaId = $modeloCarta->criar(
            $idsCategoria[$categoria],
            null,
            $resposta,
            $respostaNormalizada,
            isset($bruta['url_fonte']) ? trim((string) $bruta['url_fonte']) : null,
            null,
            $status
        );

        $modeloDica->criarEmLote($cartaId, $dicas);

        if ($alternativas !== []) {
            $modeloAlternativa->criarEmLote($cartaId, array_map(
                static fn (string $texto): array => [
                    'texto'             => $texto,
                    'texto_normalizado' => Normalizador::normalizar($texto),
                ],
                $alternativas
            ));
        }

        Database::confirmar();
    } catch (\Throwable $e) {
        Database::desfazer();
        $recusar('erro ao gravar: ' . $e->getMessage());
        continue;
    }

    if ($jaExistia) {
        $atualizadas++;
    } else {
        $importadas++;
    }
}

fwrite(STDOUT, sprintf(
    "%d carta(s) %s, %d atualizada(s), %d recusada(s).\n",
    $importadas,
    $soConferir ? 'passariam na conferencia' : 'importada(s)',
    $atualizadas,
    count($recusadas)
));

if ($recusadas !== []) {
    fwrite(STDOUT, "\nRecusadas:\n");

    foreach ($recusadas as $motivo) {
        fwrite(STDOUT, '  - ' . $motivo . "\n");
    }

    fwrite(STDOUT, "\nCorrija e rode de novo: carta ja importada e atualizada, nao duplicada.\n");
}

exit($recusadas === [] ? 0 : 2);

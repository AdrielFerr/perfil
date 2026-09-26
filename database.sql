-- =====================================================================
-- Jogo Perfil - Estrutura do banco de dados
-- MySQL 5.7+ / MariaDB 10.3+   |   charset utf8mb4
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `perfil`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `perfil`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `acoes_partida`;
DROP TABLE IF EXISTS `cartas_usadas`;
DROP TABLE IF EXISTS `jogadores`;
DROP TABLE IF EXISTS `partidas`;
DROP TABLE IF EXISTS `respostas_alternativas`;
DROP TABLE IF EXISTS `dicas`;
DROP TABLE IF EXISTS `cartas`;
DROP TABLE IF EXISTS `categorias`;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- categorias
-- ---------------------------------------------------------------------
CREATE TABLE `categorias` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chave`  VARCHAR(20)  NOT NULL COMMENT 'pessoa | lugar | ano | coisa',
  `nome`   VARCHAR(40)  NOT NULL,
  `cor`    VARCHAR(9)   NOT NULL COMMENT 'cor hexadecimal da categoria',
  `icone`  VARCHAR(8)   NOT NULL COMMENT 'emoji usado na interface',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_categorias_chave` (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categorias` (`chave`, `nome`, `cor`, `icone`) VALUES
  ('pessoa', 'Pessoa', '#B3202E', '🧑'),
  ('lugar',  'Lugar',  '#1D5C4F', '🌍'),
  ('ano',    'Ano',    '#A06A10', '📅'),
  ('coisa',  'Coisa',  '#26406E', '📦');

-- ---------------------------------------------------------------------
-- cartas
-- ---------------------------------------------------------------------
CREATE TABLE `cartas` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `categoria_id`         INT UNSIGNED NOT NULL,
  `qid`                  VARCHAR(24)  NULL COMMENT 'identificador do Wikidata, ex.: Q1035',
  `resposta`             VARCHAR(180) NOT NULL,
  `resposta_normalizada` VARCHAR(180) NOT NULL COMMENT 'minuscula, sem acento, sem espaco extra',
  `url_fonte`            VARCHAR(400) NULL COMMENT 'link do artigo da Wikipedia',
  `resumo_fonte`         TEXT         NULL,
  `status`               ENUM('pendente','aprovada','rejeitada') NOT NULL DEFAULT 'pendente',
  `vezes_jogada`         INT UNSIGNED NOT NULL DEFAULT 0,
  `criado_em`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cartas_qid` (`qid`),
  UNIQUE KEY `uk_cartas_resposta_normalizada` (`resposta_normalizada`),
  KEY `ix_cartas_status_vezes` (`status`, `vezes_jogada`),
  KEY `ix_cartas_categoria` (`categoria_id`),
  CONSTRAINT `fk_cartas_categoria`
    FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- dicas (sempre 20 por carta, numeradas de 1 a 20)
-- ---------------------------------------------------------------------
CREATE TABLE `dicas` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `carta_id`           INT UNSIGNED NOT NULL,
  `numero`             TINYINT UNSIGNED NOT NULL COMMENT '1 a 20',
  `texto`              VARCHAR(255) NOT NULL,
  `texto_normalizado`  VARCHAR(255) NOT NULL COMMENT 'usado para evitar dicas repetidas',
  `dificuldade`        ENUM('dificil','media','facil') NOT NULL DEFAULT 'media',
  `propriedade_origem` VARCHAR(60)  NULL COMMENT 'ex.: P106, resumo_wikipedia, calculado_decada',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dicas_carta_numero` (`carta_id`, `numero`),
  UNIQUE KEY `uk_dicas_carta_texto` (`carta_id`, `texto_normalizado`),
  CONSTRAINT `fk_dicas_carta`
    FOREIGN KEY (`carta_id`) REFERENCES `cartas` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- respostas_alternativas (skos:altLabel do Wikidata e sinonimos manuais)
-- ---------------------------------------------------------------------
CREATE TABLE `respostas_alternativas` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `carta_id`          INT UNSIGNED NOT NULL,
  `texto`             VARCHAR(180) NOT NULL,
  `texto_normalizado` VARCHAR(180) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_alternativas_carta_texto` (`carta_id`, `texto_normalizado`),
  CONSTRAINT `fk_alternativas_carta`
    FOREIGN KEY (`carta_id`) REFERENCES `cartas` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- partidas
-- ---------------------------------------------------------------------
CREATE TABLE `partidas` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo`              CHAR(8)      NOT NULL COMMENT 'codigo publico da partida',
  `pontuacao_vitoria`   SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  `categorias`          JSON         NULL COMMENT 'temas escolhidos na criacao; vazio = todos',
  `status`              ENUM('em_andamento','encerrada') NOT NULL DEFAULT 'em_andamento',
  `carta_atual_id`      INT UNSIGNED NULL,
  `jogador_vez_id`      INT UNSIGNED NULL,
  `ultimo_acertador_id` INT UNSIGNED NULL COMMENT 'desempate: quem acertou a ultima carta',
  `vencedor_id`         INT UNSIGNED NULL,
  `dicas_reveladas`     JSON         NULL COMMENT 'numeros ja revelados na carta atual, em ordem',
  `eliminados_carta`    JSON         NULL COMMENT 'ids dos jogadores que ja erraram ou desistiram da carta atual',
  `dica_da_vez`         TINYINT UNSIGNED NULL COMMENT 'dica que o jogador da vez ja abriu; NULL = ainda precisa escolher',
  `numero_carta`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `carta_revelada`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 quando a resposta ja foi mostrada',
  `criado_em`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_partidas_codigo` (`codigo`),
  KEY `ix_partidas_carta_atual` (`carta_atual_id`),
  CONSTRAINT `fk_partidas_carta_atual`
    FOREIGN KEY (`carta_atual_id`) REFERENCES `cartas` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- jogadores
-- ---------------------------------------------------------------------
CREATE TABLE `jogadores` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `partida_id` INT UNSIGNED NOT NULL,
  `nome`       VARCHAR(40)  NOT NULL,
  `cor`        VARCHAR(9)   NOT NULL DEFAULT '#B3202E',
  `avatar`     VARCHAR(8)   NOT NULL DEFAULT '🙂',
  `pontos`     SMALLINT     NOT NULL DEFAULT 0,
  `ordem`      TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_jogadores_partida_ordem` (`partida_id`, `ordem`),
  CONSTRAINT `fk_jogadores_partida`
    FOREIGN KEY (`partida_id`) REFERENCES `partidas` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- cartas_usadas (uma carta nunca se repete dentro da mesma partida)
-- ---------------------------------------------------------------------
CREATE TABLE `cartas_usadas` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `partida_id` INT UNSIGNED NOT NULL,
  `carta_id`   INT UNSIGNED NOT NULL,
  `usada_em`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cartas_usadas` (`partida_id`, `carta_id`),
  KEY `ix_cartas_usadas_carta` (`carta_id`),
  CONSTRAINT `fk_cartas_usadas_partida`
    FOREIGN KEY (`partida_id`) REFERENCES `partidas` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cartas_usadas_carta`
    FOREIGN KEY (`carta_id`) REFERENCES `cartas` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- acoes_partida (pilha usada pelo botao "Desfazer")
-- ---------------------------------------------------------------------
CREATE TABLE `acoes_partida` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `partida_id`      INT UNSIGNED NOT NULL,
  `tipo`            VARCHAR(30)  NOT NULL COMMENT 'revelar_dica | palpite | resultado | pular | revelar_resposta',
  `descricao`       VARCHAR(120) NULL,
  `estado_anterior` JSON         NOT NULL COMMENT 'fotografia do estado antes da acao',
  `criado_em`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_acoes_partida` (`partida_id`, `id`),
  CONSTRAINT `fk_acoes_partida`
    FOREIGN KEY (`partida_id`) REFERENCES `partidas` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

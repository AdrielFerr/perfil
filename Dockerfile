# =====================================================================
# Imagem do jogo Perfil: front-end compilado + API PHP no mesmo Apache.
#
#   /                -> React (frontend/dist)
#   /api             -> API PHP (api/public)
#
# Tudo que e segredo (senha do banco, hash do admin) entra por variavel
# de ambiente no stack, nunca na imagem.
# =====================================================================

# ---------------------------------------------------------------------
# 1) Compila o front-end
# ---------------------------------------------------------------------
FROM node:22-alpine AS front

WORKDIR /app

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./

# No container o jogo mora na raiz do dominio e a API responde em /api.
ENV VITE_BASE=/
ENV VITE_API_BASE=/api

RUN npm run build

# ---------------------------------------------------------------------
# 2) Autoloader do Composer
# ---------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /api

COPY api/composer.json ./
COPY api/app ./app

RUN composer install --no-dev --no-interaction --optimize-autoloader --ignore-platform-reqs

# ---------------------------------------------------------------------
# 3) Imagem final
# ---------------------------------------------------------------------
FROM php:8.2-apache

# intl precisa da libicu; as outras extensoes ja vem no php:8.2.
# A libicu-dev fica instalada de proposito: dar purge nela levaria junto a
# biblioteca de runtime e o intl pararia de carregar.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql intl; \
    rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers expires deflate remoteip

# php.ini de producao: erro nunca vai para a tela, so para o log.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/apache-perfil.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrada.sh /usr/local/bin/entrada.sh
RUN chmod +x /usr/local/bin/entrada.sh

WORKDIR /var/www/html

# API: codigo, templates e o front controller.
COPY api/app             ./api/app
COPY api/config          ./api/config
COPY api/public          ./api/public
COPY api/templates_dicas ./api/templates_dicas
COPY api/bin             ./api/bin
COPY api/composer.json   ./api/composer.json
COPY api/.htaccess       ./api/.htaccess

# Schema e baralho, usados na primeira subida. Ficam fora do DocumentRoot:
# o navegador nunca chega neles.
COPY database.sql        ./database.sql
COPY cartas.sql          ./cartas.sql
COPY --from=vendor /api/vendor ./api/vendor

# A configuracao por variavel de ambiente vira a configuracao do container.
RUN cp ./api/config/config.ambiente.php ./api/config/config.php

# Front-end compilado na raiz servida pelo Apache.
COPY --from=front /app/dist ./public

# O cache do gerador e o log precisam ser escrevíveis pelo Apache.
RUN mkdir -p ./api/cache/http && chown -R www-data:www-data ./api/cache

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/api/saude") ? 0 : 1);'

ENTRYPOINT ["/usr/local/bin/entrada.sh"]
CMD ["apache2-foreground"]

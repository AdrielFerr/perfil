#!/bin/sh
# =====================================================================
# Antes de subir o Apache:
#   1. espera o MySQL aceitar conexao
#   2. cria as tabelas se o banco ainda estiver vazio
#
# O passo 2 NUNCA roda com o banco ja criado: se a tabela `categorias`
# existe, o script sai sem tocar em nada. O database.sql comeca com
# DROP TABLE, entao rodar de novo apagaria as partidas.
# =====================================================================
set -e

if [ "${PERFIL_PREPARAR_BANCO:-true}" = "true" ]; then
    php /var/www/html/api/bin/preparar-banco.php
fi

exec "$@"

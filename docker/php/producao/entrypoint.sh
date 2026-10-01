#!/bin/sh
# Entrypoint de PRODUÇÃO (serviços app, worker e scheduler).
# - A configuração vem só de variáveis de ambiente (.env.prod via env_file); não há backend/.env na imagem.
# - config:cache tem de correr no arranque (e não no build) porque grava os valores das variáveis
#   (APP_KEY, palavras-passe) — nunca podem ficar numa camada da imagem.
# - routes e eventos já vêm em cache do build (não dependem do ambiente).
# - Não executa migrações: são um passo explícito do deploy (docs/PRODUCAO.md).
set -eu

cd /var/www/html

if [ -z "${APP_KEY:-}" ]; then
    echo "ERRO: APP_KEY não definida (ver .env.prod.example)." >&2
    exit 1
fi

# Estrutura de runtime (bootstrap/cache e storage/framework podem ser tmpfs com o sistema de ficheiros só de leitura).
mkdir -p storage/framework/cache/data storage/framework/views storage/framework/sessions \
         storage/framework/testing storage/logs storage/app/private storage/app/public bootstrap/cache

# Os ficheiros gerados no build (pacotes, serviços, rotas, eventos) são copiados para bootstrap/cache se este for tmpfs.
if [ -d /opt/erp/bootstrap-cache ]; then
    cp -n /opt/erp/bootstrap-cache/*.php bootstrap/cache/ 2>/dev/null || true
fi

if [ "${ERP_CACHE_CONFIG:-1}" = "1" ]; then
    php artisan config:cache --no-ansi -q
fi

exec docker-php-entrypoint "$@"

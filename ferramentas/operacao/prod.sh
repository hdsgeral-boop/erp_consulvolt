#!/bin/sh
# Atalho para o compose de PRODUÇÃO (evita esquecer o --env-file, sem o qual as ${VARIAVEIS} ficam vazias).
#   ferramentas/operacao/prod.sh ps
#   ferramentas/operacao/prod.sh exec app php artisan about
#   ERP_ENV_FICHEIRO=/srv/erp/.env.prod ferramentas/operacao/prod.sh up -d
set -eu
RAIZ=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
ENV_FICHEIRO="${ERP_ENV_FICHEIRO:-$RAIZ/.env.prod}"
[ -f "$ENV_FICHEIRO" ] || { echo "ERRO: $ENV_FICHEIRO não existe (copiar .env.prod.example)." >&2; exit 1; }
export ERP_ENV_FICHEIRO="$ENV_FICHEIRO"   # também usado no env_file dos serviços PHP
exec docker compose --project-directory "$RAIZ" -f "$RAIZ/docker-compose.prod.yml" --env-file "$ENV_FICHEIRO" "$@"

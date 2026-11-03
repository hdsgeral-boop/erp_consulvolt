#!/bin/sh
# Atalho para o compose de PRODUÇÃO (evita esquecer o --env-file, sem o qual as ${VARIAVEIS} ficam vazias).
#   ferramentas/operacao/prod.sh ps
#   ferramentas/operacao/prod.sh exec app php artisan about
#   ERP_ENV_FICHEIRO=/srv/erp/.env.prod ferramentas/operacao/prod.sh up -d
#   ERP_COMPOSE_EXTRA="-f docker-compose.teste.yml" ferramentas/operacao/prod.sh up -d   (ficheiros compose adicionais,
#   ex.: os limites de memória do servidor de TESTE; aplicam-se também ao deploy.sh e ao migrar-postgres18.sh)
set -eu
RAIZ=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
ENV_FICHEIRO="${ERP_ENV_FICHEIRO:-$RAIZ/.env.prod}"
[ -f "$ENV_FICHEIRO" ] || { echo "ERRO: $ENV_FICHEIRO não existe (copiar .env.prod.example)." >&2; exit 1; }
export ERP_ENV_FICHEIRO="$ENV_FICHEIRO"   # também usado no env_file dos serviços PHP
# shellcheck disable=SC2086 # ERP_COMPOSE_EXTRA é uma lista de argumentos («-f ficheiro -f ficheiro»), expandida de propósito
exec docker compose --project-directory "$RAIZ" -f "$RAIZ/docker-compose.prod.yml" ${ERP_COMPOSE_EXTRA:-} --env-file "$ENV_FICHEIRO" "$@"

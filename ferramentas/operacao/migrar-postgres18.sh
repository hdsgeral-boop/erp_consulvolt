#!/bin/sh
# =============================================================================================
# ERP_CONSULVOLT — migração do PostgreSQL 16 para o 18 (mudança de versão maior: exige pg_dump/pg_restore).
#
#   ferramentas/operacao/migrar-postgres18.sh [--sem-confirmar] [--sem-arranque]
#   (--sem-arranque: termina com o PostgreSQL 18 restaurado e o resto parado — para o deploy.sh fazer as migrações e o arranque)
#
# Contexto: a imagem postgres:18 mudou a pasta de dados (PGDATA=/var/lib/postgresql/18/docker, volume em
# /var/lib/postgresql). O docker-compose.prod.yml usa por isso um volume NOVO (erp_pgdados18) — o volume antigo
# (erp_pgdados, PostgreSQL 16) nunca é tocado e serve de recuo.
#
#   1. pára quem escreve na base (app, worker, scheduler, web, copias);
#   2. pg_dump (formato custom) da base a partir do contentor PostgreSQL 16 ainda a correr → $ERP_PASTA_COPIAS/pg18/;
#   3. remove o contentor antigo (o volume fica) e arranca o PostgreSQL 18 com o volume novo (initdb + extensões);
#   4. recria a base vazia (sem extensões, para o restauro as criar) e faz pg_restore --exit-on-error;
#   5. compara o número de linhas de todas as tabelas antes/depois; ANALYZE;
#   6. arranca o resto dos serviços e verifica /api/saude.
# Recuo: repor no compose «image: postgres:16-alpine» e «erp_pgdados:/var/lib/postgresql/data» e `prod.sh up -d`.
# Depois de confirmar que tudo está bem: docker volume rm <projecto>_erp_pgdados
# =============================================================================================
set -eu

RAIZ=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
prod() { sh "$RAIZ/ferramentas/operacao/prod.sh" "$@"; }
registar() { printf '%s [pg18] %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$*"; }
falhar() { registar "ERRO: $*"; exit 1; }

ENV_FICHEIRO="${ERP_ENV_FICHEIRO:-$RAIZ/.env.prod}"
[ -f "$ENV_FICHEIRO" ] || falhar "$ENV_FICHEIRO não existe."
ler() { sed -n "s/^$1=//p" "$ENV_FICHEIRO" | tail -1 | sed 's/^"\(.*\)"$/\1/'; }
BASE=$(ler POSTGRES_DB); UTILIZADOR=$(ler POSTGRES_USER)
[ -n "$BASE" ] && [ -n "$UTILIZADOR" ] || falhar "POSTGRES_DB/POSTGRES_USER não definidos em $ENV_FICHEIRO."
PASTA="$(ler ERP_PASTA_COPIAS)"; PASTA="${PASTA:-$RAIZ/copias}/pg18"
mkdir -p "$PASTA" && chmod 700 "$PASTA"
DUMP="$PASTA/base_${BASE}_pg16_$(date '+%Y%m%d-%H%M%S').dump"

ANTIGO=$(prod ps -q postgres 2>/dev/null | head -1)
[ -n "$ANTIGO" ] || falhar "o contentor postgres não está a correr: nada a migrar (ou já migrado)."
VERSAO=$(docker exec "$ANTIGO" postgres --version | sed 's/.*) //')
case "$VERSAO" in 18*) falhar "o PostgreSQL a correr já é a versão $VERSAO." ;; esac
registar "PostgreSQL actual: $VERSAO (contentor $ANTIGO); base $BASE; dump em $DUMP"

CONFIRMAR=1; ARRANCAR=1
for arg in "$@"; do
    case "$arg" in
        --sem-confirmar) CONFIRMAR=0 ;;
        --sem-arranque) ARRANCAR=0 ;;
        *) falhar "opção desconhecida: $arg" ;;
    esac
done
if [ "$CONFIRMAR" = "1" ]; then
    printf 'Continuar com a migração para o PostgreSQL 18? (escreva SIM) '
    read -r resposta
    [ "$resposta" = "SIM" ] || falhar "cancelado."
fi

registar "1/6 a parar quem escreve na base"
prod stop app worker scheduler web copias 2>/dev/null || true

registar "2/6 pg_dump (PostgreSQL $VERSAO)"
docker exec "$ANTIGO" pg_dump -U "$UTILIZADOR" --format=custom --compress=6 "$BASE" > "$DUMP" || falhar "pg_dump falhou."
docker exec "$ANTIGO" pg_restore --version >/dev/null
CONTAGEM_ANTES=$(docker exec "$ANTIGO" psql -U "$UTILIZADOR" -d "$BASE" -XAt -c \
    "select coalesce(sum(n_live_tup),0) from pg_stat_user_tables" | tr -d '[:space:]')
TABELAS_ANTES=$(docker exec "$ANTIGO" psql -U "$UTILIZADOR" -d "$BASE" -XAt -c "select count(*) from pg_stat_user_tables" | tr -d '[:space:]')
registar "dump: $(du -h "$DUMP" | cut -f1); tabelas: $TABELAS_ANTES; linhas (estimativa): $CONTAGEM_ANTES"

registar "3/6 a substituir o contentor pelo PostgreSQL 18 (volume novo erp_pgdados18; o antigo fica intacto)"
prod rm -sf postgres >/dev/null
prod up -d --wait postgres || falhar "o PostgreSQL 18 não arrancou (ver: prod.sh logs postgres)."
NOVO=$(prod ps -q postgres | head -1)
registar "PostgreSQL novo: $(docker exec "$NOVO" postgres --version | sed 's/.*) //')"

registar "4/6 restauro"
docker exec "$NOVO" psql -U "$UTILIZADOR" -d postgres -XAt -v ON_ERROR_STOP=1 \
    -c "drop database if exists \"$BASE\"" \
    -c "create database \"$BASE\" encoding 'UTF8' locale 'C.UTF-8' template template0" >/dev/null
docker cp "$DUMP" "$NOVO:/tmp/restauro.dump"
docker exec "$NOVO" pg_restore -U "$UTILIZADOR" --dbname="$BASE" --no-owner --no-privileges --exit-on-error --jobs=2 /tmp/restauro.dump \
    || falhar "pg_restore falhou. A base antiga continua no volume erp_pgdados (ver recuo no cabeçalho deste script)."
docker exec "$NOVO" rm -f /tmp/restauro.dump
docker exec "$NOVO" psql -U "$UTILIZADOR" -d "$BASE" -XAt -c "analyze" >/dev/null

registar "5/6 conferência"
TABELAS_DEPOIS=$(docker exec "$NOVO" psql -U "$UTILIZADOR" -d "$BASE" -XAt -c "select count(*) from pg_stat_user_tables" | tr -d '[:space:]')
CONTAGEM_DEPOIS=$(docker exec "$NOVO" psql -U "$UTILIZADOR" -d "$BASE" -XAt -c "select coalesce(sum(n_live_tup),0) from pg_stat_user_tables" | tr -d '[:space:]')
registar "tabelas: $TABELAS_ANTES → $TABELAS_DEPOIS; linhas: $CONTAGEM_ANTES → $CONTAGEM_DEPOIS"
[ "$TABELAS_ANTES" = "$TABELAS_DEPOIS" ] || falhar "número de tabelas diferente: restauro incompleto."
docker exec "$NOVO" psql -U "$UTILIZADOR" -d "$BASE" -XAt -c "select extname||' '||extversion from pg_extension where extname in ('unaccent','pg_trgm')" | sed 's/^/extensão: /'

VOLUME_ANTIGO="$(prod config --format json 2>/dev/null | sed -n 's/.*"name": *"\([^"]*\)".*/\1/p' | head -1)_erp_pgdados"
if [ "$ARRANCAR" = "0" ]; then
    registar "6/6 (--sem-arranque) PostgreSQL 18 restaurado; os serviços ficam parados para o deploy. Volume antigo: $VOLUME_ANTIGO"
    exit 0
fi
registar "6/6 a arrancar os serviços"
prod up -d --wait --remove-orphans
i=0
until prod exec -T web wget -q -O - http://127.0.0.1:8080/api/saude 2>/dev/null | grep -q '"estado":"OK"'; do
    i=$((i + 1)); [ "$i" -lt 30 ] || falhar "/api/saude não ficou OK em 60 s."
    sleep 2
done
registar "concluído: PostgreSQL 18 em serviço. Quando confirmar que está tudo bem: docker volume rm $VOLUME_ANTIGO"

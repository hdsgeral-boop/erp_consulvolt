#!/bin/sh
# =============================================================================================
# ERP_CONSULVOLT — implantação de uma nova versão em PRODUÇÃO (passos detalhados em docs/PRODUCAO.md).
#
#   ferramentas/operacao/deploy.sh [--versao=<etiqueta>] [--sem-build] [--sem-copia] [--manutencao]
#
#   1. constrói as imagens app/web/copias etiquetadas com a versão (por omissão: git describe);
#   2. cópia de segurança da base ANTES das migrações (salvo --sem-copia);
#   3. [--manutencao] php artisan down (pedidos recebem 503 durante a migração);
#   4. php artisan migrate --force num contentor efémero da NOVA imagem;
#   5. recria app/web/worker/scheduler/copias com a nova imagem (o worker antigo termina o trabalho em curso);
#   6. verifica GET /api/saude através do nginx; regista a versão em .versoes-implantadas.
# Reverter: ver docs/PRODUCAO.md › Rollback (ERP_VERSAO=<anterior> ferramentas/operacao/prod.sh up -d).
# =============================================================================================
set -eu

RAIZ=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
prod() { sh "$RAIZ/ferramentas/operacao/prod.sh" "$@"; }
registar() { printf '%s [deploy] %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$*"; }
falhar() { registar "ERRO: $*"; exit 1; }

VERSAO=""; BUILD=1; COPIA=1; MANUTENCAO=0
for arg in "$@"; do
    case "$arg" in
        --versao=*) VERSAO="${arg#--versao=}" ;;
        --sem-build) BUILD=0 ;;
        --sem-copia) COPIA=0 ;;
        --manutencao) MANUTENCAO=1 ;;
        *) sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
    esac
done
if [ -z "$VERSAO" ]; then
    VERSAO=$(git -C "$RAIZ" describe --always --tags --dirty 2>/dev/null || date '+%Y%m%d%H%M')
fi
export ERP_VERSAO="$VERSAO"
registar "versão a implantar: $ERP_VERSAO"

if [ "$BUILD" = "1" ]; then
    registar "1/6 build das imagens"
    prod build app web copias
fi

registar "2/6 PostgreSQL e Redis"
prod up -d --wait postgres redis

if [ "$COPIA" = "1" ]; then
    registar "2/6 cópia de segurança antes das migrações"
    prod run --rm --no-deps copias /operacao/backup.sh || falhar "a cópia falhou: implantação interrompida (nada foi alterado)."
fi

if [ "$MANUTENCAO" = "1" ] && [ -n "$(prod ps -q app 2>/dev/null)" ]; then
    registar "3/6 modo de manutenção"
    prod exec -T app php artisan down --retry=30 || true
fi

registar "4/6 migrações (nova imagem)"
prod run --rm --no-deps app php artisan migrate --force || falhar "migrações falharam. A versão anterior continua em serviço; ver docs/PRODUCAO.md › Rollback."

registar "5/6 recriar serviços"
prod up -d --wait --remove-orphans
# Uma tarefa withoutOverlapping (ex.: erp:agt:ciclo) interrompida pela paragem do scheduler antigo deixaria o
# mutex no Redis até 24 h; o scheduler novo acabou de arrancar, por isso limpam-se os mutexes agora.
prod exec -T scheduler php artisan schedule:clear-cache || true

if [ "$MANUTENCAO" = "1" ]; then
    prod exec -T app php artisan up || true
fi

registar "6/6 verificação de saúde"
i=0
until prod exec -T web wget -q -O - http://127.0.0.1:8080/api/saude 2>/dev/null | grep -q '"estado":"OK"'; do
    i=$((i + 1))
    [ "$i" -lt 30 ] || falhar "/api/saude não ficou OK em 60 s — ver: ferramentas/operacao/prod.sh logs --tail=200 app web"
    sleep 2
done
printf '%s %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$ERP_VERSAO" >> "$RAIZ/.versoes-implantadas"
registar "concluído: versão $ERP_VERSAO em serviço"

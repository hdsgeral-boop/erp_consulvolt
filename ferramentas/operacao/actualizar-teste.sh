#!/bin/sh
# =============================================================================================
# ERP_CONSULVOLT — actualização do servidor de TESTE (teste.consulvolt.meuerp.ao, t3.micro sem memória para
# construir imagens): as imagens app/web/copias são construídas NOUTRA máquina, enviadas para o S3 e carregadas aqui.
#
#   sh /opt/erp/ferramentas/operacao/actualizar-teste.sh <versao> <url_codigo.tar.gz> <url_imagens.tar>
#
#   <versao>        etiqueta das imagens (ERP_VERSAO), ex.: teste-20261102
#   <url_codigo>    URL (pré-assinado do S3) do tar.gz do repositório (git archive + frontend/dist opcional)
#   <url_imagens>   URL (pré-assinado do S3) do «docker save» das imagens erp_consulvolt/{app,web,copias}:<versao>
#
# Preserva no servidor: .env.prod*, .segredos, docker-compose.teste.yml, NOTA-DEPLOY-TESTE.md, .versoes-implantadas.
# Depois: ERP_VERSAO=<versao> no .env.prod e deploy.sh --sem-build (cópia de segurança, migrações, recriação, saúde),
# com os limites de memória de docker-compose.teste.yml (ERP_COMPOSE_EXTRA).
# =============================================================================================
set -eu
VERSAO=${1:?versao}; URL_CODIGO=${2:?url do codigo}; URL_IMAGENS=${3:?url das imagens}
DESTINO=/opt/erp
export ERP_COMPOSE_EXTRA="-f $DESTINO/docker-compose.teste.yml"
registar() { printf '%s [teste] %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$*"; }
falhar() { registar "ERRO: $*"; exit 1; }
case "$VERSAO" in *[!A-Za-z0-9._-]*|"") falhar "versão inválida: $VERSAO" ;; esac

TMP=$(mktemp -d /tmp/erp-teste.XXXXXX)
trap 'rm -rf "$TMP"' EXIT

registar "1/5 a descarregar código e imagens"
curl -fsSL -o "$TMP/codigo.tar.gz" "$URL_CODIGO" || falhar "não foi possível descarregar o código."
curl -fsSL -o "$TMP/imagens.tar" "$URL_IMAGENS" || falhar "não foi possível descarregar as imagens."
mkdir -p "$TMP/codigo" && tar -xzf "$TMP/codigo.tar.gz" -C "$TMP/codigo"
[ -f "$TMP/codigo/docker-compose.prod.yml" ] || falhar "o pacote não tem docker-compose.prod.yml."

registar "2/5 docker load"
docker load -i "$TMP/imagens.tar" | sed 's/^/   /'
for img in app web copias; do
    docker image inspect "erp_consulvolt/$img:$VERSAO" >/dev/null 2>&1 || falhar "imagem erp_consulvolt/$img:$VERSAO não está no pacote."
done

registar "3/5 código em $DESTINO (cópia do anterior em /opt/erp-releases)"
mkdir -p /opt/erp-releases
tar -czf "/opt/erp-releases/anterior-$(date '+%Y%m%d-%H%M%S').tar.gz" --exclude=./frontend/node_modules --exclude=./backend/vendor -C "$DESTINO" . 2>/dev/null || true
find /opt/erp-releases -maxdepth 1 -name 'anterior-*.tar.gz' -printf '%T@ %p\n' | sort -rn | tail -n +3 | cut -d' ' -f2- | xargs -r rm -f   # só as 2 últimas cópias
rsync -a --delete \
    --exclude='/.env.prod' --exclude='/.env.prod.*' --exclude='/.segredos/' --exclude='/docker-compose.teste.yml' \
    --exclude='/NOTA-DEPLOY-TESTE.md' --exclude='/.versoes-implantadas' --exclude='/.claude/' --exclude='/copias/' \
    --exclude='/frontend/node_modules/' --exclude='/backend/vendor/' \
    "$TMP/codigo/" "$DESTINO/"
chown -R ubuntu:ubuntu "$DESTINO"
chmod 600 "$DESTINO/.env.prod"

registar "4/5 ERP_VERSAO=$VERSAO em .env.prod"
if grep -q '^ERP_VERSAO=' "$DESTINO/.env.prod"; then
    sed -i "s/^ERP_VERSAO=.*/ERP_VERSAO=$VERSAO/" "$DESTINO/.env.prod"
else
    printf 'ERP_VERSAO=%s\n' "$VERSAO" >> "$DESTINO/.env.prod"
fi

cd "$DESTINO"
# PostgreSQL ainda na versão 16? Migrar ANTES do deploy (o compose novo usa postgres:18 e um volume novo; um `up`
# directo arrancaria uma base vazia). O script faz pg_dump do contentor antigo e restaura no 18; o volume antigo fica.
ANTIGO=$(sh ferramentas/operacao/prod.sh ps -q postgres 2>/dev/null | head -1)
if [ -n "$ANTIGO" ] && docker exec "$ANTIGO" postgres --version 2>/dev/null | grep -qE ' 1[0-7]\.'; then
    registar "4b/5 PostgreSQL $(docker exec "$ANTIGO" postgres --version | sed 's/.*) //') detectado: migração para o 18"
    sh ferramentas/operacao/migrar-postgres18.sh --sem-confirmar --sem-arranque
fi

registar "5/5 deploy (sem build)"
sh ferramentas/operacao/deploy.sh --versao="$VERSAO" --sem-build
docker image prune -f >/dev/null 2>&1 || true
registar "concluído: versão $VERSAO em teste"

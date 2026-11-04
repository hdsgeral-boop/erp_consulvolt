#!/bin/bash
# Instalação (ou reinstalação) do MEU ERP no servidor AWS (EC2 + RDS). Corre como root, via SSM:
#   bash /opt/erp/deploy/aws/instalar.sh <bucket> <endpoint-rds> [--restaurar]
# - descarrega o pacote do código (erp.tar.gz) do bucket e extrai-o para /opt/erp (preserva o .env da raiz);
# - cria o .env da raiz na primeira vez: palavra-passe da RDS lida do SSM Parameter Store, erp_app e Redis gerados aqui;
# - com --restaurar: cria a role erp_app e restaura erp_aws.dump na RDS (só numa base vazia);
# - constrói as imagens, instala as dependências, aplica migrações e a RLS, faz cache e arranca os serviços.
set -euo pipefail
BUCKET=$1; RDS=$2; RESTAURAR=${3:-}
REGIAO=eu-north-1
cd /opt/erp

echo "== pacote"
aws s3 cp "s3://$BUCKET/erp.tar.gz" /tmp/erp.tar.gz --only-show-errors
tar xzf /tmp/erp.tar.gz -C /opt/erp
chown -R ubuntu:ubuntu /opt/erp

MASTER=$(aws ssm get-parameter --region $REGIAO --name /meuerp/rds/master_password --with-decryption --query Parameter.Value --output text)
if [ ! -f .env ]; then
  echo "== .env da raiz (novo)"
  gerar() { tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "$1"; }
  cat > .env <<EOF
# Gerado por deploy/aws/instalar.sh — servidor AWS independente (sem túnel)
POSTGRES_DB=erp_consulvolt
POSTGRES_USER=erp_utilizador
POSTGRES_PASSWORD=$MASTER
DB_HOST_RDS=$RDS
REDIS_PASSWORD=$(gerar 40)
WEB_PORTA=8080
DB_APP_USERNAME=erp_app
DB_APP_PASSWORD=$(gerar 40)
SAAS_ATIVO=true
SAAS_LIGACAO_DONO=pgsql_owner
PHP_OPCACHE_VALIDAR=0
# Domínio (quando existir): nomes separados por vírgula; o Caddy tira o certificado HTTPS de cada um
CADDY_DOMINIOS=
EOF
  chmod 600 .env
fi
set -a; . ./.env; set +a

# backend/.env legível pelo PHP-FPM (www-data, gid 82) — docs/DEPLOY_AWS.md
chgrp 82 backend/.env && chmod 640 backend/.env

PSQL="docker run --rm -i -e PGPASSWORD=$MASTER -e PGSSLMODE=require -v /tmp:/tmp postgres:16-alpine"
if [ "$RESTAURAR" = "--restaurar" ]; then
  echo "== restauro na RDS"
  aws s3 cp "s3://$BUCKET/erp_aws.dump" /tmp/erp_aws.dump --only-show-errors
  TABELAS=$($PSQL psql -h "$RDS" -U erp_utilizador -d erp_consulvolt -At -c "select count(*) from pg_tables where schemaname='public'")
  if [ "$TABELAS" != "0" ]; then echo "A base na RDS já tem $TABELAS tabelas: restauro recusado."; exit 1; fi
  $PSQL psql -h "$RDS" -U erp_utilizador -d erp_consulvolt -v ON_ERROR_STOP=1 -c "DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='erp_app') THEN CREATE ROLE erp_app LOGIN; END IF; END \$\$; ALTER ROLE erp_app PASSWORD '$DB_APP_PASSWORD';"
  $PSQL pg_restore -h "$RDS" -U erp_utilizador -d erp_consulvolt --no-privileges --jobs 2 /tmp/erp_aws.dump 2> /tmp/restauro.err || true
  echo "erros do restauro: $(grep -c 'error' /tmp/restauro.err || true)"; grep -i 'error' /tmp/restauro.err | head -20 || true
fi

echo "== imagens e dependências"
C="docker compose -f docker-compose.yml -f docker-compose.aws.yml"
$C build app
$C up -d redis
$C run --rm --no-deps app composer install --no-dev --optimize-autoloader --no-interaction --no-progress
echo "== migrações, RLS e cache"
$C run --rm app php artisan migrate --database=pgsql_owner --force
$C run --rm app php artisan erp:saas:aplicar-rls
$C up -d app worker scheduler web caddy
$C exec -T app php artisan optimize:clear
$C exec -T app php artisan optimize
$C exec -T app php artisan erp:empresas:invalidar-cache
$C exec -T app php artisan erp:saas:aplicar-rls --verificar
$C restart app worker scheduler
$C ps

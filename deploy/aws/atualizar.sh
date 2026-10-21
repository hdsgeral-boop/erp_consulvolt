#!/bin/bash
# Actualiza o MEU ERP no servidor AWS a partir de um pacote do CI (s3://<bucket>/deploy/<sha>.tar.gz). Corre como root,
# pelo documento SSM «MeuErp-Atualizar» (o GitHub Actions só pode correr este script, com o sha como parâmetro).
#   bash /opt/erp/deploy/aws/atualizar.sh <sha>
# Preserva: .env e backend/.env, backend/storage, backend/bootstrap/cache, .segredos e os activos antigos do frontend
# (quem tem a página aberta continua a carregar os ficheiros com hash antigos). Se a saúde falhar no fim, repõe o
# código anterior e reinicia (as migrações não se desfazem: a RDS tem recuperação para qualquer minuto dos últimos 7 dias).
set -euo pipefail
SHA=$1
[[ "$SHA" =~ ^[0-9a-f]{7,40}$ ]] || { echo "sha inválido"; exit 2; }
BUCKET=meuerp-transferencia-962367498111
cd /opt/erp
C="docker compose -f docker-compose.yml -f docker-compose.aws.yml"
EXCLUIR=(--exclude=/.env --exclude=/backend/.env --exclude=/backend/storage/ --exclude=/backend/bootstrap/cache/ --exclude=/.segredos/ --exclude=/backend/vendor/ --exclude=/frontend/dist/ --exclude=/deploy/cloudflared/)

echo "== pacote $SHA"
rm -rf /tmp/erp-novo && mkdir -p /tmp/erp-novo
aws s3 cp "s3://$BUCKET/deploy/$SHA.tar.gz" /tmp/erp-novo.tar.gz --only-show-errors
tar xzf /tmp/erp-novo.tar.gz -C /tmp/erp-novo

echo "== cópia do código actual"
mkdir -p /opt/erp-releases
tar czf /opt/erp-releases/anterior.tar.gz --exclude=./backend/storage --exclude=./backend/vendor --exclude=./node_modules -C /opt/erp .

echo "== código novo"
rsync -a --delete "${EXCLUIR[@]}" /tmp/erp-novo/ /opt/erp/
rsync -a /tmp/erp-novo/frontend/dist/ /opt/erp/frontend/dist/
chown -R ubuntu:ubuntu /opt/erp
chgrp 82 backend/.env && chmod 640 backend/.env

repor() {
  echo "!! a repor o código anterior"
  tar xzf /opt/erp-releases/anterior.tar.gz -C /opt/erp
  $C up -d app worker scheduler web caddy
  $C exec -T app php artisan optimize >/dev/null 2>&1 || true
  $C restart app worker scheduler
}
trap 'repor; exit 1' ERR

echo "== imagens, dependências, migrações"
$C build app
$C run --rm --no-deps app composer install --no-dev --optimize-autoloader --no-interaction --no-progress
$C run --rm app php artisan migrate --database=pgsql_owner --force
$C run --rm app php artisan erp:saas:aplicar-rls

echo "== arranque"
$C up -d app worker scheduler web caddy
$C exec -T app php artisan optimize:clear >/dev/null
$C exec -T app php artisan optimize
$C exec -T app php artisan erp:empresas:invalidar-cache
$C restart app worker scheduler

echo "== saúde"
for i in $(seq 1 20); do
  sleep 6
  if curl -fsS -o /dev/null http://127.0.0.1/api/saude; then
    echo "OK: $SHA em produção"; rm -rf /tmp/erp-novo /tmp/erp-novo.tar.gz; trap - ERR; exit 0
  fi
done
echo "a saúde não respondeu"
false

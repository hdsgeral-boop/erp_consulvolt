#!/bin/sh
# =============================================================================================
# ERP_CONSULVOLT — liga o servidor de TESTE ao bucket Cloudflare R2 das imagens (logótipos, imagens de produtos).
# Corre NO SERVIDOR (via SSM ou ssh), depois de o R2 estar activado no painel Cloudflare e de existir o bucket, o
# acesso público (r2.dev ou domínio próprio) e um token «Object Read & Write» limitado ao bucket (docs/TESTE_AWS.md).
#
#   R2_BUCKET=erp-imagens R2_ENDPOINT=https://<conta>.r2.cloudflarestorage.com R2_URL_PUBLICA=https://pub-<id>.r2.dev \
#   R2_ACCESS_KEY_ID=... R2_SECRET_ACCESS_KEY=... sh /opt/erp/ferramentas/operacao/activar-r2-teste.sh [--migrar]
#
# 1. grava as variáveis em /opt/erp/.env.prod (ERP_IMAGENS_DISCO=imagens) com cópia de segurança do ficheiro;
# 2. recria app/worker/scheduler (a cache de configuração é refeita pelo entrypoint) e espera pela saúde;
# 3. testa escrita/leitura/apagamento no bucket a partir do contentor;
# 4. corre erp:imagens:migrar --simular; com --migrar corre também a migração real.
# Se o teste do bucket falhar, repõe o .env.prod anterior e recria os serviços (o ERP volta ao modo antigo).
# =============================================================================================
set -eu
DESTINO=/opt/erp
ENV="$DESTINO/.env.prod"
APP=erp_consulvolt_prod-app-1
export ERP_COMPOSE_EXTRA="-f $DESTINO/docker-compose.teste.yml"
registar() { printf '%s [r2] %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$*"; }
falhar() { registar "ERRO: $*"; exit 1; }

MIGRAR=0
[ "${1:-}" = "--migrar" ] && MIGRAR=1
for v in R2_BUCKET R2_ENDPOINT R2_URL_PUBLICA R2_ACCESS_KEY_ID R2_SECRET_ACCESS_KEY; do
    eval "val=\${$v:-}"
    [ -n "$val" ] || falhar "falta a variável $v."
    case "$val" in *[\'\"\\\$\#]*) falhar "o valor de $v não pode ter aspas, barras invertidas, \$ nem #." ;; esac
done
case "$R2_ENDPOINT" in https://*.r2.cloudflarestorage.com) ;; *) falhar "R2_ENDPOINT deve ser https://<conta>.r2.cloudflarestorage.com" ;; esac
case "$R2_URL_PUBLICA" in https://*) ;; *) falhar "R2_URL_PUBLICA deve começar por https://" ;; esac
R2_URL_PUBLICA=${R2_URL_PUBLICA%/}
[ -f "$ENV" ] || falhar "não existe $ENV."
cd "$DESTINO"

definir() { # definir CHAVE VALOR — substitui (ou acrescenta) a linha no .env.prod
    grep -v "^$1=" "$ENV" > "$ENV.novo" || true
    printf "%s='%s'\n" "$1" "$2" >> "$ENV.novo"
    cat "$ENV.novo" > "$ENV" && rm -f "$ENV.novo"
}

COPIA="$ENV.antes-r2-$(date '+%Y%m%d-%H%M%S')"
cp -p "$ENV" "$COPIA"
chmod 600 "$COPIA"
registar "1/4 variáveis R2 em .env.prod (cópia em $COPIA)"
definir ERP_IMAGENS_DISCO imagens
definir R2_BUCKET "$R2_BUCKET"
definir R2_ENDPOINT "$R2_ENDPOINT"
definir R2_URL_PUBLICA "$R2_URL_PUBLICA"
definir R2_ACCESS_KEY_ID "$R2_ACCESS_KEY_ID"
definir R2_SECRET_ACCESS_KEY "$R2_SECRET_ACCESS_KEY"
chmod 600 "$ENV"

recriar() { sh "$DESTINO/ferramentas/operacao/prod.sh" up -d --force-recreate --wait app worker scheduler; }
repor() {
    registar "a repor o .env.prod anterior e a recriar os serviços"
    cat "$COPIA" > "$ENV"
    recriar >/dev/null 2>&1 || true
}

registar "2/4 a recriar app/worker/scheduler"
recriar || { repor; falhar "os serviços não ficaram saudáveis com a configuração R2."; }

registar "3/4 teste de escrita/leitura no bucket $R2_BUCKET"
# PHP lido do stdin (sem depender do tinker, que não vem na imagem de produção): escreve, lê, obtém o URL e apaga.
TESTE=$(docker exec -i "$APP" php 2>&1 <<'PHP' | tail -n 1
<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $d = Illuminate\Support\Facades\Storage::disk('imagens');
    $c = 'sistema/teste-ligacao-'.bin2hex(random_bytes(4)).'.txt';
    $d->put($c, 'ok '.date('c'), ['ContentType' => 'text/plain']);
    $ok = $d->get($c) !== null;
    $url = $d->url($c);
    $d->delete($c);
    echo $ok ? "OK $url" : 'FALHOU: leitura devolveu vazio', PHP_EOL;
} catch (Throwable $e) {
    echo 'FALHOU: ', get_class($e), ' — ', $e->getMessage(), PHP_EOL;
}
PHP
) || true
case "$TESTE" in
    OK\ *) registar "bucket operacional; URL público de exemplo: ${TESTE#OK }" ;;
    *) repor; falhar "o contentor não conseguiu escrever no bucket: $TESTE" ;;
esac

registar "4/4 migração das imagens existentes"
docker exec "$APP" php artisan erp:imagens:migrar --simular
if [ "$MIGRAR" = 1 ]; then
    docker exec "$APP" php artisan erp:imagens:migrar
else
    registar "simulação apenas; para migrar: docker exec $APP php artisan erp:imagens:migrar"
fi
registar "concluído: imagens públicas em $R2_URL_PUBLICA"

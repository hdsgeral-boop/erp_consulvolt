#!/bin/sh
# Entrypoint da imagem Render: valida o ambiente, gera a configuração do nginx para $PORT, aquece as caches
# do Laravel com as variáveis do painel, aplica as migrações no Supabase e entrega o controlo ao supervisord.
set -eu

cd /var/www/html

falta=""
for v in APP_KEY DB_HOST DB_USERNAME DB_PASSWORD; do
    eval "valor=\${$v:-}"
    [ -z "$valor" ] && falta="$falta $v"
done
if [ -n "$falta" ]; then
    echo "ERRO: variáveis obrigatórias por definir no painel da Render:$falta (ver docs/RENDER.md)." >&2
    exit 1
fi

PORT="${PORT:-10000}"
case "$PORT" in
    ''|*[!0-9]*) echo "ERRO: PORT inválida: '$PORT'." >&2; exit 1 ;;
esac

# Configuração de runtime (o sistema de ficheiros da Render é efémero; tudo é recriado em cada arranque).
mkdir -p /tmp/erp-render/nginx/corpo /tmp/erp-render/nginx/proxy /tmp/erp-render/nginx/fastcgi \
         /tmp/erp-render/nginx/uwsgi /tmp/erp-render/nginx/scgi
sed "s/__PORT__/${PORT}/g" /etc/erp-render/nginx.conf.template > /tmp/erp-render/nginx.conf
cp /etc/erp-render/supervisord.conf /tmp/erp-render/supervisord.conf

mkdir -p storage/framework/cache/data storage/framework/views storage/framework/sessions \
         storage/framework/testing storage/logs storage/app/private storage/app/public bootstrap/cache

# Caches do Laravel no arranque (config:cache grava os valores das variáveis: nunca pode correr no build).
php artisan config:cache --no-ansi -q
php artisan route:cache --no-ansi -q
php artisan event:cache --no-ansi -q
# A API não tem vistas próprias (resources/views não existe): o view:cache falharia nesse caso.
if [ -d resources/views ]; then
    php artisan view:cache --no-ansi -q
fi

if [ "${ERP_MIGRAR_NO_ARRANQUE:-1}" = "1" ]; then
    echo "A aplicar migrações na base de dados (${DB_HOST})..."
    # Várias tentativas: o pooler do Supabase pode demorar a aceitar a primeira ligação após um arranque a frio.
    tentativa=1
    until php artisan migrate --force --no-ansi; do
        if [ "$tentativa" -ge 5 ]; then
            echo "ERRO: as migrações falharam após $tentativa tentativas." >&2
            exit 1
        fi
        tentativa=$((tentativa + 1))
        echo "Nova tentativa de migração ($tentativa/5) dentro de 5 s..." >&2
        sleep 5
    done
fi

nginx -e stderr -t -q -c /tmp/erp-render/nginx.conf

exec "$@"

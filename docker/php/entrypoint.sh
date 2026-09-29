#!/bin/sh
# Garante que o PHP-FPM (www-data) consegue escrever nos directórios de runtime do Laravel.
# Necessário com código montado por volume a partir do Windows (os ficheiros aparecem como root:root 755).
set -e
if [ -d /var/www/html/storage ]; then
    mkdir -p /var/www/html/storage/framework/cache/data /var/www/html/storage/framework/views \
             /var/www/html/storage/framework/sessions /var/www/html/storage/logs /var/www/html/bootstrap/cache
    chmod -R ug+rwX,o+rwX /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
fi
exec docker-php-entrypoint "$@"

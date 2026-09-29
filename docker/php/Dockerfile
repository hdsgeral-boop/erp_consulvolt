# Imagem PHP 8.3-FPM do backend ERP_CONSULVOLT (usada pelos serviços `app` e `worker`).
FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-libs libzip libpq postgresql-client tzdata \
 && apk add --no-cache --virtual .deps-build $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip bcmath opcache pcntl \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .deps-build \
 && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-erp.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-erp.conf
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/erp-entrypoint

ENV TZ=Africa/Luanda
WORKDIR /var/www/html
ENTRYPOINT ["erp-entrypoint"]
CMD ["php-fpm"]

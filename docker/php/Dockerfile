# Imagem PHP 8.5-FPM do backend ERP_CONSULVOLT (usada pelos serviços `app`, `worker`, `scheduler` e `app_e2e`).
# As extensões são instaladas com o instalador oficial da comunidade (mlocati/docker-php-extension-installer):
# resolve as dependências do Alpine, compila e remove as ferramentas de build num só passo.
# O phpredis compila-se a partir do código-fonte no GitHub (o PECL deixou de publicar versões em 2026).
FROM php:8.5-fpm-alpine

ARG PHPREDIS_VERSAO=6.3.0
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN apk add --no-cache postgresql-client tzdata \
 && install-php-extensions pdo_pgsql pgsql intl zip bcmath opcache pcntl gd
RUN apk add --no-cache --virtual .deps-build $PHPIZE_DEPS \
 && wget -qO- "https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSAO}.tar.gz" | tar xz -C /tmp \
 && cd "/tmp/phpredis-${PHPREDIS_VERSAO}" && phpize && ./configure && make -j"$(nproc)" && make install \
 && docker-php-ext-enable redis \
 && apk del .deps-build && rm -rf /tmp/phpredis-* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-erp.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-erp.conf
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/erp-entrypoint

ENV TZ=Africa/Luanda
WORKDIR /var/www/html
ENTRYPOINT ["erp-entrypoint"]
CMD ["php-fpm"]

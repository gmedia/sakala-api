# syntax=docker/dockerfile:1.7

FROM php:8.5-fpm-alpine AS php-base

RUN apk add --no-cache icu-libs libpq libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev libzip-dev linux-headers \
    && docker-php-ext-install bcmath intl pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

WORKDIR /var/www/html

COPY docker/php/conf.d/zz-sakala.ini "$PHP_INI_DIR/conf.d/zz-sakala.ini"

FROM php-base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts \
    --optimize-autoloader

COPY . .

RUN composer dump-autoload \
    --no-dev \
    --no-interaction \
    --classmap-authoritative

FROM php-base AS app

COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /var/www/html/vendor ./vendor

RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage

USER www-data

EXPOSE 9000

CMD ["php-fpm", "-F"]

FROM nginxinc/nginx-unprivileged:1.29-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/up >/dev/null 2>&1 || exit 1

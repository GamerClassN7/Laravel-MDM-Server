# syntax=docker/dockerfile:1

# Single Alpine base for all stages: small, and only one image to pull.
ARG ALPINE_VERSION=3.22

FROM alpine:${ALPINE_VERSION} AS php

# Only the extensions the application needs (see `composer check-platform-reqs --no-dev`).
RUN apk add --no-cache \
        php84 \
        php84-ctype \
        php84-curl \
        php84-dom \
        php84-fileinfo \
        php84-iconv \
        php84-mbstring \
        php84-opcache \
        php84-openssl \
        php84-pcntl \
        php84-pdo_mysql \
        php84-pdo_sqlite \
        php84-posix \
        php84-session \
        php84-simplexml \
        php84-tokenizer \
        php84-xml \
        php84-xmlwriter \
    && ln -s /usr/bin/php84 /usr/bin/php

WORKDIR /var/www


FROM php AS vendor

RUN apk add --no-cache composer

COPY src/laravel/composer.json src/laravel/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY src/laravel/ ./
RUN composer dump-autoload --no-dev --optimize --no-scripts --no-interaction


FROM alpine:${ALPINE_VERSION} AS assets

RUN apk add --no-cache nodejs npm

WORKDIR /build
COPY src/laravel/package.json src/laravel/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY src/laravel/vite.config.js ./
COPY src/laravel/resources ./resources
RUN npm run build


FROM php AS runtime

RUN apk add --no-cache nginx php84-fpm \
    && adduser -D -H -u 1000 -s /sbin/nologin app \
    && mkdir -p /run/nginx /var/lib/nginx/tmp /var/log/nginx \
    && chown -R app:app /run/nginx /var/lib/nginx /var/log/nginx

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/php/php.ini /etc/php84/conf.d/zz-app.ini
COPY docker/php/fpm.conf /etc/php84/php-fpm.d/www.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

COPY --from=vendor --chown=app:app /var/www ./
COPY --from=assets --chown=app:app /build/public/build ./public/build

RUN rm -rf tests .env .env.example phpunit.xml \
    && php artisan package:discover --ansi \
    && ln -sfn /var/www/storage/app/public public/storage \
    && chown -R app:app bootstrap/cache storage

USER app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    CONTAINER_ROLE=app

VOLUME ["/var/www/storage"]
EXPOSE 8000 8080

ENTRYPOINT ["entrypoint"]

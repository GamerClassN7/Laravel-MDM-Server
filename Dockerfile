FROM php:8.4 AS php

RUN apt-get update -y
RUN apt-get install -y unzip libpq-dev libcurl4-gnutls-dev
RUN docker-php-ext-install pdo pdo_mysql bcmath

WORKDIR /var/www
COPY src/laravel/ .
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

ENV PORT=8000
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENTRYPOINT ["entrypoint.sh"]

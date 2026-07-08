FROM php:8.2-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libpq-dev \
        postgresql-client \
        unzip \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p var/cache var/log var/share \
    && chmod -R 777 var \
    && chmod +x bin/render-init-db.sh docker/php/entrypoint.sh

COPY docker/php/entrypoint.sh /usr/local/bin/cnb-entrypoint
RUN chmod +x /usr/local/bin/cnb-entrypoint

ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV PORT=8000

EXPOSE 8000

ENTRYPOINT ["cnb-entrypoint"]
CMD []

FROM php:8.4-cli

# pdo_mysql: row locks, NOWAIT and generated columns are the point of this app.
# bcmath: integer minor-unit money arithmetic. zip: composer archive extraction.
# curl/unzip/git: composer + the compose healthcheck.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /var/www

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

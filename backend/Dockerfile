# syntax=docker/dockerfile:1.7
# One image for app (nginx + PHP-FPM), horizon, scheduler and reverb (docs/09-infrastructure/docker.md).
ARG PHP_IMAGE=serversideup/php:8.5.10-fpm-nginx-trixie

# ---- base: PHP extensions and tools shared by every stage ----
FROM ${PHP_IMAGE} AS base
USER root
RUN install-php-extensions intl gd bcmath sockets uv \
 && apt-get update \
 && apt-get install -y --no-install-recommends postgresql-client procps \
 && rm -rf /var/lib/apt/lists/*
ENV HEALTHCHECK_PATH=/up \
    AUTORUN_ENABLED=false \
    SHOW_WELCOME_MESSAGE=false \
    LOG_CHANNEL=stderr
WORKDIR /var/www/html

# ---- development: code is bind-mounted; the container user matches the host user ----
FROM base AS development
ARG USER_ID=1000
ARG GROUP_ID=1000
RUN docker-php-serversideup-set-id www-data ${USER_ID}:${GROUP_ID} \
 && docker-php-serversideup-set-file-permissions --owner ${USER_ID}:${GROUP_ID} --service nginx
ENV PHP_OPCACHE_ENABLE=0
USER www-data

# ---- vendor: production dependencies only ----
FROM base AS vendor
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---- production ----
FROM base AS production
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
 && rm -f .env
USER www-data

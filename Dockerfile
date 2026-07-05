# Combined nginx + php-fpm image for single-container platforms (Railway,
# Fly.io). Local dev keeps using docker-compose.yaml (separate nginx/php
# containers); this exists because those platforms run one process tree per
# service, so nginx and php-fpm are supervised together here instead.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs

FROM php:7.4.33-fpm

RUN apt-get update && apt-get install -y --no-install-recommends nginx \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_mysql \
    && docker-php-ext-install mysqli && docker-php-ext-enable mysqli

COPY docker/nginx.fly.conf /etc/nginx/sites-enabled/default
COPY docker/fly-entrypoint.sh /fly-entrypoint.sh
RUN chmod +x /fly-entrypoint.sh

WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor /app/vendor

# public/index.php dies if this file doesn't exist at all, regardless of
# whether real config actually comes from it. phpdotenv only fills in vars
# that aren't already set in the process environment, so an empty file here
# satisfies that check without overriding the real secrets the platform
# (Fly/Railway) injects as actual env vars.
RUN touch .env

EXPOSE 8080
CMD ["/fly-entrypoint.sh"]

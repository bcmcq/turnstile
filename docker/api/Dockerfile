# Shared image for api, worker and publisher. The command differs per compose service.
FROM dunglas/frankenphp:1-php8.5

RUN install-php-extensions pdo_mysql redis intl opcache zip pcntl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=dev \
    SERVER_NAME=:8080

WORKDIR /app

COPY apps/api/composer.json apps/api/composer.lock apps/api/symfony.lock ./
RUN composer install --no-scripts --no-progress --prefer-dist --no-autoloader

COPY apps/api/ ./
RUN composer dump-autoload --optimize \
 && composer run-script --no-interaction post-install-cmd || true

COPY docker/api/Caddyfile /etc/caddy/Caddyfile
COPY docker/api/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

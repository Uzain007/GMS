FROM php:8.3-fpm-alpine

RUN apk add --no-cache caddy supervisor icu-dev libzip-dev postgresql-dev $PHPIZE_DEPS \
    && docker-php-ext-install bcmath intl opcache pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/supervisord.conf /etc/supervisord.conf
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p /tmp/caddy/data /tmp/caddy/config /tmp/supervisor \
    && chown -R www-data:www-data storage bootstrap/cache /tmp/caddy /tmp/supervisor
USER www-data

# Railway injects PORT at runtime. Caddy terminates the public HTTP connection
# and forwards only Laravel requests to the existing private PHP-FPM listener.
EXPOSE 8000
CMD ["supervisord", "-c", "/etc/supervisord.conf"]

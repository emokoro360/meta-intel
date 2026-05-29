FROM php:8.3-fpm-alpine AS base

LABEL maintainer="MetaIntel Platform"

RUN apk add --no-cache \
    git curl libpng-dev libxml2-dev zip unzip libzip-dev \
    oniguruma-dev postgresql-dev icu-dev imagemagick-dev \
    exiftool supervisor \
    && docker-php-ext-install pdo pdo_pgsql mbstring exif pcntl bcmath gd zip intl opcache \
    && pecl install redis imagick \
    && docker-php-ext-enable redis imagick

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY laravel-api/composer.json ./
RUN composer install --no-scripts --no-autoloader --no-dev --prefer-dist

COPY laravel-api/ .
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# ─── Production stage ───────────────────────────────────────────────────────
FROM base AS production
COPY infrastructure/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY infrastructure/php/www.conf /usr/local/etc/php-fpm.d/www.conf
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache
EXPOSE 9000
CMD ["php-fpm"]

# ─── Worker stage ────────────────────────────────────────────────────────────
FROM base AS worker
COPY infrastructure/supervisor/worker.conf /etc/supervisor/conf.d/worker.conf
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]

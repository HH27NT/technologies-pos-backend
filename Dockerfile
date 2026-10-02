# syntax=docker/dockerfile:1

###############################################################################
# Imagen de producción/desarrollo para el backend (API) del SaaS POS.
# PHP 8.2 FPM + extensiones requeridas por Laravel 12, PostgreSQL, dompdf y
# Laravel Excel (phpspreadsheet: ext-gd y ext-zip son obligatorias).
###############################################################################
FROM php:8.2-fpm-bookworm AS base

# --- Dependencias de sistema -------------------------------------------------
# libpq   -> pdo_pgsql / pgsql   | libzip -> zip (Laravel Excel)
# libpng/jpeg/freetype -> gd     | icu    -> intl   | oniguruma -> mbstring
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev libonig-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        postgresql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pgsql bcmath intl mbstring gd zip opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# --- Composer (copiado desde la imagen oficial) ------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configuración de PHP (opcache + límites de subida para exportaciones)
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# --- Dependencias PHP (capa cacheable) ---------------------------------------
# Se copian solo los manifiestos para aprovechar la caché de capas de Docker.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# --- Código de la aplicación -------------------------------------------------
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/exportaciones storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Entrypoint: espera a la BD, migra, siembra (idempotente) y cachea configuración.
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

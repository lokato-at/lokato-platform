# syntax=docker/dockerfile:1.7
#
# Lokato Laravel PHP-FPM. Multi-Arch (linux/amd64 + linux/arm64) durch
# php:8.3-fpm-alpine als Base — beide Plattformen sind im offiziellen Manifest.

FROM php:8.3-fpm-alpine

# Build-Dependencies fuer PHP-Extensions
RUN apk add --no-cache --virtual .build-deps \
        autoconf \
        g++ \
        make \
        linux-headers \
        libzip-dev \
        oniguruma-dev \
        icu-dev \
    && apk add --no-cache \
        git \
        unzip \
        libzip \
        oniguruma \
        icu-libs \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        bcmath \
        sockets \
        opcache \
        pcntl \
        exif \
        zip \
    && apk del .build-deps

# Composer fuer Laufzeit-Aufrufe (composer install in Containern, etc.)
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

COPY php-overrides.ini /usr/local/etc/php/conf.d/zz-lokato.ini

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]

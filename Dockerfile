FROM php:8.3-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libonig-dev \
        unzip git default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring gd zip opcache \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . /var/www/html

RUN chown -R www-data:www-data storage config 2>/dev/null || true

EXPOSE 9000

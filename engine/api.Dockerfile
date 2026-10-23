FROM php:8.3-cli AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
    libssl-dev libonig-dev libxml2-dev unzip git \
    && docker-php-ext-install mbstring dom \
    && pecl install mongodb-2.5.5 \
    && docker-php-ext-enable mongodb \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress
COPY apps/api/ ./
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

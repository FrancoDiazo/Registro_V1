FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip \
    && docker-php-ext-install pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts

COPY . .
RUN composer dump-autoload --optimize --no-interaction \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 8000

CMD ["sh", "-c", "php artisan migrate --force && exec php artisan serve --no-reload --host=0.0.0.0 --port=8000"]

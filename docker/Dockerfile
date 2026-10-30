# syntax=docker/dockerfile:1
FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libonig-dev libxml2-dev libzip-dev libicu-dev && docker-php-ext-install -j4 pdo_mysql mbstring dom xml xmlwriter zip intl && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]

FROM php:8.2-alpine

RUN apk add --no-cache libpq-dev && docker-php-ext-install pdo pdo_pgsql

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader

EXPOSE 80
CMD ["php", "-S", "0.0.0.0:80", "-t", "public"]

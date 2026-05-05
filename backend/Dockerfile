FROM php:8.2-fpm-alpine

# Instalar dependencias del sistema + extensiones PHP
RUN apk add --no-cache \
    curl \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Directorio de trabajo
WORKDIR /var/www/html

# Copiar archivos del proyecto
COPY . .

# Instalar dependencias PHP
RUN composer install --no-dev --optimize-autoloader

# Permisos de escritura para logs
RUN chown -R www-data:www-data /var/www/html/storage 2>/dev/null || true

EXPOSE 9000

CMD ["php-fpm"]

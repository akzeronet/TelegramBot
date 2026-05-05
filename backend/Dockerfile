# 1. Usamos una imagen ligera de PHP 8.2
FROM php:8.2-alpine

# 2. Instalar dependencias del sistema y extensiones necesarias para Postgres y el sistema
RUN apk add --no-cache \
    curl \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip

# 3. Instalar Composer (para gestionar las librerías)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. Configurar el directorio de trabajo
WORKDIR /app

# 5. Copiar los archivos del proyecto (desde la carpeta backend)
COPY . .

# 6. Instalar dependencias de producción
RUN composer install --no-dev --optimize-autoloader

# 7. Crear carpetas necesarias y dar permisos (opcional según el uso de logs)
RUN mkdir -p storage && chmod -R 777 storage

# 8. Exponer el puerto 80 para Easypanel
EXPOSE 80

# 9. Iniciar el servidor web incorporado de PHP sirviendo la carpeta 'public'
# Esto es ideal para microservicios y bots en Easypanel
CMD ["php", "-S", "0.0.0.0:80", "-t", "public"]

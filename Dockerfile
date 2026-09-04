FROM php:8.3-fpm-alpine

# 1. Instalar dependencias del sistema y la extensión PDO para MariaDB
RUN apk add --no-cache bash git unzip icu-dev libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev zlib-dev build-base \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install gd pdo_mysql zip \
  && rm -rf /var/cache/apk/*

# 2. Copiar Composer oficial desde su imagen
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 3. Exponer el puerto del servidor interno de PHP
EXPOSE 8000

# 4. Usar un script de arranque para instalar dependencias automáticamente
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]

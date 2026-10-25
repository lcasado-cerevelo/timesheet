# Entorno de desarrollo y pruebas de Tiempo: PHP 8.4 + Apache, parecido a un hosting Linux.
# La base de datos (MariaDB) y el correo de prueba (Mailpit) van en sus propios contenedores: ver ../docker-compose.yml
FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libicu-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli zip gd intl bcmath exif opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Apache sirve la carpeta public de Laravel
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Los logos subidos van en storage/app/public: Apache los sirve en /storage sin depender de un enlace simbólico de Windows
COPY docker/storage.conf /etc/apache2/conf-available/storage.conf
RUN a2enconf storage

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Zona horaria y límites parecidos a producción
RUN { echo "date.timezone=America/Puerto_Rico"; echo "memory_limit=512M"; echo "upload_max_filesize=8M"; echo "post_max_size=10M"; } > /usr/local/etc/php/conf.d/tiempo.ini

COPY docker/entrypoint.sh /usr/local/bin/tiempo-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/tiempo-entrypoint && chmod +x /usr/local/bin/tiempo-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["tiempo-entrypoint"]
CMD ["apache2-foreground"]

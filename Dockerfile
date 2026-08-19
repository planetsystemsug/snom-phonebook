FROM php:8.3-apache

# PDO SQLite and XMLWriter are compiled into the official PHP 8.3 image. Do
# not rebuild PDO SQLite here: compilation is unnecessary and may fail on NAS
# build environments with constrained memory.
RUN set -eux; \
    php -m | grep -qx 'pdo_sqlite'; \
    php -m | grep -qx 'xmlwriter'; \
    a2enmod rewrite; \
    sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html
RUN mkdir -p /data \
    && chown -R www-data:www-data /var/www/html /data

ENV APP_DATA_DIR=/data
EXPOSE 80

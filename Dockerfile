FROM php:8.3-apache

RUN docker-php-ext-install pdo_sqlite \
    && a2enmod rewrite \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html
RUN mkdir -p /data \
    && chown -R www-data:www-data /var/www/html /data

ENV APP_DATA_DIR=/data
EXPOSE 80


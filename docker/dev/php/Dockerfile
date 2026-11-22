FROM php:8.4-apache

# Installer les extensions du framework et de PHPUnit, ainsi que Composer.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libcurl4-openssl-dev libicu-dev libonig-dev libzip-dev libxml2-dev \
    && docker-php-ext-install -j"$(nproc)" curl intl mbstring mysqli pdo_mysql zip dom xml xmlwriter \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini /usr/local/etc/php/conf.d/development.ini
COPY check-writable.php /usr/local/lib/dev-check-writable.php
COPY entrypoint.sh /usr/local/bin/dev-entrypoint
RUN chmod +x /usr/local/bin/dev-entrypoint
ENTRYPOINT ["dev-entrypoint"]
CMD ["apache2-foreground"]
WORKDIR /var/www/html

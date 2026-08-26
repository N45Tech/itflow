FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
        mariadb-client \
        unzip \
        whois \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" curl gd intl mbstring mysqli xml zip \
    && a2enmod headers remoteip rewrite setenvif \
    && rm -rf /var/lib/apt/lists/*

COPY deploy/psa/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/psa/php-production.ini /usr/local/etc/php/conf.d/itflow-production.ini
COPY deploy/psa/container-entrypoint.sh /usr/local/bin/itflow-entrypoint
COPY . /var/www/html

RUN chmod 0755 /usr/local/bin/itflow-entrypoint \
    && install -d -m 0750 -o www-data -g www-data /usr/local/share/itflow-uploads /var/lib/itflow \
    && cp -a /var/www/html/uploads/. /usr/local/share/itflow-uploads/ \
    && rm -rf /var/www/html/uploads /var/www/html/config.php \
    && ln -s /var/lib/itflow/uploads /var/www/html/uploads \
    && ln -s /var/lib/itflow/config.php /var/www/html/config.php \
    && chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html

ENTRYPOINT ["itflow-entrypoint"]
CMD ["apache2-foreground"]


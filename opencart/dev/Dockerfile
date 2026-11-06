FROM php:8.3-apache@sha256:a800bbd2c0aa322702259ed475556b2cbc311076a8d3c4bba9bfe96838745472

RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg-dev libpng-dev libwebp-dev libzip-dev libonig-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd mbstring mysqli pdo_mysql zip \
    && a2enmod rewrite && a2dissite 000-default \
    && rm -rf /var/lib/apt/lists/*

COPY apache.conf /etc/apache2/conf-enabled/cmteh.conf
COPY php.ini /usr/local/etc/php/conf.d/cmteh.ini

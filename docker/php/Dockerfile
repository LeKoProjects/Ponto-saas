FROM php:8.3-fpm-alpine

RUN apk add --no-cache postgresql-dev libzip-dev icu-dev oniguruma-dev freetype-dev libpng-dev libjpeg-turbo-dev tzdata $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql pgsql zip intl mbstring pcntl gd bcmath opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

ENV TZ=America/Bahia
RUN cp /usr/share/zoneinfo/America/Bahia /etc/localtime && echo "date.timezone=America/Bahia" > /usr/local/etc/php/conf.d/tz.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM php:8.3-fpm-alpine

# System deps + PHP extensions
RUN apk add --no-cache \
    bash git curl unzip icu-dev oniguruma-dev postgresql-dev linux-headers \
    && docker-php-ext-install intl opcache bcmath pdo pdo_pgsql

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Opcjonalnie: Symfony CLI
RUN curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.alpine.sh' | bash \
    && apk add --no-cache symfony-cli || true

# Xdebug (opcjonalnie) - wymagany toolchain do pecl (autoconf, make, gcc itp.)
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del $PHPIZE_DEPS

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zzz-php.ini
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/zzz-xdebug.ini

COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]

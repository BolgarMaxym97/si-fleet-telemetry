FROM php:8.4-cli-alpine

# Runtime libs kept; build deps removed after compiling extensions.
RUN apk add --no-cache librdkafka-dev postgresql-dev git unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && pecl install rdkafka redis xdebug \
    && docker-php-ext-enable rdkafka redis xdebug \
    && docker-php-ext-install pdo pdo_pgsql pcntl \
    && apk del .build-deps

COPY docker/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

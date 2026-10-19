FROM php:8.4-fpm AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
      libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
      libonig-dev libicu-dev git unzip curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 pdo_mysql bcmath zip gd opcache mbstring intl pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY hongyue/composer.json hongyue/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-interaction --no-progress
COPY hongyue/ ./
COPY deploy/production/ops.php /opt/hongyue/ops.php
COPY deploy/production/php-entrypoint.sh /usr/local/bin/production-entrypoint
COPY hongyue/docker/php-fpm/php.ini /usr/local/etc/php/conf.d/hongyue.ini
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && rm -rf public/storage \
    && ln -s ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/production-entrypoint
USER www-data
ENTRYPOINT ["production-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS api
COPY --from=app /var/www/html/public /var/www/html/public
COPY deploy/production/nginx/api.conf /etc/nginx/conf.d/default.conf

FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    git \
    openssh-client \
    sqlite-dev \
    docker-cli \
    docker-cli-compose \
    && docker-php-ext-install pdo_sqlite pcntl posix \
    && mkdir -p /usr/local/lib/docker/cli-plugins \
    && ln -sf /usr/libexec/docker/cli-plugins/docker-compose /usr/local/lib/docker/cli-plugins/docker-compose \
    && mkdir -p /var/www/.docker \
    && echo '{}' > /var/www/.docker/config.json \
    && chown -R www-data:www-data /var/www/.docker

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && mkdir -p /data/ssh \
    && chmod 700 /data/ssh \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]

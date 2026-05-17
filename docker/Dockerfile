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
    && chmod a+rx /usr/libexec/docker/cli-plugins/docker-compose \
    && ln -sf /usr/libexec/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose \
    && mkdir -p /root/.docker \
    && echo '{}' > /root/.docker/config.json

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN echo "output_buffering = Off\nmax_execution_time = 0\nimplicit_flush = On" \
    > /usr/local/etc/php/conf.d/zzz-bridge.ini

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

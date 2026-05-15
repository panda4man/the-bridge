#!/bin/sh
set -e

mkdir -p "$(dirname "$DB_DATABASE")"
touch "$DB_DATABASE"

php /var/www/html/artisan migrate --force

if [ -z "$APP_KEY" ]; then
    php /var/www/html/artisan key:generate --force
fi

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

exec /usr/bin/supervisord -c /etc/supervisord.conf

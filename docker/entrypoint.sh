#!/bin/sh
set -e

# Ensure SQLite DB file exists and is owned by www-data
mkdir -p "$(dirname "$DB_DATABASE")"
touch "$DB_DATABASE"
chown www-data:www-data "$DB_DATABASE" "$(dirname "$DB_DATABASE")"

# Clear and rebuild config cache with current env vars
su -s /bin/sh www-data -c "php /var/www/html/artisan config:clear && php /var/www/html/artisan config:cache"

# Run migrations as www-data
su -s /bin/sh www-data -c "php /var/www/html/artisan migrate --force"

# Fix storage permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

exec /usr/bin/supervisord -c /etc/supervisord.conf

#!/bin/sh
set -e

# Ensure SQLite DB file exists and is owned by www-data
mkdir -p "$(dirname "$DB_DATABASE")"
touch "$DB_DATABASE"
chown www-data:www-data "$DB_DATABASE" "$(dirname "$DB_DATABASE")"

# Grant www-data access to the Docker socket by matching the host's docker group GID
if [ -S /var/run/docker.sock ]; then
    SOCK_GID=$(stat -c '%g' /var/run/docker.sock)
    if ! getent group "$SOCK_GID" > /dev/null 2>&1; then
        addgroup -g "$SOCK_GID" dockersock
    fi
    DOCKER_GROUP=$(getent group "$SOCK_GID" | cut -d: -f1)
    adduser www-data "$DOCKER_GROUP" 2>/dev/null || true
fi

# Clear and rebuild config cache with current env vars
su -s /bin/sh www-data -c "php /var/www/html/artisan config:clear && php /var/www/html/artisan config:cache"

# Run migrations as www-data
su -s /bin/sh www-data -c "php /var/www/html/artisan migrate --force"

# Reset any deployments stuck in running/pending from a previous crash
su -s /bin/sh www-data -c "php /var/www/html/artisan deployments:reset-stuck"

# Fix storage permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

exec /usr/bin/supervisord -c /etc/supervisord.conf

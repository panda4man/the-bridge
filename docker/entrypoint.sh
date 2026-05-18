#!/bin/sh
set -e

# Ensure SQLite DB directory and file exist
DB_PATH="${DB_PATH:-/data/bridge.db}"
mkdir -p "$(dirname "$DB_PATH")"
touch "$DB_PATH"
mkdir -p /data/ssh
chmod 700 /data/ssh

# Grant access to Docker socket by matching the host's docker group GID
if [ -S /var/run/docker.sock ]; then
    SOCK_GID=$(stat -c '%g' /var/run/docker.sock)
    if ! getent group "$SOCK_GID" > /dev/null 2>&1; then
        addgroup --gid "$SOCK_GID" dockersock
    fi
fi

exec /usr/bin/supervisord -c /etc/supervisord.conf

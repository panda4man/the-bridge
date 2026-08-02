#!/bin/sh
# Container boot. Runs exactly ONCE per container start, before supervisord —
# which matters, because supervisord restarting a crashed worker must not
# re-run any of this. `bridge:poll-health` in particular has no duplicate
# kickoff guard by design (see App\Console\Commands\PollHealth), and
# once-per-container-start is the guarantee it was designed against.
set -e

fatal() {
    echo "[entrypoint] FATAL: $*" >&2
    exit 1
}

warn() {
    echo "[entrypoint] WARNING: $*" >&2
}

info() {
    echo "[entrypoint] $*"
}

# --------------------------------------------------------------------------
# 1. Configuration checks that must fail before anything is written
# --------------------------------------------------------------------------

# REPOS_PATH was renamed to BRIDGE_REPOS_PATH in this port. An operator
# upgrading in place keeps the old variable, the app never reads it, and
# config/bridge.php falls back to the literal /repos — which is not the
# directory the volume was mounted at, so every clone fails with a misleading
# "path not found" much later and far from the cause. Refuse to boot instead.
if [ -n "${REPOS_PATH:-}" ] && [ -z "${BRIDGE_REPOS_PATH:-}" ]; then
    fatal "REPOS_PATH is set but BRIDGE_REPOS_PATH is not.
    REPOS_PATH was renamed BRIDGE_REPOS_PATH in the Laravel rebuild and is no
    longer read by anything. Rename it in your .env:
        BRIDGE_REPOS_PATH=${REPOS_PATH}
    Booting with only the old name would silently clone into /repos, which is
    not where your volume is mounted."
fi

REPOS_DIR="${BRIDGE_REPOS_PATH:-/repos}"

# The host directory is bind-mounted at the SAME absolute path inside the
# container so that the absolute paths stored in apps.path resolve identically
# on both sides. If the mount is missing, that invariant is already broken.
if [ ! -d "$REPOS_DIR" ]; then
    fatal "BRIDGE_REPOS_PATH ($REPOS_DIR) does not exist inside the container.
    It must be bind-mounted at the same absolute path on host and container —
    docker-compose.yml does this with \${BRIDGE_REPOS_PATH}:\${BRIDGE_REPOS_PATH}."
fi

if [ ! -w "$REPOS_DIR" ]; then
    fatal "BRIDGE_REPOS_PATH ($REPOS_DIR) is not writable. Clones will fail."
fi

# --------------------------------------------------------------------------
# 2. Runtime state directory
# --------------------------------------------------------------------------
DB_FILE="${DB_DATABASE:-/data/bridge.sqlite}"

mkdir -p "$(dirname "$DB_FILE")"
[ -f "$DB_FILE" ] || touch "$DB_FILE"

# Holds the private deploy key for every managed private repo. git/ssh refuses
# to use a key whose directory or file is group/world readable.
mkdir -p /data/ssh
chmod 700 /data/ssh
if [ -f "${BRIDGE_SSH_KEY_PATH:-/data/ssh/id_rsa}" ]; then
    chmod 600 "${BRIDGE_SSH_KEY_PATH:-/data/ssh/id_rsa}"
fi

# --------------------------------------------------------------------------
# 3. APP_KEY
# --------------------------------------------------------------------------
# Sessions and every encrypted cookie are signed with this. There is
# deliberately no baked-in default: a shipped key is a published key, and this
# container fronts an admin panel that can deploy arbitrary code. When the
# operator supplies none, generate one and persist it to the /data volume so
# it survives restarts and image rebuilds — a key that changes on every boot
# would log everyone out on every deploy of The Bridge itself.
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -f /data/app_key ]; then
        info "APP_KEY not set — generating one into /data/app_key"
        php artisan key:generate --show --no-ansi > /data/app_key
        chmod 600 /data/app_key
    fi
    APP_KEY="$(cat /data/app_key)"
    export APP_KEY
fi

# --------------------------------------------------------------------------
# 4. Docker socket
# --------------------------------------------------------------------------
# Everything below runs as root, which can already open the socket regardless
# of its mode. Matching the host's GID into a container group is kept anyway so
# that dropping privileges later is a one-line change rather than a debugging
# session.
DOCKER_SOCK=/var/run/docker.sock
if [ -S "$DOCKER_SOCK" ]; then
    SOCK_GID="$(stat -c '%g' "$DOCKER_SOCK")"
    if [ "$SOCK_GID" != "0" ] && ! getent group "$SOCK_GID" >/dev/null 2>&1; then
        groupadd --gid "$SOCK_GID" dockersock >/dev/null 2>&1 || true
    fi
else
    warn "no Docker socket at $DOCKER_SOCK.
    Deploying is this application's only purpose and every deploy will fail.
    On Docker Desktop for macOS the host socket is NOT at /var/run/docker.sock;
    it is at \$HOME/.docker/run/docker.sock. Check the left-hand side of the
    socket volume in docker-compose.yml, and see DOCKER_SOCK in .env.example."
fi

# Not fatal: the panel is the diagnostic surface, so it must come up even when
# Docker is unreachable — an operator who cannot log in cannot read the error.
if ! docker version >/dev/null 2>&1; then
    warn "cannot talk to the Docker daemon. Deploys will fail until this is fixed.
    Diagnose with: docker exec bridge docker version"
fi

# --------------------------------------------------------------------------
# 5. Database and caches
# --------------------------------------------------------------------------
# `docker compose restart` reuses the container filesystem, so a config cache
# built on the previous boot survives into this one. Every command below would
# then read the OLD environment — including DB_DATABASE, which would migrate
# and seed the wrong file. Discard it before anything reads config; it is
# rebuilt from the current environment at the end of this script.
#
# NOT `optimize:clear`, which also runs `cache:clear`. CACHE_STORE is
# `database`, so clearing the application cache is a DELETE against a table
# that does not exist yet on a first boot — the command throws, `set -e` stops
# the script, and the container restart-loops with "no such table: cache"
# before it has ever migrated. Only the file-backed caches can be stale here
# anyway; the database-backed one is cleared below, once its table exists.
for cache in config route view event; do
    php artisan "${cache}:clear" --no-ansi >/dev/null
done

info "running migrations"
php artisan migrate --force --no-ansi

# Now that the cache table exists. Nothing in this application depends on the
# application cache surviving a boot, and a value cached against the previous
# boot's configuration is exactly what this whole section exists to discard.
php artisan cache:clear --no-ansi >/dev/null

# Creates the first panel user from BRIDGE_ADMIN_EMAIL / BRIDGE_ADMIN_PASSWORD.
# firstOrCreate, so a password changed through the UI is not reverted; warns
# and skips when those are unset. The panel has no public registration, so an
# operator who skips them reaches a login screen with no account.
info "seeding"
php artisan db:seed --force --no-ansi

# Fails anything left `running`/`pending` by an unclean stop — including a
# deploy SIGKILLed past deploy-worker's stopwaitsecs — and puts any app still
# marked `deploying` back to `failed`. Must happen before the web server
# accepts a request, so the panel never shows a deploy that nothing is running.
info "resetting stuck deployments"
php artisan bridge:reset-stuck-deployments --no-ansi

# Config/route/view/event caches. Safe to build here and only here: every value
# they capture comes from the environment, which is fixed for the life of the
# container and re-read on the next boot.
info "caching configuration"
php artisan optimize --no-ansi

# --------------------------------------------------------------------------
# 6. Health poll chain
# --------------------------------------------------------------------------
# The chain is self-perpetuating: each tick re-dispatches its successor with a
# 60-second delay. That successor is a row in the database queue, which lives
# in /data and therefore SURVIVES the container. Kicking off a new chain on top
# of it means two chains, and one more on every subsequent restart — doubling
# health-check request volume and HealthCheck rows with nothing anywhere
# reporting it. (Verified in Phase 8: one stop/start, two pending health jobs.)
#
# The `health` queue carries nothing but this chain, so clearing it is exactly
# "cancel the old chain" and cannot discard a deploy — those are on `default`
# and must never be cleared here.
info "clearing any health chain left by a previous container"
php artisan queue:clear --queue=health --force --no-ansi >/dev/null

# Dispatches the FIRST tick of the self-rescheduling PollHealthChecks chain,
# which then re-dispatches itself forever. Exactly once per container start —
# there is no internal duplicate-kickoff guard, and two invocations mean two
# chains ticking forever (double request volume, duplicate HealthCheck rows).
# The job lands on the `health` queue; health-worker picks it up once
# supervisord starts below.
info "starting health poll chain"
php artisan bridge:poll-health --no-ansi

# --------------------------------------------------------------------------
# 7. Hand off
# --------------------------------------------------------------------------
info "starting supervisord"
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf

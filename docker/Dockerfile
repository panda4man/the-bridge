# The Bridge — Laravel + Filament runtime image.
#
# Three stages:
#   vendor  — composer dependencies + the Filament vendor assets they publish
#   assets  — Node, purely to run `vite build`; no Node reaches the runtime
#   runtime — FrankenPHP (Caddy + PHP as one process) under supervisord
#
# The runtime deliberately contains a Docker CLI and a compose plugin, because
# deploying is this application's entire purpose: every managed app is brought
# up with `docker compose` against the HOST daemon over the bind-mounted
# socket. There is no docker-in-docker here; the daemon is the host's.

# --------------------------------------------------------------------------
# Stage 0 — PHP with this application's extensions
# --------------------------------------------------------------------------
# Shared by the vendor and runtime stages. It has to be shared: composer
# enforces the `ext-*` requirements of the dependency tree at install time, so
# a vendor stage without intl and zip cannot resolve filament/support at all.
#
# pcntl is load-bearing, not decoration: without it `queue:work` cannot trap
# SIGTERM, and a container stop would abandon an in-flight deploy exactly the
# way the Express worker's `process.exit(0)` did — one of the defects this
# port exists to fix.
FROM dunglas/frankenphp:1-php8.4-bookworm AS php-base

RUN install-php-extensions \
        bcmath \
        intl \
        pcntl \
        zip

# --------------------------------------------------------------------------
# Stage 1 — PHP dependencies
# --------------------------------------------------------------------------
FROM php-base AS vendor

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

WORKDIR /app

# Dependencies first, so a source-only change does not re-resolve the tree.
# --no-scripts is required at this point: composer.json's post-autoload-dump
# runs `artisan package:discover` and `artisan filament:upgrade`, and neither
# can run before the application source exists.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist

COPY . .

# Now the scripts can run. `filament:upgrade` (via post-autoload-dump) is what
# publishes Filament's CSS/JS/fonts into public/. They are gitignored on
# purpose — see docs/porting-notes.md, "Ignored paths worth knowing about" —
# so the image MUST generate them rather than assume the build context has
# them.
RUN composer dump-autoload --optimize --no-dev --no-interaction

# --------------------------------------------------------------------------
# Stage 2 — front-end assets
# --------------------------------------------------------------------------
# Node exists only here. Tailwind v4 is CSS-first (there is no
# tailwind.config.js); resources/css/filament/admin/theme.css imports
# Filament's own theme.css out of vendor/ and declares @source globs over
# app/Filament and resources/views/filament, so all three must be present for
# the class scan to produce a correct stylesheet.
FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json vite.config.js ./
RUN npm ci --ignore-scripts

COPY resources/ ./resources/
COPY app/ ./app/
COPY public/ ./public/
COPY --from=vendor /app/vendor/filament/ ./vendor/filament/

RUN npm run build

# --------------------------------------------------------------------------
# Stage 3 — runtime
# --------------------------------------------------------------------------
FROM php-base AS runtime

# docker-ce-cli comes from Docker's own repository rather than Debian's
# `docker.io` package: the latter is several years behind and was the reason
# the Express image had to pin DOCKER_API_VERSION. A current CLI negotiates
# the API version with the host daemon over /_ping instead.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        openssh-client \
        supervisor \
    && install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg \
        -o /etc/apt/keyrings/docker.asc \
    && chmod a+r /etc/apt/keyrings/docker.asc \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        docker-ce-cli \
        docker-buildx-plugin \
        docker-compose-plugin \
    && rm -rf /var/lib/apt/lists/*

# Sensible production PHP settings. opcache is already compiled in.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-bridge.ini"

# An empty client config stops the CLI from probing for credential helpers
# that do not exist in this image on every `docker compose pull`.
RUN mkdir -p /root/.docker && echo '{}' > /root/.docker/config.json

# Docker CLI environment. Declared here rather than in supervisord.conf so
# that supervisord inherits it and passes it to both workers, AND so a
# compose `environment:` entry can override it — a program-level
# `environment=` in supervisord.conf would replace the inherited value and
# make the override silently ineffective.
#
# DOCKER_BUILDKIT=0 is carried over from the Express image. It pins managed
# apps' builds to the legacy builder, which is the configuration this
# application has actually been run against; BuildKit is installed
# (docker-buildx-plugin) and one `DOCKER_BUILDKIT=1` in compose switches to
# it. There is deliberately no DOCKER_API_VERSION pin — see the comment on
# the docker-ce-cli install above.
ENV HOME=/root \
    DOCKER_CONFIG=/root/.docker \
    DOCKER_BUILDKIT=0

# The upstream Caddy/FrankenPHP images point XDG_DATA_HOME at /data and
# XDG_CONFIG_HOME at /config, which are their own volume conventions. Here
# /data is the application's state volume — SQLite database, deploy key — and
# is what an operator backs up; Caddy's certificate store and autosaved config
# have no business in it. Moved somewhere container-local, where they belong:
# with auto_https off and admin off, nothing in there needs to survive.
ENV XDG_DATA_HOME=/var/lib/bridge \
    XDG_CONFIG_HOME=/var/lib/bridge/config

WORKDIR /app

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build

COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# storage/ and bootstrap/cache are written at runtime. They are container-local
# and deliberately NOT on a volume: everything that must survive a restart
# (SQLite database, sessions, cache, queue, SSH key) lives in /data. Recreated
# here because the framework subdirectories are only kept alive in git by
# .gitignore stubs, which a future .dockerignore change could drop.
RUN mkdir -p \
        storage/app/private \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

FROM node:22-bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    supervisor \
    git \
    openssh-client \
    docker.io \
    curl \
    ca-certificates \
    python3 \
    make \
    g++ \
    && mkdir -p /usr/libexec/docker/cli-plugins \
    && curl -fsSL "https://github.com/docker/compose/releases/download/v2.32.4/docker-compose-linux-$(uname -m)" \
        -o /usr/libexec/docker/cli-plugins/docker-compose \
    && chmod +x /usr/libexec/docker/cli-plugins/docker-compose \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /root/.docker \
    && echo '{}' > /root/.docker/config.json

WORKDIR /app

COPY package*.json ./
RUN npm ci --omit=dev

COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

COPY tsconfig.json ./
COPY src/ ./src/
COPY public/ ./public/

EXPOSE 3000

ENTRYPOINT ["/entrypoint.sh"]

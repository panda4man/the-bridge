FROM node:22-bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    supervisor \
    git \
    openssh-client \
    docker.io \
    docker-compose \
    python3 \
    make \
    g++ \
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

EXPOSE 3000

ENTRYPOINT ["/entrypoint.sh"]

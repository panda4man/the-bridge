FROM node:22-alpine

RUN apk add --no-cache \
    supervisor \
    git \
    openssh-client \
    docker-cli \
    docker-cli-compose \
    && chmod a+rx /usr/libexec/docker/cli-plugins/docker-compose \
    && ln -sf /usr/libexec/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose \
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

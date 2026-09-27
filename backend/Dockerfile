# syntax=docker/dockerfile:1
#
# Targets:
#   dev  (usado pelo docker-compose): toolchain + entrypoint.
#        Código, vendor e node_modules vêm dos volumes — o entrypoint
#        instala quando o volume está vazio ou o lock está mais novo.
#   prod: assets compilados, sem devDependencies.
# Build prod: docker build --target prod -t opmoni/backend:prod ./backend
#
# Nenhum alvo publica porta no host: os dois expõem 9000 (FastCGI) e quem
# recebe HTTP é o nginx (docker/nginx/dev.conf | docker/nginx/prod.conf).

FROM php:8.4-fpm AS base

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        curl \
        git \
        libfcgi-bin \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" bcmath opcache pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# libfcgi-bin + ping.path: healthcheck real do FPM (o master responde "pong"
# em /ping) no serviço backend, no compose e no stack.
RUN printf 'ping.path = /ping\nping.response = pong\n' > /usr/local/etc/php-fpm.d/zzz-ping.conf

# `queue` reusa a imagem; só troca o entrypoint (ver docker/queue-entrypoint.sh).
COPY --chmod=755 docker/queue-entrypoint.sh /usr/local/bin/queue-entrypoint.sh

# Node só entra nos alvos que rodam Vite ou compilam assets.
FROM base AS with-node

COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm

RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# ---------------- dev ----------------
FROM with-node AS dev

COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh

COPY docker/php/development.ini /usr/local/etc/php/conf.d/zzz-development.ini

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm", "--nodaemonize"]

# ---------------- prod ----------------
FROM with-node AS prod

ENV APP_ENV=production \
    APP_DEBUG=false

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-interaction --prefer-dist --no-progress --no-dev --optimize-autoloader

COPY package.json .npmrc ./
RUN npm install --no-audit --no-fund

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && npm run build \
    && npm prune --omit=dev \
    && mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage

COPY docker/php/production.ini /usr/local/etc/php/conf.d/zzz-production.ini

EXPOSE 9000

# Sem entrypoint de preparação: vendor e assets vêm prontos da imagem e as
# variáveis do ambiente, do serviço no Swarm. As migrations de produção rodam
# uma vez, à parte (ver AGENTS.md).
CMD ["php-fpm", "--nodaemonize"]

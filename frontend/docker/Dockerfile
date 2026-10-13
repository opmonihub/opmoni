FROM node:24-bookworm-slim

ARG APP_UID=1000
ARG APP_GID=1000

RUN npm install --global pnpm@12.11.2 \
    && groupmod --non-unique --gid "${APP_GID}" node \
    && usermod --non-unique --uid "${APP_UID}" node \
    && mkdir -p /app/node_modules /app/.nuxt /app/.output \
    && chown -R node:node /app /home/node

COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/frontend-entrypoint

WORKDIR /app
USER node

EXPOSE 3000

ENTRYPOINT ["frontend-entrypoint"]
CMD ["pnpm", "dev", "--host", "0.0.0.0", "--port", "3000"]

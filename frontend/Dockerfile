# syntax=docker/dockerfile:1
#
# Targets:
#   dev  (usado pelo docker-compose): toolchain + entrypoint.
#        Código e node_modules vêm dos volumes — o entrypoint instala
#        quando o volume está vazio ou o lock está mais novo.
#   prod: build Nuxt + `pnpm preview`.
# Build prod: docker build --target prod ./frontend

FROM node:22-bookworm-slim AS base

WORKDIR /app

# Evita prompt interativo do corepack no download do pnpm pinado
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0

RUN corepack enable

# ---------------- dev ----------------
FROM base AS dev

COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh

EXPOSE 3000

ENTRYPOINT ["entrypoint.sh"]
CMD ["pnpm", "dev", "--host", "0.0.0.0", "--port", "3000"]

# ---------------- prod ----------------
FROM base AS prod

# HOST/PORT: lidos pelo node-server do Nitro. `nuxt preview --host/--port` não
# existe no Nuxt 4 (o flag vira rootdir e o preview morre com "Cannot find
# nitro.json"), então o server sobe direto pelo entry do .output.
ENV NODE_ENV=production \
    NUXT_TELEMETRY_DISABLED=1 \
    HOST=0.0.0.0 \
    PORT=3000

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile --ignore-scripts

COPY . .
RUN pnpm run postinstall \
    && pnpm build

EXPOSE 3000

CMD ["node", ".output/server/index.mjs"]

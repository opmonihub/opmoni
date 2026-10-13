#!/bin/sh
set -eu

pnpm install --frozen-lockfile
pnpm exec nuxt prepare

exec "$@"

#!/usr/bin/env bash
set -euo pipefail
umask 077

worktree_path="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
source_path="${PASEO_SOURCE_CHECKOUT_PATH:?Paseo must provide PASEO_SOURCE_CHECKOUT_PATH.}"
source_path="$(cd "$source_path" && pwd -P)"

if ! command -v pnpm >/dev/null 2>&1; then
    echo "Install Node.js 24 and pnpm before preparing this worktree." >&2
    exit 1
fi

use_docker=false
if ! command -v php >/dev/null 2>&1 || ! command -v composer >/dev/null 2>&1; then
    if ! command -v docker >/dev/null 2>&1 || ! docker image inspect opmoni-backend:dev >/dev/null 2>&1; then
        echo "Install PHP and Composer, or build the backend image in the source checkout: docker compose build setup" >&2
        exit 1
    fi
    use_docker=true
fi

# Validate all missing files before copying anything. Never replace existing config.
for env_file in .env backend/.env frontend/.env; do
    if [[ ! -e "$worktree_path/$env_file" && ! -f "$source_path/$env_file" ]]; then
        echo "Configure $env_file in the source checkout before preparing this worktree (see README.md)." >&2
        exit 1
    fi
done

for env_file in .env backend/.env frontend/.env; do
    if [[ ! -e "$worktree_path/$env_file" ]]; then
        install -m 600 "$source_path/$env_file" "$worktree_path/$env_file"
    fi
done

cd "$worktree_path"
mkdir -p backend/storage/framework/cache/data backend/storage/framework/sessions \
    backend/storage/framework/views backend/storage/logs backend/bootstrap/cache

echo "Installing backend dependencies..."
if [[ "$use_docker" == true ]]; then
    docker run --rm --pull=never \
        --user "$(id -u):$(id -g)" \
        --env COMPOSER_HOME=/tmp/composer \
        --volume "$worktree_path:/workspace" \
        --workdir /workspace/backend \
        --entrypoint composer \
        opmoni-backend:dev install --no-interaction --prefer-dist --no-progress
else
    (cd backend && composer install --no-interaction --prefer-dist --no-progress)
fi

echo "Installing frontend dependencies and preparing Nuxt..."
pnpm --dir frontend install --frozen-lockfile

echo "Worktree dependencies and local environment files are ready."

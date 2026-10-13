#!/bin/sh
set -eu

if [ "${1:-}" = "setup" ]; then
    if [ ! -f .env ]; then
        cp .env.example .env
    fi

    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
    composer install --no-interaction --prefer-dist --no-progress

    if ! grep -q '^APP_KEY=.' .env; then
        php artisan key:generate --no-interaction
    fi

    php artisan optimize:clear --no-interaction
    php artisan migrate --force --no-interaction
    exit 0
fi

exec "$@"

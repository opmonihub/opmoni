#!/bin/sh
set -eu

cd /var/www/html

# /var/www/html é bind mount do host: o dono do diretório é o uid do dev. O pool
# do FPM roda como www-data, então alinha o uid/gid dele com esse dono — assim
# os workers gravam em storage/ (log, cache, certificados A1) com as permissões
# que o dev já tem no host, sem chown no host e sem pool root (o FPM recusa
# `user = root`). Só em dev; a imagem prod mantém www-data (uid 33).
host_uid="$(stat -c %u /var/www/html)"
host_gid="$(stat -c %g /var/www/html)"
if [ "$host_uid" = "0" ]; then
    # Dono root no host: alinhar aqui produziria um pool www-data com uid 0, ou
    # seja FPM inteiro rodando como root (o www.conf cita o usuário pelo nome,
    # então o FPM não recusa). Não faz: o dev ajusta o dono no host e os
    # workers passam a não poder gravar em storage/ — erro visível, não root.
    echo "[entrypoint] ALERTA: /var/www/html é do root no host (uid 0)." >&2
    echo "[entrypoint] Mantendo www-data como uid $(id -u www-data): pool do FPM" >&2
    echo "[entrypoint] NÃO pode virar root. Rode 'sudo chown -R \$USER:\$USER backend'" >&2
    echo "[entrypoint] no host antes de subir o compose, senão storage/ fica sem escrita." >&2
elif [ "$host_uid" != "$(id -u www-data)" ]; then
    echo "[entrypoint] alinhando www-data para ${host_uid}:${host_gid} (dono de ./backend no host)"
    # -o: não falha se o uid/gid já existir na imagem.
    usermod -o -u "$host_uid" www-data
    groupmod -o -g "$host_gid" www-data
fi

if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

if [ ! -f vendor/autoload.php ] \
    || [ composer.json -nt vendor/composer/installed.php ] \
    || [ composer.lock -nt vendor/composer/installed.php ]; then
    composer install --no-interaction --prefer-dist --no-progress
fi

if [ ! -x node_modules/.bin/vite ] || [ package.json -nt node_modules/.package-lock.json ]; then
    npm install --no-audit --no-fund --no-package-lock
fi

if [ -f .env ] && ! grep -Eq '^APP_KEY=.+' .env; then
    php artisan key:generate --force --no-interaction --ansi
fi

php artisan migrate --force --no-interaction --ansi

exec "$@"

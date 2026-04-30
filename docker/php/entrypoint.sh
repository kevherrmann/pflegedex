#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

if [ ! -d vendor ]; then
    composer install --no-interaction --prefer-dist
fi

if [ -f artisan ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force
fi

exec "$@"

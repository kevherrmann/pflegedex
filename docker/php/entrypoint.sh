#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

if [ -f composer.lock ]; then
    current_hash="$(sha256sum composer.lock | awk '{print $1}')"
    installed_hash=""

    if [ -f vendor/.composer-lock.sha256 ]; then
        installed_hash="$(cat vendor/.composer-lock.sha256)"
    fi

    if [ ! -d vendor ] || [ "$current_hash" != "$installed_hash" ]; then
        composer install --no-interaction --prefer-dist
        mkdir -p vendor
        printf '%s' "$current_hash" > vendor/.composer-lock.sha256
    fi
fi

if [ -f artisan ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force
fi

exec "$@"

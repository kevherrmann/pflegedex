#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

composer_install_needed() {
    [ -f composer.lock ] || return 1
    [ -f vendor/autoload.php ] || return 0
    [ -f vendor/.composer-lock.sha256 ] || return 0

    local current_hash
    local installed_hash
    current_hash="$(sha256sum composer.lock | awk '{print $1}')"
    installed_hash="$(cat vendor/.composer-lock.sha256)"

    [ "$current_hash" != "$installed_hash" ]
}

if composer_install_needed; then
    mkdir -p vendor
    lock_dir="vendor/.composer-install.lock"

    while ! mkdir "$lock_dir" 2>/dev/null; do
        if ! composer_install_needed; then
            break
        fi

        echo "Waiting for another Pflegedex container to finish composer install..."
        sleep 2
    done

    if [ -d "$lock_dir" ] && composer_install_needed; then
        cleanup_lock() {
            rmdir "$lock_dir" 2>/dev/null || true
        }
        trap cleanup_lock EXIT

        rm -f vendor/composer/tmp-* 2>/dev/null || true
        composer install --no-interaction --prefer-dist
        sha256sum composer.lock | awk '{print $1}' > vendor/.composer-lock.sha256

        cleanup_lock
        trap - EXIT
    fi
fi

if [ -f artisan ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force
fi

if [ "${PFLEGEDEX_AUTO_MIGRATE:-false}" = "true" ]; then
    echo "Running Pflegedex local migrations and seeders..."
    php artisan migrate --force
    php artisan db:seed --force
fi

exec "$@"

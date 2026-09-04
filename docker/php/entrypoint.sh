#!/bin/sh
set -eu
[ -f .env ] || cp .env.example .env
composer install --no-interaction --prefer-dist
if ! grep -q '^APP_KEY=.' .env; then
    php artisan key:generate --no-interaction
fi
exec "$@"

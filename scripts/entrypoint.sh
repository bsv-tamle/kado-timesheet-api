#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

AUTO_SETUP="${AUTO_SETUP:-true}"
AUTO_MIGRATE="${AUTO_MIGRATE:-true}"

if [[ "${AUTO_SETUP}" == "true" && ! -f artisan ]]; then
  echo "[entrypoint] Laravel source not found. Creating a fresh Laravel project..."
  composer create-project laravel/laravel . --prefer-dist --no-interaction
fi

if [[ -f artisan && "${APP_ENV}" == "local" ]]; then
  if [[ ! -f .env ]]; then
    cp .env.example .env
  fi

  if [[ ! -f vendor/autoload.php ]]; then
    composer install
  fi

  php artisan key:generate --force >/dev/null 2>&1

  # Keep container env as the source of truth for DB connection.
  php artisan config:clear >/dev/null 2>&1

  if [[ "${AUTO_MIGRATE}" == "true" ]]; then
    echo "[entrypoint] Running migrations..."
    php artisan migrate --force
  fi
fi

if [[ "${APP_ENV}" != "local" ]]; then
  php artisan optimize >/dev/null 2>&1
fi

echo "[entrypoint] Starting php-fpm..."
exec "$@"


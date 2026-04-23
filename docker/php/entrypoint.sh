#!/usr/bin/env sh
set -eu

if [ -n "${DOPPLER_TOKEN:-}" ] && command -v doppler >/dev/null 2>&1; then
  echo "Doppler token detected and CLI installed."
fi

if [ ! -f .env ]; then
  cp .env.example .env
fi

if [ "${APP_KEY:-}" = "" ]; then
  php artisan key:generate --force --no-interaction
fi

if [ "${RUN_MIGRATIONS_ON_STARTUP:-false}" = "true" ]; then
  php artisan migrate --force --no-interaction
fi

exec "$@"

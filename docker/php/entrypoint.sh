#!/bin/sh
set -e

cd /var/www/html

mkdir -p var/cache var/log var/share
chmod -R 777 var 2>/dev/null || true

if [ ! -f vendor/autoload.php ]; then
  echo "Instalando dependencias Composer..."
  composer install --prefer-dist --no-interaction --no-dev
fi

# Render (y otros PaaS) inyectan PORT. Asegurar sslmode en Postgres managed.
if [ -n "${DATABASE_URL:-}" ]; then
  case "$DATABASE_URL" in
    *sslmode=*) ;;
    *\?*)
      export DATABASE_URL="${DATABASE_URL}&sslmode=require"
      ;;
    *)
      export DATABASE_URL="${DATABASE_URL}?sslmode=require"
      ;;
  esac
fi

PORT="${PORT:-8000}"

if [ "$#" -eq 0 ]; then
  set -- php -S "0.0.0.0:${PORT}" -t public public/router.php
fi

exec "$@"

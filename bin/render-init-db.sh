#!/bin/sh
# Aplica el esquema CNB al Postgres de Render (solo tablas faltantes / IF NOT EXISTS donde aplica).
# Uso:
#   DATABASE_URL='postgresql://...' ./bin/render-init-db.sh
# O desde el shell de Render:
#   ./bin/render-init-db.sh

set -e

cd "$(dirname "$0")/.."

if [ -z "${DATABASE_URL:-}" ]; then
  echo "ERROR: definí DATABASE_URL" >&2
  exit 1
fi

# Convertir URL estilo Doctrine a formato psql si hace falta
PSQL_URL="$DATABASE_URL"
case "$PSQL_URL" in
  postgresql://*|postgres://*) ;;
  *)
    echo "ERROR: DATABASE_URL debe empezar con postgresql://" >&2
    exit 1
    ;;
esac

# Quitar query string (?serverVersion=...) que psql no entiende
PSQL_URL=$(printf '%s' "$PSQL_URL" | sed 's/?.*$//')

echo "Aplicando schema operativo..."
psql "$PSQL_URL" -v ON_ERROR_STOP=1 -f docker/postgres/init/01_schema.sql

echo "Aplicando portal de socios..."
psql "$PSQL_URL" -v ON_ERROR_STOP=1 -f docker/postgres/init/02_socio_portal.sql

echo "Aplicando mediciones de nivel..."
psql "$PSQL_URL" -v ON_ERROR_STOP=1 -f docker/postgres/init/03_mediciones_nivel.sql

echo "OK: base inicializada."

-- Asegura DEFAULT NOW() en mediciones_nivel (por si la tabla se creo sin defaults).
--   psql "$DATABASE_URL" -f sql/005_fix_mediciones_nivel_defaults.sql

SET search_path TO cnb_app, public;

ALTER TABLE mediciones_nivel
    ALTER COLUMN fecha SET DEFAULT NOW(),
    ALTER COLUMN created_at SET DEFAULT NOW();

-- Ampliación de amarra: además de compra y alquiler.
-- Guarda los metros ya comprados de la embarcación del padrón.
--   psql -d CNBDB -f sql/025_amarra_ampliacion.sql

SET search_path TO cnb_app, public;

ALTER TABLE solicitudes_amarra
    ADD COLUMN IF NOT EXISTS metros_comprados NUMERIC(10,2);

ALTER TABLE solicitudes_amarra
    ADD COLUMN IF NOT EXISTS cambio_embarcacion BOOLEAN NOT NULL DEFAULT FALSE;

DO $$
DECLARE
    nombre TEXT;
BEGIN
    FOR nombre IN
        SELECT c.conname
        FROM pg_constraint c
        JOIN pg_class t ON t.oid = c.conrelid
        JOIN pg_namespace n ON n.oid = t.relnamespace
        WHERE n.nspname = 'cnb_app'
          AND t.relname = 'solicitudes_amarra'
          AND c.contype = 'c'
          AND pg_get_constraintdef(c.oid) ILIKE '%modalidad%'
    LOOP
        EXECUTE format('ALTER TABLE cnb_app.solicitudes_amarra DROP CONSTRAINT %I', nombre);
    END LOOP;
END $$;

ALTER TABLE solicitudes_amarra
    DROP CONSTRAINT IF EXISTS solicitudes_amarra_modalidad_check;

ALTER TABLE solicitudes_amarra
    ADD CONSTRAINT solicitudes_amarra_modalidad_check
    CHECK (modalidad IN ('compra', 'alquiler', 'ampliacion'));

ALTER TABLE solicitudes_amarra
    DROP CONSTRAINT IF EXISTS solicitudes_amarra_metros_comprados_check;

ALTER TABLE solicitudes_amarra
    ADD CONSTRAINT solicitudes_amarra_metros_comprados_check
    CHECK (metros_comprados IS NULL OR metros_comprados >= 0);

COMMENT ON COLUMN solicitudes_amarra.metros_comprados IS
    'Metros de amarra ya comprados de la embarcación al pedir una ampliación.';

COMMENT ON COLUMN solicitudes_amarra.cambio_embarcacion IS
    'La ampliación reemplaza la embarcación actual por el tipo nuevo.';

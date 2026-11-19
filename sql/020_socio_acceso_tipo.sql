-- Distingue la cuenta de un socio. El personal no usa numero de socio:
-- vive en cnb_app.usuarios con rol empleado o admin.
-- Ejecutar conectado a CNBDB:
--   psql -d CNBDB -f sql/020_socio_acceso_tipo.sql

SET search_path TO cnb_app, public;

ALTER TABLE socio_acceso
    ADD COLUMN IF NOT EXISTS tipo VARCHAR(20) NOT NULL DEFAULT 'socio';

ALTER TABLE socio_acceso DROP CONSTRAINT IF EXISTS socio_acceso_tipo_check;
ALTER TABLE socio_acceso
    ADD CONSTRAINT socio_acceso_tipo_check CHECK (tipo IN ('socio', 'empleado'));

COMMENT ON COLUMN socio_acceso.tipo IS
    'socio: se registro con su numero. empleado: reservado; el personal entra por usuarios.rol.';

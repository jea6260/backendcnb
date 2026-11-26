-- Semáforo del turno iniciado y pedido de extensión.
--   psql -d CNBDB -f sql/027_varadero_extension.sql

SET search_path TO cnb_app, public;

ALTER TABLE reservas_varadero
    ADD COLUMN IF NOT EXISTS destino_salida VARCHAR(20);

ALTER TABLE reservas_varadero
    DROP CONSTRAINT IF EXISTS reservas_varadero_destino_salida_check;

ALTER TABLE reservas_varadero
    ADD CONSTRAINT reservas_varadero_destino_salida_check
    CHECK (destino_salida IS NULL OR destino_salida IN ('amarra', 'socio'));

ALTER TABLE reservas_varadero
    ADD COLUMN IF NOT EXISTS extension_estado VARCHAR(20);

ALTER TABLE reservas_varadero
    ADD COLUMN IF NOT EXISTS extension_hasta DATE;

ALTER TABLE reservas_varadero
    DROP CONSTRAINT IF EXISTS reservas_varadero_extension_estado_check;

ALTER TABLE reservas_varadero
    ADD CONSTRAINT reservas_varadero_extension_estado_check
    CHECK (
        extension_estado IS NULL
        OR extension_estado IN ('pendiente', 'aprobada', 'rechazada')
    );

COMMENT ON COLUMN reservas_varadero.extension_estado IS
    'Pendiente: el socio pidió extensión y el turno queda en naranja. Sin pedido, vencido queda en rojo.';

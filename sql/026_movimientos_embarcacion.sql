-- Perfil guardia, código QR de la embarcación y el libro de movimientos
-- que reemplaza la planilla de ingreso y egreso.
--   psql -d CNBDB -f sql/026_movimientos_embarcacion.sql

SET search_path TO cnb_app, public;

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS perfil_staff VARCHAR(20);

ALTER TABLE usuarios
    DROP CONSTRAINT IF EXISTS usuarios_perfil_staff_check;

ALTER TABLE usuarios
    ADD CONSTRAINT usuarios_perfil_staff_check
    CHECK (perfil_staff IS NULL OR perfil_staff IN ('operador', 'marinero', 'guardia'));

COMMENT ON COLUMN usuarios.perfil_staff IS
    'Perfil operativo del personal. Guardia no sale del padron de marineros.';

ALTER TABLE embarcaciones
    ADD COLUMN IF NOT EXISTS qr_token VARCHAR(64);

CREATE UNIQUE INDEX IF NOT EXISTS embarcaciones_qr_token_key
    ON embarcaciones (qr_token) WHERE qr_token IS NOT NULL;

COMMENT ON COLUMN embarcaciones.qr_token IS
    'Código estable del QR, en la app del socio y en la etiqueta del tráiler.';

ALTER TABLE reservas_varadero
    ADD COLUMN IF NOT EXISTS destino_salida VARCHAR(20);

ALTER TABLE reservas_varadero
    DROP CONSTRAINT IF EXISTS reservas_varadero_destino_salida_check;

ALTER TABLE reservas_varadero
    ADD CONSTRAINT reservas_varadero_destino_salida_check
    CHECK (destino_salida IS NULL OR destino_salida IN ('amarra', 'socio'));

CREATE TABLE IF NOT EXISTS movimientos_embarcacion (
    id BIGSERIAL PRIMARY KEY,
    numero_socio INTEGER NOT NULL REFERENCES socios(numero_socio) ON DELETE RESTRICT,
    embarcacion_id BIGINT NOT NULL REFERENCES embarcaciones(id) ON DELETE RESTRICT,
    reserva_varadero_id BIGINT REFERENCES reservas_varadero(id) ON DELETE SET NULL,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('ingreso', 'egreso')),
    contexto VARCHAR(20) NOT NULL CHECK (contexto IN ('club', 'varadero')),
    destino VARCHAR(20) CHECK (destino IS NULL OR destino IN ('amarra', 'socio')),
    ocurrido_en TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    validado_por BIGINT REFERENCES usuarios(id) ON DELETE SET NULL,
    validado_username VARCHAR(180) NOT NULL,
    foto TEXT,
    origen VARCHAR(20) NOT NULL DEFAULT 'manual'
        CHECK (origen IN ('manual', 'qr', 'varadero')),
    observaciones TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_movimientos_embarcacion_fecha
    ON movimientos_embarcacion (ocurrido_en DESC);

CREATE INDEX IF NOT EXISTS idx_movimientos_embarcacion_socio
    ON movimientos_embarcacion (numero_socio, ocurrido_en DESC);

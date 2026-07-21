-- Mediciones de nivel (dispositivo ESP8266 ad-hoc).
-- Ejecutar conectado a CNBDB:
--   psql -d CNBDB -f sql/004_mediciones_nivel.sql

SET search_path TO cnb_app, public;

CREATE TABLE IF NOT EXISTS mediciones_nivel (
    id BIGSERIAL PRIMARY KEY,
    fecha TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    distancia_medida_cm NUMERIC(8,2) NOT NULL,
    profundidad_cm NUMERIC(8,2) NOT NULL,
    msnm NUMERIC(8,2) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_mediciones_nivel_fecha
    ON mediciones_nivel (fecha DESC);

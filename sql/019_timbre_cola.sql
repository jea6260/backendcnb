-- Cola de timbres pedidos desde la app. El ESP toma las filas pendientes.
-- Ejecutar conectado a CNBDB:
--   psql -d CNBDB -f sql/019_timbre_cola.sql

SET search_path TO cnb_app, public;

CREATE TABLE IF NOT EXISTS timbre_cola (
    id BIGSERIAL PRIMARY KEY,
    numero_socio INTEGER NOT NULL
        REFERENCES socios(numero_socio) ON DELETE CASCADE,
    relay VARCHAR(60) NOT NULL DEFAULT 'Timbre',
    tiempo_seg SMALLINT NOT NULL DEFAULT 3
        CHECK (tiempo_seg >= 1 AND tiempo_seg <= 60),
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado IN ('pendiente', 'disparado', 'vencido')),
    creado_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    disparado_at TIMESTAMPTZ,
    expira_at TIMESTAMPTZ NOT NULL DEFAULT (NOW() + INTERVAL '60 seconds')
);

CREATE INDEX IF NOT EXISTS idx_timbre_cola_pendiente
    ON timbre_cola (creado_at)
    WHERE estado = 'pendiente';

COMMENT ON TABLE timbre_cola IS
    'Pedidos de timbre desde la app. El ESP reclama las filas pendientes y las marca disparado.';

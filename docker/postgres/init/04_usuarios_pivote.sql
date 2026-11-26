-- Pivote socio-embarcacion y usuarios. Idempotente encima de 01-03.
-- Equivalente operativo a sql/015 (pivote) + sql/016 (usuarios).

SET search_path TO cnb_app, public;

CREATE TABLE IF NOT EXISTS socio_embarcacion (
    id BIGSERIAL PRIMARY KEY,
    numero_socio INTEGER NOT NULL REFERENCES socios(numero_socio) ON DELETE CASCADE,
    embarcacion_id BIGINT NOT NULL REFERENCES embarcaciones(id) ON DELETE CASCADE,
    rol VARCHAR(30) NOT NULL DEFAULT 'titular'
        CHECK (rol IN ('titular', 'cotitular', 'autorizado')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (numero_socio, embarcacion_id)
);

CREATE INDEX IF NOT EXISTS idx_socio_embarcacion_embarcacion
    ON socio_embarcacion (embarcacion_id);

CREATE INDEX IF NOT EXISTS idx_socio_embarcacion_socio
    ON socio_embarcacion (numero_socio);

INSERT INTO socio_embarcacion (numero_socio, embarcacion_id, rol)
SELECT e.numero_socio, e.id, 'titular'
FROM embarcaciones e
INNER JOIN socios s ON s.numero_socio = e.numero_socio
WHERE e.numero_socio IS NOT NULL
ON CONFLICT (numero_socio, embarcacion_id) DO NOTHING;

CREATE TABLE IF NOT EXISTS usuarios (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol VARCHAR(20) NOT NULL CHECK (rol IN ('socio', 'empleado', 'admin')),
    numero_socio INTEGER REFERENCES socios(numero_socio) ON DELETE CASCADE,
    marinero_id BIGINT REFERENCES marineros(id) ON DELETE SET NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    cambiar_clave BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS usuarios_numero_socio_key
    ON usuarios (numero_socio) WHERE numero_socio IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS usuarios_marinero_id_key
    ON usuarios (marinero_id) WHERE marinero_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS usuario_sesiones (
    id BIGSERIAL PRIMARY KEY,
    usuario_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO usuarios (username, password_hash, rol, numero_socio, activo)
SELECT LOWER(sa.email), sa.password_hash, 'socio', sa.numero_socio, TRUE
FROM socio_acceso sa
ON CONFLICT (username) DO NOTHING;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_usuarios_updated_at') THEN
        CREATE TRIGGER trg_usuarios_updated_at
            BEFORE UPDATE ON usuarios
            FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();
    END IF;
END $$;

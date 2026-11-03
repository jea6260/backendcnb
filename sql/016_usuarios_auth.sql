-- Usuarios (socio y empleado) con clave bcrypt y sesiones por token hasheado.
-- No borra socio_acceso ni socio_sesiones: las sesiones viejas siguen valiendo
-- hasta su vencimiento. Las nuevas se emiten en usuario_sesiones (TTL 24 h).
-- Tambien asegura el pivote N:N socio_embarcacion y copia el vinculo 1:N legado.
--
--   psql "$DATABASE_URL" -f sql/016_usuarios_auth.sql

BEGIN;

CREATE TABLE IF NOT EXISTS cnb_app.socio_embarcacion (
    id BIGSERIAL PRIMARY KEY,
    numero_socio INTEGER NOT NULL,
    embarcacion_id BIGINT NOT NULL,
    rol VARCHAR(30) NOT NULL DEFAULT 'titular'
        CHECK (rol IN ('titular', 'cotitular', 'autorizado')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (numero_socio, embarcacion_id)
);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'socio_embarcacion_numero_socio_fkey'
    ) THEN
        ALTER TABLE cnb_app.socio_embarcacion
            ADD CONSTRAINT socio_embarcacion_numero_socio_fkey
            FOREIGN KEY (numero_socio) REFERENCES cnb_app.socios(numero_socio) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'socio_embarcacion_embarcacion_id_fkey'
    ) THEN
        ALTER TABLE cnb_app.socio_embarcacion
            ADD CONSTRAINT socio_embarcacion_embarcacion_id_fkey
            FOREIGN KEY (embarcacion_id) REFERENCES cnb_app.embarcaciones(id) ON DELETE CASCADE;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_socio_embarcacion_embarcacion
    ON cnb_app.socio_embarcacion (embarcacion_id);

CREATE INDEX IF NOT EXISTS idx_socio_embarcacion_socio
    ON cnb_app.socio_embarcacion (numero_socio);

INSERT INTO cnb_app.socio_embarcacion (numero_socio, embarcacion_id, rol)
SELECT e.numero_socio::INTEGER, e.id, 'titular'
FROM cnb_app.embarcaciones e
INNER JOIN cnb_app.socios s ON s.numero_socio = e.numero_socio::INTEGER
WHERE e.numero_socio IS NOT NULL
ON CONFLICT (numero_socio, embarcacion_id) DO NOTHING;

CREATE TABLE IF NOT EXISTS cnb_app.usuarios (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol VARCHAR(20) NOT NULL CHECK (rol IN ('socio', 'empleado', 'admin')),
    numero_socio INTEGER REFERENCES cnb_app.socios(numero_socio) ON DELETE CASCADE,
    marinero_id BIGINT REFERENCES cnb_app.marineros(id) ON DELETE SET NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    cambiar_clave BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS usuarios_numero_socio_key
    ON cnb_app.usuarios (numero_socio) WHERE numero_socio IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS usuarios_marinero_id_key
    ON cnb_app.usuarios (marinero_id) WHERE marinero_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS cnb_app.usuario_sesiones (
    id BIGSERIAL PRIMARY KEY,
    usuario_id BIGINT NOT NULL REFERENCES cnb_app.usuarios(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_usuario_sesiones_usuario
    ON cnb_app.usuario_sesiones (usuario_id);

INSERT INTO cnb_app.usuarios (username, password_hash, rol, numero_socio, activo)
SELECT LOWER(sa.email), sa.password_hash, 'socio', sa.numero_socio, TRUE
FROM cnb_app.socio_acceso sa
ON CONFLICT (username) DO NOTHING;

DO $$
BEGIN
    IF to_regprocedure('cnb_app.cnb_touch_updated_at()') IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_usuarios_updated_at') THEN
        CREATE TRIGGER trg_usuarios_updated_at
            BEFORE UPDATE ON cnb_app.usuarios
            FOR EACH ROW EXECUTE FUNCTION cnb_app.cnb_touch_updated_at();
    END IF;
END $$;

COMMENT ON TABLE cnb_app.usuarios IS
    'Credenciales de socios y empleados. password_hash es bcrypt; nunca texto plano.';
COMMENT ON COLUMN cnb_app.usuario_sesiones.token_hash IS
    'SHA-256 hex del token opaco. TTL 86400 s, igual que el JWT de ovmovil v3.';

COMMIT;

-- M2M socios <-> embarcaciones, foto de perfil (patron facial) y log de relays/timbre.
-- Idempotente / seguro para Render (asegura UNIQUE/PK antes de crear FKs).
--   psql "$DATABASE_URL" -f sql/015_socio_embarcacion_m2m_foto_geofence.sql

BEGIN;

-- 0a) Asegurar UNIQUE/PK en socios.numero_socio
DO $$
DECLARE
    has_unique boolean;
    col_type text;
BEGIN
    SELECT a.atttypid::regtype::text
      INTO col_type
    FROM pg_attribute a
    JOIN pg_class t ON a.attrelid = t.oid
    JOIN pg_namespace n ON t.relnamespace = n.oid
    WHERE n.nspname = 'cnb_app'
      AND t.relname = 'socios'
      AND a.attname = 'numero_socio'
      AND a.attnum > 0
      AND NOT a.attisdropped;

    IF col_type IS NULL THEN
        RAISE EXCEPTION 'cnb_app.socios.numero_socio no existe. Ejecutar migracion 008 antes.';
    END IF;

    IF col_type NOT IN ('integer', 'bigint', 'smallint') THEN
        IF EXISTS (
            SELECT 1
            FROM cnb_app.socios
            WHERE numero_socio IS NULL
               OR btrim(numero_socio::text) = ''
               OR btrim(numero_socio::text) !~ '^[0-9]{1,5}$'
        ) THEN
            RAISE EXCEPTION
                'Hay socios.numero_socio invalidos; corregir antes de migrar a INTEGER';
        END IF;

        EXECUTE $q$
            ALTER TABLE cnb_app.socios
            ALTER COLUMN numero_socio TYPE INTEGER
            USING btrim(numero_socio::text)::INTEGER
        $q$;
    END IF;

    ALTER TABLE cnb_app.socios ALTER COLUMN numero_socio SET NOT NULL;

    SELECT EXISTS (
        SELECT 1
        FROM pg_constraint c
        JOIN pg_class t ON c.conrelid = t.oid
        JOIN pg_namespace n ON t.relnamespace = n.oid
        WHERE n.nspname = 'cnb_app'
          AND t.relname = 'socios'
          AND c.contype IN ('p', 'u')
          AND pg_get_constraintdef(c.oid) ~* '\(numero_socio\)'
    ) INTO has_unique;

    IF NOT has_unique THEN
        IF EXISTS (
            SELECT numero_socio
            FROM cnb_app.socios
            GROUP BY numero_socio
            HAVING COUNT(*) > 1
        ) THEN
            RAISE EXCEPTION
                'Hay numero_socio duplicados en socios; no se puede crear UNIQUE/FK';
        END IF;

        ALTER TABLE cnb_app.socios
            DROP CONSTRAINT IF EXISTS socios_numero_socio_key;
        ALTER TABLE cnb_app.socios
            ADD CONSTRAINT socios_numero_socio_key UNIQUE (numero_socio);
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint c
        JOIN pg_class t ON c.conrelid = t.oid
        JOIN pg_namespace n ON t.relnamespace = n.oid
        WHERE n.nspname = 'cnb_app'
          AND t.relname = 'socios'
          AND c.conname = 'socios_numero_socio_check'
    ) THEN
        ALTER TABLE cnb_app.socios
            ADD CONSTRAINT socios_numero_socio_check
            CHECK (numero_socio >= 1 AND numero_socio <= 99999);
    END IF;
END $$;

-- 0b) Asegurar PK/UNIQUE en embarcaciones.id (Render a veces no lo tiene)
DO $$
DECLARE
    has_id_unique boolean;
    has_any_pk boolean;
    id_nullable boolean;
BEGIN
    IF to_regclass('cnb_app.embarcaciones') IS NULL THEN
        RAISE EXCEPTION 'cnb_app.embarcaciones no existe';
    END IF;

    SELECT EXISTS (
        SELECT 1
        FROM pg_constraint c
        JOIN pg_class t ON c.conrelid = t.oid
        JOIN pg_namespace n ON t.relnamespace = n.oid
        WHERE n.nspname = 'cnb_app'
          AND t.relname = 'embarcaciones'
          AND c.contype IN ('p', 'u')
          AND pg_get_constraintdef(c.oid) ~* '\(id\)'
    ) INTO has_id_unique;

    IF has_id_unique THEN
        RETURN;
    END IF;

    SELECT EXISTS (
        SELECT 1
        FROM pg_constraint c
        JOIN pg_class t ON c.conrelid = t.oid
        JOIN pg_namespace n ON t.relnamespace = n.oid
        WHERE n.nspname = 'cnb_app'
          AND t.relname = 'embarcaciones'
          AND c.contype = 'p'
    ) INTO has_any_pk;

    SELECT NOT a.attnotnull
      INTO id_nullable
    FROM pg_attribute a
    JOIN pg_class t ON a.attrelid = t.oid
    JOIN pg_namespace n ON t.relnamespace = n.oid
    WHERE n.nspname = 'cnb_app'
      AND t.relname = 'embarcaciones'
      AND a.attname = 'id'
      AND a.attnum > 0
      AND NOT a.attisdropped;

    IF id_nullable THEN
        IF EXISTS (SELECT 1 FROM cnb_app.embarcaciones WHERE id IS NULL) THEN
            RAISE EXCEPTION 'embarcaciones.id tiene NULLs; no se puede crear PK';
        END IF;
        ALTER TABLE cnb_app.embarcaciones ALTER COLUMN id SET NOT NULL;
    END IF;

    IF EXISTS (
        SELECT id FROM cnb_app.embarcaciones GROUP BY id HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION 'embarcaciones.id tiene duplicados; no se puede crear PK/UNIQUE';
    END IF;

    IF NOT has_any_pk THEN
        ALTER TABLE cnb_app.embarcaciones
            ADD CONSTRAINT embarcaciones_pkey PRIMARY KEY (id);
    ELSE
        -- Hay otra PK (rara); al menos UNIQUE(id) para poder referenciar.
        ALTER TABLE cnb_app.embarcaciones
            DROP CONSTRAINT IF EXISTS embarcaciones_id_key;
        ALTER TABLE cnb_app.embarcaciones
            ADD CONSTRAINT embarcaciones_id_key UNIQUE (id);
    END IF;
END $$;

-- 1) Foto de perfil del socio (base64 o data-url). Patron facial.
ALTER TABLE cnb_app.socios
    ADD COLUMN IF NOT EXISTS foto_perfil TEXT;

COMMENT ON COLUMN cnb_app.socios.foto_perfil IS
    'Foto de perfil del socio (base64). Patron para reconocimiento facial.';

-- 2) Relacion N:N socio <-> embarcacion (sin FK inline; se agregan despues)
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
        SELECT 1 FROM pg_constraint
        WHERE conname = 'socio_embarcacion_numero_socio_fkey'
    ) THEN
        ALTER TABLE cnb_app.socio_embarcacion
            ADD CONSTRAINT socio_embarcacion_numero_socio_fkey
            FOREIGN KEY (numero_socio) REFERENCES cnb_app.socios(numero_socio) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'socio_embarcacion_embarcacion_id_fkey'
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

-- 3) Eventos de relay (timbre / portones)
CREATE TABLE IF NOT EXISTS cnb_app.eventos_relay (
    id BIGSERIAL PRIMARY KEY,
    numero_socio INTEGER NOT NULL,
    tipo VARCHAR(30) NOT NULL
        CHECK (tipo IN ('timbre_marineros', 'porton')),
    destino VARCHAR(60) NOT NULL DEFAULT 'marineros',
    resultado VARCHAR(20) NOT NULL
        CHECK (resultado IN ('aprobado', 'rechazado', 'error')),
    latitud NUMERIC(10, 7),
    longitud NUMERIC(10, 7),
    distancia_m NUMERIC(10, 2),
    puntaje_facial NUMERIC(6, 4),
    observaciones TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'eventos_relay_numero_socio_fkey'
    ) THEN
        ALTER TABLE cnb_app.eventos_relay
            ADD CONSTRAINT eventos_relay_numero_socio_fkey
            FOREIGN KEY (numero_socio) REFERENCES cnb_app.socios(numero_socio) ON DELETE CASCADE;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_eventos_relay_socio_created
    ON cnb_app.eventos_relay (numero_socio, created_at DESC);

COMMIT;

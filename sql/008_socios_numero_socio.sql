-- 008: Unificacion de migraciones 006+007
-- socios.PK = numero_socio INTEGER (1..99999, hasta 5 digitos)
-- Reemplaza socio_id por numero_socio en tablas hijas + FKs + trigger.
--
-- Ejecutar:
--   psql -d CNBDB -f sql/008_socios_numero_socio.sql
--
-- Estado de origen tipico:
--   socios(id BIGSERIAL PK, numero_socio texto/numerico UNIQUE)
--   tablas con socio_id BIGINT -> socios(id)
-- Tambien soporta re-ejecucion parcial (ya migrado a numero_socio).

SET search_path TO cnb_app, public;

-- ---------------------------------------------------------------------------
-- 1) Quitar TODAS las FKs hacia socios
-- ---------------------------------------------------------------------------
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT c.conname, c.conrelid::regclass AS tbl
        FROM pg_constraint c
        WHERE c.confrelid = 'cnb_app.socios'::regclass
          AND c.contype = 'f'
    LOOP
        EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I', r.tbl, r.conname);
    END LOOP;
END $$;

-- ---------------------------------------------------------------------------
-- 2) Validar numero_socio en socios (solo digitos, max 5)
-- ---------------------------------------------------------------------------
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM cnb_app.socios
        WHERE numero_socio IS NULL
           OR btrim(numero_socio::text) = ''
           OR btrim(numero_socio::text) !~ '^[0-9]{1,5}$'
    ) THEN
        RAISE EXCEPTION
            'Hay socios con numero_socio invalido (debe ser numerico de 1 a 5 digitos)';
    END IF;
END $$;

-- ---------------------------------------------------------------------------
-- 3) Migrar socio_id -> numero_socio (INTEGER) en tablas hijas
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION cnb_app._migrate_socio_id_to_numero(p_table regclass)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    tbl text := p_table::text;
    has_socio_id boolean;
    has_numero boolean;
    tname text;
BEGIN
    tname := CASE
        WHEN position('.' IN tbl) > 0 THEN split_part(tbl, '.', 2)
        ELSE tbl
    END;

    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'cnb_app' AND table_name = tname AND column_name = 'socio_id'
    ) INTO has_socio_id;

    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'cnb_app' AND table_name = tname AND column_name = 'numero_socio'
    ) INTO has_numero;

    IF has_socio_id AND NOT has_numero THEN
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'cnb_app' AND table_name = 'socios' AND column_name = 'id'
        ) THEN
            EXECUTE format('ALTER TABLE %s ADD COLUMN numero_socio INTEGER', tbl);
            EXECUTE format(
                'UPDATE %s t
                 SET numero_socio = btrim(s.numero_socio::text)::INTEGER
                 FROM cnb_app.socios s
                 WHERE t.socio_id IS NOT NULL AND t.socio_id = s.id',
                tbl
            );
        ELSE
            RAISE EXCEPTION
                'Tabla % tiene socio_id pero socios ya no tiene id; migrar manualmente',
                tbl;
        END IF;
        EXECUTE format('ALTER TABLE %s DROP COLUMN socio_id', tbl);
    ELSIF has_socio_id AND has_numero THEN
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'cnb_app' AND table_name = 'socios' AND column_name = 'id'
        ) THEN
            EXECUTE format(
                'UPDATE %s t
                 SET numero_socio = btrim(s.numero_socio::text)::INTEGER
                 FROM cnb_app.socios s
                 WHERE t.socio_id IS NOT NULL
                   AND t.socio_id = s.id
                   AND t.numero_socio IS NULL',
                tbl
            );
        END IF;
        EXECUTE format('ALTER TABLE %s DROP COLUMN socio_id', tbl);
        EXECUTE format(
            'ALTER TABLE %s ALTER COLUMN numero_socio TYPE INTEGER
             USING NULLIF(btrim(numero_socio::text), '''')::INTEGER',
            tbl
        );
    ELSIF has_numero THEN
        EXECUTE format(
            'ALTER TABLE %s ALTER COLUMN numero_socio TYPE INTEGER
             USING NULLIF(btrim(numero_socio::text), '''')::INTEGER',
            tbl
        );
    END IF;
END;
$$;

SELECT cnb_app._migrate_socio_id_to_numero('cnb_app.embarcaciones');
SELECT cnb_app._migrate_socio_id_to_numero('cnb_app.reservas_varadero');
SELECT cnb_app._migrate_socio_id_to_numero('cnb_app.tareas');
SELECT cnb_app._migrate_socio_id_to_numero('cnb_app.socio_acceso');
SELECT cnb_app._migrate_socio_id_to_numero('cnb_app.socio_sesiones');
SELECT cnb_app._migrate_socio_id_to_numero('cnb_app.solicitudes_reunion_cd');
SELECT cnb_app._migrate_socio_id_to_numero('cnb_app.notas_cd');
SELECT cnb_app._migrate_socio_id_to_numero('cnb_app.accesos_porton');

DROP FUNCTION cnb_app._migrate_socio_id_to_numero(regclass);

-- ---------------------------------------------------------------------------
-- 4) socios: eliminar id; PK = numero_socio INTEGER (1..99999)
-- ---------------------------------------------------------------------------
ALTER TABLE cnb_app.socios DROP CONSTRAINT IF EXISTS socios_pkey;
ALTER TABLE cnb_app.socios DROP COLUMN IF EXISTS id;
ALTER TABLE cnb_app.socios DROP CONSTRAINT IF EXISTS socios_numero_socio_key;
ALTER TABLE cnb_app.socios DROP CONSTRAINT IF EXISTS socios_numero_socio_check;

ALTER TABLE cnb_app.socios
    ALTER COLUMN numero_socio TYPE INTEGER
    USING btrim(numero_socio::text)::INTEGER;

ALTER TABLE cnb_app.socios ALTER COLUMN numero_socio SET NOT NULL;

ALTER TABLE cnb_app.socios
    ADD CONSTRAINT socios_numero_socio_check
    CHECK (numero_socio >= 1 AND numero_socio <= 99999);

ALTER TABLE cnb_app.socios ADD PRIMARY KEY (numero_socio);

-- ---------------------------------------------------------------------------
-- 5) NOT NULL / UNIQUE / CHECK en tablas hijas
-- ---------------------------------------------------------------------------
ALTER TABLE cnb_app.reservas_varadero ALTER COLUMN numero_socio SET NOT NULL;
ALTER TABLE cnb_app.socio_acceso ALTER COLUMN numero_socio SET NOT NULL;
ALTER TABLE cnb_app.socio_sesiones ALTER COLUMN numero_socio SET NOT NULL;
ALTER TABLE cnb_app.solicitudes_reunion_cd ALTER COLUMN numero_socio SET NOT NULL;
ALTER TABLE cnb_app.notas_cd ALTER COLUMN numero_socio SET NOT NULL;
ALTER TABLE cnb_app.accesos_porton ALTER COLUMN numero_socio SET NOT NULL;

ALTER TABLE cnb_app.socio_acceso DROP CONSTRAINT IF EXISTS socio_acceso_socio_id_key;
ALTER TABLE cnb_app.socio_acceso DROP CONSTRAINT IF EXISTS socio_acceso_numero_socio_key;
ALTER TABLE cnb_app.socio_acceso
    ADD CONSTRAINT socio_acceso_numero_socio_key UNIQUE (numero_socio);

DO $$
DECLARE
    tbl text;
    tname text;
BEGIN
    FOREACH tbl IN ARRAY ARRAY[
        'cnb_app.embarcaciones',
        'cnb_app.reservas_varadero',
        'cnb_app.tareas',
        'cnb_app.socio_acceso',
        'cnb_app.socio_sesiones',
        'cnb_app.solicitudes_reunion_cd',
        'cnb_app.notas_cd',
        'cnb_app.accesos_porton'
    ]
    LOOP
        tname := split_part(tbl, '.', 2);

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'cnb_app'
              AND table_name = tname
              AND column_name = 'socio_id'
        ) THEN
            RAISE EXCEPTION
                'La tabla %.% todavia tiene socio_id tras la migracion',
                'cnb_app', tname;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'cnb_app'
              AND table_name = tname
              AND column_name = 'numero_socio'
        ) THEN
            CONTINUE;
        END IF;

        EXECUTE format(
            'ALTER TABLE %s DROP CONSTRAINT IF EXISTS %I',
            tbl,
            tname || '_numero_socio_check'
        );

        EXECUTE format(
            'ALTER TABLE %s
             ADD CONSTRAINT %I
             CHECK (numero_socio IS NULL OR (numero_socio >= 1 AND numero_socio <= 99999))',
            tbl,
            tname || '_numero_socio_check'
        );
    END LOOP;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM cnb_app.socios
        WHERE numero_socio < 1 OR numero_socio > 99999
    ) THEN
        RAISE EXCEPTION 'Hay socios fuera de rango 1..99999';
    END IF;
END $$;

-- ---------------------------------------------------------------------------
-- 6) Indices
-- ---------------------------------------------------------------------------
DROP INDEX IF EXISTS cnb_app.idx_embarcaciones_socio;
DROP INDEX IF EXISTS cnb_app.idx_reservas_varadero_socio;
DROP INDEX IF EXISTS cnb_app.idx_embarcaciones_numero_socio;
DROP INDEX IF EXISTS cnb_app.idx_reservas_varadero_numero_socio;
DROP INDEX IF EXISTS cnb_app.idx_tareas_numero_socio;

CREATE INDEX idx_embarcaciones_numero_socio ON cnb_app.embarcaciones(numero_socio);
CREATE INDEX idx_reservas_varadero_numero_socio ON cnb_app.reservas_varadero(numero_socio);
CREATE INDEX idx_tareas_numero_socio ON cnb_app.tareas(numero_socio);

-- ---------------------------------------------------------------------------
-- 7) FKs: numero_socio -> socios(numero_socio)
-- ---------------------------------------------------------------------------
ALTER TABLE cnb_app.embarcaciones DROP CONSTRAINT IF EXISTS embarcaciones_numero_socio_fkey;
ALTER TABLE cnb_app.embarcaciones
    ADD CONSTRAINT embarcaciones_numero_socio_fkey
    FOREIGN KEY (numero_socio) REFERENCES cnb_app.socios(numero_socio) ON DELETE SET NULL;

ALTER TABLE cnb_app.reservas_varadero DROP CONSTRAINT IF EXISTS reservas_varadero_numero_socio_fkey;
ALTER TABLE cnb_app.reservas_varadero
    ADD CONSTRAINT reservas_varadero_numero_socio_fkey
    FOREIGN KEY (numero_socio) REFERENCES cnb_app.socios(numero_socio) ON DELETE RESTRICT;

ALTER TABLE cnb_app.tareas DROP CONSTRAINT IF EXISTS tareas_numero_socio_fkey;
ALTER TABLE cnb_app.tareas
    ADD CONSTRAINT tareas_numero_socio_fkey
    FOREIGN KEY (numero_socio) REFERENCES cnb_app.socios(numero_socio) ON DELETE SET NULL;

ALTER TABLE cnb_app.socio_acceso DROP CONSTRAINT IF EXISTS socio_acceso_numero_socio_fkey;
ALTER TABLE cnb_app.socio_acceso
    ADD CONSTRAINT socio_acceso_numero_socio_fkey
    FOREIGN KEY (numero_socio) REFERENCES cnb_app.socios(numero_socio) ON DELETE CASCADE;

ALTER TABLE cnb_app.socio_sesiones DROP CONSTRAINT IF EXISTS socio_sesiones_numero_socio_fkey;
ALTER TABLE cnb_app.socio_sesiones
    ADD CONSTRAINT socio_sesiones_numero_socio_fkey
    FOREIGN KEY (numero_socio) REFERENCES cnb_app.socios(numero_socio) ON DELETE CASCADE;

ALTER TABLE cnb_app.solicitudes_reunion_cd DROP CONSTRAINT IF EXISTS solicitudes_reunion_cd_numero_socio_fkey;
ALTER TABLE cnb_app.solicitudes_reunion_cd
    ADD CONSTRAINT solicitudes_reunion_cd_numero_socio_fkey
    FOREIGN KEY (numero_socio) REFERENCES cnb_app.socios(numero_socio) ON DELETE CASCADE;

ALTER TABLE cnb_app.notas_cd DROP CONSTRAINT IF EXISTS notas_cd_numero_socio_fkey;
ALTER TABLE cnb_app.notas_cd
    ADD CONSTRAINT notas_cd_numero_socio_fkey
    FOREIGN KEY (numero_socio) REFERENCES cnb_app.socios(numero_socio) ON DELETE CASCADE;

ALTER TABLE cnb_app.accesos_porton DROP CONSTRAINT IF EXISTS accesos_porton_numero_socio_fkey;
ALTER TABLE cnb_app.accesos_porton
    ADD CONSTRAINT accesos_porton_numero_socio_fkey
    FOREIGN KEY (numero_socio) REFERENCES cnb_app.socios(numero_socio) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- 8) Trigger varadero (INTEGER)
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION cnb_app.cnb_validar_reserva_varadero()
RETURNS TRIGGER AS $$
DECLARE
    dias_anuales INTEGER;
    reserva_activa BOOLEAN;
    embarcacion_socio INTEGER;
    embarcacion_eslora NUMERIC(5,2);
    espacio_eslora_max NUMERIC(5,2);
BEGIN
    IF NEW.fecha_fin < NEW.fecha_inicio THEN
        RAISE EXCEPTION 'La fecha de fin no puede ser anterior a la fecha de inicio';
    END IF;

    IF ((NEW.fecha_fin - NEW.fecha_inicio) + 1) > 15 THEN
        RAISE EXCEPTION 'Una reserva de varadero no puede superar 15 dias';
    END IF;

    SELECT numero_socio, eslora_m INTO embarcacion_socio, embarcacion_eslora
    FROM cnb_app.embarcaciones
    WHERE id = NEW.embarcacion_id;

    IF embarcacion_socio IS DISTINCT FROM NEW.numero_socio THEN
        RAISE EXCEPTION 'La embarcacion debe pertenecer al socio que reserva';
    END IF;

    SELECT eslora_max_m INTO espacio_eslora_max
    FROM cnb_app.espacios_varadero
    WHERE id = NEW.espacio_id AND activo = TRUE;

    IF espacio_eslora_max IS NULL THEN
        RAISE EXCEPTION 'El espacio de varadero no existe o no esta activo';
    END IF;

    IF embarcacion_eslora > espacio_eslora_max THEN
        RAISE EXCEPTION 'La embarcacion supera la eslora maxima del espacio';
    END IF;

    reserva_activa := NEW.estado IN ('pendiente', 'confirmada', 'en_curso');

    IF reserva_activa THEN
        IF EXISTS (
            SELECT 1
            FROM cnb_app.reservas_varadero r
            WHERE r.espacio_id = NEW.espacio_id
              AND r.id <> COALESCE(NEW.id, 0)
              AND r.estado IN ('pendiente', 'confirmada', 'en_curso')
              AND daterange(r.fecha_inicio, r.fecha_fin + 1, '[)')
                  && daterange(NEW.fecha_inicio, NEW.fecha_fin + 1, '[)')
        ) THEN
            RAISE EXCEPTION 'El espacio de varadero ya esta reservado en ese rango';
        END IF;

        SELECT COALESCE(SUM(r.cantidad_dias), 0)
        INTO dias_anuales
        FROM cnb_app.reservas_varadero r
        WHERE r.numero_socio = NEW.numero_socio
          AND r.id <> COALESCE(NEW.id, 0)
          AND r.estado IN ('pendiente', 'confirmada', 'en_curso', 'finalizada')
          AND EXTRACT(YEAR FROM r.fecha_inicio) = EXTRACT(YEAR FROM NEW.fecha_inicio);

        IF (dias_anuales + ((NEW.fecha_fin - NEW.fecha_inicio) + 1)) > 15 THEN
            RAISE EXCEPTION 'El socio supera el maximo anual de 15 dias de varadero';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql SET search_path = cnb_app, public;

-- Asegura created_at TIMESTAMPTZ NOT NULL DEFAULT NOW() en todas las tablas de cnb_app.
--   psql -d CNBDB -f sql/009_created_at_default_now.sql

SET search_path TO cnb_app, public;

-- 1) Agregar created_at a tablas que no lo tienen (p.ej. tarea_asignaciones).
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT n.nspname AS schema_name, c.relname AS table_name
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE c.relkind = 'r'
          AND n.nspname = 'cnb_app'
          AND NOT EXISTS (
              SELECT 1
              FROM pg_attribute a
              WHERE a.attrelid = c.oid
                AND a.attname = 'created_at'
                AND a.attnum > 0
                AND NOT a.attisdropped
          )
    LOOP
        EXECUTE format(
            'ALTER TABLE %I.%I ADD COLUMN created_at TIMESTAMPTZ',
            r.schema_name,
            r.table_name
        );

        -- Si existe asignado_at (tarea_asignaciones), reutilizarlo como created_at.
        IF EXISTS (
            SELECT 1
            FROM pg_attribute a
            JOIN pg_class c ON c.oid = a.attrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = r.schema_name
              AND c.relname = r.table_name
              AND a.attname = 'asignado_at'
              AND a.attnum > 0
              AND NOT a.attisdropped
        ) THEN
            EXECUTE format(
                'UPDATE %I.%I SET created_at = COALESCE(asignado_at, NOW()) WHERE created_at IS NULL',
                r.schema_name,
                r.table_name
            );
        ELSE
            EXECUTE format(
                'UPDATE %I.%I SET created_at = NOW() WHERE created_at IS NULL',
                r.schema_name,
                r.table_name
            );
        END IF;

        EXECUTE format(
            'ALTER TABLE %I.%I
                 ALTER COLUMN created_at SET DEFAULT NOW(),
                 ALTER COLUMN created_at SET NOT NULL',
            r.schema_name,
            r.table_name
        );
    END LOOP;
END $$;

-- 2) Forzar DEFAULT NOW() en todo created_at existente.
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT n.nspname AS schema_name, c.relname AS table_name
        FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE a.attname = 'created_at'
          AND a.attnum > 0
          AND NOT a.attisdropped
          AND c.relkind = 'r'
          AND n.nspname = 'cnb_app'
    LOOP
        EXECUTE format(
            'ALTER TABLE %I.%I ALTER COLUMN created_at SET DEFAULT NOW()',
            r.schema_name,
            r.table_name
        );
    END LOOP;
END $$;

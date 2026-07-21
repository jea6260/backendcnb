-- Asegura updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW() en todas las tablas de cnb_app.
--   psql -d CNBDB -f sql/010_updated_at_default_now.sql

SET search_path TO cnb_app, public;

-- 1) Agregar updated_at a tablas que no lo tienen.
DO $$
DECLARE
    r RECORD;
    has_created_at BOOLEAN;
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
                AND a.attname = 'updated_at'
                AND a.attnum > 0
                AND NOT a.attisdropped
          )
    LOOP
        EXECUTE format(
            'ALTER TABLE %I.%I ADD COLUMN updated_at TIMESTAMPTZ',
            r.schema_name,
            r.table_name
        );

        SELECT EXISTS (
            SELECT 1
            FROM pg_attribute a
            JOIN pg_class c ON c.oid = a.attrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = r.schema_name
              AND c.relname = r.table_name
              AND a.attname = 'created_at'
              AND a.attnum > 0
              AND NOT a.attisdropped
        ) INTO has_created_at;

        IF has_created_at THEN
            EXECUTE format(
                'UPDATE %I.%I SET updated_at = COALESCE(created_at, NOW()) WHERE updated_at IS NULL',
                r.schema_name,
                r.table_name
            );
        ELSE
            EXECUTE format(
                'UPDATE %I.%I SET updated_at = NOW() WHERE updated_at IS NULL',
                r.schema_name,
                r.table_name
            );
        END IF;

        EXECUTE format(
            'ALTER TABLE %I.%I
                 ALTER COLUMN updated_at SET DEFAULT NOW(),
                 ALTER COLUMN updated_at SET NOT NULL',
            r.schema_name,
            r.table_name
        );
    END LOOP;
END $$;

-- 2) Forzar DEFAULT NOW() en todo updated_at existente.
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT n.nspname AS schema_name, c.relname AS table_name
        FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE a.attname = 'updated_at'
          AND a.attnum > 0
          AND NOT a.attisdropped
          AND c.relkind = 'r'
          AND n.nspname = 'cnb_app'
    LOOP
        EXECUTE format(
            'ALTER TABLE %I.%I ALTER COLUMN updated_at SET DEFAULT NOW()',
            r.schema_name,
            r.table_name
        );
    END LOOP;
END $$;

-- Columnas del padron Agua/Tierra que el admin ya lee y la base local no tenia.
-- Aditiva: no borra embarcaciones ni tablas vecinas.
--   psql -h 127.0.0.1 -p 5433 -d CNBDB -f sql/017_embarcaciones_columnas_padron.sql

SET search_path TO cnb_app, public;

ALTER TABLE embarcaciones
    ADD COLUMN IF NOT EXISTS ambito VARCHAR(20) NOT NULL DEFAULT 'agua',
    ADD COLUMN IF NOT EXISTS modelo VARCHAR(120),
    ADD COLUMN IF NOT EXISTS m2_matricula NUMERIC(10,3),
    ADD COLUMN IF NOT EXISTS metros_comprados NUMERIC(10,2),
    ADD COLUMN IF NOT EXISTS paga_expensas_m2 NUMERIC(10,2),
    ADD COLUMN IF NOT EXISTS ubicacion_id BIGINT,
    ADD COLUMN IF NOT EXISTS eslora_medida_m NUMERIC(5,2),
    ADD COLUMN IF NOT EXISTS manga_medida_m NUMERIC(5,2),
    ADD COLUMN IF NOT EXISTS m2_medidos NUMERIC(10,3),
    ADD COLUMN IF NOT EXISTS estado_padron_id BIGINT;

ALTER TABLE embarcaciones
    ALTER COLUMN eslora_m DROP NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'embarcaciones_ambito_check'
          AND conrelid = 'cnb_app.embarcaciones'::regclass
    ) THEN
        ALTER TABLE embarcaciones
            ADD CONSTRAINT embarcaciones_ambito_check
            CHECK (ambito IN ('agua', 'tierra'));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'embarcaciones_ubicacion_id_fkey'
          AND conrelid = 'cnb_app.embarcaciones'::regclass
    ) THEN
        ALTER TABLE embarcaciones
            ADD CONSTRAINT embarcaciones_ubicacion_id_fkey
            FOREIGN KEY (ubicacion_id) REFERENCES ubicaciones(id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'embarcaciones_estado_padron_id_fkey'
          AND conrelid = 'cnb_app.embarcaciones'::regclass
    ) THEN
        ALTER TABLE embarcaciones
            ADD CONSTRAINT embarcaciones_estado_padron_id_fkey
            FOREIGN KEY (estado_padron_id) REFERENCES estados_padron(id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_embarcaciones_ambito ON embarcaciones (ambito);
CREATE INDEX IF NOT EXISTS idx_embarcaciones_ubicacion_id ON embarcaciones (ubicacion_id);
CREATE INDEX IF NOT EXISTS idx_embarcaciones_estado_padron_id ON embarcaciones (estado_padron_id);

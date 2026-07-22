-- Amplia embarcaciones con los campos de la planilla Propietarios Marinas (hoja Agua).
--   psql -d CNBDB -f sql/012_embarcaciones_campos_padron.sql
--
-- Columnas de la planilla -> columnas DB:
--   Estado            -> estado_padron
--   N* SOCIO          -> numero_socio (ya existia)
--   NOMBRE Y APELLIDO -> (via socios; no se duplica)
--   TIPO              -> tipo (ya existia)
--   MODELO            -> modelo
--   NOMBRE            -> nombre (ya existia)
--   Matric            -> matricula (ya existia)
--   Eslora            -> eslora_m (ya existia; ahora nullable para amarres vacios)
--   Manga             -> manga_m (ya existia)
--   M2 x Matric       -> m2_matricula
--   Mts Comprado      -> metros_comprados
--   Paga Expensas     -> paga_expensas_m2
--   Ubicacion         -> ubicacion
--   Observ.           -> observaciones (ya existia)
--   (hoja Agua/Tierra)-> ambito

SET search_path TO cnb_app, public;

ALTER TABLE embarcaciones
    ADD COLUMN IF NOT EXISTS modelo VARCHAR(120),
    ADD COLUMN IF NOT EXISTS m2_matricula NUMERIC(10,3),
    ADD COLUMN IF NOT EXISTS metros_comprados NUMERIC(10,2),
    ADD COLUMN IF NOT EXISTS paga_expensas_m2 NUMERIC(10,2),
    ADD COLUMN IF NOT EXISTS ubicacion VARCHAR(120),
    ADD COLUMN IF NOT EXISTS estado_padron VARCHAR(60),
    ADD COLUMN IF NOT EXISTS ambito VARCHAR(20) NOT NULL DEFAULT 'agua';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'embarcaciones_ambito_check'
          AND conrelid = 'cnb_app.embarcaciones'::regclass
    ) THEN
        ALTER TABLE embarcaciones
            ADD CONSTRAINT embarcaciones_ambito_check
            CHECK (ambito IN ('agua', 'tierra'));
    END IF;
END $$;

-- Permitir eslora vacia (p.ej. amarres DESOCUPADO en el padron)
ALTER TABLE embarcaciones
    ALTER COLUMN eslora_m DROP NOT NULL;

CREATE INDEX IF NOT EXISTS idx_embarcaciones_ubicacion ON embarcaciones (ubicacion);
CREATE INDEX IF NOT EXISTS idx_embarcaciones_ambito ON embarcaciones (ambito);
CREATE INDEX IF NOT EXISTS idx_embarcaciones_estado_padron ON embarcaciones (estado_padron);

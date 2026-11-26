SET search_path TO cnb_app, public;

DO $$
BEGIN
    IF to_regclass('cnb_app.marineros') IS NOT NULL
       AND to_regclass('cnb_app.personal_cnb') IS NULL THEN
        ALTER TABLE cnb_app.marineros RENAME TO personal_cnb;
    END IF;
END $$;

ALTER TABLE cnb_app.personal_cnb
    ADD COLUMN IF NOT EXISTS rol VARCHAR(20) NOT NULL DEFAULT 'marinero';

ALTER TABLE cnb_app.personal_cnb
    DROP CONSTRAINT IF EXISTS personal_cnb_rol_check;

ALTER TABLE cnb_app.personal_cnb
    ADD CONSTRAINT personal_cnb_rol_check
    CHECK (rol IN ('admin', 'marinero', 'operaciones', 'guardia'));

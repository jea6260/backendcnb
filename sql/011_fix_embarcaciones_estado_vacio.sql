-- Corrige embarcaciones con estado vacio (legacy) para que aparezcan en planificacion.
--   psql -d CNBDB -f sql/011_fix_embarcaciones_estado_vacio.sql

SET search_path TO cnb_app, public;

UPDATE embarcaciones
SET estado = 'activa',
    updated_at = NOW()
WHERE COALESCE(NULLIF(TRIM(estado), ''), '') = '';

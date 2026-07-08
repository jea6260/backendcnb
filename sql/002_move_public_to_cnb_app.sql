-- Mueve las tablas operativas CNB desde public hacia cnb_app.
-- Ejecutar conectado a CNBDB:
--   psql -h 127.0.0.1 -p 5432 -U postgres -d CNBDB -f sql/002_move_public_to_cnb_app.sql

BEGIN;

CREATE SCHEMA IF NOT EXISTS cnb_app;

ALTER TABLE IF EXISTS public.socios SET SCHEMA cnb_app;
ALTER TABLE IF EXISTS public.marineros SET SCHEMA cnb_app;
ALTER TABLE IF EXISTS public.embarcaciones SET SCHEMA cnb_app;
ALTER TABLE IF EXISTS public.vehiculos SET SCHEMA cnb_app;
ALTER TABLE IF EXISTS public.espacios_varadero SET SCHEMA cnb_app;
ALTER TABLE IF EXISTS public.reservas_varadero SET SCHEMA cnb_app;
ALTER TABLE IF EXISTS public.tareas SET SCHEMA cnb_app;
ALTER TABLE IF EXISTS public.tarea_asignaciones SET SCHEMA cnb_app;
ALTER TABLE IF EXISTS public.avances_tarea SET SCHEMA cnb_app;

ALTER SEQUENCE IF EXISTS public.socios_id_seq SET SCHEMA cnb_app;
ALTER SEQUENCE IF EXISTS public.marineros_id_seq SET SCHEMA cnb_app;
ALTER SEQUENCE IF EXISTS public.embarcaciones_id_seq SET SCHEMA cnb_app;
ALTER SEQUENCE IF EXISTS public.vehiculos_id_seq SET SCHEMA cnb_app;
ALTER SEQUENCE IF EXISTS public.espacios_varadero_id_seq SET SCHEMA cnb_app;
ALTER SEQUENCE IF EXISTS public.reservas_varadero_id_seq SET SCHEMA cnb_app;
ALTER SEQUENCE IF EXISTS public.tareas_id_seq SET SCHEMA cnb_app;
ALTER SEQUENCE IF EXISTS public.tarea_asignaciones_id_seq SET SCHEMA cnb_app;
ALTER SEQUENCE IF EXISTS public.avances_tarea_id_seq SET SCHEMA cnb_app;

DO $$
BEGIN
    IF to_regprocedure('public.cnb_touch_updated_at()') IS NOT NULL THEN
        EXECUTE 'ALTER FUNCTION public.cnb_touch_updated_at() SET SCHEMA cnb_app';
    END IF;

    IF to_regprocedure('public.cnb_validar_reserva_varadero()') IS NOT NULL THEN
        EXECUTE 'ALTER FUNCTION public.cnb_validar_reserva_varadero() SET SCHEMA cnb_app';
    END IF;

    IF to_regprocedure('cnb_app.cnb_touch_updated_at()') IS NOT NULL THEN
        EXECUTE 'ALTER FUNCTION cnb_app.cnb_touch_updated_at() SET search_path = cnb_app, public';
    END IF;

    IF to_regprocedure('cnb_app.cnb_validar_reserva_varadero()') IS NOT NULL THEN
        EXECUTE 'ALTER FUNCTION cnb_app.cnb_validar_reserva_varadero() SET search_path = cnb_app, public';
    END IF;
END
$$;

COMMIT;

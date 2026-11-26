-- El personal (operador y admin) puede pedir el timbre sin numero de socio.
-- Ejecutar conectado a CNBDB:
--   psql -d CNBDB -f sql/024_timbre_personal.sql

SET search_path TO cnb_app, public;

ALTER TABLE timbre_cola ALTER COLUMN numero_socio DROP NOT NULL;

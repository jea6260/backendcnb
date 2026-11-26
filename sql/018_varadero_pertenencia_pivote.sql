-- La reserva de varadero pertenece al socio si hay fila en el pivote
-- socio_embarcacion (titular, cotitular o cualquier rol), no por
-- embarcaciones.numero_socio.
--
--   psql -h 127.0.0.1 -p 5433 -U cnb_user -d CNBDB -f sql/018_varadero_pertenencia_pivote.sql

CREATE OR REPLACE FUNCTION cnb_app.cnb_validar_reserva_varadero()
RETURNS TRIGGER AS $$
DECLARE
    dias_anuales INTEGER;
    reserva_activa BOOLEAN;
    embarcacion_eslora NUMERIC(5,2);
    espacio_eslora_max NUMERIC(5,2);
BEGIN
    IF NEW.fecha_fin < NEW.fecha_inicio THEN
        RAISE EXCEPTION 'La fecha de fin no puede ser anterior a la fecha de inicio';
    END IF;

    IF ((NEW.fecha_fin - NEW.fecha_inicio) + 1) > 15 THEN
        RAISE EXCEPTION 'Una reserva de varadero no puede superar 15 dias';
    END IF;

    SELECT eslora_m INTO embarcacion_eslora
    FROM cnb_app.embarcaciones
    WHERE id = NEW.embarcacion_id;

    IF NOT EXISTS (
        SELECT 1
        FROM cnb_app.socio_embarcacion se
        WHERE se.embarcacion_id = NEW.embarcacion_id
          AND se.numero_socio = NEW.numero_socio
    ) THEN
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
            WHERE r.numero_socio = NEW.numero_socio
              AND r.id <> COALESCE(NEW.id, 0)
              AND r.estado IN ('pendiente', 'confirmada', 'en_curso')
        ) THEN
            RAISE EXCEPTION 'El socio ya tiene una reserva de varadero abierta';
        END IF;

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

-- SQL ejecutado automaticamente al crear el volumen de Postgres en Docker.
-- Equivalente a sql/001_create_cnbdb.sql sin CREATE DATABASE (la base ya existe).

CREATE SCHEMA IF NOT EXISTS cnb_app;
SET search_path TO cnb_app, public;

CREATE TABLE socios (
    id BIGSERIAL PRIMARY KEY,
    numero_socio VARCHAR(30) NOT NULL UNIQUE,
    nombre VARCHAR(120) NOT NULL,
    apellido VARCHAR(120) NOT NULL,
    email VARCHAR(180),
    telefono VARCHAR(60),
    documento VARCHAR(40),
    estado VARCHAR(20) NOT NULL DEFAULT 'activo'
        CHECK (estado IN ('activo', 'suspendido', 'baja')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE marineros (
    id BIGSERIAL PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    apellido VARCHAR(120) NOT NULL,
    email VARCHAR(180),
    telefono VARCHAR(60),
    especialidad VARCHAR(120),
    estado VARCHAR(20) NOT NULL DEFAULT 'activo'
        CHECK (estado IN ('activo', 'licencia', 'baja')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE embarcaciones (
    id BIGSERIAL PRIMARY KEY,
    socio_id BIGINT REFERENCES socios(id) ON DELETE SET NULL,
    nombre VARCHAR(160) NOT NULL,
    matricula VARCHAR(80) UNIQUE,
    tipo VARCHAR(80) NOT NULL DEFAULT 'velero',
    eslora_m NUMERIC(5,2) NOT NULL CHECK (eslora_m > 0),
    manga_m NUMERIC(5,2),
    calado_m NUMERIC(5,2),
    es_cnb BOOLEAN NOT NULL DEFAULT FALSE,
    estado VARCHAR(20) NOT NULL DEFAULT 'activa'
        CHECK (estado IN ('activa', 'mantenimiento', 'inactiva')),
    observaciones TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE vehiculos (
    id BIGSERIAL PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    tipo VARCHAR(60) NOT NULL
        CHECK (tipo IN ('tractor', 'camioneta', 'grua', 'otro')),
    patente VARCHAR(40),
    estado VARCHAR(30) NOT NULL DEFAULT 'disponible'
        CHECK (estado IN ('disponible', 'en_uso', 'mantenimiento', 'fuera_servicio')),
    horometro NUMERIC(10,1),
    ultimo_mantenimiento DATE,
    observaciones TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE espacios_varadero (
    id BIGSERIAL PRIMARY KEY,
    codigo VARCHAR(40) NOT NULL UNIQUE,
    descripcion VARCHAR(180),
    eslora_max_m NUMERIC(5,2) NOT NULL CHECK (eslora_max_m > 0),
    manga_max_m NUMERIC(5,2),
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE reservas_varadero (
    id BIGSERIAL PRIMARY KEY,
    socio_id BIGINT NOT NULL REFERENCES socios(id) ON DELETE RESTRICT,
    embarcacion_id BIGINT NOT NULL REFERENCES embarcaciones(id) ON DELETE RESTRICT,
    espacio_id BIGINT NOT NULL REFERENCES espacios_varadero(id) ON DELETE RESTRICT,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    cantidad_dias INTEGER GENERATED ALWAYS AS ((fecha_fin - fecha_inicio) + 1) STORED,
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado IN ('pendiente', 'confirmada', 'en_curso', 'finalizada', 'cancelada', 'rechazada')),
    motivo VARCHAR(160) NOT NULL DEFAULT 'limpieza_mantenimiento',
    observaciones TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (fecha_fin >= fecha_inicio)
);

CREATE TABLE tareas (
    id BIGSERIAL PRIMARY KEY,
    titulo VARCHAR(180) NOT NULL,
    descripcion TEXT,
    tipo VARCHAR(40) NOT NULL DEFAULT 'general'
        CHECK (tipo IN ('general', 'varadero', 'mantenimiento', 'limpieza', 'seguridad', 'administrativa')),
    prioridad VARCHAR(20) NOT NULL DEFAULT 'media'
        CHECK (prioridad IN ('baja', 'media', 'alta', 'urgente')),
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado IN ('pendiente', 'asignada', 'en_progreso', 'bloqueada', 'finalizada', 'cancelada')),
    progreso INTEGER NOT NULL DEFAULT 0 CHECK (progreso BETWEEN 0 AND 100),
    socio_id BIGINT REFERENCES socios(id) ON DELETE SET NULL,
    embarcacion_id BIGINT REFERENCES embarcaciones(id) ON DELETE SET NULL,
    reserva_varadero_id BIGINT REFERENCES reservas_varadero(id) ON DELETE SET NULL,
    vehiculo_id BIGINT REFERENCES vehiculos(id) ON DELETE SET NULL,
    fecha_planificada DATE,
    fecha_limite DATE,
    fecha_inicio TIMESTAMPTZ,
    fecha_finalizacion TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE tarea_asignaciones (
    id BIGSERIAL PRIMARY KEY,
    tarea_id BIGINT NOT NULL REFERENCES tareas(id) ON DELETE CASCADE,
    marinero_id BIGINT NOT NULL REFERENCES marineros(id) ON DELETE RESTRICT,
    rol VARCHAR(80),
    asignado_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finalizado_at TIMESTAMPTZ,
    UNIQUE (tarea_id, marinero_id)
);

CREATE TABLE avances_tarea (
    id BIGSERIAL PRIMARY KEY,
    tarea_id BIGINT NOT NULL REFERENCES tareas(id) ON DELETE CASCADE,
    marinero_id BIGINT REFERENCES marineros(id) ON DELETE SET NULL,
    progreso INTEGER NOT NULL CHECK (progreso BETWEEN 0 AND 100),
    estado VARCHAR(20) NOT NULL
        CHECK (estado IN ('pendiente', 'asignada', 'en_progreso', 'bloqueada', 'finalizada', 'cancelada')),
    comentario TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_embarcaciones_socio ON embarcaciones(socio_id);
CREATE INDEX idx_reservas_varadero_fechas ON reservas_varadero(fecha_inicio, fecha_fin);
CREATE INDEX idx_reservas_varadero_socio ON reservas_varadero(socio_id);
CREATE INDEX idx_reservas_varadero_espacio ON reservas_varadero(espacio_id);
CREATE INDEX idx_tareas_estado ON tareas(estado);
CREATE INDEX idx_tareas_fecha_planificada ON tareas(fecha_planificada);
CREATE INDEX idx_asignaciones_marinero ON tarea_asignaciones(marinero_id);

CREATE OR REPLACE FUNCTION cnb_touch_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SET search_path = cnb_app, public;

CREATE TRIGGER trg_socios_updated_at BEFORE UPDATE ON socios
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

CREATE TRIGGER trg_marineros_updated_at BEFORE UPDATE ON marineros
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

CREATE TRIGGER trg_embarcaciones_updated_at BEFORE UPDATE ON embarcaciones
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

CREATE TRIGGER trg_vehiculos_updated_at BEFORE UPDATE ON vehiculos
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

CREATE TRIGGER trg_espacios_varadero_updated_at BEFORE UPDATE ON espacios_varadero
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

CREATE TRIGGER trg_reservas_varadero_updated_at BEFORE UPDATE ON reservas_varadero
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

CREATE TRIGGER trg_tareas_updated_at BEFORE UPDATE ON tareas
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

CREATE OR REPLACE FUNCTION cnb_validar_reserva_varadero()
RETURNS TRIGGER AS $$
DECLARE
    dias_anuales INTEGER;
    reserva_activa BOOLEAN;
    embarcacion_socio BIGINT;
    embarcacion_eslora NUMERIC(5,2);
    espacio_eslora_max NUMERIC(5,2);
BEGIN
    IF NEW.fecha_fin < NEW.fecha_inicio THEN
        RAISE EXCEPTION 'La fecha de fin no puede ser anterior a la fecha de inicio';
    END IF;

    IF ((NEW.fecha_fin - NEW.fecha_inicio) + 1) > 15 THEN
        RAISE EXCEPTION 'Una reserva de varadero no puede superar 15 dias';
    END IF;

    SELECT socio_id, eslora_m INTO embarcacion_socio, embarcacion_eslora
    FROM embarcaciones
    WHERE id = NEW.embarcacion_id;

    IF embarcacion_socio IS DISTINCT FROM NEW.socio_id THEN
        RAISE EXCEPTION 'La embarcacion debe pertenecer al socio que reserva';
    END IF;

    SELECT eslora_max_m INTO espacio_eslora_max
    FROM espacios_varadero
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
            FROM reservas_varadero r
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
        FROM reservas_varadero r
        WHERE r.socio_id = NEW.socio_id
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

CREATE TRIGGER trg_validar_reserva_varadero
BEFORE INSERT OR UPDATE ON reservas_varadero
FOR EACH ROW EXECUTE FUNCTION cnb_validar_reserva_varadero();

INSERT INTO espacios_varadero (codigo, descripcion, eslora_max_m, manga_max_m)
VALUES
    ('V1', 'Varadero 1 - embarcaciones chicas', 7.50, 2.80),
    ('V2', 'Varadero 2 - embarcaciones medianas', 10.00, 3.50),
    ('V3', 'Varadero 3 - embarcaciones grandes', 14.00, 4.50);

-- Tipos de embarcacion (ficha de astillero o promedio del padron) y
-- solicitudes de amarra. Hasta 23 pies (7,01 m) puede ir a tierra;
-- una eslora mayor va a agua.
-- Ejecutar conectado a CNBDB:
--   psql -d CNBDB -f sql/021_tipos_y_amarras.sql

SET search_path TO cnb_app, public;

CREATE TABLE IF NOT EXISTS tipos_embarcacion (
    id BIGSERIAL PRIMARY KEY,
    codigo VARCHAR(80) NOT NULL UNIQUE,
    nombre VARCHAR(160) NOT NULL,
    eslora_m NUMERIC(5,2) CHECK (eslora_m IS NULL OR eslora_m > 0),
    eslora_max_m NUMERIC(5,2) CHECK (eslora_max_m IS NULL OR eslora_max_m > 0),
    manga_m NUMERIC(5,2) CHECK (manga_m IS NULL OR manga_m > 0),
    puntal_m NUMERIC(5,2) CHECK (puntal_m IS NULL OR puntal_m > 0),
    calado_m NUMERIC(5,2) CHECK (calado_m IS NULL OR calado_m > 0),
    calado_max_m NUMERIC(5,2) CHECK (calado_max_m IS NULL OR calado_max_m > 0),
    fuente VARCHAR(40) NOT NULL DEFAULT 'padron'
        CHECK (fuente IN ('padron', 'astillero')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE embarcaciones
    ADD COLUMN IF NOT EXISTS tipo_embarcacion_id BIGINT REFERENCES tipos_embarcacion(id) ON DELETE SET NULL;

ALTER TABLE socios
    ADD COLUMN IF NOT EXISTS fecha_ingreso DATE;

COMMENT ON COLUMN socios.fecha_ingreso IS
    'Fecha de ingreso al club. Define la antigüedad para amarra, ampliación y el resto de las listas.';

CREATE TABLE IF NOT EXISTS solicitudes_amarra (
    id BIGSERIAL PRIMARY KEY,
    numero_socio INTEGER NOT NULL REFERENCES socios(numero_socio) ON DELETE RESTRICT,
    embarcacion_id BIGINT REFERENCES embarcaciones(id) ON DELETE SET NULL,
    tipo_embarcacion_id BIGINT REFERENCES tipos_embarcacion(id) ON DELETE RESTRICT,
    modalidad VARCHAR(20) NOT NULL CHECK (modalidad IN ('compra', 'alquiler')),
    fecha_solicitud DATE NOT NULL DEFAULT CURRENT_DATE,
    fecha_ingreso_socio DATE,
    destino_solicitado VARCHAR(20) NOT NULL CHECK (destino_solicitado IN ('agua', 'tierra')),
    destino_disponible VARCHAR(20) CHECK (destino_disponible IS NULL OR destino_disponible IN ('agua', 'tierra')),
    m2_necesarios NUMERIC(10,2) CHECK (m2_necesarios IS NULL OR m2_necesarios > 0),
    m2_disponibles NUMERIC(10,2) CHECK (m2_disponibles IS NULL OR m2_disponibles >= 0),
    estado VARCHAR(20) NOT NULL DEFAULT 'en_lista'
        CHECK (estado IN ('en_lista', 'ofrecida', 'asignada', 'rechazada', 'cancelada')),
    observaciones TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_solicitudes_amarra_lista
    ON solicitudes_amarra (estado, fecha_ingreso_socio, fecha_solicitud);

INSERT INTO tipos_embarcacion (codigo, nombre, eslora_m, manga_m, calado_m, fuente)
SELECT regexp_replace(upper(trim(modelo)), '[^A-Z0-9]+', '_', 'g'),
       upper(trim(modelo)),
       round(avg(eslora_m), 2),
       round(avg(manga_m), 2),
       round(avg(calado_m), 2),
       'padron'
FROM embarcaciones
WHERE trim(coalesce(modelo, '')) <> ''
GROUP BY upper(trim(modelo))
ON CONFLICT (codigo) DO NOTHING;

INSERT INTO tipos_embarcacion (
    codigo, nombre, eslora_m, eslora_max_m, manga_m, puntal_m, calado_m, calado_max_m, fuente
) VALUES (
    'CANESTRARI_245', 'CANESTRARI 245', 7.65, 7.95, 2.60, 1.55, 0.40, 0.70, 'astillero'
)
ON CONFLICT (codigo) DO UPDATE SET
    nombre = EXCLUDED.nombre,
    eslora_m = EXCLUDED.eslora_m,
    eslora_max_m = EXCLUDED.eslora_max_m,
    manga_m = EXCLUDED.manga_m,
    puntal_m = EXCLUDED.puntal_m,
    calado_m = EXCLUDED.calado_m,
    calado_max_m = EXCLUDED.calado_max_m,
    fuente = EXCLUDED.fuente,
    updated_at = NOW();

UPDATE embarcaciones AS e
SET tipo_embarcacion_id = t.id
FROM tipos_embarcacion AS t
WHERE e.tipo_embarcacion_id IS NULL
  AND t.codigo = regexp_replace(upper(trim(coalesce(e.modelo, ''))), '[^A-Z0-9]+', '_', 'g');

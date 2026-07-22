-- estados_padron + recrea embarcaciones con estado_padron_id al final (despues de estado).
-- Conserva datos existentes.
--   psql -d CNBDB -f sql/014_estados_padron.sql

SET search_path TO cnb_app, public;

CREATE TABLE IF NOT EXISTS estados_padron (
    id BIGSERIAL PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL UNIQUE,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

DROP TRIGGER IF EXISTS trg_estados_padron_updated_at ON estados_padron;
CREATE TRIGGER trg_estados_padron_updated_at BEFORE UPDATE ON estados_padron
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

-- Semillas desde datos actuales + valores tipicos del padron
INSERT INTO estados_padron (nombre)
SELECT DISTINCT btrim(estado_padron)
FROM embarcaciones
WHERE estado_padron IS NOT NULL AND btrim(estado_padron) <> ''
ON CONFLICT (nombre) DO NOTHING;

INSERT INTO estados_padron (nombre) VALUES
    ('Revisado OK'),
    ('Relevado'),
    ('RELEVADO'),
    ('FIRMADO'),
    ('Falta Firmar'),
    ('CONFIRMADO'),
    ('OK'),
    ('Avisar Socio'),
    ('BAJA'),
    ('RENUNCIO'),
    ('REVISAR MEDIDA'),
    ('chequear')
ON CONFLICT (nombre) DO NOTHING;

-- Liberar FKs y recrear embarcaciones con nuevo orden de columnas
UPDATE tareas SET embarcacion_id = NULL WHERE embarcacion_id IS NOT NULL;
DELETE FROM reservas_varadero;

CREATE TABLE embarcaciones_new (
    id BIGSERIAL PRIMARY KEY,
    ambito VARCHAR(20) NOT NULL DEFAULT 'agua'
        CHECK (ambito IN ('agua', 'tierra')),
    numero_socio INTEGER REFERENCES socios(numero_socio) ON DELETE SET NULL
        CHECK (numero_socio IS NULL OR (numero_socio >= 1 AND numero_socio <= 99999)),
    tipo VARCHAR(80) NOT NULL DEFAULT 'velero',
    modelo VARCHAR(120),
    nombre VARCHAR(160) NOT NULL,
    matricula VARCHAR(80),
    eslora_m NUMERIC(5,2) CHECK (eslora_m IS NULL OR eslora_m > 0),
    manga_m NUMERIC(5,2) CHECK (manga_m IS NULL OR manga_m > 0),
    m2_matricula NUMERIC(10,3),
    metros_comprados NUMERIC(10,2),
    paga_expensas_m2 NUMERIC(10,2),
    ubicacion_id BIGINT REFERENCES ubicaciones(id) ON DELETE SET NULL,
    observaciones TEXT,
    eslora_medida_m NUMERIC(5,2),
    manga_medida_m NUMERIC(5,2),
    m2_medidos NUMERIC(10,3),
    estado VARCHAR(20) NOT NULL DEFAULT 'activa'
        CHECK (estado IN ('activa', 'mantenimiento', 'inactiva')),
    estado_padron_id BIGINT REFERENCES estados_padron(id) ON DELETE SET NULL,
    es_cnb BOOLEAN NOT NULL DEFAULT FALSE,
    calado_m NUMERIC(5,2),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO embarcaciones_new (
    id, ambito, numero_socio, tipo, modelo, nombre, matricula,
    eslora_m, manga_m, m2_matricula, metros_comprados, paga_expensas_m2,
    ubicacion_id, observaciones, eslora_medida_m, manga_medida_m, m2_medidos,
    estado, estado_padron_id, es_cnb, calado_m, created_at, updated_at
)
SELECT
    e.id,
    e.ambito,
    e.numero_socio,
    e.tipo,
    e.modelo,
    e.nombre,
    e.matricula,
    e.eslora_m,
    e.manga_m,
    e.m2_matricula,
    e.metros_comprados,
    e.paga_expensas_m2,
    e.ubicacion_id,
    e.observaciones,
    e.eslora_medida_m,
    e.manga_medida_m,
    e.m2_medidos,
    e.estado,
    ep.id,
    e.es_cnb,
    e.calado_m,
    e.created_at,
    e.updated_at
FROM embarcaciones e
LEFT JOIN estados_padron ep
    ON ep.nombre = NULLIF(btrim(e.estado_padron), '');

DROP TABLE embarcaciones CASCADE;
ALTER TABLE embarcaciones_new RENAME TO embarcaciones;

SELECT setval(
    pg_get_serial_sequence('cnb_app.embarcaciones', 'id'),
    COALESCE((SELECT MAX(id) FROM embarcaciones), 1),
    true
);

CREATE UNIQUE INDEX embarcaciones_matricula_unique
    ON embarcaciones (matricula)
    WHERE matricula IS NOT NULL AND btrim(matricula) <> '';

CREATE INDEX idx_embarcaciones_numero_socio ON embarcaciones (numero_socio);
CREATE INDEX idx_embarcaciones_ambito ON embarcaciones (ambito);
CREATE INDEX idx_embarcaciones_ubicacion_id ON embarcaciones (ubicacion_id);
CREATE INDEX idx_embarcaciones_estado_padron_id ON embarcaciones (estado_padron_id);

CREATE TRIGGER trg_embarcaciones_updated_at BEFORE UPDATE ON embarcaciones
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

ALTER TABLE reservas_varadero
    DROP CONSTRAINT IF EXISTS reservas_varadero_embarcacion_id_fkey;
ALTER TABLE reservas_varadero
    ADD CONSTRAINT reservas_varadero_embarcacion_id_fkey
    FOREIGN KEY (embarcacion_id) REFERENCES embarcaciones(id) ON DELETE RESTRICT;

ALTER TABLE tareas
    DROP CONSTRAINT IF EXISTS tareas_embarcacion_id_fkey;
ALTER TABLE tareas
    ADD CONSTRAINT tareas_embarcacion_id_fkey
    FOREIGN KEY (embarcacion_id) REFERENCES embarcaciones(id) ON DELETE SET NULL;

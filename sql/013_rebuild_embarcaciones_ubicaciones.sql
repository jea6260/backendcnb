-- Rebuild desde cero: ubicaciones + estados_padron + embarcaciones (padron Agua/Tierra).
-- Borra embarcaciones y referencias (reservas/tareas.embarcacion_id).
--   psql -d CNBDB -f sql/013_rebuild_embarcaciones_ubicaciones.sql
--
-- Luego importar:
--   python3 bin/import_embarcaciones_padron.py "/ruta/Propietarios Marinas.xlsx"

SET search_path TO cnb_app, public;

-- Liberar FKs hacia embarcaciones
UPDATE tareas SET embarcacion_id = NULL WHERE embarcacion_id IS NOT NULL;
DELETE FROM reservas_varadero;

DROP TABLE IF EXISTS embarcaciones CASCADE;
DROP TABLE IF EXISTS ubicaciones CASCADE;
DROP TABLE IF EXISTS estados_padron CASCADE;

CREATE TABLE ubicaciones (
    id BIGSERIAL PRIMARY KEY,
    ambito VARCHAR(20) NOT NULL
        CHECK (ambito IN ('agua', 'tierra')),
    nombre VARCHAR(120) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (ambito, nombre)
);

CREATE TABLE estados_padron (
    id BIGSERIAL PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL UNIQUE,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE embarcaciones (
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

CREATE UNIQUE INDEX embarcaciones_matricula_unique
    ON embarcaciones (matricula)
    WHERE matricula IS NOT NULL AND btrim(matricula) <> '';

CREATE INDEX idx_ubicaciones_ambito ON ubicaciones (ambito);
CREATE INDEX idx_embarcaciones_numero_socio ON embarcaciones (numero_socio);
CREATE INDEX idx_embarcaciones_ambito ON embarcaciones (ambito);
CREATE INDEX idx_embarcaciones_ubicacion_id ON embarcaciones (ubicacion_id);
CREATE INDEX idx_embarcaciones_estado_padron_id ON embarcaciones (estado_padron_id);

CREATE TRIGGER trg_ubicaciones_updated_at BEFORE UPDATE ON ubicaciones
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

CREATE TRIGGER trg_estados_padron_updated_at BEFORE UPDATE ON estados_padron
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

CREATE TRIGGER trg_embarcaciones_updated_at BEFORE UPDATE ON embarcaciones
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

INSERT INTO ubicaciones (ambito, nombre) VALUES
    ('tierra', 'Sector tierra'),
    ('agua', 'Sin especificar')
ON CONFLICT (ambito, nombre) DO NOTHING;

INSERT INTO estados_padron (nombre) VALUES
    ('Revisado OK'),
    ('Relevado'),
    ('FIRMADO'),
    ('Falta Firmar'),
    ('CONFIRMADO'),
    ('OK'),
    ('Avisar Socio'),
    ('BAJA')
ON CONFLICT (nombre) DO NOTHING;

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

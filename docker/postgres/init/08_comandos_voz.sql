-- Frases de voz y el mnemonico del comando al que aplican.
-- Docker nuevo: docker/postgres/init/08_comandos_voz.sql
-- Local o Render (base ya creada):
--   psql -d CNBDB -f sql/023_comandos_voz.sql
--   ./bin/render-init-db.sh

SET search_path TO cnb_app, public;

CREATE TABLE IF NOT EXISTS comandos_voz (
    id BIGSERIAL PRIMARY KEY,
    mnemonico VARCHAR(40) NOT NULL UNIQUE,
    descripcion VARCHAR(180) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS frases_comando_voz (
    id BIGSERIAL PRIMARY KEY,
    comando_id BIGINT NOT NULL REFERENCES comandos_voz(id) ON DELETE CASCADE,
    frase VARCHAR(240) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (comando_id, frase)
);

DROP TRIGGER IF EXISTS trg_comandos_voz_updated_at ON comandos_voz;
CREATE TRIGGER trg_comandos_voz_updated_at BEFORE UPDATE ON comandos_voz
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

DROP TRIGGER IF EXISTS trg_frases_comando_voz_updated_at ON frases_comando_voz;
CREATE TRIGGER trg_frases_comando_voz_updated_at BEFORE UPDATE ON frases_comando_voz
FOR EACH ROW EXECUTE FUNCTION cnb_touch_updated_at();

INSERT INTO comandos_voz (mnemonico, descripcion)
VALUES
    ('iniciar_varadero', 'El barco entra a varadero'),
    ('finalizar_varadero', 'El barco vuelve a la amarra')
ON CONFLICT (mnemonico) DO NOTHING;

INSERT INTO frases_comando_voz (comando_id, frase)
SELECT c.id, f.frase
FROM (
    VALUES
        ('iniciar_varadero', 'sacar del agua el barco {nombre}'),
        ('iniciar_varadero', 'sacar del agua el barco {nombre} y llevarlo a varadero'),
        ('iniciar_varadero', 'llevar a varadero el barco del socio {socio}'),
        ('iniciar_varadero', 'iniciar varadero del barco {nombre}'),
        ('iniciar_varadero', 'iniciar varadero del socio {socio}'),
        ('finalizar_varadero', 'volver a la amarra el barco {nombre}'),
        ('finalizar_varadero', 'finalizar varadero del barco {nombre}'),
        ('finalizar_varadero', 'finalizar varadero del socio {socio}')
) AS f(mnemonico, frase)
JOIN comandos_voz c ON c.mnemonico = f.mnemonico
ON CONFLICT (comando_id, frase) DO NOTHING;

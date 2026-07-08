-- Portal de socios: autenticacion, novedades, camaras, CD y portones.
-- Ejecutar conectado a CNBDB:
--   psql -d CNBDB -f sql/003_socio_portal.sql

SET search_path TO cnb_app, public;

CREATE TABLE IF NOT EXISTS socio_acceso (
    id BIGSERIAL PRIMARY KEY,
    socio_id BIGINT NOT NULL UNIQUE REFERENCES socios(id) ON DELETE CASCADE,
    email VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    biometric_habilitado BOOLEAN NOT NULL DEFAULT FALSE,
    facial_reference TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS socio_sesiones (
    id BIGSERIAL PRIMARY KEY,
    socio_id BIGINT NOT NULL REFERENCES socios(id) ON DELETE CASCADE,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_socio_sesiones_token ON socio_sesiones(token);

CREATE TABLE IF NOT EXISTS novedades (
    id BIGSERIAL PRIMARY KEY,
    titulo VARCHAR(200) NOT NULL,
    contenido TEXT NOT NULL,
    publicado_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS camaras (
    id BIGSERIAL PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    ubicacion VARCHAR(120),
    stream_url TEXT NOT NULL,
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    orden INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS solicitudes_reunion_cd (
    id BIGSERIAL PRIMARY KEY,
    socio_id BIGINT NOT NULL REFERENCES socios(id) ON DELETE CASCADE,
    asunto VARCHAR(200) NOT NULL,
    mensaje TEXT NOT NULL,
    fecha_preferida DATE,
    estado VARCHAR(30) NOT NULL DEFAULT 'pendiente'
        CHECK (estado IN ('pendiente', 'confirmada', 'rechazada', 'realizada', 'cancelada')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS notas_cd (
    id BIGSERIAL PRIMARY KEY,
    socio_id BIGINT NOT NULL REFERENCES socios(id) ON DELETE CASCADE,
    asunto VARCHAR(200) NOT NULL,
    mensaje TEXT NOT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'recibida'
        CHECK (estado IN ('recibida', 'en_revision', 'respondida', 'archivada')),
    respuesta TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS accesos_porton (
    id BIGSERIAL PRIMARY KEY,
    socio_id BIGINT NOT NULL REFERENCES socios(id) ON DELETE CASCADE,
    porton VARCHAR(60) NOT NULL,
    resultado VARCHAR(30) NOT NULL DEFAULT 'pendiente'
        CHECK (resultado IN ('aprobado', 'rechazado', 'pendiente', 'error')),
    puntaje_facial NUMERIC(5,2),
    observaciones TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO novedades (titulo, contenido, publicado_at)
SELECT v.titulo, v.contenido, v.publicado_at
FROM (VALUES
    ('Bienvenido al portal de socios', 'Desde la app podes gestionar tu credencial, solicitar turno de varadero y comunicarte con el Consejo Directivo.', NOW() - INTERVAL '2 days'),
    ('Temporada 2026', 'Recorda renovar tu cuota social y actualizar los datos de contacto en secretaria.', NOW() - INTERVAL '1 day')
) AS v(titulo, contenido, publicado_at)
WHERE NOT EXISTS (SELECT 1 FROM novedades LIMIT 1);

INSERT INTO camaras (nombre, ubicacion, stream_url, orden)
SELECT v.nombre, v.ubicacion, v.stream_url, v.orden
FROM (VALUES
    ('Muelle principal', 'Muelle norte', 'https://www.youtube.com/embed/live_stream?channel=UC_placeholder_muelle', 1),
    ('Varadero', 'Sector seco', 'https://www.youtube.com/embed/live_stream?channel=UC_placeholder_varadero', 2),
    ('Acceso club', 'Porton ingreso', 'https://www.youtube.com/embed/live_stream?channel=UC_placeholder_acceso', 3)
) AS v(nombre, ubicacion, stream_url, orden)
WHERE NOT EXISTS (SELECT 1 FROM camaras LIMIT 1);

-- Usuario demo: socio #1 con clave "Socio123!" (cambiar en produccion)
DO $$
DECLARE
    demo_socio_id BIGINT;
BEGIN
    SELECT id INTO demo_socio_id FROM socios ORDER BY id LIMIT 1;

    IF demo_socio_id IS NULL THEN
        INSERT INTO socios (numero_socio, nombre, apellido, email, telefono, documento, estado)
        VALUES ('1001', 'Juan', 'Perez', 'socio.demo@cnb.org.ar', '2944000000', '20123456', 'activo')
        RETURNING id INTO demo_socio_id;
    END IF;

    INSERT INTO socio_acceso (socio_id, email, password_hash, biometric_habilitado)
    VALUES (
        demo_socio_id,
        COALESCE((SELECT email FROM socios WHERE id = demo_socio_id), 'socio.demo@cnb.org.ar'),
        '$2y$10$x7WJwH55cpEqOEK8mCQx/Ofp9iVOBRliVTEy8N3tsaQS9fiH/W1vK',
        FALSE
    )
    ON CONFLICT (socio_id) DO NOTHING;
END $$;

# Backend CNB

Backend Symfony para la gestion operativa del Club Nautico Bariloche.

## Incluye

- API REST bajo `/api` para socios, marineros, embarcaciones, vehiculos, espacios y reservas de varadero, tareas, asignaciones y avances.
- CRUD web administrativo bajo `/admin`.
- SQL inicial en `sql/001_create_cnbdb.sql` para crear `CNBDB`, el esquema `cnb_app`, tablas, indices, triggers y reglas de varadero.
- Reglas de varadero en PostgreSQL: maximo 15 dias por reserva, maximo anual de 15 dias por socio, control de solapamiento por espacio y control de eslora maxima.
- Portal de socios (`sql/003_socio_portal.sql`).

## Render (hosting gratis)

Guia completa: [docs/RENDER.md](docs/RENDER.md).

Resumen:

1. Subi el repo a GitHub.
2. En Render: **New → Blueprint** y selecciona el repo (`render.yaml`).
3. Cuando la app este Live, en el Shell del servicio corre `./bin/render-init-db.sh` una vez.
4. Proba `https://TU-SERVICIO.onrender.com/api/health`.

## Docker local (recomendado para desarrollo)

Requisitos: Docker Engine + Docker Compose.

```bash
docker compose up -d --build
```

La primera vez:

1. Levanta Postgres 16 con la base `CNBDB`.
2. Ejecuta automaticamente `docker/postgres/init/*.sql` (esquema operativo + portal de socios).
3. Arranca la app Symfony en el puerto **8000**.

Abrir:

- Admin: http://localhost:8000/admin
- API health: http://localhost:8000/api/health

Credenciales Docker por defecto:

| Servicio | Valor |
|----------|-------|
| Postgres host (desde el host) | `127.0.0.1:5433` |
| Postgres (desde otro contenedor) | `database:5432` |
| Usuario / clave / DB | `cnb_user` / `cnb_password` / `CNBDB` |
| App URL | http://localhost:8000 |

Comandos utiles:

```bash
docker compose logs -f app
docker compose exec app php bin/console cache:clear
docker compose down
docker compose down -v   # borra tambien el volumen de la base
```

Si cambias los scripts SQL de init y quieres recrear la base desde cero:

```bash
docker compose down -v
docker compose up -d --build
```

## Configuracion local (sin Docker)

Crear la base:

```bash
psql -h 127.0.0.1 -p 5432 -U postgres -f sql/001_create_cnbdb.sql
```

Si una instalacion anterior creo las tablas en `public`, moverlas a `cnb_app`:

```bash
psql -h 127.0.0.1 -p 5432 -U postgres -d CNBDB -f sql/002_move_public_to_cnb_app.sql
```

Portal de socios:

```bash
psql -h 127.0.0.1 -p 5432 -U postgres -d CNBDB -f sql/003_socio_portal.sql
```

Configurar credenciales en `.env.local` si no se usan los valores de desarrollo:

```dotenv
DATABASE_URL="postgresql://postgres:TU_PASSWORD@127.0.0.1:5432/CNBDB?serverVersion=16&charset=utf8"
```

Levantar el backend (escucha en todas las IPs de la PC, puerto 8000):

```bash
composer install
composer serve
```

Equivalente manual:

```bash
php -S 0.0.0.0:8000 -t public public/router.php
```

Abrir:

- CRUD web: `http://localhost:8000/admin`
- Salud API: `http://localhost:8000/api/health`
- Ejemplo API: `http://localhost:8000/api/tareas`

Desde otro dispositivo en la misma red, usar la IP local de la PC, por ejemplo `http://192.168.1.51:8000/api/health`.

Para la app Flutter en dispositivo fisico:

```bash
flutter run --dart-define=API_BASE_URL=http://TU_IP_LOCAL:8000/api
```

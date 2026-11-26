# Desplegar en Render (gratis)

Guia para publicar este backend en [Render](https://render.com) con Docker + PostgreSQL.

## 1. Subir el codigo a GitHub

Render despliega desde un repo. En la carpeta del proyecto:

```bash
cd ~/development/cnb/backendcnb
git init   # solo si aun no es un repo
git add .
git commit -m "Prepare Docker deploy for Render"
```

Crea un repo en GitHub y hace push (`git remote add origin ...` + `git push -u origin main`).

## 2. Crear cuenta en Render

1. Entra a https://dashboard.render.com
2. Registrate (GitHub es lo mas comodo)
3. Conecta el repositorio `backendcnb`

## 3. Deploy nativo sin Docker (recomendado)

Usá **Web Service** nativo + Neon (la DB no caduca con el Postgres free de Render).

1. **New → Web Services**
2. Conectá el repo `backendcnb`
3. Runtime: **Native** (no Docker)
4. Plan: **Free**
5. Build Command:
   `composer install --no-dev --optimize-autoloader --no-interaction`
6. Start Command:
   `php -S 0.0.0.0:$PORT -t public public/router.php`
7. Variables de entorno:

| Key | Valor |
|-----|--------|
| `APP_ENV` | `prod` |
| `APP_DEBUG` | `0` |
| `APP_SECRET` | una cadena larga aleatoria |
| `DEFAULT_URI` | `https://TU-SERVICIO.onrender.com` |
| `DATABASE_URL` | connection string de Neon (pooler OK) |
| `ADMIN_USERNAME` | usuario del panel `/admin` |
| `ADMIN_PASSWORD` | clave del panel, minimo 8 caracteres |

`nixpacks.toml` instala PHP 8.2 + `pdo_pgsql`. Si la base Neon ya tiene el esquema, no hace falta `render-init-db.sh`.

## 4. Blueprint (tambien nativo)

1. **New → Blueprint**
2. Elegi el repo (`render.yaml` ya apunta a runtime native)
3. Completá `DATABASE_URL` (Neon) y `DEFAULT_URI`
4. **Apply**

## 5. Inicializar el esquema SQL (una sola vez)

La base de Render nace vacia. Hay que correr los scripts:

1. En el servicio web: **Shell** (o SSH si esta habilitado)
2. Ejecutá:

```bash
./bin/render-init-db.sh
```

`DATABASE_URL` ya debe estar definida en el servicio. El script aplica:

- `docker/postgres/init/01_schema.sql`
- `docker/postgres/init/02_socio_portal.sql`

Si falla porque las tablas ya existen, es que ya se inicializo.

## 6. Probar

Cuando el deploy diga **Live**:

- Health: `https://TU-SERVICIO.onrender.com/api/health`
- Admin: `https://TU-SERVICIO.onrender.com/admin` (pide `ADMIN_USERNAME` / `ADMIN_PASSWORD`)

Esperado en health:

```json
{"status":"ok","service":"cnb-backend"}
```

## 7. App Flutter

Apunta la API a Render:

```bash
flutter run --dart-define=API_BASE_URL=https://TU-SERVICIO.onrender.com/api
```

## Limitaciones del plan free

- El servicio **se duerme** tras ~15 min sin trafico; el primer request puede tardar 30–60 s.
- El Postgres free puede **expirar** a los 30 dias si no lo upgradeas (revisa la politica vigente en Render).
- No uses datos productivos sensibles en free.

## Local vs Render

| | Local (`docker compose`) | Render |
|--|--------------------------|--------|
| Postgres | `127.0.0.1:5433` | managed (Internal URL) |
| App | `http://localhost:8000` | `https://....onrender.com` |
| Init SQL | automatico al crear volumen | manual con `bin/render-init-db.sh` |

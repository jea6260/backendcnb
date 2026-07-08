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

## 3. Deploy con Blueprint (recomendado)

1. **New → Blueprint**
2. Elegi el repo
3. Render lee `render.yaml` y crea:
   - PostgreSQL free (`cnb-db`)
   - Web Service Docker (`cnb-backend`)
4. En `DEFAULT_URI`, poné la URL publica cuando exista, por ejemplo:
   `https://cnb-backend.onrender.com`
5. **Apply** / Create

La primera build tarda varios minutos.

## 4. Alternativa manual (sin Blueprint)

### Base de datos

1. **New → PostgreSQL**
2. Plan: **Free**
3. Anotá la **Internal Database URL**

### Web Service

1. **New → Web Service**
2. Repo: este proyecto
3. Runtime: **Docker**
4. Plan: **Free**
5. Variables de entorno:

| Key | Valor |
|-----|--------|
| `APP_ENV` | `prod` |
| `APP_DEBUG` | `0` |
| `APP_SECRET` | una cadena larga aleatoria |
| `DEFAULT_URI` | `https://TU-SERVICIO.onrender.com` |
| `DATABASE_URL` | Internal Database URL (Render la puede linkear) |

Render inyecta `PORT` solo; el entrypoint ya lo respeta.

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
- Admin: `https://TU-SERVICIO.onrender.com/admin`

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

# Coleccion Insomnium

Archivo: [cnb-api.insomnia.json](./cnb-api.insomnia.json)

Para regenerarla despues de un cambio de endpoints:

```bash
python3 docs/insomnium/generate_collection.py
```

## Importar

1. Abrir Insomnium.
2. Application, Preferences, Data, Import Data.
3. Elegir `cnb-api.insomnia.json`.
4. Abrir el workspace **CNB Backend API**.

## Entorno

La base apunta a `https://backendcnb.onrender.com`. El subentorno **Local** la cambia a `http://127.0.0.1:8000`.

Completar en el entorno `socio_password` y, si se usa el usuario del panel, `staff_password`. Despues de **POST Login** de socios, copiar `data.token` a `socio_token`. Despues del login de personal, copiar el token a `staff_token`.

Los cuerpos ya traen un ejemplo. Los ids (`resource_id`, `amarra_id`, `embarcacion_id`, `espacio_id`, `tipo_id`, `tarea_id`, `reserva_id`, `marinero_id`) se cambian en el entorno.

## Carpetas

- **00 Salud y sensor**: health y la medicion del ESP.
- **01 Socios**: login, cuenta, nivel, varadero, amarras, consejo, porton y timbre.
- **02 Personal del club**: el mismo mail y clave del socio, o el usuario admin.
- **03 Amarras de administracion** y avances de tareas.
- **04 Recursos**: listado, ficha, alta, cambio y baja de cada tabla, con un ejemplo de alta.
- **05 Panel web**: formularios HTML. Usan cookie y, donde el formulario lo pide, `_token`.
- **06 Timbre publico**: la pantalla `/timbre`.

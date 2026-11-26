#!/usr/bin/env python3
"""Genera docs/insomnium/cnb-api.insomnia.json para Insomnium / Insomnia v4."""

import json
import uuid
from pathlib import Path

NOW = 1763640000000
WORKSPACE = "wrk_cnbbackendapi0001"


def uid(prefix: str) -> str:
    return f"{prefix}_{uuid.uuid4().hex[:24]}"


resources: list[dict] = []


def add(item: dict) -> str:
    resources.append(item)
    return item["_id"]


def folder(parent: str, name: str, sort: int, description: str = "") -> str:
    return add({
        "_id": uid("fld"),
        "parentId": parent,
        "modified": NOW,
        "created": NOW,
        "name": name,
        "description": description,
        "environment": {},
        "environmentPropertyOrder": None,
        "metaSortKey": sort,
        "_type": "request_group",
    })


def request(
    parent: str,
    name: str,
    method: str,
    url: str,
    sort: int,
    description: str = "",
    body: dict | list | None = None,
    auth: str | None = None,
    form: dict[str, str] | None = None,
) -> None:
    headers = [{"name": "Accept", "value": "application/json", "id": uid("pair")}]
    if body is not None:
        headers.insert(0, {"name": "Content-Type", "value": "application/json", "id": uid("pair")})
        payload = {
            "mimeType": "application/json",
            "text": json.dumps(body, indent=2, ensure_ascii=False),
        }
    elif form is not None:
        headers.insert(0, {"name": "Content-Type", "value": "application/x-www-form-urlencoded", "id": uid("pair")})
        payload = {
            "mimeType": "application/x-www-form-urlencoded",
            "params": [{"name": key, "value": value, "id": uid("pair")} for key, value in form.items()],
        }
    else:
        payload = {}
    if auth:
        headers.append({
            "name": "Authorization",
            "value": "Bearer {{ _." + auth + " }}",
            "id": uid("pair"),
        })
    add({
        "_id": uid("req"),
        "parentId": parent,
        "modified": NOW,
        "created": NOW,
        "url": url,
        "name": name,
        "description": description,
        "method": method,
        "body": payload,
        "parameters": [],
        "headers": headers,
        "authentication": {},
        "metaSortKey": sort,
        "isPrivate": False,
        "settingStoreCookies": True,
        "settingSendCookies": True,
        "settingDisableRenderRequestBody": False,
        "settingEncodeUrl": True,
        "settingRebuildPath": True,
        "settingFollowRedirects": "global",
        "_type": "request",
    })


add({
    "_id": WORKSPACE,
    "parentId": None,
    "modified": NOW,
    "created": NOW,
    "name": "CNB Backend API",
    "description": "Todos los endpoints de backendcnb, con cuerpos de ejemplo.",
    "scope": "collection",
    "_type": "workspace",
})

env_data = {
    "base_url": "https://backendcnb.onrender.com",
    "socio_email": "jangaut@gmail.com",
    "socio_password": "",
    "socio_token": "",
    "staff_user": "admin",
    "staff_password": "",
    "staff_token": "",
    "numero_socio": "1504",
    "resource_id": "1",
    "tarea_id": "1",
    "reserva_id": "1",
    "amarra_id": "1",
    "embarcacion_id": "1",
    "espacio_id": "1",
    "tipo_id": "1",
    "marinero_id": "1",
}
add({
    "_id": "env_cnbbase000000000000001",
    "parentId": WORKSPACE,
    "modified": NOW,
    "created": NOW,
    "name": "Base Environment",
    "data": env_data,
    "dataPropertyOrder": {"&": list(env_data)},
    "color": None,
    "isPrivate": False,
    "metaSortKey": 1,
    "_type": "environment",
})
add({
    "_id": "env_cnblocal00000000000001",
    "parentId": "env_cnbbase000000000000001",
    "modified": NOW,
    "created": NOW,
    "name": "Local",
    "data": {"base_url": "http://127.0.0.1:8000"},
    "dataPropertyOrder": {"&": ["base_url"]},
    "color": "#7d69cb",
    "isPrivate": False,
    "metaSortKey": 1,
    "_type": "environment",
})

B = "{{ _.base_url }}"
n = 0

def nxt() -> int:
    global n
    n -= 10
    return n

salud = folder(WORKSPACE, "00 Salud y sensor", -1000)
request(salud, "GET Health", "GET", f"{B}/api/health", nxt(), "Responde status ok.")
request(salud, "POST Medicion del sensor", "POST", f"{B}/api/dispositivo/mediciones", nxt(), "El ESP del nivel del lago.", {
    "distancia_medida_cm": 120.5,
    "profundidad_cm": 350,
    "msnm": 770.2,
    "fecha": "2026-11-20T15:00:00+00:00",
})

socios = folder(WORKSPACE, "01 Socios", -900, "Token de POST Login en socio_token.")
auth = folder(socios, "Auth y cuenta", -890)
request(auth, "POST Login", "POST", f"{B}/api/socio/login", nxt(), "Copiar data.token a socio_token.", {
    "email": "{{ _.socio_email }}",
    "password": "{{ _.socio_password }}",
})
request(auth, "POST Crear usuario", "POST", f"{B}/api/socio/register", nxt(), "El mail tiene que ser el del padron.", {
    "numero_socio": "{{ _.numero_socio }}",
    "email": "{{ _.socio_email }}",
    "password": "{{ _.socio_password }}",
})
request(auth, "GET Yo", "GET", f"{B}/api/socio/me", nxt(), auth="socio_token")
request(auth, "PATCH Cambiar clave", "PATCH", f"{B}/api/socio/password", nxt(), auth="socio_token", body={
    "password_actual": "{{ _.socio_password }}",
    "password_nueva": "clave-nueva-8",
})
request(auth, "PATCH Biometria", "PATCH", f"{B}/api/socio/biometric", nxt(), auth="socio_token", body={"habilitado": True})
request(auth, "GET Foto de perfil", "GET", f"{B}/api/socio/foto-perfil", nxt(), auth="socio_token")
request(auth, "POST Foto de perfil", "POST", f"{B}/api/socio/foto-perfil", nxt(), auth="socio_token", body={
    "imagen_base64": "data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAk",
})

datos = folder(socios, "Datos del club", -860)
request(datos, "GET Novedades", "GET", f"{B}/api/socio/novedades", nxt(), auth="socio_token")
request(datos, "GET Camaras", "GET", f"{B}/api/socio/camaras", nxt(), auth="socio_token")
request(datos, "GET Nivel del lago", "GET", f"{B}/api/socio/mediciones-nivel?desde=2026-11-01&limit=100", nxt(), auth="socio_token")
request(datos, "GET Mis embarcaciones", "GET", f"{B}/api/socio/embarcaciones", nxt(), auth="socio_token")

varadero = folder(socios, "Varadero", -830)
request(varadero, "GET Espacios", "GET", f"{B}/api/socio/espacios-varadero", nxt(), auth="socio_token")
request(varadero, "GET Disponibilidad", "GET", f"{B}/api/socio/reservas-varadero/disponibilidad?espacio_id={{{{ _.espacio_id }}}}&desde=2026-11-20&hasta=2026-12-20", nxt(), auth="socio_token")
request(varadero, "GET Mis reservas", "GET", f"{B}/api/socio/reservas-varadero", nxt(), auth="socio_token")
request(varadero, "POST Reservar", "POST", f"{B}/api/socio/reservas-varadero", nxt(), "El socio sale del token.", auth="socio_token", body={
    "embarcacion_id": "{{ _.embarcacion_id }}",
    "espacio_id": "{{ _.espacio_id }}",
    "fecha_inicio": "2026-12-02",
    "fecha_fin": "2026-12-04",
    "motivo": "limpieza_mantenimiento",
    "observaciones": "Limpieza de casco",
})
request(varadero, "POST Nota de una reserva", "POST", f"{B}/api/socio/reservas-varadero/{{{{ _.reserva_id }}}}/observaciones", nxt(), auth="socio_token", body={
    "observaciones": "Se corrio un dia por viento.",
})

amarras_socio = folder(socios, "Amarras", -800)
request(amarras_socio, "GET Tipos de embarcacion", "GET", f"{B}/api/socio/tipos-embarcacion", nxt(), auth="socio_token")
request(amarras_socio, "GET Mis solicitudes", "GET", f"{B}/api/socio/amarras", nxt(), "Solo las del socio del token, con la prioridad de la lista completa.", auth="socio_token")
request(amarras_socio, "POST Solicitar amarra", "POST", f"{B}/api/socio/amarras", nxt(), "Hasta 23 pies puede ir a tierra. Mas eslora va a agua.", auth="socio_token", body={
    "embarcacion_id": "{{ _.embarcacion_id }}",
    "tipo_id": "{{ _.tipo_id }}",
    "modalidad": "alquiler",
    "destino": "agua",
    "observaciones": "Canestrari 245, compra o alquiler en agua",
})

cd = folder(socios, "Consejo y accesos", -770)
request(cd, "POST Reunion", "POST", f"{B}/api/socio/solicitudes-reunion-cd", nxt(), auth="socio_token", body={
    "asunto": "Consulta de amarra",
    "mensaje": "Quiero revisar el lugar disponible.",
    "fecha_preferida": "2026-12-10",
})
request(cd, "GET Notas", "GET", f"{B}/api/socio/notas-cd", nxt(), auth="socio_token")
request(cd, "POST Nota", "POST", f"{B}/api/socio/notas-cd", nxt(), auth="socio_token", body={
    "asunto": "Luz del muelle",
    "mensaje": "La luminaria del finger 4 no enciende.",
})
request(cd, "POST Registrar rostro", "POST", f"{B}/api/socio/facial/enroll", nxt(), auth="socio_token", body={
    "imagen_base64": "data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAk",
})
request(cd, "POST Abrir porton", "POST", f"{B}/api/socio/portones/apertura", nxt(), "Hace falta estar dentro del radio del club.", auth="socio_token", body={
    "porton": "principal",
    "imagen_base64": "data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAk",
    "latitud": -41.133,
    "longitud": -71.31,
})
request(cd, "POST Timbre marineros", "POST", f"{B}/api/socio/timbre-marineros", nxt(), "Encola el timbre. Guardar data.desde.", auth="socio_token", body={
    "latitud": -41.133,
    "longitud": -71.31,
})
request(cd, "GET Timbre registrado", "GET", f"{B}/api/socio/timbre-marineros/estado?desde=2026-11-20T15:00:00%2B00:00", nxt(), "logged true cuando el historial del relay ya existe.", auth="socio_token")

personal = folder(WORKSPACE, "02 Personal del club", -600, "Token de POST Login en staff_token. Acepta admin, empleado o el mail del socio.")
request(personal, "POST Login", "POST", f"{B}/api/empleado/login", nxt(), "Mismo mail y clave que Socios, o el usuario admin.", {
    "user": "{{ _.socio_email }}",
    "pass": "{{ _.socio_password }}",
})
request(personal, "POST Login de admin", "POST", f"{B}/api/empleado/login", nxt(), "Usuario del panel, no el mail del socio.", {
    "user": "{{ _.staff_user }}",
    "pass": "{{ _.staff_password }}",
})
request(personal, "POST Alta de marinero", "POST", f"{B}/api/empleado/register", nxt(), "El mail tiene que existir en marineros.", {
    "user": "marinero@clubnauticobariloche.com.ar",
    "pass": "clave-inicial-8",
})
request(personal, "GET Yo", "GET", f"{B}/api/empleado/me", nxt(), auth="staff_token")
request(personal, "PATCH Cambiar clave", "PATCH", f"{B}/api/empleado/password", nxt(), auth="staff_token", body={
    "pass": "{{ _.staff_password }}",
    "nuevo": "clave-nueva-8",
})

ops = folder(WORKSPACE, "03 Amarras de administracion", -500)
request(ops, "GET Todas las solicitudes", "GET", f"{B}/api/amarras", nxt(), "Ordenadas por prioridad: metros disponibles, destino y antiguedad.", auth="staff_token")
request(ops, "PATCH Actualizar solicitud", "PATCH", f"{B}/api/amarras/{{{{ _.amarra_id }}}}", nxt(), auth="staff_token", body={
    "destino_disponible": "agua",
    "m2_disponibles": "25.5",
    "estado": "ofrecida",
})
request(ops, "POST Avance de tarea", "POST", f"{B}/api/tareas/{{{{ _.tarea_id }}}}/avance", nxt(), auth="staff_token", body={
    "progreso": 40,
    "estado": "en_progreso",
    "comentario": "Se movio la embarcacion al espacio 2",
    "marinero_id": "{{ _.marinero_id }}",
})
request(ops, "POST Asignar marinero", "POST", f"{B}/api/tareas/{{{{ _.tarea_id }}}}/asignaciones", nxt(), auth="staff_token", body={
    "marinero_id": "{{ _.marinero_id }}",
    "rol": "responsable",
})

crud = folder(WORKSPACE, "04 Recursos", -400, "Listado, ficha, alta, cambio y baja. El alta trae un ejemplo.")
ejemplos = {
    "socios": {
        "numero_socio": 3201,
        "nombre": "Ana",
        "apellido": "Perez",
        "email": "ana.perez@example.com",
        "telefono": "2944123456",
        "documento": "30111222",
        "fecha_ingreso": "2014-03-01",
        "estado": "activo",
    },
    "socio-embarcacion": {"numero_socio": "{{ _.numero_socio }}", "embarcacion_id": "{{ _.embarcacion_id }}", "rol": "titular"},
    "marineros": {"nombre": "Luis", "apellido": "Gomez", "email": "luis.gomez@example.com", "telefono": "2944000111", "especialidad": "varadero", "estado": "activo"},
    "embarcaciones": {
        "ambito": "agua",
        "numero_socio": "{{ _.numero_socio }}",
        "tipo": "crucero",
        "modelo": "CANESTRARI 245",
        "tipo_embarcacion_id": "{{ _.tipo_id }}",
        "nombre": "Nahuel",
        "matricula": "REY 1234",
        "eslora_m": "7.65",
        "manga_m": "2.60",
        "calado_m": "0.40",
        "estado": "activa",
    },
    "tipos-embarcacion": {
        "codigo": "CANESTRARI_245",
        "nombre": "CANESTRARI 245",
        "eslora_m": "7.65",
        "eslora_max_m": "7.95",
        "manga_m": "2.60",
        "puntal_m": "1.55",
        "calado_m": "0.40",
        "calado_max_m": "0.70",
        "fuente": "astillero",
    },
    "estados-padron": {"nombre": "Revisado OK", "activo": True},
    "ubicaciones": {"ambito": "agua", "nombre": "Finger 4", "activo": True},
    "vehiculos": {"nombre": "Tractor 1", "tipo": "tractor", "patente": "AB123CD", "estado": "disponible", "horometro": "1200", "ultimo_mantenimiento": "2026-10-01"},
    "espacios-varadero": {"codigo": "V1", "descripcion": "Cuna norte", "eslora_max_m": "8.00", "manga_max_m": "3.00", "activo": True},
    "reservas-varadero": {
        "numero_socio": "{{ _.numero_socio }}",
        "embarcacion_id": "{{ _.embarcacion_id }}",
        "espacio_id": "{{ _.espacio_id }}",
        "fecha_inicio": "2026-12-02",
        "fecha_fin": "2026-12-04",
        "estado": "pendiente",
        "motivo": "limpieza_mantenimiento",
        "observaciones": "Ejemplo",
    },
    "tareas": {
        "titulo": "Mover embarcacion al varadero",
        "descripcion": "Turno de limpieza",
        "tipo": "varadero",
        "prioridad": "media",
        "estado": "pendiente",
        "progreso": 0,
        "numero_socio": "{{ _.numero_socio }}",
        "embarcacion_id": "{{ _.embarcacion_id }}",
        "fecha_planificada": "2026-12-02",
    },
    "tarea-asignaciones": {"tarea_id": "{{ _.tarea_id }}", "marinero_id": "{{ _.marinero_id }}", "rol": "responsable"},
    "avances-tarea": {"tarea_id": "{{ _.tarea_id }}", "marinero_id": "{{ _.marinero_id }}", "progreso": 50, "estado": "en_progreso", "comentario": "A mitad de camino"},
    "novedades": {"titulo": "Nivel del lago", "contenido": "El lago sigue en la cota de hoy.", "activo": True},
    "camaras": {"nombre": "Muelle", "ubicacion": "Sede", "stream_url": "https://example.com/stream", "activa": True, "orden": 1},
    "socio-acceso": {"numero_socio": "{{ _.numero_socio }}", "email": "{{ _.socio_email }}", "password_hash": "$2y$13$ejemplo", "biometric_habilitado": False},
    "mediciones-nivel": {"fecha": "2026-11-20T15:00:00+00:00", "distancia_medida_cm": "120.5", "profundidad_cm": "350", "msnm": "770.2"},
}
for slug, ejemplo in ejemplos.items():
    group = folder(crud, slug, nxt())
    request(group, f"GET {slug}", "GET", f"{B}/api/{slug}", nxt(), auth="staff_token")
    request(group, f"GET {slug}/id", "GET", f"{B}/api/{slug}/{{{{ _.resource_id }}}}", nxt(), auth="staff_token")
    request(group, f"POST {slug}", "POST", f"{B}/api/{slug}", nxt(), auth="staff_token", body=ejemplo)
    request(group, f"PATCH {slug}/id", "PATCH", f"{B}/api/{slug}/{{{{ _.resource_id }}}}", nxt(), auth="staff_token", body=ejemplo)
    request(group, f"DELETE {slug}/id", "DELETE", f"{B}/api/{slug}/{{{{ _.resource_id }}}}", nxt(), "Borra el registro.", auth="staff_token")

web = folder(WORKSPACE, "05 Panel web", -200, "Formularios HTML. Usan cookie de sesion, no el token de la API.")
request(web, "GET Login", "GET", f"{B}/admin/login", nxt())
request(web, "POST Login", "POST", f"{B}/admin/login", nxt(), "El campo es username. Hace falta la cookie y _token de GET Login.", form={
    "_token": "pegar-el-token-del-formulario",
    "username": "{{ _.socio_email }}",
    "password": "{{ _.socio_password }}",
})
request(web, "GET Crear usuario de socio", "GET", f"{B}/admin/registro", nxt())
request(web, "POST Crear usuario, paso PIN", "POST", f"{B}/admin/registro", nxt(), form={
    "_token": "pegar-el-token-del-formulario",
    "accion": "pin",
    "numeroSocio": "{{ _.numero_socio }}",
    "pin": "1234",
})
request(web, "POST Crear usuario, mail y clave", "POST", f"{B}/admin/registro", nxt(), form={
    "_token": "pegar-el-token-del-formulario",
    "accion": "cuenta",
    "email": "{{ _.socio_email }}",
    "password": "{{ _.socio_password }}",
    "confirmar": "{{ _.socio_password }}",
    "pinNuevo": "5678",
    "pinConfirmar": "5678",
})
request(web, "POST Recuperar clave", "POST", f"{B}/admin/recuperar", nxt(), form={
    "_token": "pegar-el-token-del-formulario",
    "numeroSocio": "{{ _.numero_socio }}",
    "pin": "1234",
    "email": "{{ _.socio_email }}",
    "password": "clave-nueva-8",
    "confirmar": "clave-nueva-8",
})
request(web, "GET Inicio", "GET", f"{B}/admin", nxt())
request(web, "POST Alta de personal", "POST", f"{B}/admin/personal", nxt(), form={
    "email": "operaciones@clubnauticobariloche.com.ar",
    "password": "clave-inicial-8",
})
request(web, "GET Amarras", "GET", f"{B}/admin/amarras", nxt())
request(web, "POST Solicitud de amarra", "POST", f"{B}/admin/amarras", nxt(), form={
    "accion": "solicitar",
    "embarcacion_id": "{{ _.embarcacion_id }}",
    "tipo_id": "{{ _.tipo_id }}",
    "modalidad": "alquiler",
    "destino": "agua",
    "observaciones": "Ejemplo desde Insomnium",
})
request(web, "POST Actualizar solicitud", "POST", f"{B}/admin/amarras", nxt(), form={
    "accion": "actualizar",
    "id": "{{ _.amarra_id }}",
    "destino_disponible": "agua",
    "m2_disponibles": "25.5",
    "estado": "ofrecida",
})
request(web, "GET Timbre marineros", "GET", f"{B}/admin/timbre", nxt())
request(web, "POST Tocar timbre", "POST", f"{B}/admin/timbre", nxt())
request(web, "GET Estado del timbre", "GET", f"{B}/admin/timbre/estado", nxt())
request(web, "GET Nivel del lago", "GET", f"{B}/admin/nivel-lago", nxt())
request(web, "GET Varadero", "GET", f"{B}/admin/varadero", nxt())
request(web, "GET Varadero timeline", "GET", f"{B}/admin/varadero/timeline", nxt())
request(web, "GET Varadero disponibilidad", "GET", f"{B}/admin/varadero/disponibilidad?espacio_id={{{{ _.espacio_id }}}}&desde=2026-11-20&hasta=2026-12-20", nxt())
request(web, "GET Varadero embarcaciones", "GET", f"{B}/admin/varadero/embarcaciones", nxt())
request(web, "POST Reservar varadero", "POST", f"{B}/admin/varadero", nxt(), form={
    "_accion": "crear",
    "numero_socio": "{{ _.numero_socio }}",
    "embarcacion_id": "{{ _.embarcacion_id }}",
    "espacio_id": "{{ _.espacio_id }}",
    "fecha_inicio": "2026-12-02",
    "fecha_fin": "2026-12-04",
    "estado": "pendiente",
    "motivo": "limpieza_mantenimiento",
    "observaciones": "Ejemplo",
})
request(web, "POST Salir", "POST", f"{B}/admin/logout", nxt())
for slug in ejemplos:
    request(web, f"GET Pantalla {slug}", "GET", f"{B}/admin/{slug}", nxt())

timbre = folder(WORKSPACE, "06 Timbre publico", -100, "Pantalla /timbre. La sesion es una cookie.")
request(timbre, "GET Pantalla", "GET", f"{B}/timbre", nxt())
request(timbre, "POST Ingreso con PIN", "POST", f"{B}/timbre/login", nxt(), form={
    "numeroSocio": "{{ _.numero_socio }}",
    "pin": "1234",
    "_token": "pegar-el-token-del-formulario",
})
request(timbre, "POST Tocar", "POST", f"{B}/timbre/tocar", nxt(), form={"_token": "pegar-el-token-del-formulario"})
request(timbre, "GET Esperando historial", "GET", f"{B}/timbre/estado", nxt(), "logged true cuando el relay quedo en el historial.")
request(timbre, "POST Salir", "POST", f"{B}/timbre/salir", nxt())

out = {
    "_type": "export",
    "__export_format": 4,
    "__export_date": "2026-11-20T15:20:00.000Z",
    "__export_source": "cnb-backend.generator",
    "resources": resources,
}
dest = Path(__file__).with_name("cnb-api.insomnia.json")
dest.write_text(json.dumps(out, indent=2, ensure_ascii=False) + "\n")
print(f"{dest} requests={sum(1 for r in resources if r['_type']=='request')} folders={sum(1 for r in resources if r['_type']=='request_group')}")

#!/usr/bin/env python3
"""
Suite de pruebas del formulario de entrevistas de tutorías.

Ejecuta ~30 escenarios contra POST /api/estudiantes sin llenar el formulario a
mano: campos opcionales presentes/ausente, merges *_otro, enums con variantes,
habilidades B/N/M, normalización del número de control y todos los rechazos.

Uso:
    python3 tests/test_form.py --dry-run                 # solo lista, sin red
    python3 tests/test_form.py --admin-password 'PW'     # suite completa

Solo usa la librería estándar de Python 3.
"""

from __future__ import annotations

import argparse
import copy
import http.cookiejar
import json
import os
import shutil
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path
from urllib.parse import urlencode

HERE = Path(__file__).resolve().parent
REPO = HERE.parent

# Campos obligatorios según $required en public/index.php. Todo lo demás en
# base_payload.json es opcional y por eso se puede probar ausente.
REQUIRED = [
    "numero_control", "nombre_completo", "fecha_nacimiento", "lugar_nacimiento",
    "domicilio_familiar", "localidad_familiar", "codigo_postal", "genero",
    "estado_civil", "zona", "tipo_vivienda", "telefono_movil", "tutor_id",
    "num_integrantes_familia", "num_hermanos", "lugar_que_ocupa", "vives_con",
    "institucion_procedencia", "localidad_escuela", "generacion_egreso",
    "promedio", "rendimiento_escolar", "reaccion_padres_calificaciones",
]

# Nota: los 9 campos de habilidades son claves planas (comprension_lectora...)
# y habilidad_fields() de index.php devuelve exactamente esas mismas 9 claves.


def optional_keys(base: dict) -> list:
    """Todas las claves del payload base que NO son obligatorias ni de control."""
    skip = set(REQUIRED) | {"csrf_token", "hp_email"}
    return [k for k in base if k not in skip]


def coerce_equal(expected, actual) -> bool:
    """Compara lo esperado con lo que devuelve PHP/PDO (tipos sueltos)."""
    if expected is None:
        return actual is None
    if isinstance(expected, bool):
        if isinstance(actual, str):
            return (actual.strip().lower() in ("1", "true")) == expected
        if isinstance(actual, (int, float)):
            return bool(int(actual)) == expected
        return actual is expected
    if isinstance(expected, (int, float)):
        try:
            return abs(float(actual) - float(expected)) < 1e-6
        except (TypeError, ValueError):
            return False
    return str(actual) == str(expected)


class Client:
    """Sesión HTTP con cookies (necesaria para el CSRF de sesión)."""

    def __init__(self, base_url: str, admin_password: str | None, dry: bool):
        self.base = base_url.rstrip("/")
        self.pw = admin_password
        self.dry = dry
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar)
        )
        self.csrf: str | None = None
        self.submits = 0  # intentos de envío, para limpiar submit_logs al final

    # --- HTTP -----------------------------------------------------------
    def call(self, method, path, *, body=None, raw=None, admin=False,
             ctype="application/json", headers=None):
        data = None
        if raw is not None:
            data = raw.encode("utf-8")
        elif body is not None:
            if ctype == "application/x-www-form-urlencoded":
                data = urlencode(body).encode("utf-8")
            else:
                data = json.dumps(body, ensure_ascii=False).encode("utf-8")
        req = urllib.request.Request(self.base + path, data=data, method=method)
        if data is not None:
            req.add_header("Content-Type", ctype)
        for key, val in (headers or {}).items():
            req.add_header(key, val)
        if admin:
            if not self.pw:
                raise RuntimeError("Falta la contraseña admin para " + path)
            req.add_header("X-Admin-Password", self.pw)
        if path == "/api/estudiantes":
            self.submits += 1
        try:
            with self.opener.open(req, timeout=40) as resp:
                return resp.status, resp.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")
        except Exception as e:  # sin servidor, DNS, timeout...
            return 0, "%s: %s" % (type(e).__name__, e)

    @staticmethod
    def as_json(text):
        try:
            data = json.loads(text)
            return data if isinstance(data, dict) else {}
        except ValueError:
            return {}

    def get_csrf(self) -> str:
        status, text = self.call("GET", "/api/csrf")
        token = self.as_json(text).get("csrfToken")
        if status != 200 or not token:
            raise RuntimeError(
                "No se pudo obtener el CSRF (%s): %s" % (status, text[:200])
            )
        return token


# --- Docker: verificación y limpieza de la base de datos -----------------
def find_container() -> str | None:
    if not shutil.which("docker"):
        return None
    for cmd in (["docker", "compose", "ps", "-q", "app"],
                ["docker", "ps", "-q", "--filter", "name=entrevistas_api"]):
        try:
            r = subprocess.run(cmd, cwd=REPO, capture_output=True, text=True,
                               timeout=40)
        except Exception:
            continue
        ids = (r.stdout or "").strip().split()
        if r.returncode == 0 and ids:
            return ids[0]
    return None


def db(cid: str | None, *args) -> dict:
    if not cid:
        return {}
    # Ruta absoluta: docker-compose monta ".:/var/www/html", así no depende
    # del WORKDIR de la imagen.
    r = subprocess.run(["docker", "exec", cid, "php",
                        "/var/www/html/tests/db.php", *args],
                       capture_output=True, text=True, timeout=90)
    if r.returncode != 0:
        raise RuntimeError("db.php %s -> %s" % (" ".join(args),
                                                (r.stderr or r.stdout).strip()))
    return Client.as_json(r.stdout)


class Suite:
    def __init__(self, args, base_payload: dict):
        self.args = args
        self.base = base_payload
        self.client = Client(args.url, args.admin_password, args.dry)
        self.cid = None if args.no_db else find_container()
        self.rows = []
        self.only = args.only
        self.keep = args.keep
        self.skip_notes = []
        self.tutor_a = None
        self.tutor_b = None
        self.first_nc = None
        self.next_nc_n = 1
        self.orig_settings = {}   # por si setup() falla antes de leerlas
        self.rate_window = 10      # minutos; setup() lo ajusta al valor real

    # --- utilidades -----------------------------------------------------
    def next_nc(self) -> str:
        nc = "9969%04d" % self.next_nc_n
        self.next_nc_n += 1
        return nc

    def payload(self, nc=None, tutor=None) -> dict:
        p = copy.deepcopy(self.base)
        p["csrf_token"] = self.client.csrf
        p["hp_email"] = ""
        p["numero_control"] = nc or self.next_nc()
        p["tutor_id"] = self.tutor_a if tutor is None else tutor
        return p

    def skip(self, name: str, why: str):
        if self.only and self.only not in name:
            return
        if self.args.dry:
            # En dry-run no sabemos si habrá docker o contraseña: se lista igual.
            print("  %-34s ->  -   (%s)" % (name, why))
            return
        self.rows.append((name, "-", "-", "SKIP: " + why))

    def record(self, name, ok, detail, expected, status):
        self.rows.append((name, str(expected), str(status),
                          "OK" if ok else "FALLA " + detail))

    # --- el corazón -----------------------------------------------------
    def run(self, name, expect, *, mutate=None, body_eq=None, missing=None,
            fields=None, db_expect=None, raw=None, nc=None, headers=None):
        if self.only and self.only not in name:
            return
        if self.args.dry:
            extra = ""
            if missing:
                extra = "  missing+=%s" % ",".join(missing)
            elif fields:
                extra = "  fields+=%s" % ",".join(fields)
            elif db_expect:
                extra = "  bd:%d" % len(db_expect)
            print("  %-34s -> %3d%s" % (name, expect, extra))
            return

        p = None
        if raw is None:
            p = self.payload(nc=nc)
            if mutate:
                mutate(p)
            if self.first_nc is None and name.startswith("payload_completo"):
                self.first_nc = p["numero_control"]

        status, text = (self.client.call("POST", "/api/estudiantes", raw=raw,
                                         headers=headers)
                        if raw is not None
                        else self.client.call("POST", "/api/estudiantes", body=p))
        data = self.client.as_json(text)
        ok = status == expect
        # Si el status no cuadra, el detalle es la respuesta real: sin eso un
        # servidor caído se vería como "FALLA" sin motivo.
        detail = "" if ok else "respuesta: " + (text or "").strip()[:90].replace(
            "\n", " ")

        if ok and body_eq:
            for k, v in body_eq.items():
                if data.get(k) != v:
                    ok, detail = False, "%s=%r (esperado %r)" % (k, data.get(k), v)
                    break

        if ok and missing:
            got = set(data.get("missing") or [])
            absent = [k for k in missing if k not in got]
            if absent:
                ok, detail = False, "missing sin %s (fue %s)" % (
                    ",".join(absent), ",".join(sorted(got)))

        if ok and fields:
            got = set((data.get("fields") or {}).keys())
            absent = [k for k in fields if k not in got]
            if absent:
                ok, detail = False, "fields sin %s (fue %s)" % (
                    ",".join(absent), ",".join(sorted(got)))

        if ok and db_expect and not self.cid:
            # Sin docker no hay forma de leer la BD: no es un fallo de la
            # app, se anota SKIP para no generar falsos negativos.
            self.rows.append((name, str(expect), str(status),
                              "SKIP: sin docker no se verifica la BD "
                              "(status correcto)"))
            return

        if ok and db_expect:
            raw_nc = (p or {}).get("numero_control") or nc or ""
            # Misma normalización que index.php:346 (trim, sin espacios,
            # upper): es el valor que queda guardado y con el que hay que
            # buscar, p. ej. " b24 690077 " -> "B24690077".
            used_nc = str(raw_nc).strip().replace(" ", "").upper()
            try:
                real = db(self.cid, "verify", used_nc)
            except RuntimeError as e:
                ok, detail = False, str(e)
            else:
                for path, exp in db_expect.items():
                    table, col = path.split(".", 1)
                    actual = (real.get(table) or {}).get(col)
                    if not coerce_equal(exp, actual):
                        ok = False
                        detail = "%s=%r (esperado %r)" % (path, actual, exp)
                        break

        self.record(name, ok, detail, expect, status)

    # --- ciclo de vida --------------------------------------------------
    def setup(self):
        c = self.client
        c.csrf = c.get_csrf()

        # Guardar settings para restaurarlas después
        _, text = c.call("GET", "/api/admin/settings", admin=True)
        current = {s["key"]: s for s in c.as_json(text).get("settings", [])}
        self.orig_settings = {
            "rate_limit_max": current.get("rate_limit_max", {}).get("value"),
            "form_active": current.get("form_active", {}).get("value"),
        }
        try:
            self.rate_window = max(1, int(
                current.get("rate_limit_window_min", {}).get("value") or 10))
        except (TypeError, ValueError):
            self.rate_window = 10

        # Importante: sin esto, a partir del 6º envío todo daría 429
        c.call("PUT", "/api/admin/settings", admin=True,
               body={"settings": {"rate_limit_max": "999"}})
        # El form debe estar abierto para que los casos de 201 funcionen
        c.call("PUT", "/api/admin/settings", admin=True,
               body={"settings": {"form_active": True}})

        self.tutor_a = self.create_tutor("ZZTEST Suite A")
        self.tutor_b = self.create_tutor("ZZTEST Suite B")

    def create_tutor(self, nombre: str):
        # Mismo formato que el panel (admin.js envía URLSearchParams): el
        # endpoint usa getParsedBody(), que en slim/psr7 solo parsea
        # form-encoded y multipart, no JSON (ServerRequestFactory.php:104).
        status, text = self.client.call(
            "POST", "/api/admin/tutores", admin=True,
            body={"nombre": nombre, "email": ""},
            ctype="application/x-www-form-urlencoded")
        data = self.client.as_json(text)
        tid = data.get("id") or data.get("tutor", {}).get("id")
        if status not in (200, 201) or not tid:
            raise RuntimeError("No se pudo crear el tutor de prueba (%s): %s"
                               % (status, text[:200]))
        return int(tid)

    def teardown(self):
        if self.args.dry:
            return
        c = self.client
        if not c.pw:
            return
        # Restaurar settings: clave ausente = volver a borrarla (default real)
        restore = {}
        for key, orig in self.orig_settings.items():
            if orig is None:
                c.call("DELETE", "/api/admin/settings/" + key, admin=True)
            elif key == "rate_limit_max":
                restore[key] = orig
            elif key == "form_active":
                restore[key] = orig == "1"
        if restore:
            c.call("PUT", "/api/admin/settings", admin=True,
                   body={"settings": restore})
        if self.cid and not self.keep:
            for tid in (self.tutor_a, self.tutor_b):
                if tid:
                    try:
                        db(self.cid, "cleanup", str(tid))
                        db(self.cid, "tutor-delete", str(tid))
                    except RuntimeError as e:
                        self.skip_notes.append(str(e))
            try:
                # Misma ventana que usa el rate limiter: al borrarla el
                # contador por IP vuelve a cero y no bloquea los envíos
                # reales que hagas después desde esta misma conexión.
                db(self.cid, "logs-window", str(self.rate_window))
            except RuntimeError as e:
                self.skip_notes.append(str(e))


# ---------------------------------------------------------------------------
# Los escenarios
# ---------------------------------------------------------------------------
def scenarios(s: Suite):
    base = s.base

    # ---------- A. Aceptación con combinaciones de campos opcionales -----
    s.run("payload_completo", 201, db_expect={
        "estudiantes.capturado": True,
        "estudiantes.tipo_vivienda": "OTRA",
        # tipo_vivienda_otro NO se fusiona: va a su propia columna
        "estudiantes.tipo_vivienda_otro": "Piezas de vecindad",
        "estudiantes.tiempo_traslado_transporte": "30 minutos",
        "estudiantes.costo_transporte": 16.5,
        "datos_familiares.vives_con": "Tíos y abuelos",       # merge OTROS
        "datos_familiares.horas_trabajo": 20,
        "datos_escolares.tipo_beca": "Beca deportiva municipal",  # merge OTRA
        "datos_escolares.rendimiento_escolar": "MUY_BUENO",
        # Campo numérico obligatorio: debe persistir en datos_escolares,
        # NO en estudiantes (regresión: no perder el promedio).
        "datos_escolares.promedio": base["promedio"],
        "habilidades_escolares.comprension_lectora": "Bueno",
        "habilidades_escolares.expresion_oral": "Malo",
        "datos_medicos.cual_enfermedad": "Asma leve",          # merge OTRA
        "datos_medicos.cual_condicion": "Miopía",              # merge OTRA
        "expectativas_ingreso.tipo_apoyo": "Tutorías entre pares",  # merge OTRO
        "expectativas_ingreso.prio_otra": "Que tenga laboratorios abiertos",
        "expectativas_ingreso.causa_problemas_estudio":
            "Me organizo mal; Por distraerme en otra cosa",
    })

    s.run("solo_requeridos", 201, mutate=lambda p: [
        p.pop(k) for k in optional_keys(base)
    ], db_expect={
        "estudiantes.habla_otra_lengua": False,
        "estudiantes.usa_transporte_publico": False,
        "estudiantes.tiempo_traslado_transporte": None,
        "estudiantes.costo_transporte": None,
        "datos_familiares.padre_nombre": None,
        "datos_medicos.padece_enfermedad": False,
        "expectativas_ingreso.prio_otra": None,
        "habilidades_escolares.vocabulario": None,
    })

    s.run("sin_transporte_tiempo_y_costo", 201, mutate=lambda p: p.update({
        "usa_transporte_publico": False,
        "tiempo_traslado_transporte": "9 horas",
        "costo_transporte": 99.99,
    }), db_expect={
        "estudiantes.usa_transporte_publico": False,
        "estudiantes.tiempo_traslado_transporte": None,
        "estudiantes.costo_transporte": None,
    })

    s.run("habilidades_en_palabras", 201, mutate=lambda p: p.update({
        "comprension_lectora": "Malo", "expresion_oral": "Bueno",
        "ortografia": "Normal",
    }), db_expect={
        "habilidades_escolares.comprension_lectora": "Malo",
        "habilidades_escolares.expresion_oral": "Bueno",
        "habilidades_escolares.ortografia": "Normal",
    })

    s.run("enums_con_variantes", 201, mutate=lambda p: p.update({
        "estado_civil": "union libre",
        "rendimiento_escolar": "muy bueno",
        "reaccion_padres_calificaciones": "MUY BIEN",
        "preferencia_trabajo": "ME_DA_IGUAL",
        "tipo_vivienda": "prestada",
        "zona": "rural",
        "genero": "nb",
    }), db_expect={
        "estudiantes.estado_civil": "UNION LIBRE",
        "estudiantes.tipo_vivienda": "PRESTADA",
        "estudiantes.zona": "RURAL",
        "estudiantes.genero": "NB",
        "datos_escolares.rendimiento_escolar": "MUY_BUENO",
        "datos_escolares.reaccion_padres_calificaciones": "MUY_BIEN",
        "expectativas_ingreso.preferencia_trabajo": "IGUAL",
    })

    s.run("numero_control_normalizado", 201,
          mutate=lambda p: p.update({"numero_control": " b24 690077 "}),
          db_expect={"estudiantes.numero_control": "B24690077"})

    s.run("acentos_sql_y_scripts", 201, mutate=lambda p: p.update({
        "nombre_completo": "Ñandú Águila Muñóz",
        "domicilio_familiar": "Calle '; DROP TABLE estudiantes; -- <script>alert(1)</script>",
        "materias_favoritas": 'Comillas "dobles" y apostrofes \'solas\'',
    }), db_expect={"estudiantes.nombre_completo": "Ñandú Águila Muñóz"})

    # Preregistro (como el CSV que sube el admin) que el form debe completar
    if s.args.dry:
        s.run("actualiza_preregistro", 201, db_expect={
            "estudiantes.capturado": True,
            "estudiantes.nombre_completo": base["nombre_completo"],
        })
    elif s.cid:
        seed_nc = "99690090"
        try:
            db(s.cid, "seed", str(s.tutor_a), seed_nc)
        except RuntimeError as e:
            s.skip("actualiza_preregistro", str(e))
        else:
            s.run("actualiza_preregistro", 201, nc=seed_nc, db_expect={
                "estudiantes.capturado": True,
                "estudiantes.nombre_completo": base["nombre_completo"],
            })
    else:
        s.skip("actualiza_preregistro", "necesita docker")

    # ---------- B. Rechazos --------------------------------------------
    s.run("honeypot_relleno", 400,
          mutate=lambda p: p.update({"hp_email": "spam@bot.com"}),
          body_eq={"error": "Bad request"})

    s.run("sin_csrf", 403, mutate=lambda p: p.pop("csrf_token"),
          body_eq={"error": "CSRF token missing or invalid"})

    s.run("csrf_invalido", 403,
          mutate=lambda p: p.update({"csrf_token": "token-falso"}),
          body_eq={"error": "CSRF token missing or invalid"})

    if s.args.dry:
        s.run("formulario_cerrado", 423,
              body_eq={"error": "Formularios cerrados temporalmente"})
    elif s.client.pw:
        s.client.call("PUT", "/api/admin/settings", admin=True,
                      body={"settings": {"form_active": False}})
        s.run("formulario_cerrado", 423,
              body_eq={"error": "Formularios cerrados temporalmente"})
        s.client.call("PUT", "/api/admin/settings", admin=True,
                      body={"settings": {"form_active": True}})
    else:
        s.skip("formulario_cerrado", "requiere --admin-password")

    s.run("faltan_requeridos", 422,
          mutate=lambda p: [p.pop(k) for k in
                            ("nombre_completo", "promedio", "zona")],
          body_eq={"error": "Faltan campos requeridos"},
          missing=["nombre_completo", "promedio", "zona"])

    s.run("tutor_inexistente", 422,
          mutate=lambda p: p.update({"tutor_id": 999999}),
          fields=["tutor_id"])

    s.run("fecha_invalida", 422,
          mutate=lambda p: p.update({"fecha_nacimiento": "31/12/2008"}),
          fields=["fecha_nacimiento"])

    s.run("telefono_corto", 422,
          mutate=lambda p: p.update({"telefono_movil": "123"}),
          fields=["telefono_movil"])

    s.run("promedio_fuera_de_rango", 422,
          mutate=lambda p: p.update({"promedio": 15}),
          fields=["promedio"])

    s.run("genero_invalido", 422,
          mutate=lambda p: p.update({"genero": "X"}),
          fields=["genero"])

    s.run("numero_control_mal_formado", 422,
          mutate=lambda p: p.update({"numero_control": "ABC-123"}),
          fields=["numero_control"])

    s.run("habilidad_invalida", 422,
          mutate=lambda p: p.update({"comprension_lectora": "Z"}),
          fields=["comprension_lectora"])

    s.run("preferencia_trabajo_invalida", 422,
          mutate=lambda p: p.update({"preferencia_trabajo": "ME_DA_LO_QUE_SEA"}),
          fields=["preferencia_trabajo"])

    # json_decode fallido => [] => todos los requeridos ausentes. Con el CSRF
    # por cabecera llega hasta la validación: el cuerpo corrupto no debe
    # producir 500 (sin cabecera caería antes, en el 403 de CSRF).
    s.run("cuerpo_no_json", 422, raw="{esto no es json",
          headers={"X-CSRF-Token": s.client.csrf or "x"},
          body_eq={"error": "Faltan campos requeridos"},
          missing=["numero_control", "nombre_completo", "promedio"])

    # ---------- C. Conflictos de número de control ----------------------
    if s.args.dry or s.first_nc:
        s.run("numero_control_ya_capturado", 409, nc=s.first_nc,
              body_eq={"error": "Número de control ya registrado"})
    else:
        s.skip("numero_control_ya_capturado", "falló payload_completo")

    if s.args.dry or s.cid:
        seed_nc2 = "99690091"
        if not s.args.dry:
            try:
                db(s.cid, "seed", str(s.tutor_a), seed_nc2)
            except RuntimeError as e:
                s.skip("numero_control_de_otro_tutor", str(e))
                seed_nc2 = None
        if seed_nc2:
            s.run("numero_control_de_otro_tutor", 409, nc=seed_nc2,
                  mutate=lambda p: p.update({"tutor_id": s.tutor_b}),
                  body_eq={"error": "Número de control asignado a otro tutor"})
    else:
        s.skip("numero_control_de_otro_tutor", "necesita docker")

    # ---------- D. Rate limit (siempre al final: toca settings y logs) ---
    if s.args.dry:
        s.run("rate_limit_envio_1", 201)
        s.run("rate_limit_envio_2", 201)
        s.run("rate_limit_tercer_envio", 429)
    elif s.client.pw and s.cid:
        s.client.call("PUT", "/api/admin/settings", admin=True,
                      body={"settings": {"rate_limit_max": "2"}})
        try:
            # Contador limpio: sin esto, los envíos anteriores de la suite ya
            # habrían consumido el cupo de 2 y todo daría 429.
            db(s.cid, "logs-window", str(s.rate_window))
        except RuntimeError as e:
            s.skip("rate_limit_tercer_envio", str(e))
        else:
            s.run("rate_limit_envio_1", 201)
            s.run("rate_limit_envio_2", 201)
            s.run("rate_limit_tercer_envio", 429,
                  body_eq={"error": "Demasiados envíos desde esta conexión. "
                                    "Intenta de nuevo más tarde."})
    else:
        s.skip("rate_limit_tercer_envio",
               "requiere --admin-password y docker (limpia los submit_logs)")


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Suite de pruebas del formulario de tutorías")
    ap.add_argument("--url", default="http://localhost:8095",
                    help="URL base de la app (por defecto %(default)s)")
    ap.add_argument("--admin-password", default=os.environ.get("ADMIN_PASSWORD"),
                    help="Contraseña admin (o variable ADMIN_PASSWORD)")
    ap.add_argument("--dry-run", action="store_true",
                    help="Lista los escenarios sin tocar nada")
    ap.add_argument("--no-db", action="store_true",
                    help="No verifica ni limpia la base de datos")
    ap.add_argument("--keep", action="store_true",
                    help="No borra los registros de prueba al terminar")
    ap.add_argument("--only", default=None,
                    help="Solo escenarios cuyo nombre contiene este texto")
    args = ap.parse_args()
    # alias corto que usan Suite, run(), skip() y teardown()
    args.dry = args.dry_run

    # Respaldo: si no se pasó la contraseña, leerla del .env del repo (ahí
    # vive el resto de secretos y .gitignore lo excluye). Nunca se imprime.
    if not args.admin_password:
        env_file = REPO / ".env"
        if env_file.exists():
            for line in env_file.read_text(encoding="utf-8",
                                           errors="replace").splitlines():
                line = line.strip()
                if line.startswith("ADMIN_PASSWORD="):
                    args.admin_password = line.split("=", 1)[1].strip().strip(
                        "\"'")
                    break

    payload_path = HERE / "base_payload.json"
    base = json.loads(payload_path.read_text(encoding="utf-8"))
    faltantes = [k for k in REQUIRED if k not in base]
    if faltantes:
        print("base_payload.json le falta lo obligatorio: %s" % faltantes)
        return 2

    print("URL: %s" % args.url)
    if args.dry:
        print("\nEscenarios (dry run, nada se envía):")

    if not args.dry_run and not args.admin_password:
        print("Falta la contraseña admin (--admin-password o ADMIN_PASSWORD).")
        print("Se usa para crear el tutor de prueba y subir el rate limit.")
        return 2

    suite = Suite(args, base)
    if not args.dry_run:
        if suite.cid is None:
            print("Aviso: no se detectó docker; solo se verificarán las "
                  "respuestas HTTP y NO habrá limpieza automática.")
        try:
            suite.setup()
        except Exception as e:
            print("No se pudo preparar la suite: %s" % e)
            # Restaurar settings/tutores aunque el arranque falle a medias
            # (p. ej. crea el tutor A y el B no)
            try:
                suite.teardown()
            except Exception as e2:
                print("Aviso en la limpieza: %s" % e2)
            return 2

    try:
        scenarios(suite)
    finally:
        try:
            suite.teardown()
        except Exception as e:
            print("Aviso en la limpieza: %s" % e)

    if not suite.rows and args.only:
        print("Ningún escenario coincide con --only=%r" % args.only)
        return 2

    # ---------- reporte ----------
    print("\n" + "=" * 74)
    print("%-36s %-5s %-5s %s" % ("ESCENARIO", "ESP", "OBT", "RESULTADO"))
    print("-" * 74)
    for name, exp, got, result in suite.rows:
        print("%-36s %-5s %-5s %s" % (name, exp, got, result))
    print("=" * 74)

    fails = [r for r in suite.rows if r[3].startswith("FALLA")]
    skips = [r for r in suite.rows if r[3].startswith("SKIP")]
    print("Resultado: %d OK / %d SKIP / %d FALLA"
          % (len(suite.rows) - len(fails) - len(skips), len(skips), len(fails)))
    print("Envíos al formulario: %d" % suite.client.submits)
    for note in suite.skip_notes:
        print("  aviso: %s" % note)
    if not args.dry_run and suite.cid is None and not suite.keep:
        print("  IMPORTANTE: sin docker no se limpió nada. Borra los")
        print("  registros de prueba (tutor ZZTEST Suite A/B) antes de producir.")
    if suite.keep:
        print("  --keep: los registros de prueba quedaron en la BD.")
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())

# Mini plataforma de entrevistas de tutorías

Sistema para la **captura de fichas de entrevista escolar** de los estudiantes
(asignados a un tutor o tutora), con panel administrativo para el seguimiento
del avance, la carga de listas de preregistros y la descarga de reportes en
Excel. Pensado para campañas anuales: cada generación se gestiona como un
**periodo** independiente.

| Ruta     | Qué sirve                                   |
| -------- | ------------------------------------------- |
| `/`      | Formulario público de captura (`form.html`) |
| `/admin` | Panel de administración (`admin.html`)      |

## Stack

- **Backend:** PHP 8.3 · Slim 4 · Eloquent (`illuminate/database`) · PhpSpreadsheet
- **Frontend:** HTML + JavaScript vanilla · Tailwind CSS (CDN) · sin build step
- **Base de datos:** MySQL
- **Infraestructura:** Docker (`docker-compose.yml`), PHP built-in server (`php -S`) en el puerto interno `8000`, publicado en `8095`

## Estructura del repositorio

```txt
├── public/
│   ├── index.php        # API completa (Slim) + rutas raíz y fallback 404
│   ├── form.html        # Formulario público de captura (5 pasos)
│   ├── app.js           # Lógica del formulario (pasos, validaciones, envío)
│   ├── admin.html       # Panel de administración
│   └── admin.js         # Lógica del panel (tutores, KPIs, exports, settings)
├── src/Models/          # Modelos Eloquent (Estudiante, Tutor y 5 bloques)
├── db/                  # Esquema SQL (mysql_entrevistas.sql) y migraciones
├── tests/               # Suite automatizada del formulario (ver tests/README.md)
├── form.md              # Especificación de campos por paso (referencia del formulario)
├── Entrevista Ficha Escolar.pdf   # Ficha original en papel
├── Dockerfile · entrypoint.sh · docker-compose.yml
└── .env.example         # Plantilla de variables de entorno (DB_*, APP_*)
```

## Módulos

### 1. Formulario de captura (`form.html` + `app.js`)

Flujo para el estudiante/tutorado:

- **Paso 0 – Verificación:** pide el número de control (formato institucional,
  prefijo `B`/`C` opcional) y consulta `GET /api/estudiantes/check`:
  - si ya existe y está capturado → aviso de "captura realizada" y no deja continuar;
  - si existe pendiente → se **precarga** su lista y se bloquea el selector de tutor
    (el registro ya fue asignado por el administrador).
- **Pasos 1–5:** datos personales, familiares, escolares, habilidades,
  médicos y expectativas (bloques condicionales que se muestran/ocultan según
  respuestas). Ver `form.md` por el detalle de cada campo.
- **Teléfono móvil obligatorio:** máscara de 10 dígitos (JS + `maxlength` +
  `pattern`) con texto de ayuda para usar un celular de familiar de confianza.
- **Prioridades de atención:** validación en cliente para evitar duplicados.
- **Envío:** `POST /api/estudiantes` con token CSRF; en éxito muestra alerta,
  limpia el formulario y regresa al panel de verificación. En error **conserva
  todos los datos capturados** (el reset sólo ocurre en éxito).

### 2. Panel de administración (`admin.html` + `admin.js`)

- **Acceso:** contraseña enviada en el header `X-Admin-Password`; se guarda en
  `localStorage` y se renueva con `GET /api/admin/ping`.
- **Desglose de avance:** KPIs globales (tutorados, respondieron, pendientes,
  sin tutor, % de avance) + barra de progreso, **selector de periodo opcional**
  y botón **"Descargar histórico"**.
- **Crear tutor** y **lista de tutores** con acciones por fila:
  - editar nombre/email/activo,
  - **cargar lista de preregistros** (CSV/XLS/XLSX; primera columna = número de
    control) vía `POST /api/admin/tutores/{id}/upload` → sólo reasigna
    `tutor_id`, no toca periodo ni datos personales,
  - guardar cambios, ver tutorados (modal con avance y acciones),
  - **descargar respuestas del tutor** en Excel (periodo activo),
  - eliminar tutor.
- **Modal de tutorados:**
  - ver respuestas completas del estudiante (vista formateada),
  - **liberar captura** (`DELETE …/{nc}/captura`): borra las respuestas y deja
    `capturado=0` para que se vuelva a capturar,
  - **eliminar tutorado** (`DELETE …/{nc}`): borra la fila precargada completa
    (útil para registros de prueba o filas subidas por error).
- **Configuración:** CRUD de `app_settings` (periodo activo, cierre de
  formulario, rate limit, etc.).

### 3. API (`public/index.php`)

#### Público (formulario)

| Método | Ruta                                    | Descripción                                               |
| ------ | --------------------------------------- | --------------------------------------------------------- |
| GET    | `/api/csrf`                             | Emite token CSRF de sesión                                |
| GET    | `/api/tutores`                          | Tutores activos para el select                            |
| GET    | `/api/estudiantes/check?numeroControl=` | Valida NC: formato, `form_active` (423), ¿ya capturado?   |
| POST   | `/api/estudiantes`                      | Registra la ficha completa (honeypot + CSRF + rate limit) |

#### Administración (requieren header `X-Admin-Password`)

| Método     | Ruta                                             | Descripción                                                         |
| ---------- | ------------------------------------------------ | ------------------------------------------------------------------- |
| GET        | `/api/admin/ping`                                | Verifica credenciales                                               |
| GET/POST   | `/api/admin/tutores`                             | Lista (con KPIs por tutor y `resumen`) / crea tutor                 |
| PUT/DELETE | `/api/admin/tutores/{id}`                        | Editar / eliminar tutor                                             |
| POST       | `/api/admin/tutores/{id}/upload`                 | Carga de lista (CSV/XLS/XLSX) → reasigna `tutor_id`                 |
| GET        | `/api/admin/tutores/{id}/report`                 | Avance del tutor (periodo activo)                                   |
| GET        | `/api/admin/tutores/{id}/tutorados/{nc}`         | Respuestas de un estudiante (6 bloques)                             |
| DELETE     | `/api/admin/tutores/{id}/tutorados/{nc}/captura` | Libera la captura (vuelve a pendiente)                              |
| DELETE     | `/api/admin/tutores/{id}/tutorados/{nc}`         | Elimina el registro completo                                        |
| GET        | `/api/admin/periodos`                            | Periodos con capturas + periodo activo (selector)                   |
| GET        | `/api/admin/exportar/historico?periodo=`         | **Workbook histórico** (todas las respuestas, todos los tutores)    |
| GET        | `/api/admin/tutores/{id}/exportar`               | Workbook de respuestas del tutor (periodo activo)                   |
| GET        | `/api/estudiantes/exportar`                      | Registro global de estudiantes (todos los periodos, sin respuestas) |
| GET/PUT    | `/api/admin/settings`                            | Leer / guardar `app_settings`                                       |
| DELETE     | `/api/admin/settings/{key}`                      | Eliminar una clave de configuración                                 |

### 4. Base de datos

| Tabla                                                                                                   | Contenido                                                                            |
| ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| `tutores`                                                                                               | Tutores (nombre, email, activo)                                                      |
| `estudiantes`                                                                                           | Registro principal: NC, datos personales, `tutor_id`, `periodo_captura`, `capturado` |
| `datos_familiares`, `datos_escolares`, `habilidades_escolares`, `datos_medicos`, `expectativas_ingreso` | Respuestas de los 5 bloques (enlazadas por `estudiante_id`)                          |
| `submit_logs`                                                                                           | Intentos de envío que pasaron honeypot + CSRF (base del rate limit)                  |
| `app_settings`                                                                                          | Configuración por clave-valor (ver abajo)                                            |

## Funciones transversales

### Seguridad

- **CSRF por sesión:** el token se pide al cargar, **se refresca justo antes
  de cada envío** y, si el servidor lo rechaza (403), se pide uno nuevo y se
  **reintenta una vez en silencio** — un llenado largo (móvil) no obliga a
  recapturar. Como respaldo queda un mensaje en español que conserva los datos.
- **Honeypot:** campo oculto `hp_email`; si viene lleno → `400` (bots).
- **Rate limit por IP:** cuenta envíos que pasaron honeypot+CSRF en la
  ventana configurada (`rate_limit_max` / `rate_limit_window_min`); al
  excederlo → `429`.
- **Autenticación admin:** header `X-Admin-Password` comparado con
  `app_settings.admin_password` (`hash_equals`).
- **`form_active`:** cierre global del formulario (`423`) sin tocar la BD.

### Segmentación por periodo (campaña)

- `app_settings.periodo` = generación vigente (p. ej. `20263`).
- **Sólo periodo activo:** KPIs, lista/avance por tutor y descarga por tutor.
- **Todos los periodos (auditoría):** descarga histórica y registro global.
- `periodo_captura` se estampa **una sola vez** (al precargar la lista) y es
  inmutable: ni capturar ni volver a subir la lista lo modifica.
- Vacío ⇒ sin filtro (comportamiento de respaldo); conviene cargar las listas
  con `periodo` ya configurado.

### Exportaciones a Excel (PhpSpreadsheet)

| Archivo                                | Botón                                        | Contenido                                                                                              | Periodos                |
| -------------------------------------- | -------------------------------------------- | ------------------------------------------------------------------------------------------------------ | ----------------------- |
| `respuestas_tutor_*.xlsx`              | ícono por fila en la lista de tutores        | 6 hojas (personales + 5 bloques) del tutor                                                             | Sólo activo             |
| `historico_entrevistas[_periodo].xlsx` | "↓ Descargar histórico" (Desglose de avance) | Mismas 6 hojas de **todos** los tutores; columna **Tutor** y **Periodo de captura** en todas las hojas | Todos o el del selector |
| (sólo API) `/api/estudiantes/exportar` | —                                            | Registro global: NC, nombre, teléfono, tutor, periodo, capturado, creado                               | Todos                   |

## Configuración

### Variables de entorno (`.env`, ver `.env.example`)

`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` · `APP_ENV`, `APP_DEBUG`
(algunos valores de prueba leen además `ADMIN_PASSWORD` desde el entorno).

### Claves en `app_settings`

| Clave                   | Función                                                         |
| ----------------------- | --------------------------------------------------------------- |
| `periodo`               | Periodo/campaña vigente (segmenta KPIs, listas y XLS por tutor) |
| `form_active`           | `1` = formulario abierto; otro valor → `423`                    |
| `rate_limit_max`        | Envíos máximos por ventana (por defecto en código: 5)           |
| `rate_limit_window_min` | Ventana en minutos (por defecto en código: 10)                  |
| `admin_password`        | Contraseña del panel (se envía en `X-Admin-Password`)           |

El panel **Configuración** permite crear/editar/eliminar cualquier otra clave.

## Despliegue

```bash
docker compose up -d          # primera vez (build) o tras cambiar Dockerfile/entrypoint.sh
git pull                      # deploy normal: el bind mount .:/var/www/html lo sirve al instante
```

- El contenedor (`entrevistas_api`) corre con `restart: unless-stopped`.
- **No hace falta rebuild ni reinicio** para cambios en `public/`, `src/` o
  `composer.json` ya instalado (el bind mount lo actualiza en caliente);
  sí para cambios de imagen (`Dockerfile`, `entrypoint.sh`).
- El tráfico llega por HTTP en `:8095`; si se usa HTTPS se coloca un proxy
  delante (la app no termina TLS).

## Pruebas

- `tests/test_form.py` — suite de **~30 escenarios** contra
  `POST /api/estudiantes` (opciones, merges `*_otro`, enums y caminos de
  error), con verificación y limpieza en la BD. Requiere la app corriendo;
  ver **`tests/README.md`** para instrucciones y banderas.

> ⚠️ **Nunca correr la suite contra producción**: altera `rate_limit_max`,
> crea registros `ZZTEST` y limpia ventanas de `submit_logs`. Usar sólo
> contra un entorno local/descartable.

## Documentación relacionada

- `form.md` — especificación campo por campo de cada paso del formulario.
- `Entrevista Ficha Escolar.pdf` — ficha original que se digitalizó.
- `tests/README.md` — cómo ejecutar y qué cubre la suite automatizada.

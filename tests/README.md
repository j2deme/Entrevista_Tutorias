# Pruebas automatizadas del formulario

Ejecutan **~30 escenarios** contra `POST /api/estudiantes` para cubrir los campos
opcionales, los merges `*_otro`, los enums con variantes y todos los caminos de
error, sin llenar el formulario a mano una y otra vez.

## Requisitos

- La app corriendo (por ejemplo `docker compose up -d`) y accesible.
- `python3` (solo librería estándar, sin `pip install`).
- `docker` disponible desde la terminal (para verificar lo que quedó guardado
  en la base de datos y para limpiar después). Si `docker ps` responde
  *"could not be found in this WSL 2 distro"*, activa Docker Desktop →
  Settings → Resources → **WSL Integration** para esta distro: sin eso la
  suite corre igual, pero **no verifica ni limpia la BD** y te lo avisa.
- La contraseña admin (la misma del panel `/admin`).

## Ejecutar

```bash
# Ver qué haría, sin tocar nada (no necesita servidor ni contraseña)
python3 tests/test_form.py --dry-run

# Suite completa
python3 tests/test_form.py --admin-password 'MI_PASSWORD'

# La contraseña también puede ir por variable de entorno
ADMIN_PASSWORD='MI_PASSWORD' python3 tests/test_form.py

# ...o simplemente en el .env del repo (donde ya están los demás secretos):
#   ADMIN_PASSWORD=MI_PASSWORD
# en ese caso no hace falta ningún flag.
```

Flags útiles:

| Flag | Qué hace |
|---|---|
| `--url http://localhost:8095` | otra URL base (por defecto `http://localhost:8095`) |
| `--only merges` | ejecuta solo los cuyos nombres contienen "merges" |
| `--no-db` | no verifica la base de datos ni limpia (solo respuestas HTTP) |
| `--keep` | deja los registros de prueba en la BD (para inspeccionarlos) |

## Qué comprueba

**Aceptación (espera `201`) y verificación en BD**

1. Payload completo con todos los campos opcionales.
2. Solo los campos obligatorios (todos los opcionales fuera).
3. Transporte público **apagado** con `tiempo`/`costo` enviados → deben quedar `NULL`.
4. Habilidades en códigos `B/N/M` y en palabras `Bueno/Normal/Malo` → en BD siempre palabras.
5. Enums con variantes: `union libre`, `MUY BUENO`, `ME_DA_IGUAL` → forma canónica en BD.
6. Los 5 merges `*_otro` (`vives_con`, `tipo_beca`, `cual_enfermedad`,
   `cual_condicion`, `tipo_apoyo`) **y** `tipo_vivienda_otro` que **no** se fusiona.
7. Número de control con espacios/minúsculas → normalizado (` b24 690077 ` → `B24690077`).
8. Textos con `ñ`, acentos, `<script>` y comillas SQL → se guardan tal cual.
9. Preregistro sin capturar (creado con `tests/db.php seed`) → el envío lo completa y pone `capturado=1`.

**Rechazos**

| Escenario | Espera |
|---|---|
| Honeypot `hp_email` relleno | 400 |
| Sin `csrf_token` / token inválido | 403 |
| Formulario cerrado (`form_active` ≠ `1`) | 423 |
| Faltan campos obligatorios → `missing[]` | 422 |
| `tutor_id` inexistente o inactivo → `fields.tutor_id` | 422 |
| Fecha, teléfono, promedio, enum, habilidad, preferencia o formato de control inválidos → `fields.*` | 422 |
| Cuerpo que no es JSON | 400 |
| Número de control ya capturado | 409 |
| Número de control asignado a otro tutor | 409 |
| Rate limit (`rate_limit_max=2` → 3er envío) | 429 |

## Efectos sobre tu entorno

La suite crea datos identificables y **los borra sola** al terminar (a menos que
pases `--keep`):

- Crea dos tutores `ZZTEST Suite A/B` y los borra al final.
- Usa `tutor_id` del tutor de prueba como marcador: `cleanup` borra solo sus
  estudiantes (las 5 tablas hijas caen en cascada con `ON DELETE CASCADE`).
- Sube temporalmente `rate_limit_max` a 999 y lo restaura al valor original.
- Abre temporalmente `form_active` si estaba cerrado y lo restaura.
- Borra los `submit_logs` de la ventana activa al terminar (el contador de
  envíos por IP vuelve a cero; solo relaja ese límite, no toca datos).

Si la suite se interrumpe con Ctrl+C, repítela una vez: al arrancar vuelve a
crear los tutores y, al final, limpia lo que encuentre. Para borrar a mano:

```bash
docker exec entrevistas_api php tests/db.php cleanup <tutor_id>
docker exec entrevistas_api php tests/db.php tutor-delete <id>
docker exec entrevistas_api php tests/db.php logs-window 10
```

## Validar también la interfaz (recomendado antes de producción)

Esta suite escribe los payloads **a mano**, así que comprueba el backend. Para
asegurar además de que el formulario envía las claves correctas, captura **una
vez** el payload real y úsalo como base:

1. Abre `/form.html`, llena **todos** los campos (incluidos los opcionales) y envía.
2. F12 → pestaña **Network** → el request `estudiantes` → clic derecho →
   **Copy → Copy as fetch**.
3. Pega el `body` JSON en `tests/base_payload.json` (reemplazándolo).
4. Vuelve a correr la suite: ahora las ~30 variantes parten de lo que el
   navegador envía de verdad.

Con eso quedan cubiertas las dos mitades: el mapeo del formulario **y** la
validación/guardado del backend.

## No cubierto por esta suite

- La interfaz paso a paso (validación de prioridades, mostrar/ocultar secciones).
- La subida de CSV (`POST /api/admin/tutores/{id}/upload`).
- La descarga Excel (`GET /api/admin/tutores/{id}/exportar`), salvo que pidas
  ampliarla.

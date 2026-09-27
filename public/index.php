<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use App\Models\Estudiante;
use App\Models\DatosFamiliares;
use App\Models\DatosEscolares;
use App\Models\HabilidadesEscolares;
use App\Models\DatosMedicos;
use App\Models\ExpectativasIngreso;
use Respect\Validation\Validator as v;

// Excepción para errores de negocio (se devuelven tal cual al cliente)
class FormException extends \Exception
{
}

// --- Catálogo de valores permitidos (enums) -------------------------------
// Fuente única de verdad en PHP. Mantener sincronizado con
// db/mysql_entrevistas.sql y validar siempre con enum_value().
function enums(string $key): array
{
  static $catalog = [
  'genero' => ['F', 'M', 'NB'],
  'estado_civil' => ['SOLTERO', 'CASADO', 'UNION LIBRE', 'DIVORCIADO', 'VIUDO', 'OTRO'],
  'zona' => ['RURAL', 'URBANA'],
  'tipo_vivienda' => ['PROPIA', 'RENTADA', 'PRESTADA', 'OTRA'],
  'rendimiento_escolar' => ['MUY_BUENO', 'BUENO', 'REGULAR', 'MALO', 'MUY_MALO'],
  'reaccion_padres' => ['MUY_BIEN', 'NORMAL', 'MUY_MAL', 'NO_SABEN', 'NO_LES_IMPORTA'],
  'estudio_es' => ['INTERESANTE', 'ABURRIDO', 'UTIL', 'IMPUESTO', 'PASATIEMPO', 'AMIGOS', 'IMPORTANTE'],
  'preferencia_trabajo' => ['SOLO', 'COMPAÑERO', 'EQUIPO', 'IGUAL'],
  'relacion_padres' => ['MUY_BUENA', 'BUENA', 'REGULAR', 'MALA', 'MUY_MALA'],
  'habilidades' => ['B', 'N', 'M', 'BUENO', 'NORMAL', 'MALO'],
  ];
  return $catalog[$key] ?? [];
}

// Normaliza un valor enum (mayúsculas, espacio <-> guion bajo, sin acentos)
// y devuelve la variante canónica del catálogo; null si no está permitido.
// Ej.: 'MUY BUENO' y 'MUY_BUENO' => 'MUY_BUENO'; 'COMPANERO' => 'COMPAÑERO'.
function enum_value($raw, array $allowed): ?string
{
  if ($raw === null || trim((string) $raw) === '')
    return null;
  $fold      = static function ($s): string {
    $s = strtoupper((string) $s);
    $s = strtr($s, ['Ñ' => 'N', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);
    return str_replace([' ', '-'], '_', $s);
  };
  $candidate = $fold($raw);
  foreach ($allowed as $option) {
    if ($fold($option) === $candidate)
      return $option;
  }
  return null;
}

// Valida un valor enum presente y lo reemplaza por su forma canónica.
// Los vacíos no generan error aquí: se cubren con la lista de requeridos.
// Las claves de $errors usan snake_case para coincidir con los name= del formulario.
function enum_or_error(array &$errors, string $field, &$raw, array $allowed, string $label): void
{
  if ($raw === null || trim((string) $raw) === '')
    return;
  $normalized = enum_value($raw, $allowed);
  if ($normalized === null) {
    $errors[$field] = $label . ': valor no permitido';
    return;
  }
  $raw = $normalized;
}

// Campos de habilidades escolares tal como llegan del formulario (planos)
function habilidad_fields(): array
{
  return [
    'comprension_lectora',
    'comprension_oral',
    'resolucion_problemas',
    'expresion_oral',
    'expresion_escrita',
    'vocabulario',
    'calculo',
    'expresion_grafica',
    'ortografia',
  ];
}

// El formulario envía códigos (B/N/M); la tabla habilidades_escolares
// almacena palabras ('Bueno','Normal','Malo'). Ver db/mysql_entrevistas.sql.
function habilidad_to_db($value): ?string
{
  if ($value === null || trim((string) $value) === '')
    return null;
  $map = [
    'B' => 'Bueno',
    'N' => 'Normal',
    'M' => 'Malo',
    'BUENO' => 'Bueno',
    'NORMAL' => 'Normal',
    'MALO' => 'Malo'
  ];
  return $map[strtoupper(trim((string) $value))] ?? null;
}

function db_enum_contains(string $table, string $column, string $value): bool
{
  // ¿Existe el valor en el ENUM actual de la BD? (cache por worker)
  static $types = [];
  $key = $table . '.' . $column;
  if (!array_key_exists($key, $types)) {
    $rows        = Capsule::select(
      "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
      [$table, $column]
    );
    $types[$key] = $rows ? (string) ($rows[0]->COLUMN_TYPE ?? '') : '';
  }
  return stripos($types[$key], "'" . $value . "'") !== false;
}

// Fusiona el campo libre "..._otro/otra" en su select cuando se eligió la
// opción genérica (OTRA/OTRO/OTROS), para no perder la descripción.
function merge_otro(&$target, string $genericCode, $freeText): void
{
  if (
    is_string($target) && $target === $genericCode
    && is_string($freeText) && trim($freeText) !== ''
  ) {
    $target = trim($freeText);
  }
}

// Un input numérico vacío llega como '' (y los Sí/No como true/false):
// sin esta conversión, insertar '' en una columna INT/DECIMAL rompe el guardado.
function int_or_null($v): ?int
{
  if (is_bool($v))
    return (int) $v;
  if ($v === null || $v === '' || is_array($v))
    return null;
  return is_numeric($v) ? (int) $v : null;
}

function num_or_null($v)
{
  if ($v === null || $v === '' || is_array($v) || is_bool($v))
    return is_bool($v) ? (int) $v : null;
  return is_numeric($v) ? $v + 0 : null;
}

require __DIR__ . '/../vendor/autoload.php';

// Iniciar sesión para protección CSRF basada en sesión
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// 1. Cargar Variables de Entorno
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// 2. Inicializar Eloquent ORM
$capsule = new Capsule;
$capsule->addConnection([
  'driver' => 'mysql',
  'host' => $_ENV['DB_HOST'],
  'database' => $_ENV['DB_NAME'],
  'username' => $_ENV['DB_USER'],
  'password' => $_ENV['DB_PASS'],
  'port' => $_ENV['DB_PORT'] ?? 3306,
  'charset' => 'utf8mb4',
  'collation' => 'utf8mb4_unicode_ci',
  'prefix' => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$app = AppFactory::create();

// Helper simple para verificar contraseña admin almacenada en app_settings.
// Solo se acepta el header X-Admin-Password: nunca por query string, porque
// quedaría registrado en logs de servidor, historial del navegador y referers.
function check_admin(Request $request)
{
  $sent   = $request->getHeaderLine('X-Admin-Password');
  $stored = Capsule::table('app_settings')->where('key', 'admin_password')->value('value');
  if (empty($stored) || empty($sent))
    return false;
  return hash_equals((string) $stored, (string) $sent);
}

// Endpoint: listar tutores activos para selección en el formulario
$app->get('/api/tutores', function (Request $request, Response $response) {
  $rows = Capsule::table('tutores')->where('active', 1)->select('id', 'nombre')->orderBy('nombre')->get();
  $response->getBody()->write(json_encode(['tutores' => $rows]));
  return $response->withHeader('Content-Type', 'application/json');
});

// Endpoint para obtener token CSRF (inicia sesión debe estar activa)
$app->get('/api/csrf', function (Request $request, Response $response) {
  // Generar token y guardarlo en la sesión (válido por un corto periodo)
  $token                  = bin2hex(random_bytes(32));
  $_SESSION['csrf_token'] = $token;
  $response->getBody()->write(json_encode(['csrfToken' => $token]));
  return $response->withHeader('Content-Type', 'application/json');
});

// (se eliminaron rutas antiguas de Tutorado)

// RUTA: Recibir ficha completa y guardar registro simplificado
$app->post('/api/estudiantes', function (Request $request, Response $response) {
  // Acepta JSON anidado o form-encoded
  $input = $request->getParsedBody();
  if (empty($input)) {
    $raw   = (string) $request->getBody();
    $input = json_decode($raw, true) ?: [];
  }

  // Aceptar payload anidado o plano en snake_case. Normalizar claves a snake_case.
  function camel_to_snake($s)
  {
    return strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string) $s));
  }
  function normalize_keys_recursive($arr)
  {
    $out = [];
    if (!is_array($arr))
      return $arr;
    foreach ($arr as $k => $v) {
      $nk = is_string($k) ? camel_to_snake($k) : $k;
      if (is_array($v))
        $out[$nk] = normalize_keys_recursive($v);
      else
        $out[$nk] = $v;
    }
    return $out;
  }

  $in = normalize_keys_recursive($input);

  // Mapear a las estructuras esperadas por la lógica existente (keys en camelCase)
  $dp = [
    'nombreCompleto' => $in['nombre_completo'] ?? $in['nombreCompleto'] ?? null,
    'numeroControl' => $in['numero_control'] ?? $in['numeroControl'] ?? null,
    'fechaNacimiento' => $in['fecha_nacimiento'] ?? $in['fechaNacimiento'] ?? null,
    'lugarNacimiento' => $in['lugar_nacimiento'] ?? $in['lugarNacimiento'] ?? null,
    'edad' => $in['edad'] ?? null,
    'genero' => $in['genero'] ?? null,
    'estadoCivil' => $in['estado_civil'] ?? $in['estadoCivil'] ?? null,
    'domicilioFamiliar' => $in['domicilio_familiar'] ?? null,
    'localidadFamiliar' => $in['localidad_familiar'] ?? null,
    'codigoPostal' => $in['codigo_postal'] ?? null,
    'zona' => $in['zona'] ?? null,
    'tipoVivienda' => $in['tipo_vivienda'] ?? null,
    'telefonoMovil' => $in['telefono_movil'] ?? null,
    'hablaOtraLengua' => $in['habla_otra_lengua'] ?? null,
    'cualLengua' => $in['cual_lengua'] ?? null,
    'tutorId' => isset($in['tutor_id']) ? $in['tutor_id'] : ($in['tutorId'] ?? null)
  ];

  $df = [
    'padreNombre' => $in['padre_nombre'] ?? null,
    'padreVive' => $in['padre_vive'] ?? null,
    'padreEdad' => $in['padre_edad'] ?? null,
    'padreNivelEstudios' => $in['padre_nivel_estudios'] ?? ($in['padreNivelEstudios'] ?? null),
    'padreOcupacion' => $in['padre_ocupacion'] ?? ($in['padreOcupacion'] ?? null),
    'madreNombre' => $in['madre_nombre'] ?? null,
    'madreVive' => $in['madre_vive'] ?? null,
    'madreEdad' => $in['madre_edad'] ?? null,
    'madreNivelEstudios' => $in['madre_nivel_estudios'] ?? ($in['madreNivelEstudios'] ?? null),
    'madreOcupacion' => $in['madre_ocupacion'] ?? ($in['madreOcupacion'] ?? null),
    'numIntegrantesFamilia' => $in['num_integrantes_familia'] ?? ($in['numIntegrantesFamilia'] ?? null),
    'numHermanos' => $in['num_hermanos'] ?? ($in['numHermanos'] ?? null),
    'lugarQueOcupa' => $in['lugar_que_ocupa'] ?? ($in['lugarQueOcupa'] ?? null),
    'vivesCon' => $in['vives_con'] ?? ($in['vivesCon'] ?? null),
    'situacionEspecial' => $in['situacion_especial'] ?? ($in['situacionEspecial'] ?? null),
    'relacionPadres' => $in['relacion_padres'] ?? ($in['relacionPadres'] ?? null),
    'trabajaActualmente' => $in['trabaja_actualmente'] ?? ($in['trabajaActualmente'] ?? null),
    'horasTrabajo' => $in['horas_trabajo'] ?? ($in['horasTrabajo'] ?? null),
    'empresaTrabajo' => $in['empresa_trabajo'] ?? ($in['empresaTrabajo'] ?? null),
    'motivoTrabajo' => $in['motivo_trabajo'] ?? ($in['motivoTrabajo'] ?? null),
    'apoyoEconomico' => $in['apoyo_economico'] ?? ($in['apoyoEconomico'] ?? null),
    'ingresoMensualFamiliar' => $in['ingreso_mensual_familiar'] ?? ($in['ingresoMensualFamiliar'] ?? null)
  ];

  $de = [
    'institucionProcedencia' => $in['institucion_procedencia'] ?? null,
    'localidad' => $in['localidad'] ?? ($in['localidad_escuela'] ?? null),
    'generacionEgreso' => $in['generacion_egreso'] ?? ($in['generacionEgreso'] ?? null),
    'promedio' => $in['promedio'] ?? null,
    'rendimientoEscolar' => $in['rendimiento_escolar'] ?? ($in['rendimientoEscolar'] ?? null),
    'reprobadoCurso' => $in['reprobado_curso'] ?? ($in['reprobadoCurso'] ?? null),
    'causaReprobacion' => $in['causa_reprobacion'] ?? ($in['causaReprobacion'] ?? null),
    'materiasReprobadas' => $in['materias_reprobadas'] ?? ($in['materiasReprobadas'] ?? null),
    'satisfechoResultados' => $in['satisfecho_resultados'] ?? ($in['satisfechoResultados'] ?? null),
    'motivoSatisfaccion' => $in['motivo_satisfaccion'] ?? ($in['motivoSatisfaccion'] ?? null),
    'haEstadoBecado' => $in['ha_estado_becado'] ?? ($in['haEstadoBecado'] ?? null),
    'gradoBeca' => $in['grado_beca'] ?? ($in['gradoBeca'] ?? null),
    'tipoBeca' => $in['tipo_beca'] ?? ($in['tipoBeca'] ?? null),
    'materiasFavoritas' => $in['materias_favoritas'] ?? ($in['materiasFavoritas'] ?? null),
    'reaccionPadresCalificaciones' => $in['reaccion_padres_calificaciones'] ?? ($in['reaccionPadresCalificaciones'] ?? null),
    // Se completa más abajo: acepta payload plano, anidado o con prefijo hab_
    'habilidades' => []
  ];

  $dm = [
    'padeceEnfermedad' => $in['padece_enfermedad'] ?? ($in['padeceEnfermedad'] ?? null),
    'cualEnfermedad' => $in['cual_enfermedad'] ?? ($in['cualEnfermedad'] ?? null),
    'condicionFisica' => $in['condicion_fisica'] ?? ($in['condicionFisica'] ?? null),
    'cualCondicion' => $in['cual_condicion'] ?? ($in['cualCondicion'] ?? null),
    'tomaMedicacion' => $in['toma_medicacion'] ?? ($in['tomaMedicacion'] ?? null),
    'cualMedicacion' => $in['cual_medicacion'] ?? ($in['cualMedicacion'] ?? null),
    'haSidoOperado' => $in['ha_sido_operado'] ?? ($in['haSidoOperado'] ?? null),
    'deQueOperacion' => $in['de_que_operacion'] ?? ($in['deQueOperacion'] ?? null)
  ];

  $ei = [
    'carreraGusta' => isset($in['carrera_gusta']) ? (int) $in['carrera_gusta'] : ($in['carreraGusta'] ?? null),
    'queMasAtrae' => $in['que_mas_atrae'] ?? ($in['queMasAtrae'] ?? null),
    'tienePreocupacionCurso' => $in['tiene_preocupacion_curso'] ?? ($in['tienePreocupacionCurso'] ?? null),
    'quePreocupa' => $in['que_preocupa'] ?? ($in['quePreocupa'] ?? null),
    'estudioEs' => $in['estudio_es'] ?? ($in['estudioEs'] ?? null),
    'formaApoyoInstitucion' => $in['forma_apoyo_institucion'] ?? ($in['formaApoyoInstitucion'] ?? null),
    'deseaApoyoInstitucional' => $in['desea_apoyo_institucional'] ?? ($in['deseaApoyoInstitucional'] ?? null),
    'tipoApoyo' => $in['tipo_apoyo'] ?? ($in['tipoApoyo'] ?? null),
    'pasatiempoFavorito' => $in['pasatiempo_favorito'] ?? ($in['pasatiempoFavorito'] ?? null),
    'causaProblemasEstudio' => $in['causa_problemas_estudio_compuesta'] ?? ($in['causa_problemas_estudio'] ?? ($in['causaProblemasEstudio'] ?? null)),
    'preferenciaTrabajo' => $in['preferencia_trabajo'] ?? ($in['preferenciaTrabajo'] ?? null),
    'preferenciaEnClase' => $in['preferencia_en_clase'] ?? ($in['preferenciaEnClase'] ?? null),
    'tiempoEstudioCasa' => $in['tiempo_estudio_casa'] ?? ($in['tiempoEstudioCasa'] ?? null),
    'formaPasarTiempo' => $in['forma_pasartiempo'] ?? ($in['formaPasarTiempo'] ?? null),
    'formaHacerAmigos' => $in['forma_hacer_amigos'] ?? ($in['formaHacerAmigos'] ?? null),
    'cuentaLugarAdecuado' => $in['cuenta_lugar_adecuado'] ?? ($in['cuentaLugarAdecuado'] ?? null),
    'prio_explicacionClara' => $in['prio_explicacion_clara'] ?? ($in['prio_explicacionClara'] ?? null),
    'prio_paciente' => $in['prio_paciente'] ?? null,
    'prio_estricto' => $in['prio_estricto'] ?? null,
    'prio_justo' => $in['prio_justo'] ?? null,
    'prio_comprensivo' => $in['prio_comprensivo'] ?? null,
    'prio_buen_humor' => $in['prio_buen_humor'] ?? null,
    'prio_otra' => $in['prio_otra'] ?? null
  ];

  // Habilidades: el formulario envía claves planas (comprension_lectora, ...).
  // También se acepta el formato anidado `habilidades: {...}` y el prefijo `hab_*`.
  $habIn = is_array($in['habilidades'] ?? null) ? $in['habilidades'] : [];
  foreach (habilidad_fields() as $hf) {
    $raw = $habIn[$hf] ?? $in['hab_' . $hf] ?? $in[$hf] ?? null;
    if (is_scalar($raw) && (string) $raw !== '')
      $de['habilidades'][$hf] = $raw;
  }

  // Fusionar los campos libres "..._otro/otra" cuando se eligió la opción
  // genérica, para no guardar el código vacío sin la descripción.
  // (El cliente ya hace lo mismo; aquí queda cubierta también la API directa.)
  merge_otro($df['vivesCon'], 'OTROS', $in['vives_con_otro'] ?? null);
  merge_otro($de['tipoBeca'], 'OTRA', $in['tipo_beca_otro'] ?? null);
  merge_otro($dm['cualEnfermedad'], 'OTRA', $in['cual_enfermedad_otro'] ?? null);
  merge_otro($dm['cualCondicion'], 'OTRA', $in['cual_condicion_otro'] ?? null);
  merge_otro($df['situacionEspecial'], 'OTRO', $in['situacion_especial_otro'] ?? null);
  merge_otro($df['apoyoEconomico'], 'OTRO', $in['apoyo_economico_otro'] ?? null);
  merge_otro($df['motivoTrabajo'], 'OTRO', $in['motivo_trabajo_otro'] ?? null);
  merge_otro($ei['tipoApoyo'], 'OTRO', $in['tipo_apoyo_otro'] ?? null);

  // Transporte público: si no aplica, no deben quedar registrado tiempo ni costo
  $usaTransporte  = !empty($in['usa_transporte_publico']);
  $tiempoTraslado = $usaTransporte ? ($in['tiempo_traslado_transporte'] ?? null) : null;
  $costoRaw       = $in['costo_transporte'] ?? null;
  $costoTraslado  = ($usaTransporte && $costoRaw !== '' && $costoRaw !== null) ? num_or_null($costoRaw) : null;

  // Normalizar número de control: trim, eliminar espacios internos y uppercase
  $nc_raw  = $dp['numeroControl'] ?? $input['numeroControl'] ?? null;
  $nc_norm = null;
  if (!empty($nc_raw)) {
    $nc_norm = strtoupper(str_replace(' ', '', trim((string) $nc_raw)));
  }
  // Propagar la versión normalizada a los arrays usados
  $dp['numeroControl']    = $nc_norm;
  $input['numeroControl'] = $nc_norm;

  // --- Protecciones anti-abuso ---
  // Honeypot check (campo oculto que bots llenan)
  if (!empty($in['hp_email'] ?? $in['hpEmail'] ?? $input['hp_email'] ?? null)) {
    $response->getBody()->write(json_encode(['error' => 'Bad request']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
  }

  // CSRF token check (token enviado en payload o header X-CSRF-Token)
  $csrfSent   = $in['csrf_token'] ?? $input['csrfToken'] ?? $request->getHeaderLine('X-CSRF-Token');
  $csrfStored = $_SESSION['csrf_token'] ?? null;
  if (empty($csrfSent) || empty($csrfStored) || !hash_equals((string) $csrfStored, (string) $csrfSent)) {
    $response->getBody()->write(json_encode(['error' => 'CSRF token missing or invalid']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }


  // Revisar si el formulario está activo en configuración
  $formActive = Capsule::table('app_settings')->where('key', 'form_active')->value('value');
  if ($formActive !== null && (string) $formActive !== '1') {
    $response->getBody()->write(json_encode(['error' => 'Formularios cerrados temporalmente']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(423);
  }

  // --- Rate limiting por IP -------------------------------------------
  // Cada intento que pasa honeypot + CSRF queda registrado en submit_logs y
  // se rechaza si supera el máximo configurable de la ventana (app_settings:
  // rate_limit_max, por defecto 5 envíos; rate_limit_window_min, por defecto 10 min).
  $ip            = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  $rateMax       = (int) (Capsule::table('app_settings')->where('key', 'rate_limit_max')->value('value') ?: 5);
  $rateWindowMin = (int) (Capsule::table('app_settings')->where('key', 'rate_limit_window_min')->value('value') ?: 10);
  $since         = date('Y-m-d H:i:s', time() - max(1, $rateWindowMin) * 60);
  $recent        = Capsule::table('submit_logs')->where('ip', $ip)->where('created_at', '>=', $since)->count();
  if ($recent >= max(1, $rateMax)) {
    $response->getBody()->write(json_encode(['error' => 'Demasiados envíos desde esta conexión. Intenta de nuevo más tarde.']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(429);
  }
  Capsule::table('submit_logs')->insert(['ip' => $ip]);

  // Campos requeridos mínimos. Las claves usan snake_case para coincidir con
  // los name= del formulario y poder resaltar el campo con error.
  $required = [
    // El número de control es obligatorio; el prefijo B/C es opcional
    'numero_control' => $dp['numeroControl'],
    'nombre_completo' => $dp['nombreCompleto'],
    'fecha_nacimiento' => $dp['fechaNacimiento'],
    'lugar_nacimiento' => $dp['lugarNacimiento'],
    'domicilio_familiar' => $dp['domicilioFamiliar'],
    'localidad_familiar' => $dp['localidadFamiliar'],
    'codigo_postal' => $dp['codigoPostal'],
    'genero' => $dp['genero'],
    'estado_civil' => $dp['estadoCivil'],
    'zona' => $dp['zona'],
    'tipo_vivienda' => $dp['tipoVivienda'],
    'telefono_movil' => $dp['telefonoMovil'],
    // Tutor asignado (obligatorio para filtrar registros posteriormente)
    'tutor_id' => $dp['tutorId'],
    // Familiares
    'num_integrantes_familia' => $df['numIntegrantesFamilia'],
    'num_hermanos' => $df['numHermanos'],
    'lugar_que_ocupa' => $df['lugarQueOcupa'],
    'vives_con' => $df['vivesCon'],
    // Escolares
    'institucion_procedencia' => $de['institucionProcedencia'],
    'localidad_escuela' => $de['localidad'],
    'generacion_egreso' => $de['generacionEgreso'],
    'promedio' => $de['promedio'],
    'rendimiento_escolar' => $de['rendimientoEscolar'],
    'reaccion_padres_calificaciones' => $de['reaccionPadresCalificaciones']
  ];

  $missing = [];
  foreach ($required as $key => $val) {
    if ($val === null || $val === '')
      $missing[] = $key;
  }
  if (!empty($missing)) {
    $response->getBody()->write(json_encode(['error' => 'Faltan campos requeridos', 'missing' => $missing]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }

  // Verificar que el tutor enviado exista. Debe estar activo, salvo que sea
  // exactamente el tutor ya asignado en el preregistro: si a su tutor lo dan
  // de baja después de precargar, el tutorado no debe quedar bloqueado.
  $tutorIdCheck = isset($dp['tutorId']) ? intval($dp['tutorId']) : null;
  if (empty($tutorIdCheck) || !Capsule::table('tutores')->where('id', $tutorIdCheck)->exists()) {
    $response->getBody()->write(json_encode(['error' => 'Tutor inválido o inactivo', 'fields' => ['tutor_id' => 'Tutor inválido o inactivo']]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }
  if (!Capsule::table('tutores')->where('id', $tutorIdCheck)->where('active', 1)->exists()) {
    $yaAsignado = !empty($dp['numeroControl'])
      && Estudiante::where('numero_control', $dp['numeroControl'])->where('tutor_id', $tutorIdCheck)->exists();
    if (!$yaAsignado) {
      $response->getBody()->write(json_encode(['error' => 'Tutor inválido o inactivo', 'fields' => ['tutor_id' => 'Tutor inválido o inactivo']]));
      return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
    }
  }

  // Validaciones con Respect/Validation. Claves en snake_case (name= del form).
  $errors = [];
  // nombre
  try {
    v::stringType()->notEmpty()->length(1, 150)->assert($dp['nombreCompleto'] ?? '');
  } catch (\Throwable $e) {
    $errors['nombre_completo'] = 'Nombre completo inválido';
  }
  // fecha
  try {
    v::date('Y-m-d')->assert($dp['fechaNacimiento'] ?? '');
  } catch (\Throwable $e) {
    $errors['fecha_nacimiento'] = 'Fecha de nacimiento inválida (formato Y-m-d)';
  }
  // telefono
  try {
    v::digit()->length(7, 15)->assert(preg_replace('/\D/', '', (string) ($dp['telefonoMovil'] ?? '')));
  } catch (\Throwable $e) {
    $errors['telefono_movil'] = 'Teléfono inválido (debe tener entre 7 y 15 dígitos)';
  }
  // Enums: validar y dejar la forma canónica del catálogo. Se aceptan tanto
  // 'MUY BUENO' como 'MUY_BUENO'; en la BD siempre se guarda el canónico.
  enum_or_error($errors, 'genero', $dp['genero'], enums('genero'), 'Género');
  enum_or_error($errors, 'estado_civil', $dp['estadoCivil'], enums('estado_civil'), 'Estado civil');
  enum_or_error($errors, 'zona', $dp['zona'], enums('zona'), 'Zona');
  enum_or_error($errors, 'tipo_vivienda', $dp['tipoVivienda'], enums('tipo_vivienda'), 'Tipo de vivienda');
  enum_or_error($errors, 'rendimiento_escolar', $de['rendimientoEscolar'], enums('rendimiento_escolar'), 'Rendimiento escolar');
  enum_or_error($errors, 'reaccion_padres_calificaciones', $de['reaccionPadresCalificaciones'], enums('reaccion_padres'), 'Reacción ante las calificaciones');
  enum_or_error($errors, 'estudio_es', $ei['estudioEs'], enums('estudio_es'), 'Para ti estudiar es');
  // "Importante" existe en el ENUM a partir de db/migracion_paso3.sql
  if (
    ($ei['estudioEs'] ?? null) === 'IMPORTANTE'
    && !db_enum_contains('expectativas_ingreso', 'estudio_es', 'IMPORTANTE')
  ) {
    $errors['estudio_es'] = 'La opción "Importante" aún no está habilitada en la BD: ejecuta db/migracion_paso3.sql y reintenta.';
  }
  // numero de control (obligatorio). El prefijo [B|C] es opcional: [B|C]?YY69####
  if (empty($dp['numeroControl'])) {
    $errors['numero_control'] = 'Número de control requerido';
  } elseif (!preg_match('/^(?:[BC])?\d{2}69\d{4}$/i', (string) $dp['numeroControl'])) {
    $errors['numero_control'] = 'Formato inválido de número de control';
  }
  // Materias reprobadas (Paso 3): entero opcional 0-999
  if ($de['materiasReprobadas'] !== null && $de['materiasReprobadas'] !== '') {
    if (!preg_match('/^\d{1,3}$/', (string) $de['materiasReprobadas'])) {
      $errors['materias_reprobadas'] = 'Materias reprobadas inválidas (número entero entre 0 y 999)';
    }
  }
  // Promedio: validar rango numérico (0-10)
  if ($de['promedio'] !== null && $de['promedio'] !== '') {
    try {
      v::numericVal()->between(0, 10)->assert($de['promedio']);
    } catch (\Throwable $e) {
      $errors['promedio'] = 'Promedio inválido (debe ser numérico entre 0 y 10)';
    }
  }
  // Paso 2: edades y contadores familiares deben ser enteros >= 0
  // (protege tambien POSTs directos a la API: las columnas son INT con signo)
  $enterosNoNegativos = [
    'padreEdad' => 'padre_edad',
    'madreEdad' => 'madre_edad',
    'numIntegrantesFamilia' => 'num_integrantes_familia',
    'numHermanos' => 'num_hermanos',
    'lugarQueOcupa' => 'lugar_que_ocupa',
  ];
  foreach ($enterosNoNegativos as $camel => $snake) {
    $v = $df[$camel] ?? null;
    if ($v !== null && $v !== '' && !(is_scalar($v) && preg_match('/^\d+$/', (string) $v))) {
      $errors[$snake] = 'Debe ser un número entero mayor o igual a 0';
    }
  }
  // Paso 2: integrantes, hijos y lugar entre los hijos son al menos 1
  // (el tutorado siempre cuenta; un 0 es ilógico venga de donde venga)
  $enterosDesdeUno = [
    'numIntegrantesFamilia' => 'num_integrantes_familia',
    'numHermanos' => 'num_hermanos',
    'lugarQueOcupa' => 'lugar_que_ocupa',
  ];
  foreach ($enterosDesdeUno as $camel => $snake) {
    $v = $df[$camel] ?? null;
    if ($v !== null && $v !== '' && is_numeric($v) && (int) $v < 1) {
      $errors[$snake] = 'Debe ser un número entero mayor o igual a 1';
    }
  }
  // Edad de los padres: nunca menor que la edad del tutorado. Se deriva de la
  // fecha de nacimiento (dato duro del formulario); si falta, se usa el campo edad.
  $edadT = null;
  $fNac  = isset($dp['fechaNacimiento']) && is_string($dp['fechaNacimiento']) ? trim($dp['fechaNacimiento']) : '';
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fNac)) {
    $nac = \DateTime::createFromFormat('Y-m-d', $fNac);
    if ($nac instanceof \DateTime && $nac->format('Y-m-d') === $fNac) {
      $edadT = $nac->diff(new \DateTime('today'))->y;
    }
  }
  if ($edadT === null && isset($dp['edad']) && is_numeric($dp['edad'])) {
    $edadT = (int) $dp['edad'];
  }
  if ($edadT !== null && $edadT >= 0) {
    foreach (['padreEdad' => 'padre_edad', 'madreEdad' => 'madre_edad'] as $camel => $snake) {
      $v = $df[$camel] ?? null;
      if ($v !== null && $v !== '' && is_numeric($v) && (int) $v < $edadT) {
        $errors[$snake] = 'No puede ser menor que la edad del tutorado (' . $edadT . ' años)';
      }
    }
  }
  // Paso 5: horas de trabajo (columna INT) e ingreso mensual (DECIMAL) nunca
  // negativos. Ambos opcionales: vacío o 0 son válidos; protege también los
  // POSTs directos a la API.
  $hT = $df['horasTrabajo'] ?? null;
  if ($hT !== null && $hT !== '' && !preg_match('/^\d+$/', (string) $hT)) {
    $errors['horas_trabajo'] = 'Debe ser un número entero mayor o igual a 0';
  }
  $ing = $df['ingresoMensualFamiliar'] ?? null;
  if ($ing !== null && $ing !== '' && (!is_numeric($ing) || (float) $ing < 0)) {
    $errors['ingreso_mensual_familiar'] = 'Debe ser un número mayor o igual a 0';
  }
  // Preferencia de trabajo: mapear variantes al valor canónico del ENUM
  $prefRaw = $ei['preferenciaEnClase'] ?? ($ei['preferenciaTrabajo'] ?? null);
  if (is_string($prefRaw) && strcasecmp($prefRaw, 'ME_DA_IGUAL') === 0)
    $prefRaw = 'IGUAL';
  $prefNorm = enum_value($prefRaw, enums('preferencia_trabajo'));
  if (trim((string) $prefRaw) !== '' && $prefNorm === null)
    $errors['preferencia_trabajo'] = 'Valor inválido para preferencia de trabajo';
  $ei['preferenciaTrabajo'] = $prefNorm;
  unset($ei['preferenciaEnClase']);
  // Habilidades: valores B, N o M (o su forma larga Bueno/Normal/Malo)
  foreach (habilidad_fields() as $hf) {
    if (!isset($de['habilidades'][$hf]))
      continue;
    $norm = enum_value($de['habilidades'][$hf], enums('habilidades'));
    if ($norm === null) {
      unset($de['habilidades'][$hf]);
      $errors[$hf] = 'Valor inválido (usa B, N o M)';
    } else {
      $de['habilidades'][$hf] = $norm;
    }
  }
  if (!empty($errors)) {
    $response->getBody()->write(json_encode(['error' => 'Errores de validación', 'fields' => $errors]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }

  try {
    // Guardar todo en transacción (crear o actualizar preregistro existente)
    Capsule::connection()->transaction(function () use ($dp, $df, $de, $dm, $ei, &$response, $in, $usaTransporte, $tiempoTraslado, $costoTraslado) {
      // Leer periodo de captura activo (opcional)
      $capturePeriod = Capsule::table('app_settings')->where('key', 'periodo')->value('value');

      // Buscar estudiante por numero_control
      $existing = null;
      if (!empty($dp['numeroControl'])) {
        $existing = Estudiante::where('numero_control', $dp['numeroControl'])->first();
      }

      if ($existing) {
        // Si ya fue capturado, no permitir reescribir
        if (!empty($existing->capturado)) {
          throw new FormException('Número de control ya registrado');
        }
        // Si existe y pertenece a otro tutor distinto, rechazar para evitar reasignaciones accidentales
        $incomingTutor = isset($dp['tutorId']) ? intval($dp['tutorId']) : null;
        if (!empty($existing->tutor_id) && $incomingTutor && $existing->tutor_id !== $incomingTutor) {
          throw new FormException('Número de control asignado a otro tutor');
        }

        // Actualizar el preregistro con los datos enviados y marcar capturado
        $existing->update([
          'nombre_completo' => $dp['nombreCompleto'] ?? $existing->nombre_completo,
          'fecha_nacimiento' => $dp['fechaNacimiento'] ?? $existing->fecha_nacimiento,
          'lugar_nacimiento' => $dp['lugarNacimiento'] ?? $existing->lugar_nacimiento,
          'edad' => isset($dp['edad']) ? intval($dp['edad']) : $existing->edad,
          'genero' => $dp['genero'] ?? $existing->genero,
          'estado_civil' => $dp['estadoCivil'] ?? $existing->estado_civil,
          'domicilio_familiar' => $dp['domicilioFamiliar'] ?? $existing->domicilio_familiar,
          'localidad_familiar' => $dp['localidadFamiliar'] ?? $existing->localidad_familiar,
          'codigo_postal' => $dp['codigoPostal'] ?? $existing->codigo_postal,
          'zona' => $dp['zona'] ?? $existing->zona,
          'tipo_vivienda' => $dp['tipoVivienda'] ?? $existing->tipo_vivienda,
          'tipo_vivienda_otro' => $in['tipo_vivienda_otro'] ?? $existing->tipo_vivienda_otro,
          'telefono_movil' => $dp['telefonoMovil'] ?? $existing->telefono_movil,
          'habla_otra_lengua' => !empty($dp['hablaOtraLengua']) ? 1 : $existing->habla_otra_lengua,
          'cual_lengua' => $dp['cualLengua'] ?? $existing->cual_lengua,
          'usa_transporte_publico' => $usaTransporte ? 1 : 0,
          'tiempo_traslado_transporte' => $usaTransporte ? ($tiempoTraslado ?? $existing->tiempo_traslado_transporte) : null,
          'costo_transporte' => $usaTransporte ? ($costoTraslado ?? $existing->costo_transporte) : null,
          'tutor_id' => isset($dp['tutorId']) ? intval($dp['tutorId']) : $existing->tutor_id,
          'periodo_captura' => $capturePeriod ?? $existing->periodo_captura,
          'capturado' => 1
        ]);

        $estudiante = $existing;
      } else {
        // Crear nuevo estudiante (preregistro completado)
        $estudiante = Estudiante::create([
          'numero_control' => !empty($dp['numeroControl']) ? $dp['numeroControl'] : null,
          'nombre_completo' => $dp['nombreCompleto'] ?? null,
          'fecha_nacimiento' => $dp['fechaNacimiento'] ?? null,
          'lugar_nacimiento' => $dp['lugarNacimiento'] ?? null,
          'edad' => isset($dp['edad']) ? intval($dp['edad']) : null,
          'genero' => $dp['genero'] ?? null,
          'estado_civil' => $dp['estadoCivil'] ?? null,
          'domicilio_familiar' => $dp['domicilioFamiliar'] ?? null,
          'localidad_familiar' => $dp['localidadFamiliar'] ?? null,
          'codigo_postal' => $dp['codigoPostal'] ?? null,
          'zona' => $dp['zona'] ?? null,
          'tipo_vivienda' => $dp['tipoVivienda'] ?? null,
          'tipo_vivienda_otro' => $in['tipo_vivienda_otro'] ?? null,
          'tutor_id' => isset($dp['tutorId']) ? intval($dp['tutorId']) : null,
          'telefono_movil' => $dp['telefonoMovil'] ?? null,
          'habla_otra_lengua' => !empty($dp['hablaOtraLengua']) ? 1 : 0,
          'cual_lengua' => $dp['cualLengua'] ?? null,
          'usa_transporte_publico' => $usaTransporte ? 1 : 0,
          'tiempo_traslado_transporte' => $tiempoTraslado,
          'costo_transporte' => $costoTraslado,
          'periodo_captura' => $capturePeriod ?? null,
          'capturado' => 1
        ]);
      }

      // Datos familiares: updateOrCreate
      DatosFamiliares::updateOrCreate(['estudiante_id' => $estudiante->id], [
        'padre_nombre' => $df['padreNombre'] ?? null,
        'padre_vive' => !empty($df['padreVive']) ? 1 : 0,
        'padre_edad' => int_or_null($df['padreEdad']),
        'padre_nivel_estudios' => $df['padreNivelEstudios'] ?? null,
        'padre_ocupacion' => $df['padreOcupacion'] ?? null,
        'madre_nombre' => $df['madreNombre'] ?? null,
        'madre_vive' => !empty($df['madreVive']) ? 1 : 0,
        'madre_edad' => int_or_null($df['madreEdad']),
        'madre_nivel_estudios' => $df['madreNivelEstudios'] ?? null,
        'madre_ocupacion' => $df['madreOcupacion'] ?? null,
        'num_integrantes_familia' => int_or_null($df['numIntegrantesFamilia']),
        'num_hermanos' => int_or_null($df['numHermanos']),
        'lugar_que_ocupa' => int_or_null($df['lugarQueOcupa']),
        'vives_con' => $df['vivesCon'] ?? null,
        'situacion_especial' => $df['situacionEspecial'] ?? null,
        'relacion_padres' => $df['relacionPadres'] ?? null,
        'trabaja_actualmente' => !empty($df['trabajaActualmente']) ? 1 : 0,
        'horas_trabajo' => int_or_null($df['horasTrabajo']),
        'empresa_trabajo' => $df['empresaTrabajo'] ?? null,
        'motivo_trabajo' => $df['motivoTrabajo'] ?? null,
        'apoyo_economico' => $df['apoyoEconomico'] ?? null,
        'ingreso_mensual_familiar' => num_or_null($df['ingresoMensualFamiliar'])
      ]);

      // Datos escolares
      $escolarRow = [
        'institucion_procedencia' => $de['institucionProcedencia'] ?? null,
        'localidad' => $de['localidad'] ?? ($de['localidadEscuela'] ?? null),
        'generacion_egreso' => $de['generacionEgreso'] ?? null,
        'promedio' => isset($de['promedio']) ? $de['promedio'] : null,
        'rendimiento_escolar' => $de['rendimientoEscolar'] ?? null,
        'reprobado_curso' => !empty($de['reprobadoCurso']) ? 1 : 0,
        'causa_reprobacion' => $de['causaReprobacion'] ?? null,
        'satisfecho_resultados' => !empty($de['satisfechoResultados']) ? 1 : 0,
        'motivo_satisfaccion' => $de['motivoSatisfaccion'] ?? null,
        'ha_estado_becado' => !empty($de['haEstadoBecado']) ? 1 : 0,
        'grado_beca' => $de['gradoBeca'] ?? null,
        'tipo_beca' => $de['tipoBeca'] ?? null,
        'materias_favoritas' => $de['materiasFavoritas'] ?? null,
        'reaccion_padres_calificaciones' => $de['reaccionPadresCalificaciones'] ?? null
      ];
      // Campo nuevo (migracion en db/): solo se usa la columna si existe, para
      // que la captura no dependa de haber corrido el ALTER todavia.
      if (
        ($de['materiasReprobadas'] ?? null) !== null && $de['materiasReprobadas'] !== ''
        && Capsule::getSchemaBuilder()->hasColumn('datos_escolares', 'materias_reprobadas')
      ) {
        $escolarRow['materias_reprobadas'] = int_or_null($de['materiasReprobadas']);
      }
      DatosEscolares::updateOrCreate(['estudiante_id' => $estudiante->id], $escolarRow);

      // Habilidades (el formulario envía B/N/M; la BD almacena Bueno/Normal/Malo)
      $habRow = [];
      foreach (habilidad_fields() as $hf) {
        $habRow[$hf] = habilidad_to_db($de['habilidades'][$hf] ?? null);
      }
      HabilidadesEscolares::updateOrCreate(['estudiante_id' => $estudiante->id], $habRow);

      // Datos medicos
      DatosMedicos::updateOrCreate(['estudiante_id' => $estudiante->id], [
        'padece_enfermedad' => !empty($dm['padeceEnfermedad']) ? 1 : 0,
        'cual_enfermedad' => $dm['cualEnfermedad'] ?? null,
        'condicion_fisica' => !empty($dm['condicionFisica']) ? 1 : 0,
        'cual_condicion' => $dm['cualCondicion'] ?? null,
        'toma_medicacion' => !empty($dm['tomaMedicacion']) ? 1 : 0,
        'cual_medicacion' => $dm['cualMedicacion'] ?? null,
        'ha_sido_operado' => !empty($dm['haSidoOperado']) ? 1 : 0,
        'de_que_operacion' => $dm['deQueOperacion'] ?? null
      ]);

      // Expectativas
      $eiRow = [
        'carrera_gusta' => !empty($ei['carreraGusta']) ? 1 : 0,
        'que_mas_atrae' => $ei['queMasAtrae'] ?? null,
        'tiene_preocupacion_curso' => !empty($ei['tienePreocupacionCurso']) ? 1 : 0,
        'que_preocupa' => $ei['quePreocupa'] ?? null,
        'estudio_es' => $ei['estudioEs'] ?? null,
        'forma_apoyo_institucion' => $ei['formaApoyoInstitucion'] ?? null,
        'desea_apoyo_institucional' => !empty($ei['deseaApoyoInstitucional']) ? 1 : 0,
        'tipo_apoyo' => $ei['tipoApoyo'] ?? null,
        'pasatiempo_favorito' => $ei['pasatiempoFavorito'] ?? null,
        'causa_problemas_estudio' => $ei['causaProblemasEstudio'] ?? null,
        'preferencia_trabajo' => $ei['preferenciaTrabajo'] ?? null,
        'tiempo_estudio_casa' => $ei['tiempoEstudioCasa'] ?? null,
        'forma_pasartiempo' => $ei['formaPasarTiempo'] ?? null,
        'forma_hacer_amigos' => $ei['formaHacerAmigos'] ?? null,
        'cuenta_lugar_adecuado' => !empty($ei['cuentaLugarAdecuado']) ? 1 : 0,
        'prio_explicacion_clara' => int_or_null($ei['prio_explicacionClara'] ?? null),
        'prio_paciente' => int_or_null($ei['prio_paciente'] ?? null),
        'prio_estricto' => int_or_null($ei['prio_estricto'] ?? null),
        'prio_justo' => int_or_null($ei['prio_justo'] ?? null),
        'prio_comprensivo' => int_or_null($ei['prio_comprensivo'] ?? null),
        'prio_buen_humor' => int_or_null($ei['prio_buen_humor'] ?? null),
        'prio_otra' => $ei['prio_otra'] ?? null
      ];
      // Compatibilidad pre-migracion: si db/migracion_paso3.sql aun no corrio,
      // escribir en las columnas originales para no perder la captura; al correr
      // el ALTER, CHANGE conserva esos valores en las columnas renombradas.
      if (!Capsule::getSchemaBuilder()->hasColumn('expectativas_ingreso', 'prio_paciente')) {
        $prioCompat = [
          'prio_paciente' => 'prio_entienda_jovenes',
          'prio_estricto' => 'prio_justo_evaluar',
          'prio_justo' => 'prio_permita_preguntar',
          'prio_comprensivo' => 'prio_respete_e_imponga',
          'prio_buen_humor' => 'prio_no_se_enoje',
        ];
        foreach ($prioCompat as $nuevo => $viejo) {
          $eiRow[$viejo] = $eiRow[$nuevo] ?? null;
          unset($eiRow[$nuevo]);
        }
      }
      ExpectativasIngreso::updateOrCreate(['estudiante_id' => $estudiante->id], $eiRow);

      // El intento ya quedó registrado en submit_logs (rate limiting)

      $response->getBody()->write(json_encode(['status' => 'success', 'id' => $estudiante->id]));
    });

    return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
  } catch (FormException $e) {
    // Error de negocio esperado: se devuelve tal cual (409 = conflicto)
    $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
  } catch (\Exception $e) {
    // No exponer el detalle interno (puede revelar estructura de la BD)
    error_log('[entrevistas] POST /api/estudiantes: ' . $e->getMessage());
    $response->getBody()->write(json_encode(['error' => 'No se pudo guardar el registro. Intenta de nuevo.']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
  }
});


// RUTA: Verificar existencia de número de control (antes de mostrar formulario)
$app->get('/api/estudiantes/check', function (Request $request, Response $response) {
  $q  = $request->getQueryParams();
  $nc = strtoupper(str_replace(' ', '', trim((string) ($q['numeroControl'] ?? ''))));
  if (empty($nc)) {
    $response->getBody()->write(json_encode(['error' => 'numeroControl requerido']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }
  // Validar formato básico (misma regex que en POST)
  if (!preg_match('/^(?:[BC])?\d{2}69\d{4}$/i', $nc)) {
    $response->getBody()->write(json_encode(['error' => 'Formato inválido de número de control']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }

  // Revisar si el formulario está activo
  $formActive = Capsule::table('app_settings')->where('key', 'form_active')->value('value');
  if ($formActive !== null && (string) $formActive !== '1') {
    $response->getBody()->write(json_encode(['error' => 'Formularios cerrados temporalmente']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(423);
  }

  $exists = Capsule::table('estudiantes')->where('numero_control', $nc)->exists();
  $out    = ['exists' => $exists];
  if ($exists) {
    // Información que el formulario necesita para abrir el expediente en modo
    // "completar preregistro" (capturado=0) con el tutor asignado bloqueado.
    $row                 = Capsule::table('estudiantes')->where('numero_control', $nc)->first(['capturado', 'tutor_id']);
    $out['capturado']    = !empty($row->capturado);
    $out['tutor_id']     = $row->tutor_id !== null ? (int) $row->tutor_id : null;
    $out['tutor_nombre'] = $row->tutor_id
      ? Capsule::table('tutores')->where('id', $row->tutor_id)->value('nombre')
      : null;
  }
  $response->getBody()->write(json_encode($out));
  return $response->withHeader('Content-Type', 'application/json');
});

// Exportar estudiantes a Excel (contiene datos personales: solo admin)
$app->get('/api/estudiantes/exportar', function (Request $request, Response $response) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }

  $estudiantes = Estudiante::with('tutor')->orderBy('numero_control')->get();

  $spreadsheet = new Spreadsheet();
  $sheet       = $spreadsheet->getActiveSheet();

  $headers = [
    'ID',
    'Número de control',
    'Nombre completo',
    'Fecha nacimiento',
    'Teléfono',
    'Tutor',
    'Periodo de captura',
    'Capturado',
    'Creado'
  ];
  foreach ($headers as $col => $label) {
    $sheet->setCellValue(chr(65 + $col) . '1', $label);
  }

  $fila = 2;
  foreach ($estudiantes as $e) {
    $sheet->setCellValue('A' . $fila, $e->id);
    $sheet->setCellValue('B' . $fila, $e->numero_control);
    $sheet->setCellValue('C' . $fila, $e->nombre_completo);
    $sheet->setCellValue('D' . $fila, $e->fecha_nacimiento);
    $sheet->setCellValue('E' . $fila, $e->telefono_movil);
    $sheet->setCellValue('F' . $fila, $e->tutor ? $e->tutor->nombre : null);
    $sheet->setCellValue('G' . $fila, $e->periodo_captura);
    $sheet->setCellValue('H' . $fila, !empty($e->capturado) ? 'Sí' : 'No');
    $sheet->setCellValue('I' . $fila, (string) $e->created_at);
    $fila++;
  }

  $writer = new Xlsx($spreadsheet);
  $stream = fopen('php://memory', 'r+');
  $writer->save($stream);
  rewind($stream);

  $response = $response->withBody(new \Slim\Psr7\Stream($stream))
    ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
    ->withHeader('Content-Disposition', 'attachment; filename="reporte_estudiantes.xlsx"');

  return $response;
});


// Endpoint admin: subir CSV de preregistros para un tutor
$app->post('/api/admin/tutores/{id}/upload', function (Request $request, Response $response, $args) {
  $tutorId = intval($args['id']);
  // Verificar contraseña admin (solo header X-Admin-Password)
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }

  // Verificar tutor
  if (!Capsule::table('tutores')->where('id', $tutorId)->where('active', 1)->exists()) {
    $response->getBody()->write(json_encode(['error' => 'Tutor inválido']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }

  $uploadedFiles = $request->getUploadedFiles();
  if (empty($uploadedFiles['file'])) {
    $response->getBody()->write(json_encode(['error' => 'Archivo no enviado, campo "file" (acepta CSV, XLS o XLSX)']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }

  $file = $uploadedFiles['file'];
  if ($file->getError() !== UPLOAD_ERR_OK) {
    $response->getBody()->write(json_encode(['error' => 'Error al recibir archivo']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
  }

  $stream  = $file->getStream();
  $content = (string) $stream;
  // Quitar el BOM que escribe Excel en CSV UTF-8 (si no, el encabezado no se
  // reconoce y la línea caería como error de formato).
  $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

  // Archivos de Excel binarios (XLS/XLSX) o exportaciones "HTML-Excel": se
  // abren con PhpSpreadsheet y sólo se extrae la PRIMERA columna (número de
  // control / matrícula). Cada fila de Excel se vuelve una "línea" virtual
  // que corre por el MISMO pipeline de validación de abajo: la fila N de
  // Excel queda como línea N en los errores, el encabezado de la fila 1 se
  // omite igual y las demás columnas (nombre, etc.) se ignoran.
  $esXlsx = str_starts_with($content, "PK\x03\x04");
  $esXls  = str_starts_with($content, "\xD0\xCF\x11\xE0");
  $esHtml = str_starts_with($content, '<');
  if ($esXlsx || $esXls || $esHtml) {
    $tmp = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($tmp, $content);
    try {
      $lector = $esXlsx
        ? new \PhpOffice\PhpSpreadsheet\Reader\Xlsx()
        : ($esXls
          ? new \PhpOffice\PhpSpreadsheet\Reader\Xls()
          : new \PhpOffice\PhpSpreadsheet\Reader\Html());
      $lector->setReadDataOnly(true);
      $hoja      = $lector->load($tmp)->getActiveSheet();
      $renglones = [];
      foreach ($hoja->getRowIterator() as $fila) {
        $idx = $fila->getRowIndex();
        // getCell() sobre una celda inexistente devuelve celda vacía (NULL)
        $v = $hoja->getCell('A' . $idx)->getValue();
        if ($v === null || is_bool($v)) {
          $txt = '';
        } elseif (is_int($v)) {
          $txt = (string) $v;
        } elseif (is_float($v)) {
          // Celda numérica: 24690902.0 -> "24690902"
          $txt = (floor($v) === $v && abs($v) < 1e15) ? sprintf('%.0f', $v) : (string) $v;
        } elseif (is_object($v)) {
          $txt = method_exists($v, '__toString') ? (string) $v : '';
        } else {
          $txt = (string) $v;
        }
        $renglones[$idx] = $txt;
      }
      // Rellenar las filas ausentes para conservar la numeración original
      $ultima      = $renglones === [] ? 0 : max(array_keys($renglones));
      $lineasExcel = [];
      for ($r = 1; $r <= $ultima; $r++) {
        $lineasExcel[] = $renglones[$r] ?? '';
      }
      $content = implode("\n", $lineasExcel);
    } catch (\Throwable $e) {
      $response->getBody()->write(json_encode(['error' => 'No se pudo leer el archivo de Excel (¿está dañado o no es CSV/XLS/XLSX?)']));
      return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
    } finally {
      @unlink($tmp);
    }
  } elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $content)) {
    // Binario que no es Excel (PDF, imagen, .exe...): avisar claro en vez de
    // dejar que el parser de texto falle con un 500 confuso.
    $response->getBody()->write(json_encode(['error' => 'El archivo no parece un CSV/Excel legible; sube un CSV, XLS o XLSX']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }

  $lines   = preg_split('/\r\n|\r|\n/', $content);
  $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

  foreach ($lines as $ln => $line) {
    $line = trim($line);
    if ($line === '')
      continue;
    // Obtener número de control (primera columna, separar por , o ; o tab)
    $cols = preg_split('/\s*[,;\t]\s*/', $line);
    $col0 = trim($cols[0] ?? '', " \t\"'");
    $nc   = strtoupper(str_replace(' ', '', $col0));
    if ($nc === '')
      continue;
    // Líneas de metadato que agrega Excel: encabezados de las listas
    // ("matricula", "numero_control", con o sin acento/mayúsculas) o la
    // marca de separador ("sep=;"). Se omiten sin error; cualquier otra
    // línea sigue validándose con la regex de abajo.
    $clave = strtr(strtolower($col0), [
      'á' => 'a',
      'é' => 'e',
      'í' => 'i',
      'ó' => 'o',
      'ú' => 'u',
      'ü' => 'u',
      'Á' => 'a',
      'É' => 'e',
      'Í' => 'i',
      'Ó' => 'o',
      'Ú' => 'u',
      'Ü' => 'u',
      ' ' => '',
      '_' => '',
    ]);
    if (
      in_array($clave, ['numerodecontrol', 'numerocontrol', 'matricula'], true)
      || str_starts_with($clave, 'sep=')
    ) {
      continue;
    }

    // Validar formato
    if (!preg_match('/^(?:[BC])?\d{2}69\d{4}$/i', $nc)) {
      $summary['errors'][] = ['line' => $ln + 1, 'value' => $line, 'error' => 'Formato inválido'];
      $summary['skipped']++;
      continue;
    }

    try {
      Capsule::connection()->transaction(function () use ($nc, $tutorId, &$summary) {
        $capturePeriod = Capsule::table('app_settings')->where('key', 'periodo')->value('value');
        $existing      = Estudiante::where('numero_control', $nc)->first();
        if ($existing) {
          // Reasignar solo el tutor, sin tocar datos personales. periodo_captura
          // NO se mueve: en filas capturadas marca el periodo en que se capturó,
          // y las pendientes conservan el periodo con el que se precargaron
          // (al completar el form de todos modos se reescribe con el activo).
          if ((int) $existing->tutor_id === $tutorId) {
            $summary['skipped']++;
            return;
          }
          $existing->update(['tutor_id' => $tutorId]);
          $summary['updated']++;
        } else {
          // Insertar preregistro con campos personales NULL y capturado = 0
          Estudiante::create([
            'numero_control' => $nc,
            'tutor_id' => $tutorId,
            'periodo_captura' => $capturePeriod ?? null,
            'capturado' => 0
          ]);
          $summary['created']++;
        }
      });
    } catch (\Exception $e) {
      $summary['errors'][] = ['line' => $ln + 1, 'value' => $line, 'error' => $e->getMessage()];
    }
  }

  $response->getBody()->write(json_encode($summary));
  return $response->withHeader('Content-Type', 'application/json');
});


// Reporte por tutor (compartido por la ruta admin). Devuelve totales y
// listado de números de control: contiene datos personales, requiere auth.
function tutor_report_payload(int $tutorId): array
{
  $total    = Capsule::table('estudiantes')->where('tutor_id', $tutorId)->count();
  $captured = Capsule::table('estudiantes')->where('tutor_id', $tutorId)->where('capturado', 1)->count();
  $pending  = Capsule::table('estudiantes')->where('tutor_id', $tutorId)->where(function ($q) {
    $q->whereNull('capturado')->orWhere('capturado', 0);
  })->count();

  $list = Capsule::table('estudiantes')->where('tutor_id', $tutorId)
    ->select('id', 'numero_control', 'nombre_completo', 'periodo_captura', 'capturado', 'created_at', 'updated_at')
    ->orderBy('numero_control')->get();

  return ['tutor_id' => $tutorId, 'total' => $total, 'captured' => $captured, 'pending' => $pending, 'list' => $list];
}

// Endpoint admin: reporte por tutor (requiere X-Admin-Password)
// Nota: la ruta pública /api/tutores/{id}/report se retiró por exponer
// números de control sin autenticación; usar esta.
$app->get('/api/admin/tutores/{id}/report', function (Request $request, Response $response, $args) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }
  $tutorId = intval($args['id']);
  if (!Capsule::table('tutores')->where('id', $tutorId)->where('active', 1)->exists()) {
    $response->getBody()->write(json_encode(['error' => 'Tutor inválido']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }

  $response->getBody()->write(json_encode(tutor_report_payload($tutorId)));
  return $response->withHeader('Content-Type', 'application/json');
});


// --- Utilidades del Excel de respuestas -----------------------------------

// Catálogo de columnas de una tabla con su tipo real (information_schema),
// para formatear booleanos y enums en el reporte. Si la tabla no existe
// devuelve vacío y la hoja sale solo con encabezados.
function table_columns(string $table): array
{
  try {
    $rows = Capsule::select(
      'SELECT `column_name` AS c, `data_type` AS dt, `column_type` AS ct
         FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ?',
      [$table]
    );
  } catch (\Throwable $e) {
    return [];
  }
  $out = [];
  foreach ($rows as $r) {
    $out[$r->c] = ['dt' => (string) $r->dt, 'ct' => (string) $r->ct];
  }
  return $out;
}

// Rótulo legible a partir del nombre de columna en la BD.
function col_label(string $col): string
{
  static $over = [
  'numero_control' => 'Número de control',
  'nombre_completo' => 'Nombre completo',
  'fecha_nacimiento' => 'Fecha de nacimiento',
  'lugar_nacimiento' => 'Lugar de nacimiento',
  'genero' => 'Género',
  'codigo_postal' => 'Código postal',
  'zona' => 'Zona',
  'telefono_movil' => 'Teléfono móvil',
  'habla_otra_lengua' => '¿Habla otra lengua?',
  'cual_lengua' => 'Otra lengua que habla',
  'usa_transporte_publico' => '¿Usa transporte público?',
  'tiempo_traslado_transporte' => 'Tiempo de traslado (transporte público)',
  'costo_transporte' => 'Costo del transporte',
  'periodo_captura' => 'Periodo de captura',
  'capturado' => 'Capturado',
  'created_at' => 'Creado',
  'updated_at' => 'Actualizado',
  'lugar_que_ocupa' => 'Lugar que ocupa en la familia',
  'situacion_especial' => 'Situación especial',
  'relacion_padres' => 'Relación entre padres',
  'trabaja_actualmente' => '¿Trabaja actualmente?',
  'horas_trabajo' => 'Horas de trabajo',
  'empresa_trabajo' => 'Empresa / lugar de trabajo',
  'ingreso_mensual_familiar' => 'Ingreso mensual familiar',
  'institucion_procedencia' => 'Institución de procedencia',
  'reprobado_curso' => '¿Ha reprobado algún curso?',
  'satisfecho_resultados' => '¿Satisfecho con los resultados?',
  'ha_estado_becado' => '¿Ha estado becado?',
  'reaccion_padres_calificaciones' => 'Reacción de los padres ante las calificaciones',
  'comprension_lectora' => 'Comprensión lectora',
  'comprension_oral' => 'Comprensión oral',
  'resolucion_problemas' => 'Resolución de problemas',
  'expresion_oral' => 'Expresión oral',
  'expresion_escrita' => 'Expresión escrita',
  'expresion_grafica' => 'Expresión gráfica',
  'calculo' => 'Cálculo',
  'ortografia' => 'Ortografía',
  'padece_enfermedad' => '¿Padece alguna enfermedad?',
  'cual_enfermedad' => '¿Cuál enfermedad?',
  'condicion_fisica' => 'Condición física',
  'cual_condicion' => '¿Cuál condición?',
  'toma_medicacion' => '¿Toma medicación?',
  'cual_medicacion' => '¿Cuál medicación?',
  'ha_sido_operado' => '¿Ha sido operado?',
  'de_que_operacion' => '¿De qué operación?',
  'carrera_gusta' => '¿Le gusta su carrera?',
  'que_mas_atrae' => '¿Qué más le atrae?',
  'tiene_preocupacion_curso' => '¿Tiene alguna preocupación?',
  'que_preocupa' => '¿Qué le preocupa?',
  'estudio_es' => 'Considera que estudiar es',
  'forma_apoyo_institucion' => 'Forma de apoyo de la institución',
  'desea_apoyo_institucional' => '¿Desea apoyo institucional?',
  'tipo_apoyo' => 'Tipo de apoyo',
  'causa_problemas_estudio' => '¿Qué causa problemas de estudio?',
  'preferencia_trabajo' => 'Preferencia de trabajo',
  'forma_pasartiempo' => 'Cómo pasa el tiempo libre',
  'forma_hacer_amigos' => 'Cómo hace amigos',
  'tiempo_estudio_casa' => 'Tiempo de estudio en casa',
  'cuenta_lugar_adecuado' => '¿Cuenta con lugar adecuado para estudiar?',
  'prio_explicacion_clara' => 'Prioridad 1: que explique bien',
  'prio_paciente' => 'Prioridad 2: que sea paciente',
  'prio_estricto' => 'Prioridad 3: que sea estricto',
  'prio_justo' => 'Prioridad 4: que sea justo',
  'prio_comprensivo' => 'Prioridad 5: que sea comprensivo',
  'prio_buen_humor' => 'Prioridad 6: buen sentido del humor',
  'prio_otra' => 'Prioridad 7: otra cualidad',
  ];
  return $over[$col] ?? ucfirst(str_replace('_', ' ', $col));
}

// Etiqueta literal de un valor de opción del formulario (lo que el tutor
// vio en pantalla). Cubre columnas VARCHAR/TEXT que guardan el código de la
// opción (ej. MUY_BUENA, NINGUNA); los ENUM y booleanos se formatean antes.
// Generado desde los <option> de public/form.html.
function value_label(string $v): ?string
{
  static $map = [
  'F' => 'Femenino',
  'M' => 'Masculino',
  'NB' => 'No binario',
  'SOLTERO' => 'Soltero/a',
  'CASADO' => 'Casado/a',
  'DIVORCIADO' => 'Divorciado/a',
  'VIUDO' => 'Viudo/a',
  'UNION LIBRE' => 'Unión libre',
  'OTRO' => 'Otro',
  'RURAL' => 'Rural',
  'URBANA' => 'Urbana',
  'PROPIA' => 'Propia',
  'RENTADA' => 'Rentada',
  'PRESTADA' => 'Prestada',
  'OTRA' => 'Otra',
  'SABE_LEER_ESCRIBIR' => 'Sabe leer y escribir',
  'PRIMARIA_TERMINADA' => 'Primaria terminada',
  'PRIMARIA_TRUNCA' => 'Primaria trunca',
  'SECUNDARIA_TERMINADA' => 'Secundaria terminada',
  'SECUNDARIA_TRUNCA' => 'Secundaria trunca',
  'PREPARATORIA_TERMINADA' => 'Preparatoria terminada',
  'PREPARATORIA_TRUNCA' => 'Preparatoria trunca',
  'CARRERA_TECNICA_TERMINADA' => 'Carrera técnica terminada',
  'CARRERA_TECNICA_TRUNCA' => 'Carrera técnica trunca',
  'LICENCIATURA_TERMINADA' => 'Licenciatura terminada',
  'LICENCIATURA_TRUNCA' => 'Licenciatura trunca',
  'MAESTRIA_TERMINADA' => 'Maestría terminada',
  'MAESTRIA_TRUNCA' => 'Maestría trunca',
  'DOCTORADO_TERMINADO' => 'Doctorado terminado',
  'DOCTORADO_TRUNCO' => 'Doctorado trunco',
  'PADRE_Y_MADRE' => 'Padre y madre',
  'PADRE' => 'Solo padre',
  'MADRE' => 'Solo madre',
  'ABUELOS' => 'Abuelos',
  'OTROS' => 'Otros (especificar)',
  'NINGUNA' => 'Ninguna',
  'FALLECIMIENTO_PADRE' => 'Fallecimiento del padre',
  'FALLECIMIENTO_MADRE' => 'Fallecimiento de la madre',
  'SEPARACION_O_DIVORCIO' => 'Separación / Divorcio de los padres',
  'ABANDONO' => 'Abandono',
  'ENFERMEDAD_GRAVE_FAMILIAR' => 'Enfermedad grave de algún familiar',
  'MUY_BUENA' => 'Muy buena',
  'BUENA' => 'Buena',
  'REGULAR' => 'Regular',
  'MALA' => 'Mala',
  'MUY_MALA' => 'Muy mala',
  'MUY_BUENO' => 'Muy bueno',
  'BUENO' => 'Bueno',
  'MALO' => 'Malo',
  'MUY_MALO' => 'Muy malo',
  'B' => 'B',
  'N' => 'N',
  'MUY_BIEN' => 'Muy bien',
  'NORMAL' => 'Normal',
  'MUY_MAL' => 'Muy mal',
  'NO_SABEN' => 'No saben',
  'NO_LES_IMPORTA' => 'No les importa',
  'PRIMARIA' => 'Primaria',
  'SECUNDARIA' => 'Secundaria',
  'BACHILLERATO' => 'Bachillerato',
  'MANUTENCION' => 'Manutención',
  'ALIMENTACION' => 'Alimentación',
  'TRANSPORTE' => 'Transporte',
  'TALENTO_DEPORTIVO' => 'Talento Deportivo',
  'TALENTO_ARTISTICO' => 'Talento Artístico',
  'APROVECHAMIENTO' => 'Aprovechamiento Académico',
  'IMPORTANTE' => 'Importante',
  'INTERESANTE' => 'Algo interesante',
  'ABURRIDO' => 'Algo aburrido',
  'UTIL' => 'Algo útil',
  'IMPUESTO' => 'Impuesto por mis padres',
  'PASATIEMPO' => 'Un pasatiempo',
  'AMIGOS' => 'Un espacio para estar con mis amigos',
  'ACADEMICO' => 'Académico',
  'PSICOLOGICO' => 'Psicológico',
  'ORIENTACION' => 'Orientación',
  'Me organizo mal' => 'Me organizo mal',
  'No me interesa' => 'No me interesa',
  'Me distraigo' => 'Me distraigo',
  'No tengo lugar para estudiar' => 'No tengo lugar para estudiar',
  'SOLO' => 'Solo/a',
  'COMPANERO' => 'Con algún compañero/a',
  'EQUIPO' => 'En equipo',
  'IGUAL' => 'Me da igual',
  'DIABETES' => 'Diabetes',
  'HIPERTENSION' => 'Hipertensión',
  'ASMA' => 'Asma',
  'ALERGIAS' => 'Alergias',
  'VISUAL' => 'Discapacidad visual',
  'AUDITIVA' => 'Discapacidad auditiva',
  'MOTRIZ' => 'Discapacidad motriz',
  'AMBOS' => 'Ambos',
  'RECURSO_PROPIO' => 'Recurso propio',
  'OTRO_FAMILIAR' => 'Otro familiar',
  'MANTENER_A_MIS_ESTUDIOS' => 'Mantener a mis estudios',
  'AYUDAR_A_MIS_PADRES' => 'Ayudar a mis padres',
  'MANTENER_A_MI_FAMILIA' => 'Mantener a mi familia',
  'MENOS_DE_10_MIN' => 'Menos de 10 minutos',
  'DE_10_A_30_MIN' => 'De 10 a 30 minutos',
  'MAS_DE_30_MIN' => 'Más de 30 minutos',
  '1_HORA_O_MAS' => '1 hora o más',
  ];
  return $map[$v] ?? null;
}

// Formato de celda: Sí/No en booleanos, enum legible (guion bajo -> espacio),
// el resto como texto. `null` queda en celda vacía.
function cell_text($val, array $meta): string
{
  if ($val === null)
    return '';
  if (($meta['ct'] ?? '') === 'tinyint(1)')
    return $val ? 'Sí' : 'No';
  // Códigos de opciones del formulario -> literal lo que el tutor vio
  // (MUY_BUENA -> Muy buena, CASADO -> Casado/a, M -> Masculino...).
  if (is_string($val)) {
    $lbl = value_label($val);
    if ($lbl !== null)
      return $lbl;
  }
  if (($meta['dt'] ?? '') === 'enum')
    return str_replace('_', ' ', (string) $val);
  return is_scalar($val) ? (string) $val : '';
}

// Escribe una hoja: encabezado en la fila 1 (congelada) y filas de datos.
function write_sheet($sheet, string $title, array $headers, array $rows): void
{
  $sheet->setTitle($title);
  $sheet->freezePane('A2');
  foreach ($headers as $i => $h) {
    $letter = Coordinate::stringFromColumnIndex($i + 1);
    $width  = min(42, max(12, function_exists('mb_strlen') ? mb_strlen($h) + 2 : strlen($h) + 2));
    $sheet->setCellValue($letter . '1', $h);
    $sheet->getColumnDimension($letter)->setWidth($width);
  }
  $r = 2;
  foreach ($rows as $row) {
    foreach ($row as $i => $v) {
      $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $r, $v);
    }
    $r++;
  }
}

// [título, tabla, columnas a omitir] de cada bloque de datos del cuestionario.
// Compartido por el Excel de exportación y la vista individual de respuestas.
function data_bloques(): array
{
  return [
    ['Datos personales', 'estudiantes', ['id', 'tutor_id']],
    ['Datos familiares', 'datos_familiares', ['estudiante_id']],
    ['Datos escolares', 'datos_escolares', ['estudiante_id']],
    ['Habilidades', 'habilidades_escolares', ['estudiante_id']],
    ['Datos médicos', 'datos_medicos', ['estudiante_id']],
    ['Expectativas', 'expectativas_ingreso', ['estudiante_id']],
  ];
}

// Endpoint admin: Excel con TODAS las respuestas de los tutorados de un tutor.
// Una hoja por bloque de datos, enlazadas por `numero_control`:
//   Datos personales · Datos familiares · Datos escolares ·
//   Habilidades · Datos médicos · Expectativas
$app->get('/api/admin/tutores/{id}/exportar', function (Request $request, Response $response, $args) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }
  $tutorId = intval($args['id']);
  $tutor   = Capsule::table('tutores')->where('id', $tutorId)->first();
  if (!$tutor) {
    $response->getBody()->write(json_encode(['error' => 'Tutor no encontrado']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
  }

  $bloques = data_bloques();

  $spreadsheet = new Spreadsheet();
  $first       = true;

  foreach ($bloques as [$title, $table, $skip]) {
    $meta   = table_columns($table);
    $cols   = array_values(array_diff(array_keys($meta), $skip));
    $isMain = $table === 'estudiantes';

    try {
      if ($isMain) {
        // Datos personales: ya son los del propio estudiante del tutor.
        $query = Capsule::table('estudiantes')->where('tutor_id', $tutorId);
        $sel   = [];
      } else {
        // Hijas: se enlazan con `estudiantes` para filtrar por tutor y
        // traer su número de control como primera columna.
        $query = Capsule::table($table)
          ->join('estudiantes', 'estudiantes.id', '=', $table . '.estudiante_id')
          ->where('estudiantes.tutor_id', $tutorId);
        $sel   = ['estudiantes.numero_control AS numero_control'];
      }
      foreach ($cols as $c) {
        $sel[] = ($isMain ? '' : $table . '.') . $c . ' AS ' . $c;
      }

      $rows = $query->select($sel)->orderBy('estudiantes.numero_control')->get();
    } catch (\Throwable $e) {
      // Tabla ausente en esta BD: la hoja sale solo con encabezados en
      // vez de abortar la descarga completa.
      error_log("exportar tutorados: falló la tabla {$table}: " . $e->getMessage());
      $rows = [];
    }

    $headers = [];
    foreach ($cols as $c) {
      $headers[] = col_label($c);
    }
    if (!$isMain) {
      array_unshift($headers, col_label('numero_control'));
    }

    $data = [];
    foreach ($rows as $row) {
      $out = [];
      foreach ($cols as $c) {
        $out[] = cell_text($row->$c ?? null, $meta[$c] ?? []);
      }
      if (!$isMain) {
        array_unshift($out, (string) ($row->numero_control ?? ''));
      }
      $data[] = $out;
    }

    if ($first) {
      $sheet = $spreadsheet->getActiveSheet();
      $first = false;
    } else {
      $sheet = $spreadsheet->createSheet();
    }
    write_sheet($sheet, $title, $headers, $data);
  }

  $slug     = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $tutor->nombre), '-'));
  $filename = 'respuestas_tutor_' . ($slug !== '' ? $slug : $tutorId) . '.xlsx';

  $writer = new Xlsx($spreadsheet);
  $stream = fopen('php://memory', 'r+');
  $writer->save($stream);
  rewind($stream);

  return $response
    ->withBody(new \Slim\Psr7\Stream($stream))
    ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
    ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
});


// Endpoint admin: respuestas individuales de un tutorado (vista "Ver
// respuestas" del modal). Devuelve los datos básicos del estudiante más sus
// 6 bloques como {titulo, presente, campos:[{label, valor}]}, reutilizando
// los mismos catálogos (col_label) y formateo (cell_text) del Excel. El
// filtro por tutor impide consultar tutorados de otro tutor.
$app->get('/api/admin/tutores/{id}/tutorados/{nc}', function (Request $request, Response $response, $args) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }
  $tutorId = intval($args['id']);
  $nc      = (string) $args['nc'];
  $est     = Capsule::table('estudiantes')
    ->where('tutor_id', $tutorId)
    ->where('numero_control', $nc)
    ->first();
  if (!$est) {
    $response->getBody()->write(json_encode(['error' => 'Tutorado no encontrado']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
  }

  $bloques = [];
  foreach (data_bloques() as [$title, $table, $skip]) {
    $meta = table_columns($table);
    $cols = array_values(array_diff(array_keys($meta), $skip));
    try {
      $row = $table === 'estudiantes'
        ? Capsule::table('estudiantes')->where('id', $est->id)->first()
        : Capsule::table($table)->where('estudiante_id', $est->id)->first();
    } catch (\Throwable $e) {
      $row = null;
    }
    $campos = [];
    foreach ($cols as $c) {
      $campos[] = [
        'label' => col_label($c),
        'valor' => $row ? cell_text($row->$c ?? null, $meta[$c] ?? []) : '',
      ];
    }
    $bloques[] = ['titulo' => $title, 'presente' => $row !== null, 'campos' => $campos];
  }

  $payload = [
    'estudiante' => [
      'numero_control' => $est->numero_control,
      'nombre_completo' => $est->nombre_completo,
      'periodo_captura' => $est->periodo_captura,
      'capturado' => $est->capturado,
      'updated_at' => (string) ($est->updated_at ?? ''),
    ],
    'bloques' => $bloques,
  ];
  $response->getBody()->write(json_encode($payload));
  return $response->withHeader('Content-Type', 'application/json');
});


// Endpoint admin: limpia LA CAPTURA del tutorado — vacía las 5 tablas de
// respuestas y deja capturado=0 (vuelve a "Pendiente"). La fila base del
// estudiante (preregistro del CSV) se conserva; sirve para rehacer una
// captura hecha mal.
$app->delete('/api/admin/tutores/{id}/tutorados/{nc}/captura', function (Request $request, Response $response, $args) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }
  $tutorId = intval($args['id']);
  $nc      = (string) $args['nc'];
  $est     = Capsule::table('estudiantes')
    ->where('tutor_id', $tutorId)
    ->where('numero_control', $nc)
    ->first();
  if (!$est) {
    $response->getBody()->write(json_encode(['error' => 'Tutorado no encontrado']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
  }

  $borradas = [];
  foreach ([
    'datos_familiares',
    'datos_escolares',
    'habilidades_escolares',
    'datos_medicos',
    'expectativas_ingreso'
  ] as $t) {
    try {
      $borradas[$t] = Capsule::table($t)->where('estudiante_id', $est->id)->delete();
    } catch (\Throwable $e) {
      $borradas[$t] = 0;
    }
  }
  Capsule::table('estudiantes')->where('id', $est->id)->update([
    'capturado' => 0,
    'updated_at' => date('Y-m-d H:i:s'),
  ]);

  $response->getBody()->write(json_encode([
    'status' => 'ok',
    'modo' => 'limpiado',
    'numero_control' => $nc,
    'borradas' => $borradas,
  ]));
  return $response->withHeader('Content-Type', 'application/json');
});


// Endpoint admin: elimina el tutorado COMPLETO (cascada sobre las 5 tablas
// de respuestas). Para registros de prueba o filas subidas por error.
$app->delete('/api/admin/tutores/{id}/tutorados/{nc}', function (Request $request, Response $response, $args) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }
  $tutorId = intval($args['id']);
  $nc      = (string) $args['nc'];
  $est     = Capsule::table('estudiantes')
    ->where('tutor_id', $tutorId)
    ->where('numero_control', $nc)
    ->first();
  if (!$est) {
    $response->getBody()->write(json_encode(['error' => 'Tutorado no encontrado']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
  }

  Capsule::table('estudiantes')->where('id', $est->id)->delete();

  $response->getBody()->write(json_encode([
    'status' => 'ok',
    'modo' => 'eliminado',
    'numero_control' => $nc,
  ]));
  return $response->withHeader('Content-Type', 'application/json');
});


// Endpoint admin: ping rápido para verificar credenciales admin
$app->get('/api/admin/ping', function (Request $request, Response $response) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }
  $response->getBody()->write(json_encode(['status' => 'ok']));
  return $response->withHeader('Content-Type', 'application/json');
});


// Endpoint admin: listar tutores (incluye inactivos)
$app->get('/api/admin/tutores', function (Request $request, Response $response) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }
  $rows = Capsule::table('tutores')->select('id', 'nombre', 'email', 'active')->orderBy('nombre')->get();

  // Desglose de avance por tutor. Una sola consulta agregada (sin N+1).
  // `capturado = 1` cuenta lo capturado; el resto (0 o NULL) queda pendiente.
  $agg      = Capsule::table('estudiantes')
    ->selectRaw('tutor_id, COUNT(*) AS total, SUM(CASE WHEN capturado = 1 THEN 1 ELSE 0 END) AS done')
    ->groupBy('tutor_id')
    ->get();
  $byTutor  = [];
  $sinTutor = ['total' => 0, 'done' => 0];
  foreach ($agg as $a) {
    $bucket = ['total' => (int) $a->total, 'done' => (int) $a->done];
    if ($a->tutor_id === null) {
      $sinTutor = $bucket;   // huérfanos: tutor eliminado (FK SET NULL)
      continue;
    }
    $byTutor[(int) $a->tutor_id] = $bucket;
  }

  $gTotal = 0;
  $gDone  = 0;
  foreach ($rows as $r) {
    $b            = $byTutor[(int) $r->id] ?? ['total' => 0, 'done' => 0];
    $r->total     = $b['total'];
    $r->captured  = $b['done'];
    $r->pending   = $b['total'] - $b['done'];
    $gTotal      += $b['total'];
    $gDone       += $b['done'];
  }
  $gTotal += $sinTutor['total'];
  $gDone  += $sinTutor['done'];

  $resumen = [
    'total' => $gTotal,
    'captured' => $gDone,
    'pending' => $gTotal - $gDone,
    'sin_tutor' => $sinTutor['total'],
    'tutores' => count($rows),
    'tutores_activos' => $rows->where('active', 1)->count(),
  ];

  $response->getBody()->write(json_encode(['tutores' => $rows, 'resumen' => $resumen]));
  return $response->withHeader('Content-Type', 'application/json');
});


// Endpoint admin: crear tutor
$app->post('/api/admin/tutores', function (Request $request, Response $response) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }
  // Acepta JSON (fetch + JSON.stringify) y form-urlencoded (URLSearchParams),
  // mismo patrón que el PUT de abajo.
  $data   = json_decode((string) $request->getBody(), true) ?: $request->getParsedBody();
  $nombre = trim($data['nombre'] ?? '');
  $email  = trim($data['email'] ?? '');
  if ($nombre === '') {
    $response->getBody()->write(json_encode(['error' => 'Nombre requerido']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }
  $id = Capsule::table('tutores')->insertGetId(['nombre' => $nombre, 'email' => $email === '' ? null : $email, 'active' => 1]);
  $response->getBody()->write(json_encode(['status' => 'created', 'id' => $id]));
  return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
});


// Endpoint admin: actualizar tutor
$app->put('/api/admin/tutores/{id}', function (Request $request, Response $response, $args) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }
  $id = intval($args['id']);
  if (!Capsule::table('tutores')->where('id', $id)->exists()) {
    $response->getBody()->write(json_encode(['error' => 'Tutor no encontrado']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
  }
  $body   = json_decode((string) $request->getBody(), true) ?: $request->getParsedBody();
  $update = [];
  if (isset($body['nombre']))
    $update['nombre'] = trim($body['nombre']);
  if (array_key_exists('email', $body))
    $update['email'] = trim($body['email']) ?: null;
  if (isset($body['active']))
    $update['active'] = $body['active'] ? 1 : 0;
  if (empty($update)) {
    $response->getBody()->write(json_encode(['error' => 'Nada que actualizar']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }
  Capsule::table('tutores')->where('id', $id)->update($update);
  $response->getBody()->write(json_encode(['status' => 'updated']));
  return $response->withHeader('Content-Type', 'application/json');
});


// Endpoint admin: eliminar (soft) tutor
$app->delete('/api/admin/tutores/{id}', function (Request $request, Response $response, $args) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }
  $id = intval($args['id']);
  if (!Capsule::table('tutores')->where('id', $id)->exists()) {
    $response->getBody()->write(json_encode(['error' => 'Tutor no encontrado']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
  }
  Capsule::table('tutores')->where('id', $id)->update(['active' => 0]);
  $response->getBody()->write(json_encode(['status' => 'deleted']));
  return $response->withHeader('Content-Type', 'application/json');
});


// --- Gestión de app_settings ----------------------------------------------

// Esquema de las claves conocidas: `type` fija la validación y el enmascarado,
// `form` indica que se gestionan con el formulario del panel. Las claves no
// listadas son texto libre y aparecen en la tabla "Otras claves".
function settings_schema(): array
{
  static $schema = [
  'admin_password' => ['type' => 'password', 'form' => true],
  'form_active' => ['type' => 'bool', 'form' => true],
  'periodo' => ['type' => 'text', 'form' => true],
  'rate_limit_max' => ['type' => 'int', 'form' => true],
  'rate_limit_window_min' => ['type' => 'int', 'form' => true],
  ];
  return $schema;
}

// Metadatos de una clave; por defecto, texto libre fuera del formulario.
function setting_meta(string $key): array
{
  return settings_schema()[$key] ?? ['type' => 'text', 'form' => false];
}

// Longitud en caracteres (no bytes) para validar contra VARCHAR(255).
function setting_length(string $value): int
{
  return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

// GET /api/admin/settings -> todas las claves. La contraseña nunca sale en
// claro: solo se indica si tiene valor.
$app->get('/api/admin/settings', function (Request $request, Response $response) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }

  $settings = [];
  foreach (Capsule::table('app_settings')->orderBy('key')->get() as $row) {
    $meta       = setting_meta($row->key);
    $settings[] = [
      'key' => $row->key,
      'value' => $meta['type'] === 'password' ? null : $row->value,
      'has_value' => $row->value !== null && $row->value !== '',
      'type' => $meta['type'],
      'form' => $meta['form'],
      'updated_at' => $row->updated_at,
    ];
  }

  $response->getBody()->write(json_encode(['settings' => $settings]));
  return $response->withHeader('Content-Type', 'application/json');
});

// PUT /api/admin/settings -> upsert de { "settings": { "clave": "valor", ... } }.
// En text/int, valor vacío = eliminar la clave (vuelve al valor por defecto).
$app->put('/api/admin/settings', function (Request $request, Response $response) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }

  $body = json_decode((string) $request->getBody(), true);
  if (!is_array($body)) {
    $body = $request->getParsedBody();
  }
  $input = is_array($body['settings'] ?? null) ? $body['settings'] : null;
  if ($input === null) {
    $response->getBody()->write(json_encode(['error' => 'Se espera {"settings": {"clave": "valor"}}']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }

  $errors  = [];
  $updated = 0;
  $reset   = 0;

  foreach ($input as $key => $value) {
    $key = trim((string) $key);
    if (!preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $key)) {
      $errors[$key] = 'Clave inválida (letras, números, _ . -)';
      continue;
    }

    $type = setting_meta($key)['type'];

    if ($type === 'password') {
      // En blanco = no tocar. Evita dejar la app sin acceso (check_admin exige
      // un valor no vacío, y "0" también cuenta como vacío).
      if ($value === null || trim((string) $value) === '') {
        continue;
      }
      $value = (string) $value;
      if ($value === '0' || setting_length($value) < 4) {
        $errors[$key] = 'Mínimo 4 caracteres';
        continue;
      }
    } elseif ($type === 'bool') {
      $value = $value ? '1' : '0';
    } elseif ($type === 'int') {
      if ($value === null || trim((string) $value) === '') {
        // En blanco = eliminar para volver al valor por defecto (5 / 10).
        $reset += Capsule::table('app_settings')->where('key', $key)->delete();
        continue;
      }
      $int = filter_var($value, FILTER_VALIDATE_INT);
      if ($int === false || $int < 1) {
        $errors[$key] = 'Debe ser un número entero ≥ 1';
        continue;
      }
      $value = (string) $int;
    } else {
      $value = trim((string) ($value ?? ''));
      if ($value === '') {
        // Texto vacío = quitar la clave (equivale a no configurarla).
        $reset += Capsule::table('app_settings')->where('key', $key)->delete();
        continue;
      }
    }

    if (setting_length($value) > 255) {
      $errors[$key] = 'Máximo 255 caracteres';
      continue;
    }

    Capsule::table('app_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    $updated++;
  }

  if ($errors) {
    $response->getBody()->write(json_encode(['error' => 'Revisa los valores', 'fields' => $errors]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }

  $response->getBody()->write(json_encode(['status' => 'saved', 'updated' => $updated, 'reset' => $reset]));
  return $response->withHeader('Content-Type', 'application/json');
});

// DELETE /api/admin/settings/{key} -> restablecer una clave a su valor por defecto.
$app->delete('/api/admin/settings/{key}', function (Request $request, Response $response, $args) {
  if (!check_admin($request)) {
    $response->getBody()->write(json_encode(['error' => 'Autenticación admin requerida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
  }

  $key = trim((string) ($args['key'] ?? ''));
  if (!preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $key)) {
    $response->getBody()->write(json_encode(['error' => 'Clave inválida']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }
  if ($key === 'admin_password') {
    // Sin esta clave check_admin siempre falla: sería un bloqueo total.
    $response->getBody()->write(json_encode(['error' => 'No se puede eliminar la contraseña admin; cámbiala en su lugar']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }

  if (!Capsule::table('app_settings')->where('key', $key)->delete()) {
    $response->getBody()->write(json_encode(['error' => 'Clave no encontrada']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
  }

  $response->getBody()->write(json_encode(['status' => 'deleted', 'key' => $key]));
  return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/', function (Request $request, Response $response) {
  // Redirige a la página del formulario estático en /public/form.html
  return $response->withHeader('Location', '/form.html')->withStatus(302);
});


// Ruta administrativa estática: /admin -> /admin.html
$app->get('/admin', function (Request $request, Response $response) {
  return $response->withHeader('Location', '/admin.html')->withStatus(302);
});


// --- Manejo de errores y 404 ----------------------------------------------
// Slim\App::run() NO tiene try/catch, así que sin estas dos piezas cualquier
// ruta desconocida o excepción no capturada escapaba como Fatal error de PHP,
// que filtraba rutas, versiones de vendor y rutas internas del contenedor.

// 1) Red de seguridad global: cualquier Throwable pasa a ser una respuesta
//    HTTP con su código real (404, 405, 500...) en vez de un crash.
//    APP_DEBUG=true muestra el detalle completo; en producción déjalo en false.
$debug = filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
$app->addErrorMiddleware($debug, true, $debug);

// 2) Ruta comodín (registrada la última y solo para GET): lo que no coincida
//    con ninguna otra ruta cae aquí y devuelve un 404 legible en lugar de
//    lanzar HttpNotFoundException. JSON para /api/*, página con enlace al
//    panel para el resto.
$app->get('/{path:.*}', function (Request $request, Response $response, $args) {
  $path = '/' . (string) ($args['path'] ?? '');

  // Cortesía: /admin/ (con barra final) también abre el panel.
  if (rtrim($path, '/') === '/admin') {
    return $response->withHeader('Location', '/admin.html')->withStatus(302);
  }

  $wantsJson = str_starts_with($path, '/api/')
    || str_contains($request->getHeaderLine('Accept'), 'application/json');

  $hintAdmin = in_array(rtrim($path, '/'), ['/api/admin', '/api/admin/'], true)
    || str_starts_with(rtrim($path, '/'), '/api/admin/');

  if ($wantsJson) {
    $response->getBody()->write(json_encode([
      'error' => 'Ruta no encontrada',
      'path' => $path,
      'hint' => $hintAdmin
        ? 'El panel de administración está en /admin, no bajo /api/'
        : null,
    ]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
  }

  $hint = '';
  if ($hintAdmin) {
    $hint = '<p>¿Buscabas el <a href="/admin">panel de administración</a>?'
      . ' Vive en <code>/admin</code>, no bajo <code>/api/</code>.</p>';
  }
  $response->getBody()->write(
    '<!doctype html><html lang="es"><head><meta charset="utf-8">'
    . '<title>404 — Página no encontrada</title></head>'
    . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:60px auto;line-height:1.6">'
    . '<h1>404 — Página no encontrada</h1>'
    . '<p>No existe la ruta <code>' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '</code>.</p>'
    . $hint
    . '<p><a href="/">Volver al formulario</a> · <a href="/admin">Panel admin</a></p>'
    . '</body></html>'
  );
  return $response
    ->withHeader('Content-Type', 'text/html; charset=utf-8')
    ->withStatus(404);
});

$app->run();

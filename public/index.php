<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Models\Estudiante;
use App\Models\DatosFamiliares;
use App\Models\DatosEscolares;
use App\Models\HabilidadesEscolares;
use App\Models\DatosMedicos;
use App\Models\ExpectativasIngreso;
use Respect\Validation\Validator as v;

// Valores permitidos (enums) — mantener sincronizados con db/mysql_entrevistas.sql
$ENUM_GENERO              = ['F', 'M'];
$ENUM_ESTADO_CIVIL        = ['SOLTERO', 'CASADO', 'UNION LIBRE', 'OTRO'];
$ENUM_ZONA                = ['RURAL', 'URBANA'];
$ENUM_TIPO_VIVIENDA       = ['PROPIA', 'RENTADA', 'PRESTADA', 'OTRA'];
$ENUM_RENDIMIENTO         = ['MUY BUENO', 'BUENO', 'REGULAR', 'MALO', 'MUY MALO'];
$ENUM_REACCION_PADRES     = ['MUY BIEN', 'NORMAL', 'MUY MAL', 'NO SABEN'];
$ENUM_ESTUDIO_ES          = ['INTERESANTE', 'ABURRIDO', 'UTIL', 'IMPUESTO', 'PASATIEMPO', 'AMIGOS'];
$ENUM_PREFERENCIA_TRABAJO = ['SOLO', 'COMPAÑERO', 'EQUIPO', 'IGUAL'];

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

  $dp = $input['datosPersonales'] ?? [];
  $df = $input['datosFamiliares'] ?? [];
  $de = $input['datosEscolares'] ?? [];
  $dm = $input['datosMedicos'] ?? [];
  $ei = $input['expectativasIngreso'] ?? [];

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
  if (!empty($input['hp_email'] ?? null)) {
    $response->getBody()->write(json_encode(['error' => 'Bad request']));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
  }

  // CSRF token check (token enviado en payload o header X-CSRF-Token)
  $csrfSent   = $input['csrfToken'] ?? $request->getHeaderLine('X-CSRF-Token');
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



  // Campos requeridos mínimos según entrevistas.sql
  $required = [
    // El número de control es obligatorio; el prefijo B/C es opcional
    'numeroControl' => $dp['numeroControl'] ?? $input['numeroControl'] ?? null,
    'nombreCompleto' => $dp['nombreCompleto'] ?? $input['nombreCompleto'] ?? null,
    'fechaNacimiento' => $dp['fechaNacimiento'] ?? $input['fechaNacimiento'] ?? null,
    'lugarNacimiento' => $dp['lugarNacimiento'] ?? $input['lugarNacimiento'] ?? null,
    'domicilioFamiliar' => $dp['domicilioFamiliar'] ?? $input['domicilioFamiliar'] ?? null,
    'localidadFamiliar' => $dp['localidadFamiliar'] ?? $input['localidadFamiliar'] ?? null,
    'codigoPostal' => $dp['codigoPostal'] ?? $input['codigoPostal'] ?? null,
    'zona' => $dp['zona'] ?? $input['zona'] ?? null,
    'tipoVivienda' => $dp['tipoVivienda'] ?? $input['tipoVivienda'] ?? null,
    'telefonoMovil' => $dp['telefonoMovil'] ?? $input['telefonoMovil'] ?? null,
    // Tutor asignado (obligatorio para filtrar registros posteriormente)
    'tutorId' => $dp['tutorId'] ?? $input['tutorId'] ?? null,
    // Familiares
    'numIntegrantesFamilia' => $df['numIntegrantesFamilia'] ?? $input['numIntegrantesFamilia'] ?? null,
    'numHermanos' => $df['numHermanos'] ?? $input['numHermanos'] ?? null,
    'lugarQueOcupa' => $df['lugarQueOcupa'] ?? $input['lugarQueOcupa'] ?? null,
    'vivesCon' => $df['vivesCon'] ?? $input['vivesCon'] ?? null,
    // Escolares
    'institucionProcedencia' => $de['institucionProcedencia'] ?? $input['institucionProcedencia'] ?? null,
    'localidadEscuela' => $de['localidad'] ?? $input['localidadEscuela'] ?? null,
    'generacionEgreso' => $de['generacionEgreso'] ?? $input['generacionEgreso'] ?? null,
    'promedio' => $de['promedio'] ?? $input['promedio'] ?? null,
    'rendimientoEscolar' => $de['rendimientoEscolar'] ?? $input['rendimientoEscolar'] ?? null,
    'reaccionPadresCalificaciones' => $de['reaccionPadresCalificaciones'] ?? $input['reaccionPadresCalificaciones'] ?? null
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

  // Verificar que el tutor enviado exista y esté activo
  $tutorIdCheck = isset($dp['tutorId']) ? intval($dp['tutorId']) : (isset($input['tutorId']) ? intval($input['tutorId']) : null);
  if (empty($tutorIdCheck) || !Capsule::table('tutores')->where('id', $tutorIdCheck)->where('active', 1)->exists()) {
    $response->getBody()->write(json_encode(['error' => 'Tutor inválido o inactivo', 'fields' => ['tutorId' => 'Tutor inválido o inactivo']]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }

  // Validaciones con Respect/Validation
  $errors = [];
  // nombre
  try {
    v::stringType()->notEmpty()->length(1, 150)->assert($dp['nombreCompleto'] ?? '');
  } catch (\Throwable $e) {
    $errors['nombreCompleto'] = $e->getMessage();
  }
  // fecha
  try {
    v::date('Y-m-d')->assert($dp['fechaNacimiento'] ?? '');
  } catch (\Throwable $e) {
    $errors['fechaNacimiento'] = $e->getMessage();
  }
  // telefono
  try {
    v::digit()->length(7, 15)->assert(preg_replace('/\D/', '', $dp['telefonoMovil'] ?? ''));
  } catch (\Throwable $e) {
    $errors['telefonoMovil'] = $e->getMessage();
  }
  // Enums: validar opciones contra listas permitidas
  $allowedGenero       = ['F', 'M', 'NB'];
  $allowedEstadoCivil  = ['SOLTERO', 'CASADO', 'UNION LIBRE', 'OTRO'];
  $allowedZona         = ['RURAL', 'URBANA'];
  $allowedTipoVivienda = ['PROPIA', 'RENTADA', 'PRESTADA', 'OTRA'];
  try {
    v::in($allowedGenero)->assert(strtoupper($dp['genero'] ?? ''));
  } catch (\Throwable $e) {
    $errors['genero'] = 'Valor inválido para género';
  }
  try {
    v::in($allowedEstadoCivil)->assert(strtoupper($dp['estadoCivil'] ?? ''));
  } catch (\Throwable $e) {
    $errors['estadoCivil'] = 'Valor inválido para estado civil';
  }
  try {
    v::in($allowedZona)->assert(strtoupper($dp['zona'] ?? ''));
  } catch (\Throwable $e) {
    $errors['zona'] = 'Valor inválido para zona';
  }
  try {
    v::in($allowedTipoVivienda)->assert(strtoupper($dp['tipoVivienda'] ?? ''));
  } catch (\Throwable $e) {
    $errors['tipoVivienda'] = 'Valor inválido para tipo de vivienda';
  }
  // numero de control (obligatorio). El prefijo [B|C] es opcional: [B|C]?YY69####
  if (empty($dp['numeroControl'])) {
    $errors['numeroControl'] = 'Número de control requerido';
  } else {
    try {
      v::regex('/^(?:[BC])?\d{2}69\d{4}$/i')->assert($dp['numeroControl']);
    } catch (\Throwable $e) {
      $errors['numeroControl'] = 'Formato inválido de número de control';
    }
  }
  // datos escolares: rendimiento y reaccion de padres deben corresponder a enums
  $allowedRend     = ['MUY BUENO', 'BUENO', 'REGULAR', 'MALO', 'MUY MALO'];
  $allowedReaccion = ['MUY BIEN', 'NORMAL', 'MUY MAL', 'NO SABEN'];
  try {
    v::in($allowedRend)->assert(strtoupper($de['rendimientoEscolar'] ?? ''));
  } catch (\Throwable $e) {
    $errors['rendimientoEscolar'] = 'Valor inválido para rendimiento escolar';
  }
  try {
    v::in($allowedReaccion)->assert(strtoupper($de['reaccionPadresCalificaciones'] ?? ''));
  } catch (\Throwable $e) {
    $errors['reaccionPadresCalificaciones'] = 'Valor inválido para reacción de padres';
  }
  // Promedio: validar rango numérico (0-10)
  try {
    v::numericVal()->between(0, 10)->assert(isset($de['promedio']) ? $de['promedio'] : null);
  } catch (\Throwable $e) {
    $errors['promedio'] = 'Promedio inválido (debe ser numérico entre 0 y 10)';
  }
  // Expectativas: estudio_es enum
  $allowedEstudioEs = ['INTERESANTE', 'ABURRIDO', 'UTIL', 'IMPUESTO', 'PASATIEMPO', 'AMIGOS'];
  try {
    v::in($allowedEstudioEs)->assert(strtoupper($ei['estudioEs'] ?? ''));
  } catch (\Throwable $e) {
    $errors['estudioEs'] = 'Valor inválido para "Para ti el estudio es"';
  }
  // Habilidades: valores deben ser B, N o M
  $habAllowed = ['B', 'N', 'M'];
  $habKeys    = ['comprensionLectora', 'comprensionOral', 'resolucionProblemas', 'expresionOral', 'expresionEscrita', 'vocabulario', 'calculo', 'expresionGrafica', 'ortografia'];
  foreach ($habKeys as $hk) {
    $val = $de['habilidades'][$hk] ?? ($de['hab_' . $hk] ?? null);
    if ($val !== null && $val !== '') {
      try {
        v::in($habAllowed)->assert(strtoupper($val));
      } catch (\Throwable $e) {
        $errors['hab_' . $hk] = 'Valor inválido para ' . $hk;
      }
    }
  }
  if (!empty($errors)) {
    $response->getBody()->write(json_encode(['error' => 'Errores de validación', 'fields' => $errors]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }
  if (!empty($missing)) {
    $response->getBody()->write(json_encode(['error' => 'Faltan campos requeridos', 'missing' => $missing]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
  }

  try {
    // Capturar IP para auditoría
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Guardar todo en transacción
    Capsule::connection()->transaction(function () use ($dp, $df, $de, $dm, $ei, &$response, $ip) {
      // Verificar unicidad de numero_control si fue enviado
      if (!empty($dp['numeroControl'])) {
        $exists = Capsule::table('estudiantes')->where('numero_control', $dp['numeroControl'])->exists();
        if ($exists) {
          // Lanzar excepción para abortar la transacción y devolver 409
          throw new \Exception('Número de control ya registrado');
        }
      }

      // Crear estudiante (una sola vez)
      // Leer periodo de captura activo (opcional)
      $capturePeriod = Capsule::table('app_settings')->where('key', 'periodo')->value('value');

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
        'tutor_id' => isset($dp['tutorId']) ? intval($dp['tutorId']) : null,
        'telefono_movil' => $dp['telefonoMovil'] ?? null,
        'habla_otra_lengua' => !empty($dp['hablaOtraLengua']) ? 1 : 0,
        'cual_lengua' => $dp['cualLengua'] ?? null,
        'periodo_captura' => $capturePeriod ?? null
      ]);

      // Datos familiares
      DatosFamiliares::create(array_merge(['estudiante_id' => $estudiante->id], [
        'padre_nombre' => $df['padre']['nombre'] ?? ($df['padreNombre'] ?? null),
        'padre_vive' => !empty($df['padre']['vive']) ? 1 : (!empty($df['padreVive']) ? 1 : (isset($df['padreVive']) ? intval($df['padreVive']) : null)),
        'padre_edad' => $df['padre']['edad'] ?? ($df['padreEdad'] ?? null),
        'padre_profesion' => $df['padre']['profesion'] ?? ($df['padreProfesion'] ?? null),
        'padre_nivel_estudios' => $df['padre']['nivelEstudios'] ?? ($df['padreNivelEstudios'] ?? null),
        'padre_ocupacion' => $df['padre']['ocupacion'] ?? ($df['padreOcupacion'] ?? null),
        'madre_nombre' => $df['madre']['nombre'] ?? ($df['madreNombre'] ?? null),
        'madre_vive' => !empty($df['madre']['vive']) ? 1 : (!empty($df['madreVive']) ? 1 : (isset($df['madreVive']) ? intval($df['madreVive']) : null)),
        'madre_edad' => $df['madre']['edad'] ?? ($df['madreEdad'] ?? null),
        'madre_profesion' => $df['madre']['profesion'] ?? ($df['madreProfesion'] ?? null),
        'madre_nivel_estudios' => $df['madre']['nivelEstudios'] ?? ($df['madreNivelEstudios'] ?? null),
        'madre_ocupacion' => $df['madre']['ocupacion'] ?? ($df['madreOcupacion'] ?? null),
        'num_integrantes_familia' => $df['numIntegrantesFamilia'] ?? null,
        'num_hermanos' => $df['numHermanos'] ?? null,
        'lugar_que_ocupa' => $df['lugarQueOcupa'] ?? null,
        'vives_con' => $df['vivesCon'] ?? null,
        'situacion_especial' => $df['situacionEspecial'] ?? null,
        'relacion_padres' => $df['relacionPadres'] ?? null,
        'trabaja_actualmente' => !empty($df['trabajaActualmente']) ? 1 : 0,
        'horas_trabajo' => $df['horasTrabajo'] ?? null,
        'empresa_trabajo' => $df['empresaTrabajo'] ?? null,
        'motivo_trabajo' => $df['motivoTrabajo'] ?? null,
        'tiempo_traslado_escuela' => $df['tiempoTrasladoEscuela'] ?? null,
        'apoyo_economico' => $df['apoyoEconomico'] ?? null,
        'ingreso_mensual_familiar' => $df['ingresoMensualFamiliar'] ?? null
      ]));

      // Datos escolares
      DatosEscolares::create(array_merge(['estudiante_id' => $estudiante->id], [
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
      ]));

      // Habilidades
      HabilidadesEscolares::create(array_merge(['estudiante_id' => $estudiante->id], [
        'comprension_lectora' => $de['habilidades']['comprensionLectora'] ?? ($de['hab_comprensionLectora'] ?? null),
        'comprension_oral' => $de['habilidades']['comprensionOral'] ?? ($de['hab_comprensionOral'] ?? null),
        'resolucion_problemas' => $de['habilidades']['resolucionProblemas'] ?? ($de['hab_resolucionProblemas'] ?? null),
        'expresion_oral' => $de['habilidades']['expresionOral'] ?? ($de['hab_expresionOral'] ?? null),
        'expresion_escrita' => $de['habilidades']['expresionEscrita'] ?? ($de['hab_expresionEscrita'] ?? null),
        'vocabulario' => $de['habilidades']['vocabulario'] ?? ($de['hab_vocabulario'] ?? null),
        'calculo' => $de['habilidades']['calculo'] ?? ($de['hab_calculo'] ?? null),
        'expresion_grafica' => $de['habilidades']['expresionGrafica'] ?? ($de['hab_expresionGrafica'] ?? null),
        'ortografia' => $de['habilidades']['ortografia'] ?? ($de['hab_ortografia'] ?? null)
      ]));

      // Datos medicos
      DatosMedicos::create(array_merge(['estudiante_id' => $estudiante->id], [
        'padece_enfermedad' => !empty($dm['padeceEnfermedad']) ? 1 : 0,
        'cual_enfermedad' => $dm['cualEnfermedad'] ?? null,
        'condicion_fisica' => !empty($dm['condicionFisica']) ? 1 : 0,
        'cual_condicion' => $dm['cualCondicion'] ?? null,
        'toma_medicacion' => !empty($dm['tomaMedicacion']) ? 1 : 0,
        'cual_medicacion' => $dm['cualMedicacion'] ?? null,
        'ha_sido_operado' => !empty($dm['haSidoOperado']) ? 1 : 0,
        'de_que_operacion' => $dm['deQueOperacion'] ?? null
      ]));

      // Expectativas
      ExpectativasIngreso::create(array_merge(['estudiante_id' => $estudiante->id], [
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
        // preferenciaEnClase viene desde el frontend; normalizar a valores permitidos
        'preferencia_trabajo' => (function ($v) {
          if (empty($v))
            return null;
          $map = [
            'ME_DA_IGUAL' => 'IGUAL',
            'IGUAL' => 'IGUAL',
            'COMPANERO' => 'COMPAÑERO',
            'COMPAÑERO' => 'COMPAÑERO',
            'SOLO' => 'SOLO',
            'EQUIPO' => 'EQUIPO'
          ];
          $vk  = strtoupper(str_replace(' ', '_', (string) $v));
          return $map[$vk] ?? null;
        })($ei['preferenciaEnClase'] ?? ($ei['preferenciaTrabajo'] ?? null)),
        'tiempo_estudio_casa' => $ei['tiempoEstudioCasa'] ?? null,
        'forma_pasartiempo' => $ei['formaPasarTiempo'] ?? null,
        'forma_hacer_amigos' => $ei['formaHacerAmigos'] ?? null,
        'cuenta_lugar_adecuado' => !empty($ei['cuentaLugarAdecuado']) ? 1 : 0,
        'prio_explicacion_clara' => $ei['prioridadesProfesor']['explicacionClara'] ?? ($ei['prio_explicacionClara'] ?? null),
        'prio_entienda_jovenes' => $ei['prioridadesProfesor']['entiendaJovenes'] ?? ($ei['prio_entiendaJovenes'] ?? null),
        'prio_justo_evaluar' => $ei['prioridadesProfesor']['justoEvaluar'] ?? ($ei['prio_justoEvaluar'] ?? null),
        'prio_permita_preguntar' => $ei['prioridadesProfesor']['permitaPreguntar'] ?? ($ei['prio_permitaPreguntar'] ?? null),
        'prio_respete_e_imponga' => $ei['prioridadesProfesor']['respeteEImponga'] ?? ($ei['prio_respeteEImponga'] ?? null),
        'prio_no_se_enoje' => $ei['prioridadesProfesor']['noSeEnoje'] ?? ($ei['prio_noSeEnoje'] ?? null),
        'prio_otra' => $ei['prio_otra'] ?? null
      ]));

      // Set response data
      // Registrar intento exitoso en submit_logs
      Capsule::table('submit_logs')->insert(['ip' => $ip]);

      $response->getBody()->write(json_encode(['status' => 'success', 'id' => $estudiante->id]));
    });

    return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
  } catch (\Exception $e) {
    $response->getBody()->write(json_encode(['error' => 'Error al guardar: ' . $e->getMessage()]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
  }
});


// RUTA: Verificar existencia de número de control (antes de mostrar formulario)
$app->get('/api/estudiantes/check', function (Request $request, Response $response) use ($ENUM_GENERO) {
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
  $response->getBody()->write(json_encode(['exists' => $exists]));
  return $response->withHeader('Content-Type', 'application/json');
});

// Exportar estudiantes (registros guardados con payload JSON)
$app->get('/api/estudiantes/exportar', function (Request $request, Response $response) {
  $estudiantes = Estudiante::all();

  $spreadsheet = new Spreadsheet();
  $sheet       = $spreadsheet->getActiveSheet();

  $sheet->setCellValue('A1', 'ID');
  $sheet->setCellValue('B1', 'Nombre Completo');
  $sheet->setCellValue('C1', 'Fecha Nacimiento');
  $sheet->setCellValue('D1', 'Teléfono');
  $sheet->setCellValue('E1', 'Payload (JSON)');

  $fila = 2;
  foreach ($estudiantes as $e) {
    $sheet->setCellValue('A' . $fila, $e->id);
    $sheet->setCellValue('B' . $fila, $e->nombre_completo);
    $sheet->setCellValue('C' . $fila, $e->fecha_nacimiento);
    $sheet->setCellValue('D' . $fila, $e->telefono_movil);
    $sheet->setCellValue('E' . $fila, json_encode($e->payload, JSON_UNESCAPED_UNICODE));
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

$app->get('/', function (Request $request, Response $response) {
  // Redirige a la página del formulario estático en /public/form.html
  return $response->withHeader('Location', '/form.html')->withStatus(302);
});

$app->run();

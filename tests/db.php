<?php
/**
 * Herramienta de apoyo para tests/test_form.py.
 *
 * Se ejecuta SIEMPRE dentro del contenedor de la app (el volumen monta el repo):
 *   docker exec <contenedor> php tests/db.php <comando> [...]
 *
 * Comandos:
 *   verify <numero_control>              -> JSON con las 6 tablas del estudiante
 *   seed <tutor_id> <numero_control>     -> crea un preregistro (capturado=0)
 *   cleanup <tutor_id>                   -> borra los estudiantes de ese tutor
 *                                            (las 5 tablas hijas caen en cascada)
 *   nc-delete <numero_control>           -> borra por numero_control, sin depender
 *                                            del tutor asignado
 *   logs-window <min>                    -> borra los submit_logs de los últimos
 *                                            <min> minutos (contador del rate limit)
 *   tutor-delete <id>                    -> borra físicamente un tutor de prueba
 */

use Illuminate\Database\Capsule\Manager as Capsule;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// $_ENV puede no poblarse en CLI según variables_order; getenv() es el
// respaldo (compose inyecta estas variables vía env_file).
$env = function ($k, $default = null) {
  $v = $_ENV[$k] ?? getenv($k);
  return ($v === false || $v === null) ? $default : $v;
};

$capsule = new Capsule;
$capsule->addConnection([
  'driver'    => 'mysql',
  'host'      => $env('DB_HOST'),
  'database'  => $env('DB_NAME'),
  'username'  => $env('DB_USER'),
  'password'  => $env('DB_PASS'),
  'port'      => $env('DB_PORT', 3306),
  'charset'   => 'utf8mb4',
  'collation' => 'utf8mb4_unicode_ci',
  'prefix'    => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$cmd    = $argv[1] ?? '';
$arg    = $argv[2] ?? '';
$arg2   = $argv[3] ?? '';

$out = function ($data) {
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
};

try {
  switch ($cmd) {
    case 'verify':
      // Devuelve todas las filas relacionadas con un numero_control.
      $est = Capsule::table('estudiantes')->where('numero_control', $arg)->first();
      if (!$est) {
        fwrite(STDERR, "No existe el estudiante {$arg}\n");
        exit(1);
      }
      $id   = $est->id;
      $rest = [
        'estudiantes'          => $est,
        'datos_familiares'     => Capsule::table('datos_familiares')->where('estudiante_id', $id)->first(),
        'datos_escolares'      => Capsule::table('datos_escolares')->where('estudiante_id', $id)->first(),
        'habilidades_escolares' => Capsule::table('habilidades_escolares')->where('estudiante_id', $id)->first(),
        'datos_medicos'        => Capsule::table('datos_medicos')->where('estudiante_id', $id)->first(),
        'expectativas_ingreso' => Capsule::table('expectativas_ingreso')->where('estudiante_id', $id)->first(),
      ];
      $out($rest);
      break;

    case 'seed':
      // Preregistro tal como lo deja la subida de CSV: capturado = 0.
      $id = Capsule::table('estudiantes')->insertGetId([
        'numero_control'   => $arg2,
        'tutor_id'         => (int) $arg,
        'nombre_completo'  => 'PREREGISTRO TEST',
        'capturado'        => 0,
        'periodo_captura'  => 'TEST',
      ]);
      $out(['seeded' => true, 'id' => $id, 'numero_control' => $arg2]);
      break;

    case 'cleanup':
      // El tutor de prueba es el marcador: solo borra lo que creó la suite.
      $n = Capsule::table('estudiantes')->where('tutor_id', (int) $arg)->delete();
      $out(['deleted_students' => $n]);
      break;

    case 'nc-delete':
      // Borra por numero_control (las 5 tablas hijas caen en cascada). Evita
      // depender del tutor: un NC de prueba puede terminar reasignado a un
      // tutor real y cleanup <tutor_id> nunca debe apuntar a tutores reales.
      $n = Capsule::table('estudiantes')->where('numero_control', $arg)->delete();
      $out(['deleted_students' => $n]);
      break;

    case 'counts':
      // Totales globales: para comprobar que la limpieza no dejó residuos
      // ni tocó datos reales.
      $out([
        'students' => Capsule::table('estudiantes')->count(),
        'tutores'  => Capsule::table('tutores')->count(),
        'logs'     => Capsule::table('submit_logs')->count(),
      ]);
      break;

    case 'logs-window':
      // Misma ventana que usa el rate limiter (index.php:382): al borrarla,
      // el contador por IP vuelve a cero antes de probar el límite.
      $mins  = max(0, (int) $arg);
      $since = date('Y-m-d H:i:s', time() - $mins * 60);
      $n     = Capsule::table('submit_logs')->where('created_at', '>=', $since)->delete();
      $out(['deleted_logs' => $n]);
      break;

    case 'tutor-delete':
      // Sin estudiantes que lo referencien (hizo cleanup antes); si quedara
      // alguno, la FK es ON DELETE SET NULL y no falla.
      $n = Capsule::table('tutores')->where('id', (int) $arg)->delete();
      $out(['deleted_tutors' => $n]);
      break;

    default:
      fwrite(STDERR, "Comando desconocido: {$cmd}\n");
      exit(2);
  }
} catch (Throwable $e) {
  fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
  exit(1);
}

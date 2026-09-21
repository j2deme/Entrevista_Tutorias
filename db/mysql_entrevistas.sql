-- Esquema MySQL/MariaDB derivado de entrevistas.sql (tablas normalizadas)
CREATE DATABASE IF NOT EXISTS `entrevista_tutorias` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `entrevista_tutorias`;

-- Tabla principal: estudiantes
CREATE TABLE IF NOT EXISTS `estudiantes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `numero_control` VARCHAR(9) NOT NULL,
  `nombre_completo` VARCHAR(150) DEFAULT NULL,
  `fecha_nacimiento` DATE DEFAULT NULL,
  `lugar_nacimiento` VARCHAR(100) DEFAULT NULL,
  `edad` INT DEFAULT NULL,
  `genero` ENUM('F','M','NB') DEFAULT NULL,
  `estado_civil` ENUM('SOLTERO','CASADO','UNION LIBRE','DIVORCIADO','VIUDO','OTRO') DEFAULT NULL,
  `domicilio_familiar` TEXT DEFAULT NULL,
  `localidad_familiar` VARCHAR(100) DEFAULT NULL,
  `codigo_postal` VARCHAR(10) DEFAULT NULL,
  `zona` ENUM('RURAL','URBANA') DEFAULT NULL,
  `tipo_vivienda` ENUM('PROPIA','RENTADA','PRESTADA','OTRA') DEFAULT NULL,
  `tipo_vivienda_otro` VARCHAR(100) DEFAULT NULL,
  `telefono_movil` VARCHAR(20) DEFAULT NULL,
  `habla_otra_lengua` TINYINT(1) DEFAULT 0,
  `cual_lengua` VARCHAR(50) DEFAULT NULL,
  `usa_transporte_publico` TINYINT(1) DEFAULT 0,
  `tiempo_traslado_transporte` VARCHAR(50) DEFAULT NULL,
  `costo_transporte` DECIMAL(8,2) DEFAULT NULL,
  `tutor_id` INT UNSIGNED DEFAULT NULL,
  `periodo_captura` VARCHAR(50) DEFAULT NULL,
  `capturado` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_numero_control` (`numero_control`),
  KEY `idx_tutor_id` (`tutor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- periodo_captura se define en la tabla `estudiantes` (si se requiere almacenar el periodo activo)

-- Datos familiares y socioeconómicos
CREATE TABLE IF NOT EXISTS `datos_familiares` (
  `estudiante_id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `padre_nombre` VARCHAR(150) DEFAULT NULL,
  `padre_vive` TINYINT(1) DEFAULT NULL,
  `padre_edad` INT DEFAULT NULL,
  `padre_nivel_estudios` VARCHAR(100) DEFAULT NULL,
  `padre_ocupacion` VARCHAR(100) DEFAULT NULL,
  `madre_nombre` VARCHAR(150) DEFAULT NULL,
  `madre_vive` TINYINT(1) DEFAULT NULL,
  `madre_edad` INT DEFAULT NULL,
  `madre_nivel_estudios` VARCHAR(100) DEFAULT NULL,
  `madre_ocupacion` VARCHAR(100) DEFAULT NULL,
  `num_integrantes_familia` INT DEFAULT NULL,
  `num_hermanos` INT DEFAULT NULL,
  `lugar_que_ocupa` INT DEFAULT NULL,
  `vives_con` VARCHAR(50) DEFAULT NULL,
  `situacion_especial` TEXT DEFAULT NULL,
  `relacion_padres` VARCHAR(20) DEFAULT NULL,
  `trabaja_actualmente` TINYINT(1) DEFAULT 0,
  `horas_trabajo` INT DEFAULT NULL,
  `empresa_trabajo` VARCHAR(100) DEFAULT NULL,
  `motivo_trabajo` VARCHAR(255) DEFAULT NULL,
  `apoyo_economico` VARCHAR(50) DEFAULT NULL,
  `ingreso_mensual_familiar` DECIMAL(10,2) DEFAULT NULL,
  FOREIGN KEY (`estudiante_id`) REFERENCES `estudiantes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos escolares
CREATE TABLE IF NOT EXISTS `datos_escolares` (
  `estudiante_id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `institucion_procedencia` VARCHAR(150) NOT NULL,
  `localidad` VARCHAR(100) NOT NULL,
  `generacion_egreso` VARCHAR(20) NOT NULL,
  `promedio` DECIMAL(4,2) NOT NULL,
  `rendimiento_escolar` ENUM('MUY_BUENO','BUENO','REGULAR','MALO','MUY_MALO') NOT NULL,
  `reprobado_curso` TINYINT(1) DEFAULT 0,
  `causa_reprobacion` TEXT DEFAULT NULL,
  `satisfecho_resultados` TINYINT(1) DEFAULT 1,
  `motivo_satisfaccion` TEXT DEFAULT NULL,
  `ha_estado_becado` TINYINT(1) DEFAULT 0,
  `grado_beca` VARCHAR(50) DEFAULT NULL,
  `tipo_beca` VARCHAR(50) DEFAULT NULL,
  `materias_favoritas` TEXT DEFAULT NULL,
  `reaccion_padres_calificaciones` ENUM('MUY_BIEN','NORMAL','MUY_MAL','NO_SABEN') DEFAULT NULL,
  FOREIGN KEY (`estudiante_id`) REFERENCES `estudiantes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Habilidades escolares
-- Los valores del formulario llegan como códigos (B, N, M) y el backend los
-- traduce a palabras ('Bueno','Normal','Malo') antes de insertar: ver
-- habilidad_to_db() en public/index.php.
CREATE TABLE IF NOT EXISTS `habilidades_escolares` (
  `estudiante_id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `comprension_lectora` ENUM('Bueno','Normal','Malo') DEFAULT NULL,
  `comprension_oral` ENUM('Bueno','Normal','Malo') DEFAULT NULL,
  `resolucion_problemas` ENUM('Bueno','Normal','Malo') DEFAULT NULL,
  `expresion_oral` ENUM('Bueno','Normal','Malo') DEFAULT NULL,
  `expresion_escrita` ENUM('Bueno','Normal','Malo') DEFAULT NULL,
  `vocabulario` ENUM('Bueno','Normal','Malo') DEFAULT NULL,
  `calculo` ENUM('Bueno','Normal','Malo') DEFAULT NULL,
  `expresion_grafica` ENUM('Bueno','Normal','Malo') DEFAULT NULL,
  `ortografia` ENUM('Bueno','Normal','Malo') DEFAULT NULL,
  FOREIGN KEY (`estudiante_id`) REFERENCES `estudiantes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos médicos
CREATE TABLE IF NOT EXISTS `datos_medicos` (
  `estudiante_id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `padece_enfermedad` TINYINT(1) DEFAULT 0,
  `cual_enfermedad` VARCHAR(100) DEFAULT NULL,
  `condicion_fisica` TINYINT(1) DEFAULT 0,
  `cual_condicion` VARCHAR(100) DEFAULT NULL,
  `toma_medicacion` TINYINT(1) DEFAULT 0,
  `cual_medicacion` VARCHAR(100) DEFAULT NULL,
  `ha_sido_operado` TINYINT(1) DEFAULT 0,
  `de_que_operacion` VARCHAR(100) DEFAULT NULL,
  FOREIGN KEY (`estudiante_id`) REFERENCES `estudiantes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expectativas y adaptación académica
CREATE TABLE IF NOT EXISTS `expectativas_ingreso` (
  `estudiante_id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `carrera_gusta` TINYINT(1) DEFAULT 0,
  `que_mas_atrae` TEXT DEFAULT NULL,
  `tiene_preocupacion_curso` TINYINT(1) DEFAULT 0,
  `que_preocupa` TEXT DEFAULT NULL,
  `estudio_es` ENUM('INTERESANTE','ABURRIDO','UTIL','IMPUESTO','PASATIEMPO','AMIGOS') DEFAULT NULL,
  `forma_apoyo_institucion` TEXT DEFAULT NULL,
  `desea_apoyo_institucional` TINYINT(1) DEFAULT 0,
  `tipo_apoyo` VARCHAR(100) DEFAULT NULL,
  `pasatiempo_favorito` TEXT DEFAULT NULL,
  `causa_problemas_estudio` TEXT DEFAULT NULL,
  `preferencia_trabajo` ENUM('SOLO','COMPAÑERO','EQUIPO','IGUAL') DEFAULT NULL,
  `forma_pasartiempo` VARCHAR(150) DEFAULT NULL,
  `forma_hacer_amigos` VARCHAR(150) DEFAULT NULL,
  `tiempo_estudio_casa` VARCHAR(50) DEFAULT NULL,
  `cuenta_lugar_adecuado` TINYINT(1) DEFAULT 0,
  `prio_explicacion_clara` INT DEFAULT NULL,
  `prio_entienda_jovenes` INT DEFAULT NULL,
  `prio_justo_evaluar` INT DEFAULT NULL,
  `prio_permita_preguntar` INT DEFAULT NULL,
  `prio_respete_e_imponga` INT DEFAULT NULL,
  `prio_no_se_enoje` INT DEFAULT NULL,
  `prio_otra` TEXT DEFAULT NULL,
  FOREIGN KEY (`estudiante_id`) REFERENCES `estudiantes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de tutores para asignar a estudiantes (permite filtrar por tutor)
CREATE TABLE IF NOT EXISTS `tutores` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `active` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FK tutor -> estudiantes (opcional)
ALTER TABLE `estudiantes`
  ADD CONSTRAINT `fk_estudiantes_tutor` FOREIGN KEY (`tutor_id`) REFERENCES `tutores`(`id`) ON DELETE SET NULL;

-- Logs de envíos para protección contra spam (rate limiting)
-- El índice (ip, created_at) respalda la consulta de ventana del rate limit.
CREATE TABLE IF NOT EXISTS `submit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `ip` VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_ip_created` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuración de la aplicación
-- Claves conocidas:
--   admin_password        -> contraseña admin (endpoints /api/admin/*)
--   form_active           -> '1' habilita la captura, otro valor la cierra
--   periodo               -> etiqueta del periodo de captura activo
--   rate_limit_max        -> envíos máximos por IP en la ventana (defecto 5)
--   rate_limit_window_min -> tamaño de la ventana en minutos (defecto 10)
CREATE TABLE IF NOT EXISTS `app_settings` (
  `key` VARCHAR(100) NOT NULL PRIMARY KEY,
  `value` VARCHAR(255) DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ejemplo: establecer contraseña admin (texto plano) para proteger endpoints admin
-- INSERT INTO app_settings (`key`, `value`) VALUES ('admin_password', 'mi_contraseña_admin');

-- Ejemplo: parámetros del rate limiting (si no existen, el backend usa 5 envíos / 10 min)
-- INSERT INTO app_settings (`key`, `value`)
--   VALUES ('rate_limit_max', '5'), ('rate_limit_window_min', '10')
--   ON DUPLICATE KEY UPDATE `value` = `value`;

-- ===========================================================================
-- Ajustes para bases de datos que YA existían
-- ===========================================================================
-- Los CREATE TABLE de arriba usan IF NOT EXISTS, así que NO modifican tablas
-- ya creadas. Este bloque deja la BD en el mismo estado que un alta nueva y es
-- idempotente: puede ejecutarse cuantas veces se quiera, sobre una BD recién
-- creada o sobre una que venía con el esquema anterior.

-- 1) Enums de `datos_escolares`: valores con espacios -> snake_case, que es lo
--    que envía public/form.html y lo que valida public/index.php (ver
--    enums() en public/index.php).
--    Se pasa por VARCHAR para no perder filas al redefinir el ENUM.
ALTER TABLE `datos_escolares`
  MODIFY COLUMN `rendimiento_escolar` VARCHAR(20) NOT NULL,
  MODIFY COLUMN `reaccion_padres_calificaciones` VARCHAR(20) DEFAULT NULL;

UPDATE `datos_escolares`
   SET `rendimiento_escolar` = REPLACE(`rendimiento_escolar`, ' ', '_')
 WHERE `rendimiento_escolar` IN ('MUY BUENO', 'MUY MALO');

UPDATE `datos_escolares`
   SET `reaccion_padres_calificaciones` = REPLACE(`reaccion_padres_calificaciones`, ' ', '_')
 WHERE `reaccion_padres_calificaciones` IN ('MUY BIEN', 'MUY MAL', 'NO SABEN');

ALTER TABLE `datos_escolares`
  MODIFY COLUMN `rendimiento_escolar` ENUM('MUY_BUENO','BUENO','REGULAR','MALO','MUY_MALO') NOT NULL,
  MODIFY COLUMN `reaccion_padres_calificaciones` ENUM('MUY_BIEN','NORMAL','MUY_MAL','NO_SABEN') DEFAULT NULL;

-- 2) Índice que consulta el rate limiting en `submit_logs`. Se añade solo si
--    falta, para no chocar con "Duplicate key name".
--    Si el gestor no admite PREPARE, ejecuta a mano:
--    ALTER TABLE `submit_logs` ADD INDEX `idx_ip_created` (`ip`, `created_at`);
SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name   = 'submit_logs'
    AND index_name   = 'idx_ip_created'
);
SET @ddl := IF(@idx_exists = 0,
  'ALTER TABLE `submit_logs` ADD INDEX `idx_ip_created` (`ip`, `created_at`)',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) Eliminar la columna redundante `datos_familiares.tiempo_traslado_escuela`.
--    El tiempo de traslado se recaba una sola vez en
--    `estudiantes.tiempo_traslado_transporte`, que es el campo que especifica
--    form.md (los dos tenían rangos distintos y no eran reconciliables).
--    Se elimina solo si existe, para no romper un alta nueva.
--    AVISO: los valores que tenga se descartan. Si quieres conservarlos:
--      CREATE TABLE respaldo_tiempo_traslado AS
--        SELECT estudiante_id, tiempo_traslado_escuela FROM datos_familiares
--        WHERE tiempo_traslado_escuela IS NOT NULL;
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name  = 'datos_familiares'
    AND column_name = 'tiempo_traslado_escuela'
);
SET @ddl2 := IF(@col_exists > 0,
  'ALTER TABLE `datos_familiares` DROP COLUMN `tiempo_traslado_escuela`',
  'SELECT 1');
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

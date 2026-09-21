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
  `tiempo_traslado_escuela` VARCHAR(20) DEFAULT NULL,
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
  `rendimiento_escolar` ENUM('MUY BUENO','BUENO','REGULAR','MALO','MUY MALO') NOT NULL,
  `reprobado_curso` TINYINT(1) DEFAULT 0,
  `causa_reprobacion` TEXT DEFAULT NULL,
  `satisfecho_resultados` TINYINT(1) DEFAULT 1,
  `motivo_satisfaccion` TEXT DEFAULT NULL,
  `ha_estado_becado` TINYINT(1) DEFAULT 0,
  `grado_beca` VARCHAR(50) DEFAULT NULL,
  `tipo_beca` VARCHAR(50) DEFAULT NULL,
  `materias_favoritas` TEXT DEFAULT NULL,
  `reaccion_padres_calificaciones` ENUM('MUY BIEN','NORMAL','MUY MAL','NO SABEN') DEFAULT NULL,
  FOREIGN KEY (`estudiante_id`) REFERENCES `estudiantes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Habilidades escolares
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
CREATE TABLE IF NOT EXISTS `submit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `ip` VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuración de la aplicación (activar/desactivar captura, ciclo)
CREATE TABLE IF NOT EXISTS `app_settings` (
  `key` VARCHAR(100) NOT NULL PRIMARY KEY,
  `value` VARCHAR(255) DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ejemplo: establecer contraseña admin (texto plano) para proteger endpoints admin
-- INSERT INTO app_settings (`key`, `value`) VALUES ('admin_password', 'mi_contraseña_admin');

-- ============================================================
-- Ajustes de formulario — form.md (Pasos 3 y 4)
-- MariaDB · NO destructivo · re-ejecutable (ver nota de re-ejecución)
--
-- No afecta los datos ya capturados:
--   · La columna nueva deja en NULL las filas existentes.
--   · Ampliar el ENUM conserva todos los valores ya guardados.
--
-- Nota: tipo_beca y grado_beca son VARCHAR(50), por lo que las
-- opciones nuevas (Transporte, Talento Artístico) NO requieren SQL.
--
-- Re-ejecución: las secciones 1-3 son idempotentes (correrlas de
-- nuevo no tiene efecto). La sección 4 solo aplica UNA vez: si ya
-- se corrió, MariaDB responde "Unknown column" — es la señal de
-- que ya está aplicada; no se rompe nada.
-- ============================================================

-- 1) Número de materias reprobadas (campo nuevo del formulario)
ALTER TABLE datos_escolares
  ADD COLUMN IF NOT EXISTS materias_reprobadas SMALLINT UNSIGNED DEFAULT NULL
  AFTER causa_reprobacion;

-- 2) Opción "No les importa" en la reacción de los padres ante calificaciones
ALTER TABLE datos_escolares
  MODIFY COLUMN reaccion_padres_calificaciones
  ENUM('MUY_BIEN','NORMAL','MUY_MAL','NO_SABEN','NO_LES_IMPORTA') DEFAULT NULL;

-- Verificación (esperado: 2 filas; ENUM con 5 valores)
SHOW COLUMNS FROM datos_escolares
 WHERE Field IN ('materias_reprobadas', 'reaccion_padres_calificaciones');

-- 3) Opcion "Importante" en "Para ti, estudiar es".
--    IMPORTANTE se agrega AL FINAL: no reordena ni afecta valores ya guardados.
ALTER TABLE expectativas_ingreso
  MODIFY COLUMN estudio_es
  ENUM('INTERESANTE','ABURRIDO','UTIL','IMPUESTO','PASATIEMPO','AMIGOS','IMPORTANTE') DEFAULT NULL;

-- 4) Cualidades del profesor segun form.md (Paso 4 Expectativas).
--    CHANGE solo renombra la columna: conserva los valores ya capturados.
ALTER TABLE expectativas_ingreso
  CHANGE COLUMN prio_entienda_jovenes  prio_paciente    INT DEFAULT NULL,
  CHANGE COLUMN prio_justo_evaluar     prio_estricto    INT DEFAULT NULL,
  CHANGE COLUMN prio_permita_preguntar prio_justo       INT DEFAULT NULL,
  CHANGE COLUMN prio_respete_e_imponga prio_comprensivo INT DEFAULT NULL,
  CHANGE COLUMN prio_no_se_enoje       prio_buen_humor  INT DEFAULT NULL;

-- Verificacion de los ajustes 3 y 4
-- (esperado: estudio_es ENUM(...) con 7 valores y las 5 columnas renombradas)
SHOW COLUMNS FROM expectativas_ingreso
 WHERE Field IN ('estudio_es','prio_paciente','prio_estricto','prio_justo','prio_comprensivo','prio_buen_humor');

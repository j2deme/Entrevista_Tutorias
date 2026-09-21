# Datos de captura

## Paso 0

Solicitar número de control y validar que no se haya registrado previamente. En caso de que ya exista, mostrar un aviso de captura realizada y no permitir continuar.

## Paso 1: Datos personales

- Nombre completo (input text)
- Fecha de nacimiento (input date) "dd/mm/aaaa"
- Edad (input number, calculada automáticamente a partir de la fecha de nacimiento)
- Lugar de nacimiento (input text) "Ciudad, Estado"
- Edad (input number, calculada automáticamente a partir de la fecha de nacimiento, readonly)
- Género (select: Masculino, Femenino, No binario)
- Estado civil (select: Soltero/a, Casado/a, Divorciado/a, Viudo/a, Unión libre, Otro)
- Domicilio familiar (input text) "Calle, Número, Colonia, Ciudad, Estado"
- Localidad (input text) "Ciudad, Estado"
- Código postal (input number)
- Zona (select: Urbana, Rural)
- La casa donde vives es (select: Propia, Rentada, Prestada, Otro) -> Si la respuesta es "Otro", mostrar un campo adicional para especificar cuál tipo de vivienda (input text)
- Teléfono móvil (input text) "10 dígitos"
- Hablas otra lengua (select: Sí, No) -> Si la respuesta es sí, mostrar un campo adicional para especificar cuál lengua (input text)
- Tutor asignado (select: Lista de tutores disponibles) [Este campo se llenará dinámicamente desde la base de datos]

## Paso 2: Datos familiares

### Padre

- Nombre completo (input text)
- ¿Vive? (select: Sí, No)
- Edad (input number)
- Nivel de estudios (select: Saber leer y escribir, Primaria, Secundaria, Preparatoria, Licenciatura, Maestría, Doctorado) [De primaria a doctorado establecer niveles "terminado" o "trunco"]
- Ocupación (input text)

### Madre

- Nombre completo (input text)
- ¿Vive? (select: Sí, No)
- Edad (input number)
- Nivel de estudios (select: Saber leer y escribir, Primaria, Secundaria, Preparatoria, Licenciatura, Maestría, Doctorado) [De primaria a doctorado establecer niveles "terminado" o "trunco"]
- Ocupación (input text)

### Datos de la familia

- Número de integrantes de la familia (input number)
- Número de hermanos (input number) "Incluido tu mismo, si eres hijo único, poner 1"
- Lugar que ocupa en la familia (input number) "Si eres hijo único, poner 1"
- Actualmente vives con (select: Ambos padres, Solo padre, Solo madre, Abuelos, Otro familiar, Otro) -> Si la respuesta es "Otro", mostrar un campo adicional para especificar con quién vives (input text)
- ¿Existe alguna situación reciente en tu familia que pueda afectar tu desempeño académico? (select -> Ninguna, Fallecimiento del padre, Fallecimiento de la madre, Separación de los padres, Divorcio, Abandono, Enfermedad grave de algún familiar, Otro) -> Si la respuesta es "Otro", mostrar un campo adicional para especificar cuál situación (input text)
- ¿Cómo es la relación con tus padres? (select: Muy buena, Buena, Regular, Mala, Muy mala)

## Paso 3: Datos académicos

- ¿En qué institución realizaste tus estudios de bachillerato? (input text)
- ¿En qué localidad se encuentra la institución donde realizaste tus estudios de bachillerato? (input text)
- ¿Cuál fue tu promedio general de bachillerato? (input number)
- ¿En que año egresaste de bachillerato? (input number)
- ¿Cómo calificarías tu desempeño académico hasta el momento? (select: Muy bueno, Bueno, Regular, Malo, Muy malo)
- ¿Has reprobado alguna materia? (select: Sí, No) -> Si la respuesta es sí, mostrar un campo adicional para especificar cuántas materias reprobaste (input number); activar un campo adicional para mencionar las causas generales de las reprobaciones (textarea)
- ¿Cuáles son las materias que más te gustan? (textarea)
- ¿Estás satisfecho con tu desempeño académico? (select: Sí, No) -> Si la respuesta es no, mostrar un campo adicional para especificar por qué no estás satisfecho (textarea)
- ¿Cómo evaluarías tu desempeño en los siguientes aspectos? (select: Bueno, Regular, Malo)
  - Comprensión lectora
  - Comprensión oral
  - Resolución de problemas
  - Expresión oral
  - Expresión escrita
  - Vocabulario
  - Cálculo y razonamiento matemático
  - Expresión gráfica / artística
  - Ortografía
- En general, ¿Cómo suelen reaccionar tus padres ante tus calificaciones? (select: Muy bien, Normal, Muy mal, No saben, No les importa)

### Becas y apoyos

- ¿Has recibido alguna beca o apoyo económico durante tus estudios? (select: Sí, No)
  - Si la respuesta es sí, mostrar un campo adicional para especificar indicar en que nivel de estudios (select: Primaria, Secundaria, Preparatoria)
  - Si la respuesta es sí, mostrar un campo adicional para especificar el tipo de beca o apoyo económico (select: Manutención, Alimentación, Transporte, Talento Deportivo, Talento Artístico, Aprovechamiento Académico, Otro) -> Si la respuesta es "Otro", mostrar un campo adicional para especificar cuál tipo de beca o apoyo económico (input text)

## Paso 4: Expectativas de ingreso

- ¿La carrera que elegiste te gusta? (select: Sí, No)
- ¿Porqué? (textarea) [Ajustar leyenda según la respuesta anterior]
- Consideras que estudiar es: (select: Importante, Aburrido, Útil, Algo impuesto por mis padres, Algo que me permite pasar tiempo con mis amigos)
- ¿Te gustaría tener algún apoyo en la institución? (select: Sí, No) -> Si la respuesta es sí, mostrar un campo adicional para especificar cuál apoyo (select: Académico, Psicológico, Orientación, Otro) -> Si la respuesta es "Otro", mostrar un campo adicional para especificar cuál apoyo (input text)
- Cuando has tenido problemas con tus estudios ¿A qué crees que se debe? (select: Me organizo mal, No me interesa, Me distraigo, No tengo lugar para estudiar, Otro) -> Si la respuesta es "Otro", mostrar un campo adicional para especificar cuál problema (input text)
- En clase generalmente prefieres trabajar: (select: Solo/a, Con algún compañero/a, En equipo, Me da igual)
- En un profesor, ¿Qué es lo más importante para ti? "Priorizar del 1 al 6, siendo 1 lo más importante y 6 lo menos importante" (select: Que explique bien, Que sea paciente, Que sea estricto, Que sea justo, Que sea comprensivo, Que tenga buen sentido del humor) [Implementar con múltiples select, cada uno con las opciones del 1 al 6, y validar que no se repitan los valores seleccionados]
- ¿Cuántas horas a la semana dedicas a estudiar? (input number) "Fuera del horario de clases"
- En tu casa, ¿Tienes un lugar adecuado para estudiar? (select: Sí, No)

## Paso 5: Otros datos importantes

### Médicos

- Actualmente, ¿Tienes alguna enfermedad o padecimiento que pueda afectar tu desempeño académico? (select: Sí, No) -> Si la respuesta es sí, mostrar un campo adicional para especificar cuál enfermedad o padecimiento (select: Diabetes, Hipertensión, Asma, Alergias, Otro) -> Si la respuesta es "Otro", mostrar un campo adicional para especificar cuál enfermedad o padecimiento (input text)
- ¿Tienes alguna condición física que pueda afectar tu desempeño académico? (select: Sí, No) -> Si la respuesta es sí, mostrar un campo adicional para especificar cuál condición física (select: Discapacidad visual, Discapacidad auditiva, Discapacidad motriz, Otro) -> Si la respuesta es "Otro", mostrar un campo adicional para especificar cuál condición física (input text)
- ¿Tomas algún medicamento de manera regular? (select: Sí, No) -> Si la respuesta es sí, mostrar un campo adicional para especificar cuál medicamento (input text)
- ¿Alguna vez te han operado? (select: Sí, No) -> Si la respuesta es sí, mostrar un campo adicional para especificar cuál operación (input text)

### Laborales y económicos

- ¿Quién te apoya económicamente para tus estudios? (select: Padre, Madre, Ambos, Recurso Propio, Otro familiar, Otro) -> Si la respuesta es "Otro", mostrar un campo adicional para especificar quién te apoya económicamente (input text)
- Aproximadamente, ¿Cuánto dinero recibes al mes para tus estudios? (input number)
- Actualmente, ¿Trabajas? (select: Sí, No) -> Si la respuesta es sí, mostrar un campo adicional para especificar cuántas horas a la semana trabajas (input number) y un campo adicional para especificar el lugar de trabajo (input text)
- Si la respuesta anterior es sí, mostrar un campo adicional para especificar el motivo por el cual trabajas (select: Mantener a mis estudios, Ayudar a mis padres, Mantener a mi familia, Otro) -> Si la respuesta es "Otro", mostrar un campo adicional para especificar cuál motivo (input text)

### Otros

- ¿Te desplazas en transporte público para asistir a tus clases? (select: Sí, No) -> Si la respuesta es sí, mostrar un campo adicional para especificar cuánto tiempo tardas en llegar a la institución (select: Menos de 10 minutos, De 10 a 30 minutos, Más de 30 minutos, 1 hora o más) y un campo adicional para especificar el costo aproximado del transporte (input number)

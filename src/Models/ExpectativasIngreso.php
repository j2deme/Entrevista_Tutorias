<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpectativasIngreso extends Model
{
  protected $table = 'expectativas_ingreso';
  public $timestamps = false;

  protected $primaryKey = 'estudiante_id';
  public $incrementing = false;

  protected $fillable = [
    'estudiante_id',
    'carrera_gusta',
    'que_mas_atrae',
    'tiene_preocupacion_curso',
    'que_preocupa',
    'estudio_es',
    'forma_apoyo_institucion',
    'desea_apoyo_institucional',
    'tipo_apoyo',
    'pasatiempo_favorito',
    'causa_problemas_estudio',
    'preferencia_trabajo',
    'forma_pasartiempo',
    'forma_hacer_amigos',
    'tiempo_estudio_casa',
    'cuenta_lugar_adecuado',
    'prio_explicacion_clara',
    'prio_paciente',
    'prio_estricto',
    'prio_justo',
    'prio_comprensivo',
    'prio_buen_humor',
    // Nombres previos a db/migracion_paso3.sql (se usan hasta correr el ALTER)
    'prio_entienda_jovenes',
    'prio_justo_evaluar',
    'prio_permita_preguntar',
    'prio_respete_e_imponga',
    'prio_no_se_enoje',
    'prio_otra'
  ];
}

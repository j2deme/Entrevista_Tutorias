<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatosFamiliares extends Model
{
  protected $table = 'datos_familiares';
  public $timestamps = false;

  protected $primaryKey = 'estudiante_id';
  public $incrementing = false;

  protected $fillable = [
    'estudiante_id',
    'padre_nombre',
    'padre_vive',
    'padre_edad',
    'padre_nivel_estudios',
    'padre_ocupacion',
    'madre_nombre',
    'madre_vive',
    'madre_edad',
    'madre_nivel_estudios',
    'madre_ocupacion',
    'num_integrantes_familia',
    'num_hermanos',
    'lugar_que_ocupa',
    'vives_con',
    'situacion_especial',
    'relacion_padres',
    'trabaja_actualmente',
    'horas_trabajo',
    'empresa_trabajo',
    'motivo_trabajo',
    'apoyo_economico',
    'ingreso_mensual_familiar'
  ];
}

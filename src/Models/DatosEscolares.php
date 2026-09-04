<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatosEscolares extends Model
{
  protected $table = 'datos_escolares';
  public $timestamps = false;

  protected $primaryKey = 'estudiante_id';
  public $incrementing = false;

  protected $fillable = [
    'estudiante_id',
    'institucion_procedencia',
    'localidad',
    'generacion_egreso',
    'promedio',
    'rendimiento_escolar',
    'reprobado_curso',
    'causa_reprobacion',
    'satisfecho_resultados',
    'motivo_satisfaccion',
    'ha_estado_becado',
    'grado_beca',
    'tipo_beca',
    'materias_favoritas',
    'reaccion_padres_calificaciones'
  ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatosMedicos extends Model
{
  protected $table = 'datos_medicos';
  public $timestamps = false;

  protected $primaryKey = 'estudiante_id';
  public $incrementing = false;

  protected $fillable = [
    'estudiante_id',
    'padece_enfermedad',
    'cual_enfermedad',
    'condicion_fisica',
    'cual_condicion',
    'toma_medicacion',
    'cual_medicacion',
    'ha_sido_operado',
    'de_que_operacion'
  ];
}

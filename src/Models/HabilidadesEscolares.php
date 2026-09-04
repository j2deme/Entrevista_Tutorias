<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HabilidadesEscolares extends Model
{
  protected $table = 'habilidades_escolares';
  public $timestamps = false;

  protected $primaryKey = 'estudiante_id';
  public $incrementing = false;

  protected $fillable = [
    'estudiante_id',
    'comprension_lectora',
    'comprension_oral',
    'resolucion_problemas',
    'expresion_oral',
    'expresion_escrita',
    'vocabulario',
    'calculo',
    'expresion_grafica',
    'ortografia'
  ];
}

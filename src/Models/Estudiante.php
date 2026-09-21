<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Estudiante extends Model
{
  protected $table = 'estudiantes';

  public $timestamps = true;

  protected $fillable = [
    'numero_control',
    'nombre_completo',
    'fecha_nacimiento',
    'lugar_nacimiento',
    'edad',
    'genero',
    'estado_civil',
    'domicilio_familiar',
    'localidad_familiar',
    'codigo_postal',
    'zona',
    'tipo_vivienda',
    'tipo_vivienda_otro',
    'telefono_movil',
    'habla_otra_lengua',
    'cual_lengua',
    'usa_transporte_publico',
    'tiempo_traslado_transporte',
    'costo_transporte',
    'tutor_id',
    'periodo_captura',
    'capturado'
  ];

  // Relaciones con las tablas detalladas
  public function datosFamiliares()
  {
    return $this->hasOne(DatosFamiliares::class, 'estudiante_id');
  }

  public function datosEscolares()
  {
    return $this->hasOne(DatosEscolares::class, 'estudiante_id');
  }

  public function habilidades()
  {
    return $this->hasOne(HabilidadesEscolares::class, 'estudiante_id');
  }

  public function datosMedicos()
  {
    return $this->hasOne(DatosMedicos::class, 'estudiante_id');
  }

  public function expectativas()
  {
    return $this->hasOne(ExpectativasIngreso::class, 'estudiante_id');
  }

  public function tutor()
  {
    return $this->belongsTo(Tutor::class, 'tutor_id');
  }
}

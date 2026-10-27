<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AttendanceAction extends Model {
 use Concerns\BelongsToTenant;
 protected $fillable=['tenant_id','nome','descricao','tipo','modo_pagina','url','chatbot_integration_id','dados','perfis','ativa','ordem','ia_consulta'];
 protected $casts=['perfis'=>'array','ativa'=>'boolean','ia_consulta'=>'boolean','ordem'=>'integer'];
 public function integration(){return $this->belongsTo(ChatbotIntegration::class,'chatbot_integration_id');}
 protected static function booted():void {static::saving(fn($a)=>app(\App\Services\AcoesAtendimento::class)->validar($a));}
}

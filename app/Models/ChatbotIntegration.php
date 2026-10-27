<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ChatbotIntegration extends Model {
 use \App\Models\Concerns\BelongsToTenant;
 protected $fillable=['tenant_id','nome','url','metodo','timeout','token','ativa'];
 protected $hidden=['token'];
 protected $casts=['token'=>'encrypted','ativa'=>'boolean','timeout'=>'integer'];
 protected static function booted(): void {static::saving(function($m){if(!app(\App\Services\BlocosChatbot::class)->urlPublica((string)$m->url,false)||!in_array($m->metodo,['GET','POST'])||$m->timeout<1||$m->timeout>20)throw \Illuminate\Validation\ValidationException::withMessages(['url'=>'Use HTTPS público, método GET/POST e tempo limite de 1 a 20 segundos.']);});}
}

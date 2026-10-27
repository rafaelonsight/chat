<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
class KnowledgeDocument extends Model {
 use BelongsToTenant;
 protected $fillable=['tenant_id','titulo','idioma','conteudo','ativo'];
 protected $casts=['ativo'=>'boolean'];
 public function save(array $options=[]){return \Illuminate\Support\Facades\DB::transaction(fn()=>parent::save($options));}
 protected static function booted():void {
  static::saving(function($d){if(!trim($d->titulo)||!trim($d->conteudo)||mb_strlen($d->conteudo)>100000||!in_array($d->idioma,['en_GB','pt_BR']))throw \Illuminate\Validation\ValidationException::withMessages(['conteudo'=>__('Informe um título e conteúdo de até 100 mil caracteres.')]);});
  static::saved(fn($d)=>app(\App\Services\KnowledgeBase::class)->indexar($d));
 }
}

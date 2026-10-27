<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
class AiAgent extends Model {
 use BelongsToTenant;
 protected $fillable=['tenant_id','nome','tipo','instrucoes','modelo','max_turnos','ativo','ferramentas','fontes_conhecimento'];
 protected $casts=['ativo'=>'boolean','max_turnos'=>'integer','ferramentas'=>'array','fontes_conhecimento'=>'array'];
 protected static function booted():void {static::saving(function($a){$sources=$a->fontes_conhecimento??[];
   if(!is_array($sources)||count($sources)>20||count(array_unique($sources))!==count($sources)||($sources&&KnowledgeDocument::where('tenant_id',$a->tenant_id?:\App\Support\TenantContext::get())->whereIn('id',$sources)->count()!==count($sources)))throw \Illuminate\Validation\ValidationException::withMessages(['fontes_conhecimento'=>__('Selecione até vinte conteúdos desta conta.')]);
   $ids=$a->ferramentas??[];if(!is_array($ids)||count($ids)>5||count(array_unique($ids))!==count($ids)||($ids&&AttendanceAction::where('tenant_id',$a->tenant_id?:\App\Support\TenantContext::get())->where('tipo','integracao')->where('ativa',true)->whereIn('id',$ids)->count()!==count($ids)))throw \Illuminate\Validation\ValidationException::withMessages(['ferramentas'=>__('Selecione até cinco integrações ativas desta conta.')]);});}
}

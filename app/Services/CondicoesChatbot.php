<?php
namespace App\Services;
use App\Models\{ChatbotAction,Conversation,Tag,ContactField};
use Illuminate\Support\Str;
class CondicoesChatbot {
 public const OPERADORES=['igual'=>'É igual a','diferente'=>'É diferente de','contem'=>'Contém','comeca'=>'Começa com','maior'=>'Maior que','menor'=>'Menor que','preenchido'=>'Está preenchido','vazio'=>'Está vazio'];
 public static function handles(array $cfg): array {return array_merge(array_map(fn($c)=>'caso:'.$c['id'],$cfg['casos']??[]),['padrao']);}
 public static function validar(array $cfg,int $tenant): array {
  $errors=[];$casos=$cfg['casos']??[];
  if(!is_array($casos)||count($casos)<1||count($casos)>10)return ['Crie de 1 a 10 saídas.'];
  $ids=[];
  foreach($casos as $c){
   if(!is_array($c)){$errors[]='Saída inválida.';continue;}
   $id=$c['id']??'';if(!is_string($id)||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$id))$errors[]='Identificador de saída inválido.';$ids[]=$id;
   if(trim((string)($c['nome']??''))==='' || mb_strlen((string)($c['nome']??''))>60)$errors[]='Dê um nome de até 60 caracteres a cada saída.';
   if(!in_array($c['combinacao']??'', ['todas','qualquer']))$errors[]='Selecione todas ou qualquer regra.';
   $rules=$c['regras']??[];if(!is_array($rules)||count($rules)<1||count($rules)>10){$errors[]='Cada saída precisa de 1 a 10 regras.';continue;}
   foreach($rules as $rule){
    $source=$rule['fonte']??'';$op=$rule['operador']??'';
    if($source==='etiqueta'){
     if(!in_array($op,['presente','ausente'])||!Tag::where('tenant_id',$tenant)->whereIn('contexto',['contato','conversa'])->whereKey((int)($rule['etiqueta_id']??0))->exists())$errors[]='Selecione uma etiqueta válida desta empresa e sua condição.';
    }elseif(in_array($source,['resposta','contato'])){
     $field=(string)($rule['campo']??'');
     if($source==='resposta' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_]+)*$/',$field))$errors[]='Informe o nome da resposta guardada.';
     if($source==='contato' && !self::campoValido($field,$tenant))$errors[]='Selecione um campo válido do contato desta empresa.';
     if(!array_key_exists($op,self::OPERADORES))$errors[]='Operador inválido.';
     if(!in_array($op,['vazio','preenchido']) && trim((string)($rule['valor']??''))==='')$errors[]='Informe o valor de comparação.';
     if(in_array($op,['maior','menor']) && !is_numeric(str_replace(',','.',(string)($rule['valor']??''))))$errors[]='Comparações numéricas precisam de um número.';
    }else $errors[]='Fonte da regra inválida.';
   }
  }
  if(count(array_unique($ids))!==count($ids))$errors[]='Identificadores de saída repetidos.';
  return array_values(array_unique($errors));
 }
 private static function campoValido(string $field,int $tenant): bool {
  if(str_starts_with($field,'contato.'))return array_key_exists(substr($field,8),CampoDoContato::PADRAO);
  return preg_match('/^personalizado\.(\d+)$/',$field,$m) && ContactField::where('tenant_id',$tenant)->whereKey($m[1])->exists();
 }
 public static function escolher(array $cfg,array $respostas,array $contato,array $etiquetas): string {
  foreach($cfg['casos']??[] as $caso){
   $rules=$caso['regras']??[];if(!$rules)continue;
   $results=array_map(function($rule)use($respostas,$contato,$etiquetas){
    if(($rule['fonte']??'')==='etiqueta'){$yes=in_array((int)($rule['etiqueta_id']??0),$etiquetas);return ($rule['operador']??'')==='ausente'?!$yes:$yes;}
    $data=($rule['fonte']??'')==='contato'?$contato:$respostas;
    $value=data_get($data,(string)($rule['campo']??''));if(!is_scalar($value) && $value!==null)return false;
    $value=trim((string)$value);$expected=trim((string)($rule['valor']??''));$a=Str::lower(Str::ascii($value));$v=Str::lower(Str::ascii($expected));
    return match($rule['operador']??''){
     'vazio'=>$value==='','preenchido'=>$value!=='','igual'=>$a===$v,'diferente'=>$a!==$v,'contem'=>$v!==''&&str_contains($a,$v),'comeca'=>$v!==''&&str_starts_with($a,$v),
     'maior','menor'=>is_numeric(str_replace(',','.',$value)) && is_numeric(str_replace(',','.',$expected)) && (($rule['operador']==='maior')?(float)str_replace(',','.',$value)>(float)str_replace(',','.',$expected):(float)str_replace(',','.',$value)<(float)str_replace(',','.',$expected)),default=>false};
   },$rules);
   if(($caso['combinacao']??'todas')==='qualquer'?in_array(true,$results,true):!in_array(false,$results,true))return 'caso:'.$caso['id'];
  }
  return 'padrao';
 }
 public static function destino(ChatbotAction $a,Conversation $c): string {
  // Recursos removidos ou movidos não podem fazer uma regra negativa casar por engano.
  if(self::validar($a->config??[],$c->tenant_id)!==[])return 'padrao';
  $contact=$c->contact;$data=['contato'=>[],'personalizado'=>$contact?->camposPersonalizados()??[]];
  foreach(CampoDoContato::PADRAO as $key=>$label)$data['contato'][$key]=$contact?->$key;
  $tags=array_merge($c->tags()->pluck('tags.id')->all(),$contact?->tags()->pluck('tags.id')->all()??[]);
  return self::escolher($a->config??[],$c->chatbot_respostas??[],$data,$tags);
 }
}

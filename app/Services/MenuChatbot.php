<?php
namespace App\Services;
use App\Models\Channel;
/** Uma apresentação com os mesmos IDs no botão, na lista e na alternativa textual. */
class MenuChatbot {
 public const FORMATOS=['numerico'=>'Texto com opções','botoes'=>'Botões de resposta','lista'=>'Lista de opções'];
 public static function validar(string $formato,array $opcoes): array {
  $errors=[];
  if(!array_key_exists($formato,self::FORMATOS))return ['Selecione um formato de menu válido.'];
  if($formato==='numerico')return [];
  $max=$formato==='botoes'?3:10;$length=$formato==='botoes'?20:24;
  if(count($opcoes)<1 || count($opcoes)>$max)$errors[]="Este formato permite de 1 a {$max} opções.";
  $ids=[];
  foreach($opcoes as $o){$id=(string)($o['gatilho']??$o['id']??'');$title=trim((string)($o['rotulo']??''));if($id==='' || mb_strlen($id)>200 || preg_match('/[\r\n]/',$id))$errors[]='O identificador da opção deve ter de 1 a 200 caracteres, sem quebra de linha.';if($title==='' || mb_strlen($title)>$length)$errors[]="O título de cada opção deve ter de 1 a {$length} caracteres.";$ids[]=$id;}
  if(count(array_unique($ids))!==count($ids))$errors[]='Os identificadores das opções devem ser únicos.';
  return array_unique($errors);
 }
 public static function montar(Channel $channel,string $formato,string $body,array $opcoes,string $fallback): string|array {
  if($formato==='numerico' || $channel->tipo!==Channel::META_CLOUD)return $fallback;
  if(self::validar($formato,$opcoes)!==[] || trim($body)==='' || mb_strlen($body)>1024)throw new \RuntimeException(__('ui_7140d890713d'));
  $rows=array_map(fn($o)=>['id'=>'vc:'.(string)($o['gatilho']??$o['id']),'title'=>trim($o['rotulo'])],$opcoes);
  $interactive=['type'=>$formato==='botoes'?'button':'list','body'=>['text'=>$body]];
  $interactive['action']=$formato==='botoes'?['buttons'=>array_map(fn($o)=>['type'=>'reply','reply'=>$o],$rows)]:['button'=>'Ver opções','sections'=>[['rows'=>$rows]]];
  return ['interactive'=>$interactive,'texto'=>$fallback];
 }
}

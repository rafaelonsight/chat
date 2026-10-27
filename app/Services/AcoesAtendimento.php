<?php
namespace App\Services;
use App\Models\{AttendanceAction,ChatbotIntegration,Conversation,User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Validation\ValidationException;
class AcoesAtendimento {
 public const VARIAVEIS=['nome_cliente','telefone_cliente','email_cliente','protocolo','id_conversa','id_contato','id_usuario','id_conta'];
 public const PERFIS=['admin'=>'Administrador','supervisor'=>'Supervisor','atendente'=>'Atendente'];
 public function validar(AttendanceAction $a):void {
  $errors=[];
  if(!in_array($a->tipo,['pagina','integracao'],true))$errors['tipo']=__('Escolha uma página ou integração.');
  if(!trim((string)$a->nome)||mb_strlen($a->nome)>80)$errors['nome']=__('Informe um nome de até 80 caracteres.');
  if(!is_array($a->perfis)||!count($a->perfis)||array_diff($a->perfis,array_keys(self::PERFIS)))$errors['perfis']=__('Selecione os perfis autorizados.');
  if($a->tipo==='pagina'){
   if(!in_array($a->modo_pagina??'nova_aba',['nova_aba','painel'],true))$errors['modo_pagina']=__('Escolha painel lateral ou nova aba.');
   if(!$this->paginaValida((string)$a->url))$errors['url']=__('Use uma página HTTPS pública, com variáveis apenas no caminho ou na consulta.');
   $this->validarVariaveis((string)$a->url,$errors,'url');
  }
  if($a->tipo==='integracao'){
   if($a->ia_consulta&&ChatbotIntegration::where('tenant_id',$a->tenant_id?:TenantContext::get())->whereKey($a->chatbot_integration_id)->value('metodo')!=='GET')$errors['ia_consulta']=__('Somente integrações GET podem ser autorizadas como consulta automática.');
   if(!ChatbotIntegration::where('tenant_id',$a->tenant_id?:TenantContext::get())->where('ativa',true)->whereKey($a->chatbot_integration_id)->exists())$errors['chatbot_integration_id']=__('Selecione uma integração ativa desta conta.');
   try{$v=json_decode((string)($a->dados?:'{}'),true,16,JSON_THROW_ON_ERROR);if(!is_array($v)||mb_strlen((string)$a->dados)>4096)throw new \RuntimeException();}catch(\Throwable $e){$errors['dados']=__('Informe JSON válido de até 4.096 caracteres.');}
   $this->validarVariaveis((string)$a->dados,$errors,'dados');
  }
  if($errors)throw ValidationException::withMessages($errors);
 }
 private function validarVariaveis(string $s,array &$errors,string $field):void {
  preg_match_all('/\{\{([^{}]+)\}\}/',$s,$m);
  if(array_diff($m[1],self::VARIAVEIS))$errors[$field]=__('Use somente as variáveis disponíveis.');
 }
 public function paginaValida(string $url):bool {
  $u=parse_url($url);
  return $u && !str_contains($u['host']??'','{') && app(BlocosChatbot::class)->urlPublica($url,false);
 }
 public function conversa(int $id,User $user):Conversation {
  $tenant=TenantContext::get();abort_unless($tenant,403);
  // O modelo aplica também as restrições de equipe e canal do usuário autenticado.
  return Conversation::where('tenant_id',$tenant)->with('contact')->findOrFail($id);
 }
 public function permitida(AttendanceAction $a,Conversation $c,User $user):bool {
  return $a->ativa&&(int)$a->tenant_id===(int)$c->tenant_id&&(int)$c->tenant_id===(int)TenantContext::get()&&in_array($user->perfil,array_keys(self::PERFIS),true)&&in_array($user->perfil,$a->perfis??[],true)&&($a->tipo!=='integracao'||$a->integration?->ativa);
 }
 public function valores(Conversation $c,?User $user):array {
  return ['nome_cliente'=>$c->contact?->nomeExibicao()??'','telefone_cliente'=>$c->contact?->telefone_e164??'','email_cliente'=>$c->contact?->email??'','protocolo'=>$c->protocolo??'','id_conversa'=>(string)$c->id,'id_contato'=>(string)$c->contact_id,'id_usuario'=>(string)($user?->id??''),'id_conta'=>(string)$c->tenant_id];
 }
 private function substituir(string $s,array $values,bool $url=false):string {
  return preg_replace_callback('/\{\{([^{}]+)\}\}/',fn($m)=>$url?rawurlencode($values[$m[1]]??''):($values[$m[1]]??''),$s);
 }
 public function preparar(AttendanceAction $a,Conversation $c,User $user):array {
  abort_unless($this->permitida($a,$c,$user),403);$this->validar($a);$values=$this->valores($c,$user);
  if($a->tipo==='pagina'){
   $url=$this->substituir($a->url,$values,true);abort_unless($this->paginaValida($url),422);
   return ['url'=>$url,'destino'=>parse_url($url,PHP_URL_HOST),'dados'=>null];
  }
  return ['url'=>null,'destino'=>parse_url($a->integration->url,PHP_URL_HOST),'dados'=>$this->resolverDados($a,$c,$user)];
 }
 public function resolverDados(AttendanceAction $a,Conversation $c,?User $user):array {
  $values=$this->valores($c,$user);$data=json_decode($a->dados?:'{}',true,16,JSON_THROW_ON_ERROR);
  $walk=function($v)use(&$walk,$values){return is_array($v)?array_map($walk,$v):(is_string($v)?$this->substituir($v,$values):$v);};return $walk($data);
 }
 public function assinatura(AttendanceAction $a,array $preview):string {return hash('sha256',json_encode([$a->getAttributes(),$a->integration?->updated_at?->toISOString(),$preview]));}
 public function executar(AttendanceAction $a,Conversation $c,User $user,string $chave,string $assinatura):string {
  $preview=$this->preparar($a,$c,$user);
  abort_unless(hash_equals($this->assinatura($a,$preview),$assinatura),409,__('A configuração mudou. Abra a ação novamente.'));
  abort_unless(\Illuminate\Support\Str::isUuid($chave),422);
  $claimed=DB::table('attendance_action_runs')->insertOrIgnore(['chave'=>$chave,'tenant_id'=>$c->tenant_id,'attendance_action_id'=>$a->id,'conversation_id'=>$c->id,'user_id'=>$user->id,'status'=>'executando','created_at'=>now(),'updated_at'=>now()]);
  if(!$claimed)throw new \RuntimeException(__('Esta confirmação já foi processada. Abra a ação novamente para uma nova execução.'));
  try{
   if($a->tipo==='pagina')$message=__('Página pronta para abrir.');
   else {
    $integration=$a->integration;$ip=app(BlocosChatbot::class)->urlPublica($integration->url);
    if(!$ip)throw new \RuntimeException(__('O destino da integração não está disponível.'));
    $http=Http::connectTimeout(5)->timeout($integration->timeout)->withOptions(['allow_redirects'=>false,'curl'=>[CURLOPT_RESOLVE=>[$ip[0].':443:'.$ip[1]]]]);
    if($integration->token)$http=$http->withToken($integration->token);
    $r=$integration->metodo==='GET'?$http->get($integration->url,$preview['dados']):$http->post($integration->url,$preview['dados']);
    if(!$r->successful()||strlen($r->body())>65536)throw new \RuntimeException(__('A integração não confirmou a execução. Verifique no sistema de destino antes de tentar novamente.'));
    // Retorno não vai para a conversa e nunca é interpretado como HTML ou instrução.
    $body=$r->json();$summary=is_array($body)?($body['mensagem']??$body['resumo']??null):null;
    $message=is_string($summary)?mb_substr($summary,0,1500):__('Integração executada com sucesso.');
   }
   DB::table('attendance_action_runs')->where('chave',$chave)->update(['status'=>$a->tipo==='pagina'?'preparada':'sucesso','updated_at'=>now()]);return $message;
  }catch(\Throwable $e){
   DB::table('attendance_action_runs')->where('chave',$chave)->update(['status'=>'falha','updated_at'=>now()]);
   if($e instanceof \Illuminate\Http\Client\ConnectionException)throw new \RuntimeException(__('A integração demorou para responder. Verifique no sistema de destino antes de tentar novamente.'));
   throw $e;
  }
 }
}

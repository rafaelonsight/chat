<?php
namespace App\Services;
use App\Models\{AgendaChannelConnection as Connection,User};
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
class AgendaChannels {
 public function administrator(User $actor):User {return app(AgendaCalendar::class)->administrator($actor);}
 public function save(User $actor,string $type,array $data):Connection {
  $actor=$this->administrator($actor);abort_unless(isset(Connection::TYPES[$type]),422);
  validator($data,['name'=>'required|string|max:120','external_id'=>['nullable','regex:/^[0-9]{5,40}$/'],'business_id'=>['nullable','regex:/^[0-9]{5,40}$/'],'token'=>'nullable|string|min:16|max:4096','app_secret'=>'nullable|string|min:16|max:256'])->validate();
  try{return DB::transaction(function()use($actor,$type,$data){
   DB::table('tenants')->where('id',$actor->tenant_id)->lockForUpdate()->first();$this->administrator($actor);
   $c=Connection::where('type',$type)->lockForUpdate()->first() ?? new Connection(['type'=>$type,'public_id'=>(string)Str::uuid(),'version'=>0]);
   $secret=$c->credentials ?? [];
   foreach(['token','app_secret'] as $field)if(!empty($data[$field]))$secret[$field]=$data[$field];
   if($type!=='evolution' && (empty($data['external_id']) || empty($secret['token'])))throw ValidationException::withMessages(['credentials'=>__('Informe o identificador da conta e a credencial para continuar.')]);
   if($type==='meta_cloud' && empty($data['business_id']))throw ValidationException::withMessages(['business_id'=>__('Informe o identificador da conta WhatsApp Business.')]);
   $secret['verify_token']??=Str::random(48);
   $c->fill(['name'=>trim($data['name']),'external_id'=>$type==='evolution'?null:($data['external_id']??null),'business_id'=>$type==='meta_cloud'?$data['business_id']:null,'credentials'=>$secret,'status'=>'configurado','version'=>($c->version??0)+1,'validated_at'=>null,'received_at'=>null,'failure_code'=>null])->save();
   $this->event($actor,$c,'configurado');return $c;
  });}catch(QueryException $e){if($e->getCode()!=='23505')throw $e;throw ValidationException::withMessages(['external_id'=>__('Esta conta já está vinculada a outro canal desta instalação.')]);}
 }
 public function disconnect(User $actor,int $id):void {
  $actor=$this->administrator($actor);DB::transaction(function()use($actor,$id){
   DB::table('tenants')->where('id',$actor->tenant_id)->lockForUpdate()->first();$this->administrator($actor);
   $c=Connection::whereKey($id)->lockForUpdate()->firstOrFail();$c->forceFill(['status'=>'desconectado','credentials'=>null,'version'=>$c->version+1,'validated_at'=>null,'failure_code'=>null])->save();$this->event($actor,$c,'desconectado');
  });
 }
 public function validate(User $actor,int $id):bool {
  $actor=$this->administrator($actor);$c=Connection::findOrFail($id);
  if(!$this->transportEnabled($c->type))throw ValidationException::withMessages(['connection'=>__('A ativação do provedor nesta instalação ainda está pendente. A configuração foi preservada.')]);
  if($c->status==='desconectado' || empty($c->credentials['token']))throw ValidationException::withMessages(['connection'=>__('Configure novamente a credencial antes de validar.')]);
  $version=$c->version;$ok=false;
  try{
   $host=$c->type==='instagram'?'https://graph.instagram.com':'https://graph.facebook.com';$api=config('services.meta.versao');
   $client=Http::withToken($c->credentials['token'])->acceptJson()->timeout(15)->withoutRedirecting();
   if($c->type==='meta_cloud'){
    $phone=$client->get($host.'/'.$api.'/'.$c->external_id,['fields'=>'id,display_phone_number']);
    $business=$client->get($host.'/'.$api.'/'.$c->business_id.'/phone_numbers',['fields'=>'id','limit'=>100]);
    $ok=$phone->successful() && (string)$phone->json('id')===$c->external_id && $business->successful() && collect($business->json('data',[]))->contains(fn($p)=>(string)($p['id']??'')===$c->external_id);
   }else{
    $response=$client->get($host.'/'.$api.'/'.$c->external_id,['fields'=>$c->type==='instagram'?'id,username':'id,name']);$ok=$response->successful() && (string)$response->json('id')===$c->external_id;
   }
  }catch(\Throwable){$ok=false;}
  DB::transaction(function()use($actor,$c,$version,$ok){
   DB::table('tenants')->where('id',$actor->tenant_id)->lockForUpdate()->first();$this->administrator($actor);
   $fresh=Connection::whereKey($c->id)->lockForUpdate()->firstOrFail();
   if($fresh->version!==$version)throw ValidationException::withMessages(['connection'=>__('A configuração mudou durante a validação. Tente novamente.')]);
   $fresh->forceFill(['status'=>$ok?'validado':'falha','validated_at'=>$ok?now():null,'failure_code'=>$ok?null:'PROVIDER_VALIDATION_FAILED'])->save();$this->event($actor,$fresh,$ok?'validado':'falha');
  });return $ok;
 }
 public function transportEnabled(string $type):bool {
  // QR transport needs a dedicated instance manager and is never routed through Meta.
  return $type!=='evolution' && config('agenda.channels_enabled',false);
 }
 private function event(User $actor,Connection $c,string $action):void {DB::table('agenda_channel_events')->insert(['tenant_id'=>$actor->tenant_id,'connection_id'=>$c->id,'actor_id'=>$actor->id,'action'=>$action,'created_at'=>now()]);}
}

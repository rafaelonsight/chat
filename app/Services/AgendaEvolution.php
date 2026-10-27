<?php
namespace App\Services;
use App\Models\{AgendaChannelConnection as Connection,User};
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class AgendaEvolution {
 private function client(){
  if(!config('agenda.evolution_enabled') || config('agenda.evolution_url')!=='http://127.0.0.1:8085' || !config('agenda.evolution_key'))throw ValidationException::withMessages(['qr'=>__('O serviço de conexão está indisponível. Tente novamente em instantes.')]);
  return Http::baseUrl(config('agenda.evolution_url'))->withHeaders(['apikey'=>config('agenda.evolution_key')])->acceptJson()->withoutRedirecting()->timeout(12);
 }
 public function channel(User $actor):Connection {
  app(AgendaChannels::class)->administrator($actor);
  return DB::transaction(function()use($actor){
   DB::table('tenants')->where('id',$actor->tenant_id)->lockForUpdate()->first();app(AgendaChannels::class)->administrator($actor);
   return Connection::where('type','evolution')->first() ?? Connection::create(['public_id'=>(string)Str::uuid(),'type'=>'evolution','name'=>'WhatsApp','status'=>'configurado','version'=>1]);
  });
 }
 private function instance(Connection $c):string {return 'agenda-'.str_replace('-','',$c->public_id);}
 public function refresh(User $actor,int $id):array {
  app(AgendaChannels::class)->administrator($actor);$c=Connection::where('type','evolution')->findOrFail($id);
  try{
   $client=$this->client();$instance=$this->instance($c);$response=$client->get('/instance/connectionState/'.$instance);
   if($response->status()===404){$create=$client->post('/instance/create',['instanceName'=>$instance,'integration'=>'WHATSAPP-BAILEYS','qrcode'=>true]);if(!$create->successful() && $create->status()!==403 && $create->status()!==409)$create->throw();$response=$client->get('/instance/connectionState/'.$instance);}
   $response->throw();$state=$response->json('instance.state');$qr=null;
   if($state!=='open'){
    $connect=$client->get('/instance/connect/'.$instance)->throw();$qr=$connect->json('base64');
    if($qr && (!str_starts_with($qr,'data:image/png;base64,') || strlen($qr)>250000 || base64_decode(substr($qr,22),true)===false))$qr=null;
   }
   DB::transaction(function()use($actor,$c,$state){
    DB::table('tenants')->where('id',$actor->tenant_id)->lockForUpdate()->first();app(AgendaChannels::class)->administrator($actor);$fresh=Connection::whereKey($c->id)->lockForUpdate()->firstOrFail();
    if($fresh->version!==$c->version)throw ValidationException::withMessages(['qr'=>__('Esta conexão mudou. Abra o QR Code novamente.')]);
    $fresh->forceFill(['status'=>$state==='open'?'conectado':'conectando','validated_at'=>$state==='open'?now():null,'failure_code'=>null])->save();
   });
   if($state==='open' && \Illuminate\Support\Facades\Schema::hasColumn('agenda_channel_connections','channel_id'))app(AgendaInbox::class)->subscribe($actor,$c->id);
   return ['state'=>$state==='open'?'open':'connecting','qr'=>$qr];
  }catch(ValidationException $e){throw $e;}catch(\Throwable){throw ValidationException::withMessages(['qr'=>__('Não foi possível gerar o QR Code. Tente novamente.')]);}
 }
 public function disconnect(User $actor,int $id):void {
  app(AgendaChannels::class)->administrator($actor);$c=Connection::where('type','evolution')->findOrFail($id);
  // Serialize disconnect and polling for this account. Only our UUID-derived instance is touched.
  DB::transaction(function()use($actor,$c){
   DB::table('tenants')->where('id',$actor->tenant_id)->lockForUpdate()->first();app(AgendaChannels::class)->administrator($actor);
   $r=$this->client()->delete('/instance/logout/'.$this->instance($c));if(!$r->successful() && $r->status()!==404)$r->throw();
   $c->refresh()->forceFill(['status'=>'desconectado','version'=>$c->version+1,'validated_at'=>null])->save();
   if($c->channel_id)\App\Models\Channel::whereKey($c->channel_id)->update(['status'=>'close']);
  });
 }
}

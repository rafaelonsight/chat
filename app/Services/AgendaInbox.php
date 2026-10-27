<?php
namespace App\Services;
use App\Models\{AgendaChannelConnection as Connection,Channel,User};
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
class AgendaInbox {
 public function connect(User $actor,int $id):Channel {
  app(AgendaChannels::class)->administrator($actor);
  return DB::transaction(function()use($actor,$id){
   DB::table('tenants')->where('id',$actor->tenant_id)->lockForUpdate()->first();app(AgendaChannels::class)->administrator($actor);
   $c=Connection::where('type','evolution')->whereKey($id)->lockForUpdate()->firstOrFail();
   $channel=$c->channel_id?Channel::withoutGlobalScopes()->where('tenant_id',$actor->tenant_id)->findOrFail($c->channel_id):Channel::create(['tenant_id'=>$actor->tenant_id,'tipo'=>'evolution','nome'=>$c->name,'instance_name'=>'agenda-'.str_replace('-','',$c->public_id),'status'=>'open','conectado_em'=>now()]);
   $secret=$c->credentials??[];$secret['webhook_secret']??=Str::random(64);
   $c->forceFill(['channel_id'=>$channel->id,'credentials'=>$secret])->save();
   $channel->forceFill(['status'=>'open'])->save();
   $actor->canais()->syncWithoutDetaching([$channel->id]);return $channel;
  });
 }
 public function subscribe(User $actor,int $id):void {
  $channel=$this->connect($actor,$id);$c=Connection::findOrFail($id);
  if(!config('agenda.evolution_enabled'))throw new \RuntimeException('Agenda transport disabled.');
  Http::baseUrl(config('agenda.evolution_url'))->withHeaders(['apikey'=>config('agenda.evolution_key')])->timeout(15)->withoutRedirecting()->post('/webhook/set/'.$channel->instance_name,['webhook'=>['enabled'=>true,'url'=>url('/webhooks/agenda/evolution/'.$c->public_id),'headers'=>['x-agenda-webhook-secret'=>$c->credentials['webhook_secret']],'byEvents'=>false,'base64'=>true,'events'=>['MESSAGES_UPSERT','MESSAGES_UPDATE','CONNECTION_UPDATE','SEND_MESSAGE']]])->throw();
 }
}

<?php
namespace App\Http\Controllers;
use App\Models\{AgendaChannelConnection as Connection,WebhookEvent};
use Illuminate\Http\Request;
class AgendaEvolutionWebhook {
 public function __invoke(Request $r,string $connection){
  abort_unless(config('agenda.evolution_enabled'),503);
  $c=Connection::withoutGlobalScopes()->where('public_id',$connection)->where('type','evolution')->first();
  $secret=$c?->credentials['webhook_secret']??'';
  abort_unless($c && $c->channel_id && $secret && hash_equals($secret,(string)$r->header('x-agenda-webhook-secret')),404);
  abort_unless($r->input('instance')==='agenda-'.str_replace('-','',$c->public_id),403);
  abort_if($r->getContent()!=='' && strlen($r->getContent())>16000000,413);
  $payload=$r->except(['apikey','apiKey','server_url','destination']);
  $event=WebhookEvent::withoutGlobalScopes()->create(['tenant_id'=>$c->tenant_id,'channel_id'=>$c->channel_id,'evento'=>$r->input('event'),'payload'=>$payload,'recebido_em'=>now()]);
  \App\Jobs\ProcessEvolutionWebhook::dispatch($event->id)->onQueue('agenda-inbox');
  $c->forceFill(['received_at'=>now()])->saveQuietly();
  return response()->json(['ok'=>true]);
 }
}

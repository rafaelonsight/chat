<?php
namespace App\Services;
use App\Models\{User,Contact,Channel,Conversation,Message,AgendaCalendarEntry,AgendaSettings,Tenant};
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable as Time;
class AgendaOutreach {
 public const REMINDER='Olá, {nome}! Seu {servico} com {profissional} está marcado para {dia_semana}, {data}, às {hora}, em {empresa}.';
 public const STATES=['draft'=>'Rascunho','scheduled'=>'Agendada','running'=>'Enviando','paused'=>'Pausada','completed'=>'Concluída','cancelled'=>'Cancelada','pending'=>'Previsto','queued'=>'Em fila','skipped'=>'Não enviado','sent'=>'Enviado','delivered'=>'Entregue','read'=>'Lido','failed'=>'Falhou','uncertain'=>'Resultado desconhecido'];
 public function q(string $table){$alias=last(explode(' as ',$table));return DB::table($table)->where($alias.'.tenant_id',TenantContext::get()?:0);}
 private function fail(string $text):never {throw ValidationException::withMessages(['communication'=>$text]);}
 public function event(string $kind,int $id,?int $actor,array $data=[]):void {$this->q('agenda_communication_events')->insert(['tenant_id'=>TenantContext::get(),'kind'=>$kind,'subject_id'=>$id,'actor_id'=>$actor,'data'=>json_encode($data),'created_at'=>now()->toIso8601String()]);}
 public function preference(User $u,int $contact,bool $marketing,bool $reminders,string $source):void {
  app(AgendaCalendar::class)->administrator($u);validator(compact('source'),['source'=>'required|string|min:5|max:240'])->validate();$c=Contact::where('tipo','pessoa')->findOrFail($contact);if($c->anonimizado_em)$this->fail('Contato anonimizado.');
  DB::transaction(function()use($u,$c,$marketing,$reminders,$source){DB::table('tenants')->where('id',$u->tenant_id)->lockForUpdate()->first();
   $this->q('agenda_communication_preferences')->updateOrInsert(['tenant_id'=>$u->tenant_id,'contact_id'=>$c->id],['marketing'=>$marketing,'reminders'=>$reminders,'source'=>$source,'actor_id'=>$u->id,'created_at'=>now()->toIso8601String(),'updated_at'=>now()->toIso8601String()]);
   if($marketing)$c->forceFill(['opt_out_em'=>null,'opt_out_motivo'=>null])->save();
   $this->event('preference',$c->id,$u->id,['marketing'=>$marketing,'reminders'=>$reminders,'source'=>$source]);
  });
 }
 public function channel(int $id):Channel {
  $c=Channel::withoutGlobalScopes()->where('tenant_id',TenantContext::get())->findOrFail($id);
  if($c->tipo!=='evolution')$this->fail('Este canal ainda não está homologado para automações. O WhatsApp oficial exige integração e templates aprovados.');return $c;
 }
 private function body(string $body,bool $reminder):void {
  validator(compact('body'),['body'=>'required|string|min:5|max:2500'])->validate();preg_match_all('/\{([^{}]+)\}/u',$body,$m);
  if(array_diff($m[1],$reminder?['nome','empresa','servico','profissional','data','hora','dia_semana']:['nome','empresa']))$this->fail('Use somente as variáveis indicadas na tela.');
 }
 public function rule(User $u,array $d,?int $id=null):int {
  app(AgendaCalendar::class)->administrator($u);$this->channel((int)$d['channel_id']);$this->body($d['body'],true);
  validator($d,['name'=>'required|string|max:120','enabled'=>'required|boolean','minutes_before'=>'required|integer|between:5,10080','mode'=>'required|in:confirmation,reminder'])->validate();
  return DB::transaction(function()use($u,$d,$id){DB::table('tenants')->where('id',$u->tenant_id)->lockForUpdate()->first();$old=$id?$this->q('agenda_reminder_rules')->where('id',$id)->lockForUpdate()->first():null;if($id)abort_unless($old,404);
   $values=array_intersect_key($d,array_flip(['channel_id','name','body','enabled','minutes_before','mode']));$values+=['actor_id'=>$u->id,'version'=>($old->version??0)+1,'updated_at'=>now()->toIso8601String()];
   if($old)$this->q('agenda_reminder_rules')->where('id',$id)->update($values);else $id=$this->q('agenda_reminder_rules')->insertGetId($values+['tenant_id'=>$u->tenant_id,'created_at'=>now()->toIso8601String()]);$this->event('rule',$id,$u->id,['enabled'=>$d['enabled']]);return $id;
  });
 }
 public function eligible(Contact $c,string $purpose):bool {
  if($c->eGrupo() || $c->bloqueado() || $c->anonimizado_em || $c->arquivado_em || !$c->telefone_e164 || !$c->destinoWhatsApp() || ($purpose==='marketing' && $c->saiuDaLista()))return false;
  return (bool)$this->q('agenda_communication_preferences')->where('contact_id',$c->id)->value($purpose);
 }
 public function campaign(User $u,array $d,array $contacts):int {
  app(AgendaCalendar::class)->administrator($u);$this->channel((int)$d['channel_id']);$this->body($d['body'],false);
  validator($d,['name'=>'required|string|max:120','per_minute'=>'required|integer|between:1,30','window_start'=>'required|date_format:H:i','window_end'=>'required|date_format:H:i|after:window_start'])->validate();
  $ids=array_values(array_unique(array_map('intval',$contacts)));if(!$ids || count($ids)>1000)$this->fail('Selecione de 1 a 1.000 clientes.');
  $rows=Contact::whereIn('id',$ids)->get();if($rows->count()!==count($ids))$this->fail('Há contatos indisponíveis nesta seleção.');
  return DB::transaction(function()use($u,$d,$rows){DB::table('tenants')->where('id',$u->tenant_id)->lockForUpdate()->first();$id=$this->q('agenda_outreach_campaigns')->insertGetId(array_intersect_key($d,array_flip(['name','body','channel_id','per_minute','window_start','window_end']))+['tenant_id'=>$u->tenant_id,'actor_id'=>$u->id,'created_at'=>now()->toIso8601String(),'updated_at'=>now()->toIso8601String()]);
   foreach($rows as $c)$this->q('agenda_outreach_deliveries')->insert(['tenant_id'=>$u->tenant_id,'campaign_id'=>$id,'contact_id'=>$c->id,'channel_id'=>$d['channel_id'],'dedupe'=>hash('sha256','campaign:'.$id.':'.$c->id),'state'=>$this->eligible($c,'marketing')?'pending':'skipped','reason'=>$this->eligible($c,'marketing')?null:'Sem autorização ou contato indisponível.','due_at'=>now()->toIso8601String(),'expires_at'=>now()->addDays(30)->toIso8601String(),'created_at'=>now()->toIso8601String(),'updated_at'=>now()->toIso8601String()]);
   $this->event('campaign',$id,$u->id,['action'=>'draft']);return $id;
  });
 }
 public function campaignAction(User $u,int $id,string $action,?string $local=null):void {
  app(AgendaCalendar::class)->administrator($u);
  DB::transaction(function()use($u,$id,$action,$local){DB::table('tenants')->where('id',$u->tenant_id)->lockForUpdate()->first();$c=$this->q('agenda_outreach_campaigns')->where('id',$id)->lockForUpdate()->first();abort_unless($c,404);
   $next=['draft'=>['schedule'],'scheduled'=>['pause','cancel'],'running'=>['pause','cancel'],'paused'=>['schedule','cancel']];if(!in_array($action,$next[$c->status]??[],true))$this->fail('Essa alteração não está disponível.');
   $values=['updated_at'=>now()->toIso8601String()];
   if($action==='schedule'){$at=$local?app(AgendaCalendar::class)->local(str_replace('T',' ',$local)):now()->toImmutable();if($at->lt(now()->subMinute()))$this->fail('Escolha um horário futuro.');$values+=['status'=>'scheduled','scheduled_at'=>$at->toIso8601String()];$this->q('agenda_outreach_deliveries')->where('campaign_id',$id)->where('state','pending')->update(['due_at'=>$at->toIso8601String(),'expires_at'=>$at->addDay()->toIso8601String()]);}
   else $values['status']=$action==='pause'?'paused':'cancelled';
   $this->q('agenda_outreach_campaigns')->where('id',$id)->update($values);$this->event('campaign',$id,$u->id,['action'=>$action]);
  });
 }
 public function editCampaign(User $u,int $id,array $d):void {
  app(AgendaCalendar::class)->administrator($u);$this->body($d['body'],false);
  validator($d,['name'=>'required|string|max:120','per_minute'=>'required|integer|between:1,30','window_start'=>'required|date_format:H:i','window_end'=>'required|date_format:H:i|after:window_start'])->validate();
  DB::transaction(function()use($u,$id,$d){DB::table('tenants')->where('id',$u->tenant_id)->lockForUpdate()->first();$r=$this->q('agenda_outreach_campaigns')->where('id',$id)->lockForUpdate()->first();abort_unless($r,404);if($r->status!=='draft')$this->fail('Somente rascunhos podem ser editados.');$this->q('agenda_outreach_campaigns')->where('id',$id)->update(array_intersect_key($d,array_flip(['name','body','per_minute','window_start','window_end']))+['actor_id'=>$u->id,'version'=>$r->version+1,'updated_at'=>now()->toIso8601String()]);$this->event('campaign',$id,$u->id,['action'=>'edited']);});
 }
 public function render(string $body,Contact $contact,?AgendaCalendarEntry $e=null):string {
  $values=['{nome}'=>$contact->nome?:'cliente','{empresa}'=>Tenant::findOrFail(TenantContext::get())->nome];
  if($e){$date=$e->start_at->timezone(AgendaSettings::current()->timezone);$values+=['{servico}'=>$e->snapshot['service'],'{profissional}'=>$e->snapshot['professional'],'{data}'=>$date->format('d/m/Y'),'{hora}'=>$date->format('H:i'),'{dia_semana}'=>['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'][$date->dayOfWeek]];}
  return strtr($body,$values);
 }
 // Scheduler only enumerates tenant IDs; all customer queries run within their tenant context.
 public function tick():void {
  foreach(DB::table('tenants')->orderBy('id')->pluck('id')->filter(fn($t)=>\App\Support\Modulos::ativo('agenda',(int)$t)) as $tenant)TenantContext::runAs((int)$tenant,function(){DB::transaction(function(){
   DB::table('tenants')->where('id',TenantContext::get())->lockForUpdate()->first();
   foreach($this->q('agenda_reminder_rules')->where('enabled',true)->get() as $r){
    foreach(AgendaCalendarEntry::where('kind','booking')->where('status','confirmado')->where('active',true)->whereBetween('start_at',[now()->toIso8601String(),now()->addMinutes($r->minutes_before+2)->toIso8601String()])->get() as $e){
     $due=$e->start_at->subMinutes($r->minutes_before);if($due->gt(now()) || $due->lt(Time::parse($r->created_at)) || $due->lt($e->created_at))continue;
     $this->q('agenda_outreach_deliveries')->insertOrIgnore(['tenant_id'=>TenantContext::get(),'rule_id'=>$r->id,'rule_version'=>$r->version,'entry_id'=>$e->id,'entry_version'=>$e->version,'contact_id'=>$e->contact_id,'channel_id'=>$r->channel_id,'dedupe'=>hash('sha256','reminder:'.$r->id.':'.$e->id.':'.$e->start_at->toIso8601String()),'state'=>$due->lt(now()->subMinutes(2))?'skipped':'pending','reason'=>$due->lt(now()->subMinutes(2))?'Prazo do lembrete vencido.':null,'due_at'=>$due->toIso8601String(),'expires_at'=>$e->start_at->toIso8601String(),'created_at'=>now()->toIso8601String(),'updated_at'=>now()->toIso8601String()]);
    }
   }
   foreach($this->q('agenda_outreach_campaigns')->whereIn('status',['scheduled','running'])->where('scheduled_at','<=',now()->toIso8601String())->get() as $c){
    $clock=now(AgendaSettings::current()->timezone)->format('H:i');if($clock<$c->window_start || $clock>=$c->window_end || ($c->last_batch_at && Time::parse($c->last_batch_at)->gt(now()->subMinute())))continue;
    $this->q('agenda_outreach_campaigns')->where('id',$c->id)->update(['status'=>'running','last_batch_at'=>now()->toIso8601String()]);
    foreach($this->q('agenda_outreach_deliveries')->where('campaign_id',$c->id)->where('state','pending')->orderBy('id')->limit($c->per_minute)->get() as $d)$this->enqueue($d);
    $this->complete($c->id);
   }
   foreach($this->q('agenda_outreach_deliveries')->whereNotNull('rule_id')->where('state','pending')->where('due_at','<=',now()->toIso8601String())->orderBy('id')->limit(100)->get() as $d)$this->enqueue($d);
   // Recover a commit-to-Redis interruption with the SAME message identity. Delivery's
   // durable claim still prevents a second HTTP send, including ambiguous outcomes.
   foreach($this->q('agenda_outreach_deliveries as d')->join('messages as m','m.id','=','d.message_id')->where('d.state','queued')->where('m.status','queued')->where('d.updated_at','<',now()->subMinutes(2)->toIso8601String())->where('d.expires_at','>',now()->toIso8601String())->select('d.*')->limit(100)->get() as $d){
    $m=Message::find($d->message_id);if(!$m || !$this->permit($m))continue;
    $this->q('agenda_outreach_deliveries')->where('id',$d->id)->update(['updated_at'=>now()->toIso8601String()]);\App\Jobs\SendTextMessage::dispatch($m->id)->onQueue($d->rule_id?'agenda-reminders':'agenda-campaigns')->afterCommit();
   }
  });});
 }
 private function complete(int $id):void {
  if(!$this->q('agenda_outreach_deliveries')->where('campaign_id',$id)->where('state','pending')->exists() && !$this->q('agenda_outreach_deliveries as d')->join('messages as m','m.id','=','d.message_id')->where('d.campaign_id',$id)->where('m.status','queued')->exists())$this->q('agenda_outreach_campaigns')->where('id',$id)->where('status','running')->update(['status'=>'completed','updated_at'=>now()->toIso8601String()]);
 }
 public function reason(object $d,?Conversation $conversation=null):?string {
  if(Time::parse($d->expires_at)->lte(now()))return 'Prazo do envio vencido.';
  $c=Contact::find($d->contact_id);if(!$c || !$this->eligible($c,$d->rule_id?'reminders':'marketing'))return 'Autorização revogada ou contato indisponível.';
  $channel=Channel::withoutGlobalScopes()->where('tenant_id',TenantContext::get())->find($d->channel_id);if(!$channel || $channel->tipo!=='evolution' || $channel->status!=='open')return 'Canal desconectado ou não homologado.';
  if($conversation && ($conversation->atendente_id || $conversation->status==='arquivada' || $this->q('agenda_flow_sessions')->where('conversation_id',$conversation->id)->where('step','<>','menu')->where('expires_at','>',now()->toIso8601String())->exists()))return 'Atendimento humano, fluxo em andamento ou conversa arquivada.';
  if($d->rule_id){$r=$this->q('agenda_reminder_rules')->where('id',$d->rule_id)->first();$e=AgendaCalendarEntry::find($d->entry_id);
   if(!$r?->enabled || $r->version!==$d->rule_version)return 'Regra pausada ou alterada.';
   $actor=User::withoutGlobalScopes()->where('tenant_id',TenantContext::get())->find($r->actor_id);if(!$actor?->canEnterAgenda() || !($actor->admin || $actor->perfil==='admin'))return 'Administrador responsável sem permissão.';
   if(!$e || $e->version!==$d->entry_version || !$e->active || $e->status!=='confirmado' || $e->contact_id!==$d->contact_id || $e->start_at->isPast())return 'Agendamento alterado ou indisponível.';
   if($r->mode==='confirmation' && $e->presence!=='pendente')return 'A presença já foi respondida.';
   if($this->q('agenda_outreach_deliveries as d')->join('messages as m','m.id','=','d.message_id')->where('d.id','<>',$d->id)->where('d.contact_id',$d->contact_id)->where('d.channel_id',$d->channel_id)->whereNotNull('d.rule_id')->where('d.consumed',false)->where('d.expires_at','>',now()->toIso8601String())->whereIn('m.status',['queued','sent','delivered','read'])->exists())return 'Outro lembrete deste cliente ainda aguarda resposta.';
  }else {$c=$this->q('agenda_outreach_campaigns')->where('id',$d->campaign_id)->first();if(!$c || !in_array($c->status,['scheduled','running'],true))return 'Campanha pausada ou cancelada.';
   $actor=User::withoutGlobalScopes()->where('tenant_id',TenantContext::get())->find($c->actor_id);if(!$actor?->canEnterAgenda() || !($actor->admin || $actor->perfil==='admin'))return 'Administrador responsável sem permissão.';
   $clock=now(AgendaSettings::current()->timezone)->format('H:i');if($clock<$c->window_start || $clock>=$c->window_end)return 'Fora do período de envio.';
  }
  return null;
 }
 private function enqueue(object $d):void {
  $conversation=Conversation::withoutGlobalScopes()->where('tenant_id',TenantContext::get())->where('channel_id',$d->channel_id)->where('contact_id',$d->contact_id)->where('status','<>','arquivada')->latest('id')->first();
  $reason=$this->reason($d,$conversation);if($reason){$this->q('agenda_outreach_deliveries')->where('id',$d->id)->update(['state'=>'skipped','reason'=>$reason,'updated_at'=>now()->toIso8601String()]);return;}
  $contact=Contact::findOrFail($d->contact_id);$conversation??=Conversation::create(['channel_id'=>$d->channel_id,'contact_id'=>$d->contact_id,'status'=>'nova']);
  if($d->rule_id){$r=$this->q('agenda_reminder_rules')->where('id',$d->rule_id)->first();$e=AgendaCalendarEntry::findOrFail($d->entry_id);$text=$this->render($r->body,$contact,$e);if($r->mode==='confirmation')$text.="\n\n1 — Confirmar presença\n2 — Reagendar\n3 — Cancelar\nATENDENTE — falar com a equipe";$text.="\n\nPARAR LEMBRETES — não receber novos lembretes";}
  else {$r=$this->q('agenda_outreach_campaigns')->where('id',$d->campaign_id)->first();$text=$this->render($r->body,$contact)."\n\nSAIR — não receber campanhas";}
  $m=Message::create(['conversation_id'=>$conversation->id,'channel_id'=>$d->channel_id,'direcao'=>'out','tipo'=>'text','corpo'=>$text,'status'=>'queued','automatica'=>true]);
  $this->q('agenda_outreach_deliveries')->where('id',$d->id)->whereNull('message_id')->update(['message_id'=>$m->id,'state'=>'queued','consumed'=>(bool)($d->rule_id && $r->mode==='reminder'),'updated_at'=>now()->toIso8601String()]);
  $this->event('delivery',$d->id,null,['action'=>'queued']);\App\Jobs\SendTextMessage::dispatch($m->id)->onQueue($d->rule_id?'agenda-reminders':'agenda-campaigns')->afterCommit();
 }
 public function permit(Message $m):bool {
  $d=$this->q('agenda_outreach_deliveries')->where('message_id',$m->id)->first();if(!$d)return true;
  $reason=$this->reason($d,$m->conversation);if(!$reason)return true;
  $this->q('agenda_outreach_deliveries')->where('id',$d->id)->update(['state'=>'skipped','reason'=>$reason,'updated_at'=>now()->toIso8601String()]);$m->forceFill(['status'=>'failed','agenda_delivery_state'=>'failed','erro'=>$reason])->save();return false;
 }
}

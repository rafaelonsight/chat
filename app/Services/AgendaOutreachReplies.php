<?php
namespace App\Services;
use App\Models\{Message,Conversation,Contact,User,Tenant,AgendaCalendarEntry,ConversationEvent};
use Illuminate\Support\Facades\DB;
class AgendaOutreachReplies {
 // Called under the same tenant/conversation transaction as the guided flow.
 public function receive(Message $m,Conversation $c):bool {
  $s=app(AgendaOutreach::class);$person=$c->contact;if(!$person || $person->eGrupo() || $person->anonimizado_em)return false;
  $input=mb_strtolower(trim((string)$m->corpo));$text=null;$human=false;
  if(in_array($input,['sair','parar lembretes'],true)){
   $p=$s->q('agenda_communication_preferences')->where('contact_id',$person->id)->first();
   $s->q('agenda_communication_preferences')->updateOrInsert(['tenant_id'=>$m->tenant_id,'contact_id'=>$person->id],['marketing'=>$input==='sair'?false:($p->marketing??false),'reminders'=>$input==='parar lembretes'?false:($p->reminders??false),'source'=>'Solicitação pelo WhatsApp','actor_id'=>null,'created_at'=>now()->toIso8601String(),'updated_at'=>now()->toIso8601String()]);
   if($input==='sair')$person->forceFill(['opt_out_em'=>now(),'opt_out_motivo'=>'Solicitação pelo WhatsApp'])->save();
   else $s->q('agenda_outreach_deliveries')->where('contact_id',$person->id)->whereNotNull('rule_id')->update(['consumed'=>true]);
   $s->event('preference',$person->id,null,['action'=>$input]);$text=$input==='sair'?'Você não receberá novas campanhas. Seus agendamentos permanecem disponíveis.':'Você não receberá novos lembretes. Seus agendamentos permanecem disponíveis.';
  }else {
   if($c->atendente_id || $person->bloqueado())return false;
   $d=$s->q('agenda_outreach_deliveries as d')->join('messages as m','m.id','=','d.message_id')->join('agenda_reminder_rules as r','r.id','=','d.rule_id')->where('d.contact_id',$person->id)->where('d.channel_id',$c->channel_id)->where('d.consumed',false)->where('d.expires_at','>',now()->toIso8601String())->where('r.mode','confirmation')->whereIn('m.status',['sent','delivered','read'])->select('d.*','r.actor_id')->orderByDesc('d.id')->first();
   if(!$d)return false;
   if($input==='menu'){$s->q('agenda_outreach_deliveries')->where('id',$d->id)->update(['consumed'=>true]);return false;}
   $u=User::withoutGlobalScopes()->where('tenant_id',$m->tenant_id)->find($d->actor_id);if(!$u?->canEnterAgenda() || !($u->admin || $u->perfil==='admin'))return false;
   $e=AgendaCalendarEntry::where('contact_id',$person->id)->find($d->entry_id);
   if(!$e || !$e->active || $e->status!=='confirmado' || $e->start_at->isPast() || $e->version!==$d->entry_version){$s->q('agenda_outreach_deliveries')->where('id',$d->id)->update(['consumed'=>true]);$text='Esse agendamento mudou. Digite MENU para consultar a versão atual ou ATENDENTE para ajuda.';}
   else {
    $reply=$d->reply?json_decode($d->reply,true):['step'=>'manage','data'=>['entry'=>$e->id,'entry_version'=>$e->version],'invalid'=>0];
    $cfg=(object)['business_name'=>Tenant::findOrFail($m->tenant_id)->nome,'greeting'=>'Como podemos ajudar?','handoff'=>'Vou encaminhar você para a equipe.'];
    if($m->tipo!=='text'){$text='Por gentileza, responda por texto ou selecione uma opção válida.';$step=$reply['step'];$data=$reply['data'];$invalid=true;}
    else {[$step,$data,$text]=app(AgendaGuidedFlow::class)->advance($u,$cfg,$person->id,$reply['step'],$reply['data'],(string)$m->corpo);$invalid=$step===$reply['step'] && $data===$reply['data'];}
    $errors=$invalid?($reply['invalid']+1):0;
    if($errors>=2){$step='human';$text='Vou encaminhar você para a equipe para ajudar com o agendamento.';}
    elseif($invalid)$text="Por gentileza, selecione uma opção válida.\n".$text;
    $human=$step==='human';$s->q('agenda_outreach_deliveries')->where('id',$d->id)->update(['reply'=>json_encode(['step'=>$step,'data'=>$data,'invalid'=>$errors]),'consumed'=>in_array($step,['menu','human'],true),'updated_at'=>now()->toIso8601String()]);
   }
  }
  if($human){DB::table('agenda_flow_sessions')->updateOrInsert(['conversation_id'=>$c->id],['tenant_id'=>$m->tenant_id,'step'=>'human','data'=>'{}','expires_at'=>now()->addMinutes(30)->toIso8601String(),'created_at'=>now()->toIso8601String(),'updated_at'=>now()->toIso8601String()]);$c->forceFill(['status'=>'nova'])->saveQuietly();ConversationEvent::create(['conversation_id'=>$c->id,'tipo'=>'transferencia','descricao'=>'Resposta ao lembrete encaminhada à equipe.']);}
  DB::table('agenda_flow_inputs')->insert(['tenant_id'=>$m->tenant_id,'message_id'=>$m->id,'created_at'=>now()->toIso8601String()]);
  $out=Message::create(['conversation_id'=>$c->id,'channel_id'=>$c->channel_id,'direcao'=>'out','tipo'=>'text','corpo'=>$text,'status'=>'queued','automatica'=>true]);
  \App\Jobs\SendTextMessage::dispatch($out->id)->onQueue('agenda-inbox')->afterCommit();return true;
 }
}

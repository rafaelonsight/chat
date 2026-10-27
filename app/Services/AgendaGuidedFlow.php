<?php
namespace App\Services;
use App\Models\{Message,Conversation,User,AgendaService,AgendaProfessional,AgendaSettings,AgendaCalendarEntry};
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class AgendaGuidedFlow {
 public function settings(int $channel){return DB::table('agenda_flow_settings')->where('tenant_id',TenantContext::get())->where('channel_id',$channel)->first();}
 public function save(User $u,int $channel,array $d):void {
  app(AgendaCalendar::class)->administrator($u);$c=\App\Models\Channel::withoutGlobalScopes()->where('tenant_id',$u->tenant_id)->where('tipo','evolution')->findOrFail($channel);
  validator($d,['enabled'=>'required|boolean','business_name'=>'required|string|max:120','greeting'=>'required|string|max:600','address'=>'nullable|string|max:240','handoff'=>'required|string|max:400'])->validate();
  validator($d,['professional_term'=>['sometimes','required','string','max:40','regex:/^[\p{L} ]+$/u'],'assignment_strategy'=>'sometimes|required|in:alphabetical,least_booked','handoff_after_hours'=>'sometimes|nullable|string|max:600','support_hours'=>'sometimes|array|max:14','support_hours.*.weekday'=>'required|integer|between:0,6','support_hours.*.start'=>'required|date_format:H:i','support_hours.*.end'=>'required|date_format:H:i'])->validate();foreach($d['support_hours']??[] as $h)if($h['end']<=$h['start'])throw ValidationException::withMessages(['support_hours'=>'O fim do atendimento deve ser após o início.']);
  $values=array_intersect_key($d,array_flip(['enabled','business_name','greeting','address','handoff','professional_term','assignment_strategy','handoff_after_hours','support_hours']));if(isset($values['support_hours']))$values['support_hours']=json_encode($values['support_hours']);
  DB::table('agenda_flow_settings')->updateOrInsert(['tenant_id'=>$u->tenant_id,'channel_id'=>$c->id],$values+['actor_id'=>$u->id,'created_at'=>now()->toIso8601String(),'updated_at'=>now()->toIso8601String()]);
 }
 public function receive(int $id):void {
  $m=Message::withoutGlobalScopes()->find($id);if(!$m || $m->direcao!=='in')return;
  TenantContext::runAs($m->tenant_id,function()use($m){DB::transaction(function()use($m){
   DB::table('tenants')->where('id',$m->tenant_id)->lockForUpdate()->first();
   $c=Conversation::withoutGlobalScopes()->where('tenant_id',$m->tenant_id)->whereKey($m->conversation_id)->lockForUpdate()->first();$cfg=$c?$this->settings($c->channel_id):null;
   if(DB::table('agenda_flow_inputs')->where('message_id',$m->id)->exists())return;
   if($c && app(AgendaOutreachReplies::class)->receive($m,$c))return;
   if(!$cfg?->enabled || $c->atendente_id || $c->channel?->tipo!=='evolution' || $c->contact?->eGrupo() || $c->contact?->bloqueado() || $c->contact?->anonimizado_em)return;
   if(DB::table('agenda_flow_inputs')->where('message_id',$m->id)->exists())return;
   $actor=User::withoutGlobalScopes()->where('tenant_id',$m->tenant_id)->find($cfg->actor_id);if(!$actor || !$actor->canEnterAgenda() || !($actor->admin || $actor->perfil==='admin'))return;
   $s=DB::table('agenda_flow_sessions')->where('conversation_id',$c->id)->first();$data=$s?json_decode($s->data,true):[];$step=$s?->step??'menu';
   if($step==='human')return;
   if($s?->expires_at && \Carbon\Carbon::parse($s->expires_at)->isPast()){$step='menu';$data=[];}
   if($m->tipo!=='text'){$text='Neste fluxo, envie texto ou o número da opção. Digite ATENDENTE para falar com a equipe.';}
   else {$result=$cfg->editor_revision_id?app(AgendaFlowEditor::class)->advance($actor,$cfg,$c->contact_id,$data,(string)$m->corpo):$this->advance($actor,$cfg,$c->contact_id,$step,$data,(string)$m->corpo);[$step,$data,$text]=$result;}
   DB::table('agenda_flow_sessions')->updateOrInsert(['conversation_id'=>$c->id],['tenant_id'=>$m->tenant_id,'step'=>$step,'data'=>json_encode($data),'expires_at'=>now()->addMinutes(30)->toIso8601String(),'created_at'=>now()->toIso8601String(),'updated_at'=>now()->toIso8601String()]);
   DB::table('agenda_flow_inputs')->insert(['tenant_id'=>$m->tenant_id,'message_id'=>$m->id,'created_at'=>now()->toIso8601String()]);
   if($step==='human'){$c->forceFill(['status'=>'nova'])->saveQuietly();$context=$data['_handoff_context']??[];$detail="";foreach(['service'=>'Serviço','professional'=>'Profissional','date'=>'Data solicitada','time'=>'Horário solicitado'] as $field=>$label)if(!empty($context[$field]))$detail.="\n".$label.': '.$context[$field];\App\Models\ConversationEvent::create(['conversation_id'=>$c->id,'tipo'=>'transferencia','descricao'=>($data['_handoff_reason']??null)==='invalid_options'?'Duas opções inválidas consecutivas. Encaminhado à fila de atendimento; fluxo pausado.':'Cliente solicitou atendimento humano. Fluxo guiado pausado.'.$detail,'dados'=>['flow_step'=>'human','reason'=>$data['_handoff_reason']??'requested','context'=>$context]]);}
   $out=Message::create(['tenant_id'=>$m->tenant_id,'conversation_id'=>$c->id,'channel_id'=>$c->channel_id,'direcao'=>'out','tipo'=>'text','corpo'=>$text,'status'=>Message::STATUS_QUEUED,'automatica'=>true]);
   DB::table('agenda_flow_inputs')->where('message_id',$m->id)->update(['output_id'=>$out->id]);
   $c->forceFill(['ultima_msg_em'=>now()])->saveQuietly();\App\Jobs\SendTextMessage::dispatch($out->id)->onQueue('agenda-inbox')->afterCommit();
  });});
 }
 public function advance(User $actor,object $cfg,int $contact,string $step,array $d,string $input):array {
  $engine=app(AgendaCalendar::class);$engine->administrator($actor);$value=mb_strtolower(trim($input));$n=ctype_digit($value)?(int)$value:null;
  $menu=$cfg->business_name."\n".$cfg->greeting."\n\n1 — Agendar\n2 — Meu agendamento\n3 — Falar com a equipe\n\nDigite o número. MENU volta ao início; ATENDENTE chama a equipe.";
  if(in_array($value,['atendente','humano','equipe']) || ($step==='menu' && $n===3))return ['human',$d,$cfg->handoff];
  if($value==='menu')return ['menu',[],$menu];
  $person=$contact?\App\Models\Contact::where('tipo','pessoa')->whereNull('anonimizado_em')->findOrFail($contact):null;
  $known=$person && trim((string)$person->nome)!=='' && (in_array('cliente',$person->papeis??[],true) || blank($person->whatsapp_nome));
  if($step==='name'){
   $name=preg_replace('/\s+/u',' ',trim($input));
   if(mb_strlen($name)<2 || mb_strlen($name)>120 || !preg_match('/\p{L}/u',$name) || preg_match('/[\r\n\x00-\x1f<>]/u',$input) || in_array($value,['menu','sim','não','nao']))return ['name',$d,'Como você gostaria de ser chamado? Envie seu nome (de 2 a 120 caracteres) ou ATENDENTE.'];
   if($person){$person->forceFill(['nome'=>$name,'papeis'=>array_values(array_unique(array_merge($person->papeis??[],['cliente'])))])->save();}
   return ['menu',[],'Prazer, '.$name.'! '.$menu];
  }
  if($person && !$known)return ['name',[],'Olá! Você está falando com '.$cfg->business_name.'. Para começar seu agendamento, como você gostaria de ser chamado?'];
  if($known)$menu='Olá, '.$person->nome.'! '.$menu;
  try {
   if($step==='menu'){
    if($n===1)return $this->services();
    if($n===2){$rows=AgendaCalendarEntry::where('contact_id',$contact)->where('kind','booking')->where('active',true)->where('start_at','>',now())->orderBy('start_at')->limit(20)->get();if($rows->isEmpty())return ['menu',[],'Você não tem agendamentos futuros. Digite 1 para agendar ou ATENDENTE para ajuda.'];$items=$rows->map(fn($e)=>['id'=>$e->id,'label'=>$e->snapshot['service'].' com '.$e->snapshot['professional'].' · '.AgendaPreferredTime::label($e->start_at->timezone(AgendaSettings::current()->timezone)->format('Y-m-d'),$e->start_at->timezone(AgendaSettings::current()->timezone)->format('H:i'))])->all();return ['appointments',['options'=>$items],$this->list('Qual agendamento deseja consultar?',$items)];}
    return ['menu',[],$menu];
   }
   if($step==='services'){$item=$this->pick($d,$n);if(!$item)return [$step,$d,$this->list('Escolha o número do serviço.',$d['options'])];$s=AgendaService::where('ativo',true)->findOrFail($item['id']);$d=['service'=>$s->id];$items=AgendaProfessional::where('ativo',true)->whereHas('services',fn($q)=>$q->where('agenda_services.id',$s->id))->orderBy('nome')->limit(50)->get()->map(fn($p)=>['id'=>$p->id,'label'=>$p->nome])->all();if(!$items)return ['menu',[],'Ainda não há profissional habilitado para este serviço. Digite ATENDENTE.'];$d['options']=$items;return ['professionals',$d,$this->list('Com qual profissional?',$items)];}
   if($step==='professionals'){$p=$this->pick($d,$n);if(!$p)return [$step,$d,$this->list('Escolha o número do profissional.',$d['options'])];$d['professional']=$p['id'];unset($d['options']);return ['date',$d,'Qual dia? Envie DD/MM/AAAA (ex.: '.now(AgendaSettings::current()->timezone)->addDay()->format('d/m/Y').').'];}
   if($step==='date'){
    if(!empty($d['reschedule'])&&!preg_match('/^\d{2}\/\d{2}\/\d{4}$/',$value)){$this->owned($contact,$d['reschedule']);try{$at=app(AgendaPreferredTime::class)->parse($input);}catch(ValidationException){return ['date',$d,'Informe o novo dia e horário, por exemplo: sexta às 15h, ou somente a data DD/MM/AAAA.'];}$d['date']=$at->format('Y-m-d');$time=$at->format('H:i');$slots=$engine->slots($actor,$d['professional'],$d['service'],$d['date'],$d['reschedule']);if(in_array($time,$slots,true)){$d['options']=[['id'=>$time,'label'=>$time]];return $this->advance($actor,$cfg,$contact,'times',$d,'1');}$options=app(AgendaPreferredTime::class)->nextForProfessional($actor,$d['professional'],$d['service'],$d['date'],$time,$d['reschedule']);if(!$options)return ['date',$d,'Não encontrei horários depois dessa preferência. Envie outro dia e horário ou ATENDENTE.'];$d['options']=$options;return ['rebooking_choices',$d,$this->list('Esse horário não está livre. Escolha uma alternativa:',$options)];}
    $date=\Carbon\CarbonImmutable::createFromFormat('!d/m/Y',$value,AgendaSettings::current()->timezone);if(!$date || $date->format('d/m/Y')!==$value)throw new \InvalidArgumentException();$d['date']=$date->format('Y-m-d');$d['offset']=0;return $this->times($actor,$d);
   }
   if($step==='rebooking_choices'){$item=$this->pick($d,$n);if(!$item)return [$step,$d,$this->list('Escolha o número da alternativa.',$d['options'])];$d['date']=$item['date'];$d['options']=[['id'=>$item['id'],'label'=>$item['label']]];return $this->advance($actor,$cfg,$contact,'times',$d,'1');}
   if($step==='times'){
    if($value==='mais'){$d['offset']=($d['offset']??0)+10;return $this->times($actor,$d);}
    $item=$this->pick($d,$n);if(!$item)return [$step,$d,$this->list('Escolha um horário.',$d['options'])."\nMAIS — próximos horários\nMENU — começar novamente"];
    $d['time']=$item['id'];$s=AgendaService::findOrFail($d['service']);$p=AgendaProfessional::findOrFail($d['professional']);$existing=!empty($d['reschedule'])?$this->owned($contact,$d['reschedule']):null;$d['price']=(string)($existing?->price??$s->preco);$d['duration']=$existing?->duration_minutes??$s->duracao_minutos;$d['buffer']=$existing?->buffer_minutes??$s->intervalo_minutos;$d['key']=(string)Str::uuid();$tz=AgendaSettings::current()->timezone;
    $text=($existing?->snapshot['service']??$s->nome).' · '.($existing?->snapshot['professional']??$p->nome)."\n".$d['date'].' às '.$d['time']." ($tz)\nPreço: R$ ".number_format((float)$d['price'],2,',','.')."\nDuração: ".$d['duration']." min\n".($cfg->address?:'')."\nCancelamento/remarcação: antecedência de ".AgendaSettings::current()->cancel_notice_minutes." minutos.\n\n1 — Confirmar\n2 — Escolher outro horário\nHorário só fica reservado após confirmar.";
    if($existing){$old=$existing->start_at->timezone($tz);$text='Horário atual: '.AgendaPreferredTime::label($old->format('Y-m-d'),$old->format('H:i'))."\n\nNovo horário:\n".$text;}
    return ['review',$d,$text];
   }
   if($step==='review'){
    if($n===2)return $this->times($actor,$d);if($n!==1)return [$step,$d,'Digite 1 para confirmar ou 2 para escolher outro horário.'];
    $s=AgendaService::findOrFail($d['service']);if(empty($d['reschedule']) && ((string)$s->preco!==$d['price'] || $s->duracao_minutos!==$d['duration'] || $s->intervalo_minutos!==$d['buffer']))return $this->services('Os dados do serviço mudaram. Escolha novamente para conferir o preço e o tempo.');
    if(!empty($d['reschedule'])){$e=$this->owned($contact,$d['reschedule']);$e=$engine->reschedule($actor,$e->id,$d['entry_version'],$d['date'].' '.$d['time']);}
    else $e=$engine->book($actor,['professional_id'=>$d['professional'],'service_id'=>$d['service'],'contact_id'=>$contact,'start'=>$d['date'].' '.$d['time']],$d['key'],'whatsapp');
    return ['menu',[],'Agendamento confirmado: '.$e->snapshot['service'].' com '.$e->snapshot['professional'].', '.$e->start_at->timezone(AgendaSettings::current()->timezone)->format('d/m/Y H:i').".\nDigite 2 para consultar seu agendamento ou ATENDENTE para ajuda."];
   }
   if($step==='appointments'){$i=$this->pick($d,$n);if(!$i)return [$step,$d,$this->list('Escolha o número do agendamento.',$d['options'])];$e=$this->owned($contact,$i['id']);return ['manage',['entry'=>$e->id,'entry_version'=>$e->version],($person?->nome??'Cliente').', este é o seu agendamento:'."\n\n".$e->snapshot['service'].' com '.$e->snapshot['professional'].' · '.AgendaPreferredTime::label($e->start_at->timezone(AgendaSettings::current()->timezone)->format('Y-m-d'),$e->start_at->timezone(AgendaSettings::current()->timezone)->format('H:i'))."\n1 — Confirmar presença\n2 — Reagendar\n3 — Cancelar\nATENDENTE — falar com a equipe"] ;}
   if($step==='manage'){$e=$this->owned($contact,$d['entry']);if($e->version!==$d['entry_version'])return ['menu',[],'O agendamento mudou. Digite 2 para consultar novamente.'];
    if($n===1){$engine->presence($actor,$e->id,$e->version,'confirmada');return ['menu',[],'Presença confirmada. Esperamos você! Digite MENU para outras opções.'];}
    if($n===2)return ['date',['service'=>$e->service_id,'professional'=>$e->professional_id,'reschedule'=>$e->id,'entry_version'=>$e->version],'Qual o novo dia e horário? Exemplo: sexta às 15h. Você também pode enviar somente a data DD/MM/AAAA.'];
    if($n===3)return ['cancel',$d,'Deseja cancelar este agendamento?\n1 — Confirmar cancelamento\n2 — Manter agendamento'];
    return [$step,$d,'Digite 1 para presença, 2 para remarcar ou 3 para cancelar.'];
   }
   if($step==='cancel'){if($n===2)return ['menu',[],'Agendamento mantido. Digite MENU.'];if($n!==1)return [$step,$d,'Digite 1 para cancelar ou 2 para manter.'];$e=$this->owned($contact,$d['entry']);$engine->cancel($actor,$e->id,$d['entry_version']);return ['menu',[],'Agendamento cancelado. Digite 1 para agendar novamente.'];}
  }catch(ValidationException $e){if($step==='review')return $this->times($actor,$d,'Não foi possível confirmar esse horário. Consulte outras opções ou digite ATENDENTE.');return ['menu',[],'A operação não está disponível nas regras atuais. Digite ATENDENTE para ajuda ou MENU para recomeçar.'];}
  catch(\Illuminate\Database\Eloquent\ModelNotFoundException){return ['menu',[],'Essa opção mudou ou não está mais disponível. Digite MENU para recomeçar.'];}
  catch(\InvalidArgumentException|\Carbon\Exceptions\InvalidFormatException){return ['date',$d,'Data inválida. Envie DD/MM/AAAA.'];}
  return ['menu',[],$menu];
 }
 private function owned(int $contact,int $id){return AgendaCalendarEntry::where('contact_id',$contact)->where('kind','booking')->where('active',true)->where('start_at','>',now())->findOrFail($id);}
 public function services(string $title='Qual serviço deseja agendar?'):array {$items=AgendaService::where('ativo',true)->orderBy('nome')->limit(50)->get()->map(fn($s)=>['id'=>$s->id,'label'=>$s->nome.' · R$ '.number_format((float)$s->preco,2,',','.').' · '.$s->duracao_minutos.' min'])->all();return $items?['services',['options'=>$items],$this->list($title,$items)]:['menu',[],'Ainda não há serviços disponíveis. Digite ATENDENTE.'];}
 public function times(User $actor,array $d,string $title='Escolha um horário disponível.'):array {$slots=app(AgendaCalendar::class)->slots($actor,$d['professional'],$d['service'],$d['date'],$d['reschedule']??null);$items=array_map(fn($t)=>['id'=>$t,'label'=>$t],array_slice($slots,$d['offset']??0,10));if(!$items){$d['offset']=0;return ['date',$d,'Não há horários nesta seleção. Envie outro dia (DD/MM/AAAA) ou ATENDENTE.'];}$d['options']=$items;return ['times',$d,$this->list($title.' '.$d['date'],$items)."\nMAIS — próximos horários\nMENU — recomeçar"];}
 private function pick(array $d,?int $n):?array {return $n && $n>0?($d['options'][$n-1]??null):null;}
 private function list(string $title,array $items):string {return $title."\n\n".implode("\n",array_map(fn($i,$n)=>($n+1).' — '.$i['label'],$items,array_keys($items)))."\n\nATENDENTE — falar com a equipe";}
}

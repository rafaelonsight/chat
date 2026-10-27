<?php
namespace App\Services;

use App\Models\{AgendaCalendarEntry, AgendaProfessional, AgendaService, AgendaSettings, Contact, User};
use App\Support\{TenantContext, PhoneNumber};
use Carbon\CarbonImmutable as Time;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgendaCalendar {
 public function actor(User $actor): User {
  $fresh=User::withoutGlobalScopes()->findOrFail($actor->id);
  abort_unless($fresh->canEnterAgenda() && (int)$fresh->tenant_id===TenantContext::get(),403);return $fresh;
 }
 public function administrator(User $actor): User {
  $fresh=$this->actor($actor);abort_unless($fresh->admin || $fresh->perfil==='admin',403);return $fresh;
 }
 public function canManage(User $actor): bool {
  $actor=$this->actor($actor);
  return !$actor->ehAuditor() && ($actor->admin || in_array($actor->perfil,['admin','supervisor','atendente'],true) || ($actor->isAgendaProfessional() && AgendaSettings::current()->professional_manage));
 }
 public function professional(User $actor,int $id,bool $write=false): AgendaProfessional {
  $actor=$this->actor($actor);if($write)abort_unless($this->canManage($actor),403);
  $p=AgendaProfessional::findOrFail($id);
  if($actor->isAgendaProfessional())abort_unless($p->user_id===$actor->id,403);
  return $p;
 }
 public function entries(User $actor,?int $professional=null) {
  $actor=$this->actor($actor);$query=AgendaCalendarEntry::query();
  if($actor->isAgendaProfessional())$query->where('professional_id',$actor->agendaProfessional()->id);
  if($professional){$this->professional($actor,$professional);$query->where('professional_id',$professional);}
  return $query;
 }
 public function entry(User $actor,int $id,bool $write=false): AgendaCalendarEntry {
  $entry=$this->entries($actor)->findOrFail($id);$this->professional($actor,$entry->professional_id,$write);return $entry;
 }
 private function fail(string $message,string $key='agenda'): never {throw ValidationException::withMessages([$key=>__($message)]);}
 private function transaction(callable $fn): mixed {
  try{return DB::transaction($fn,3);}catch(QueryException $e){
   if($e->getCode()==='23P01')$this->fail('Este horário acabou de ser ocupado. Escolha outro horário.');
   if($e->getCode()==='23505')$this->fail('Esta solicitação já foi processada. Atualize a agenda.');
   throw $e;
  }
 }
 private function lock(User $actor,int $professional): AgendaProfessional {
  DB::table('tenants')->where('id',$actor->tenant_id)->lockForUpdate()->first();
  $this->actor($actor);
  return AgendaProfessional::whereKey($professional)->lockForUpdate()->firstOrFail();
 }
 public function local(string $value,?string $timezone=null): Time {
  $tz=$timezone ?? AgendaSettings::current()->timezone;
  validator(['date'=>$value],['date'=>'required|date_format:Y-m-d H:i'])->validate();
  try{$local=Time::createFromFormat('!Y-m-d H:i',$value,$tz);}catch(\Throwable){$this->fail('Informe uma data e um horário válidos.');}
  if($local->format('Y-m-d H:i')!==$value)$this->fail('Esse horário não existe no fuso escolhido. Escolha outro horário.');
  // Reject duplicated wall times during daylight-saving transitions rather than guessing.
  $zone=new \DateTimeZone($tz);$offsets=[$local->utcOffset()*60];
  foreach($zone->getTransitions($local->timestamp-86400,$local->timestamp+86400) ?: [] as $transition)$offsets[]=$transition['offset'];
  $wall=Time::createFromFormat('!Y-m-d H:i',$value,'UTC')->timestamp;$matches=0;
  foreach(array_unique($offsets) as $offset)if(Time::createFromTimestampUTC($wall-$offset)->setTimezone($tz)->format('Y-m-d H:i')===$value)$matches++;
  if($matches>1)$this->fail('Esse horário é ambíguo no fuso escolhido. Escolha outro horário.');
  return $local->utc();
 }
 public function settings(User $actor,array $data): AgendaSettings {
  $this->administrator($actor);
  return $this->transaction(function()use($actor,$data){
   DB::table('tenants')->where('id',$actor->tenant_id)->lockForUpdate()->first();$this->administrator($actor);
   $settings=AgendaSettings::current();
   if($settings->timezone!==($data['timezone']??$settings->timezone) && AgendaCalendarEntry::where('active',true)->where('occupied_until','>',now('UTC')->toIso8601String())->exists())$this->fail('Para alterar o fuso, primeiro resolva os agendamentos e bloqueios futuros.');
   $settings->fill($data)->save();return $settings;
  });
 }
 public function hours(User $actor,int $professional,array $rows): void {
  $this->administrator($actor);$this->professional($actor,$professional);
  validator(['hours'=>$rows],['hours'=>'array|max:28','hours.*.weekday'=>'required|integer|between:0,6','hours.*.start'=>'required|date_format:H:i','hours.*.end'=>['required','regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$|^24:00$/']])->validate();
  $normal=[];
  foreach($rows as $row){[$h,$m]=explode(':',$row['start']);[$eh,$em]=explode(':',$row['end']);$a=(int)$h*60+(int)$m;$b=(int)$eh*60+(int)$em;
   if($b<=$a)$this->fail('O fim da jornada deve ser depois do início. Divida turnos que atravessam a meia-noite.');
   foreach($normal as $r)if($r['weekday']===(int)$row['weekday'] && $a<$r['end_minute'] && $b>$r['start_minute'])$this->fail('As faixas de trabalho não podem se sobrepor.');
   $normal[]=['weekday'=>(int)$row['weekday'],'start_minute'=>$a,'end_minute'=>$b];
  }
  $this->transaction(function()use($actor,$professional,$normal){
   $this->lock($actor,$professional);$this->administrator($actor);
   foreach(AgendaCalendarEntry::where('professional_id',$professional)->where('kind','booking')->where('active',true)->where('occupied_until','>',now('UTC')->toIso8601String())->get() as $entry){
    if(!$this->insideHours($entry->start_at,$entry->occupied_until,$normal,AgendaSettings::current()->timezone))$this->fail('Essa jornada retiraria um atendimento já reservado. Remarque ou cancele antes de salvar.');
   }
   DB::table('agenda_work_hours')->where('tenant_id',$actor->tenant_id)->where('professional_id',$professional)->delete();
   foreach($normal as $row)DB::table('agenda_work_hours')->insert($row+['tenant_id'=>$actor->tenant_id,'professional_id'=>$professional,'created_at'=>now('UTC')->toIso8601String(),'updated_at'=>now('UTC')->toIso8601String()]);
  });
 }
 public function workHours(int $professional): array {
  return DB::table('agenda_work_hours')->where('tenant_id',TenantContext::get())->where('professional_id',$professional)->orderBy('weekday')->orderBy('start_minute')->get()->map(fn($r)=>(array)$r)->all();
 }
 private function insideHours(Time $start,Time $end,array $rows,string $tz): bool {
  $day=$start->setTimezone($tz);$date=$day->format('Y-m-d');
  foreach($rows as $row)if((int)$row['weekday']===$day->dayOfWeek){
   try{
    $a=$this->local($date.' '.sprintf('%02d:%02d',intdiv($row['start_minute'],60),$row['start_minute']%60),$tz);
    $b=$row['end_minute']===1440 ? $this->local($day->addDay()->format('Y-m-d').' 00:00',$tz) : $this->local($date.' '.sprintf('%02d:%02d',intdiv($row['end_minute'],60),$row['end_minute']%60),$tz);
    if($start->gte($a) && $end->lte($b))return true;
   }catch(ValidationException){continue;}
  }
  return false;
 }
 private function checkWindow(AgendaProfessional $p,Time $start,int $duration,int $buffer,?int $ignore=null): array {
  $cfg=AgendaSettings::current();$now=Time::now('UTC');$end=$start->addMinutes($duration);$occupied=$end->addMinutes($buffer);
  if(!$p->ativo)$this->fail('Este profissional está inativo.');
  if($start->lt($now->addMinutes($cfg->min_notice_minutes)))$this->fail('O horário não respeita a antecedência mínima.');
  if($start->gt($now->setTimezone($cfg->timezone)->addDays($cfg->horizon_days)->endOfDay()->utc()))$this->fail('O horário ultrapassa o limite de dias para agendar.');
  if(!$this->insideHours($start,$occupied,$this->workHours($p->id),$cfg->timezone))$this->fail('O atendimento e o intervalo precisam caber na jornada do profissional.');
  $query=AgendaCalendarEntry::where('professional_id',$p->id)->where('active',true)->where('start_at','<',$occupied->toIso8601String())->where('occupied_until','>',$start->toIso8601String());
  if($ignore)$query->whereKeyNot($ignore);
  if($query->exists())$this->fail('Este horário acabou de ser ocupado. Escolha outro horário.');
  return [$end,$occupied];
 }
 public function slots(User $actor,int $professional,int $service,string $date,?int $reschedule=null): array {
  $p=$this->professional($actor,$professional);$s=AgendaService::findOrFail($service);
  if(!$s->ativo || !$p->services()->whereKey($s->id)->exists())return [];
  $duration=$s->duracao_minutos;$buffer=$s->intervalo_minutos;
  if($reschedule){$entry=$this->entry($actor,$reschedule);abort_unless($entry->professional_id===$p->id && $entry->service_id===$s->id,403);$duration=$entry->duration_minutes;$buffer=$entry->buffer_minutes;}
  $cfg=AgendaSettings::current();$start=$this->local($date.' 00:00',$cfg->timezone);$finish=$this->local($start->setTimezone($cfg->timezone)->addDay()->format('Y-m-d').' 00:00',$cfg->timezone);$slots=[];
  // Query occupation once, then evaluate weekly intervals without one SQL query per slot.
  $entries=AgendaCalendarEntry::where('professional_id',$p->id)->where('active',true)->where('start_at','<',$finish->toIso8601String())->where('occupied_until','>',$start->toIso8601String())->when($reschedule,fn($q)=>$q->whereKeyNot($reschedule))->get();
  $hours=$this->workHours($p->id);$now=Time::now('UTC');$limit=$now->setTimezone($cfg->timezone)->addDays($cfg->horizon_days)->endOfDay()->utc();
  for($t=$start;$t->lt($finish);$t=$t->addMinutes($cfg->slot_minutes)){
   $until=$t->addMinutes($duration+$buffer);
   if(!$p->ativo || $t->lt($now->addMinutes($cfg->min_notice_minutes)) || $t->gt($limit) || !$this->insideHours($t,$until,$hours,$cfg->timezone))continue;
   if($entries->contains(fn($e)=>$e->start_at->lt($until) && $e->occupied_until->gt($t)))continue;
   try{$label=$t->setTimezone($cfg->timezone)->format('H:i');$this->local($date.' '.$label,$cfg->timezone);}catch(ValidationException){continue;}
   $slots[]=$label;
  }
  return $slots;
 }
 private function client(User $actor,array $data): Contact {
  $actor=$this->actor($actor);
  if(!empty($data['contact_id'])){
   $client=Contact::withoutGlobalScopes()->where('tenant_id',$actor->tenant_id)->where('tipo',Contact::PESSOA)->whereNull('anonimizado_em')->findOrFail($data['contact_id']);
   if($actor->isAgendaProfessional() && !$this->entries($actor)->where('contact_id',$client->id)->exists())abort(403);
   return $client;
  }
  validator($data,['client_name'=>'required|string|max:120','client_phone'=>'required|string|max:30'])->validate();
  $phone=PhoneNumber::toE164($data['client_phone']);if(!$phone)$this->fail('Informe um telefone válido com código de área.','client_phone');
  $client=Contact::withoutGlobalScopes()->where('tenant_id',$actor->tenant_id)->where('tipo',Contact::PESSOA)->whereIn('telefone_e164',PhoneNumber::variantes($phone))->first();
  if($client){if($client->anonimizado_em)$this->fail('Este cadastro não está disponível. Fale com o administrador.');return $client;}
  return Contact::create(['tenant_id'=>$actor->tenant_id,'nome'=>trim($data['client_name']),'telefone_e164'=>$phone,'papeis'=>['cliente']]);
 }
 public function book(User $actor,array $data,string $key,string $source='manual'): AgendaCalendarEntry {
  $this->professional($actor,(int)($data['professional_id']??0),true);
  validator($data,['service_id'=>'required|integer','professional_id'=>'required|integer','start'=>'required|date_format:Y-m-d H:i'])->validate();
  validator(['key'=>$key,'source'=>$source],['key'=>'required|uuid','source'=>'required|in:manual,whatsapp'])->validate();
  $fingerprint=hash('sha256',json_encode(array_intersect_key($data,array_flip(['professional_id','service_id','contact_id','client_name','client_phone','start']))));
  return $this->transaction(function()use($actor,$data,$key,$source,$fingerprint){
   $p=$this->lock($actor,(int)$data['professional_id']);$this->professional($actor,$p->id,true);
   if($old=AgendaCalendarEntry::where('source',$source)->where('request_key',$key)->first()){
    $this->entry($actor,$old->id,true);if($old->request_fingerprint!==$fingerprint)$this->fail('Esta solicitação já foi usada com outros dados. Abra um novo agendamento.');return $old;
   }
   $service=AgendaService::whereKey($data['service_id'])->lockForUpdate()->firstOrFail();
   if(!$service->ativo || !$p->services()->whereKey($service->id)->exists())$this->fail('Escolha um serviço ativo executado por este profissional.');
   $start=$this->local($data['start']);[$end,$occupied]=$this->checkWindow($p,$start,$service->duracao_minutos,$service->intervalo_minutos);
   $client=$this->client($actor,$data);
   $entry=AgendaCalendarEntry::create(['professional_id'=>$p->id,'service_id'=>$service->id,'contact_id'=>$client->id,'created_by'=>$actor->id,'kind'=>'booking','status'=>'confirmado','presence'=>'pendente','active'=>true,'start_at'=>$start,'end_at'=>$end,'occupied_until'=>$occupied,'price'=>$service->preco,'duration_minutes'=>$service->duracao_minutos,'buffer_minutes'=>$service->intervalo_minutos,'snapshot'=>['service'=>$service->nome,'professional'=>$p->nome,'client'=>$client->nome,'phone'=>$client->telefone_e164,'timezone'=>AgendaSettings::current()->timezone,'currency'=>config('agenda.currency')],'source'=>$source,'request_key'=>$key,'request_fingerprint'=>$fingerprint,'version'=>1]);
   $this->event($actor,$entry,'criado');return $entry;
  });
 }
 public function block(User $actor,int $professional,string $from,string $to,string $reason): AgendaCalendarEntry {
  $this->administrator($actor);$this->professional($actor,$professional);
  validator(['reason'=>$reason],['reason'=>'required|string|max:200'])->validate();
  return $this->transaction(function()use($actor,$professional,$from,$to,$reason){
   $this->lock($actor,$professional);$this->administrator($actor);$start=$this->local($from);$end=$this->local($to);
   if($end->lte($start) || $end->gt($start->addDays(366)))$this->fail('O bloqueio deve terminar depois do início e durar no máximo um ano.');
   $entry=AgendaCalendarEntry::create(['professional_id'=>$professional,'created_by'=>$actor->id,'kind'=>'block','status'=>'bloqueado','presence'=>'pendente','active'=>true,'start_at'=>$start,'end_at'=>$end,'occupied_until'=>$end,'reason'=>trim($reason),'snapshot'=>[],'version'=>1]);$this->event($actor,$entry,'bloqueado');return $entry;
  });
 }
 private function edit(User $actor,int $id,int $version,callable $fn): AgendaCalendarEntry {
  $before=$this->entry($actor,$id,true);
  return $this->transaction(function()use($actor,$id,$version,$fn,$before){
   $this->lock($actor,$before->professional_id);$entry=$this->entry($actor,$id,true);
   if($entry->version!==$version)$this->fail('Este atendimento foi alterado em outra tela. Atualize a agenda.');
   $fn($entry);return $entry->refresh();
  });
 }
 public function reschedule(User $actor,int $id,int $version,string $when): AgendaCalendarEntry {
  return $this->edit($actor,$id,$version,function($entry)use($actor,$when){
   if($entry->kind!=='booking' || $entry->status!=='confirmado')$this->fail('Somente atendimentos confirmados podem ser remarcados.');
   $this->changeDeadline($entry);$p=$this->professional($actor,$entry->professional_id,true);
   if(!$p->services()->whereKey($entry->service_id)->exists() || !AgendaService::findOrFail($entry->service_id)->ativo)$this->fail('O serviço precisa continuar ativo e vinculado para remarcar.');
   $start=$this->local($when);[$end,$occupied]=$this->checkWindow($p,$start,$entry->duration_minutes,$entry->buffer_minutes,$entry->id);
   $old=$entry->start_at->toIso8601String();$entry->forceFill(['start_at'=>$start,'end_at'=>$end,'occupied_until'=>$occupied,'presence'=>'pendente','version'=>$entry->version+1])->save();$this->event($actor,$entry,'remarcado',['previous_start'=>$old,'start'=>$start->toIso8601String()]);
  });
 }
 private function changeDeadline(AgendaCalendarEntry $entry):void {
  if($entry->start_at->lt(Time::now('UTC')->addMinutes(AgendaSettings::current()->cancel_notice_minutes)))$this->fail('O prazo de cancelamento ou remarcação já passou. Ajuste a regra com o administrador, se necessário.');
 }
 public function cancel(User $actor,int $id,int $version): AgendaCalendarEntry {
  return $this->edit($actor,$id,$version,function($entry)use($actor){
   if(!$entry->active)$this->fail('Este item já está cancelado.');
   if($entry->kind==='block')$this->administrator($actor);
   else {if($entry->status!=='confirmado')$this->fail('Somente atendimentos confirmados podem ser cancelados.');$this->changeDeadline($entry);}
   $entry->forceFill(['status'=>'cancelado','active'=>false,'version'=>$entry->version+1])->save();$this->event($actor,$entry,'cancelado');
  });
 }
 public function state(User $actor,int $id,int $version,string $state): AgendaCalendarEntry {
  return $this->edit($actor,$id,$version,function($entry)use($actor,$state){
   $next=['confirmado'=>['em_atendimento','falta'],'em_atendimento'=>['concluido']];
   if($entry->kind!=='booking' || !in_array($state,$next[$entry->status]??[],true))$this->fail('Essa alteração de estado não está disponível.');
   if($entry->start_at->gt(Time::now('UTC')))$this->fail('Aguarde o horário do atendimento para registrar este estado.');
   $entry->forceFill(['status'=>$state,'version'=>$entry->version+1])->save();$this->event($actor,$entry,$state);
  });
 }
 public function presence(User $actor,int $id,int $version,string $presence): AgendaCalendarEntry {
  return $this->edit($actor,$id,$version,function($entry)use($actor,$presence){
   if($entry->kind!=='booking' || $entry->status!=='confirmado' || !in_array($presence,['pendente','confirmada','recusada'],true))$this->fail('Essa confirmação de presença não está disponível.');
   $entry->forceFill(['presence'=>$presence,'version'=>$entry->version+1])->save();$this->event($actor,$entry,'presenca',['presence'=>$presence]);
  });
 }
 private function event(User $actor,AgendaCalendarEntry $entry,string $action,array $metadata=[]):void {
  DB::table('agenda_calendar_events')->insert(['tenant_id'=>$entry->tenant_id,'entry_id'=>$entry->id,'actor_id'=>$actor->id,'action'=>$action,'version'=>$entry->version,'metadata'=>json_encode($metadata),'created_at'=>now('UTC')->toIso8601String()]);
 }
 public function clientHistory(User $actor,int $client): array {
  return $this->entries($actor)->where('kind','booking')->where('contact_id',$client)->orderByDesc('start_at')->limit(100)->get()->map(fn($e)=>['id'=>$e->id,'start'=>$e->start_at->setTimezone(AgendaSettings::current()->timezone)->format('d/m/Y H:i'),'service'=>$e->snapshot['service'],'professional'=>$e->snapshot['professional'],'status'=>__(AgendaCalendarEntry::STATES[$e->status]),'price'=>$e->price])->all();
 }
}

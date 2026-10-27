<?php
namespace App\Filament\Pages;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use App\Models\{Channel,Contact};
use App\Services\{AgendaCalendar,AgendaOutreach};
abstract class AgendaCommunications extends Page {
 protected static string|\UnitEnum|null $navigationGroup='Automações';
 protected string $view='filament.pages.agenda-communications';
 #[\Livewire\Attributes\Locked] public string $kind='campaign';#[\Livewire\Attributes\Locked] public ?int $editing=null;public array $form=[];public array $contacts=[];public string $search='';#[\Livewire\Attributes\Locked] public ?int $report=null;public string $scheduled='';
 public ?int $preferenceContact=null;public bool $marketing=false;public bool $reminders=false;public string $source='';
 public static function canAccess():bool {return AgendaOperations::canAccess();}
 protected function guard():AgendaOutreach {app(AgendaCalendar::class)->administrator(auth()->user());return app(AgendaOutreach::class);}
 public function mount():void {$this->kind=$this instanceof AgendaFollowUp?'reminder':'campaign';$this->resetForm();}
 public function resetForm():void {$this->guard();$this->editing=null;$this->contacts=[];$this->form=['name'=>$this->kind==='reminder'?'Confirmação de presença':'','channel_id'=>'','body'=>$this->kind==='reminder'?AgendaOutreach::REMINDER:'Olá, {nome}! Aqui é da {empresa}.','enabled'=>false,'mode'=>'confirmation','minutes_before'=>30,'per_minute'=>5,'window_start'=>'09:00','window_end'=>'20:00'];}
 public function channels(){ $this->guard();return Channel::withoutGlobalScopes()->where('tenant_id',auth()->user()->tenant_id)->where('tipo','evolution')->get();}
 public function people(){ $this->guard();return Contact::where('tipo','pessoa')->whereNull('anonimizado_em')->when($this->search,fn($q)=>$q->where(fn($s)=>$s->where('nome','ilike','%'.$this->search.'%')->orWhere('telefone_e164','like','%'.$this->search.'%')))->orderBy('nome')->limit(200)->get();}
 public function rows(){ $s=$this->guard();return $s->q($this->kind==='reminder'?'agenda_reminder_rules':'agenda_outreach_campaigns')->orderByDesc('id')->limit(100)->get();}
 public function save():void {
  $s=$this->guard();if($this->kind==='reminder')$s->rule(auth()->user(),$this->form,$this->editing);
  elseif($this->editing)$s->editCampaign(auth()->user(),$this->editing,$this->form);
  else $this->report=$s->campaign(auth()->user(),$this->form,$this->contacts);
  $this->resetForm();Notification::make()->success()->title($this->kind==='reminder'?'Regra salva':'Rascunho salvo. Revise os destinatários antes de agendar.')->send();
 }
 public function edit(int $id):void {$s=$this->guard();$r=$s->q($this->kind==='reminder'?'agenda_reminder_rules':'agenda_outreach_campaigns')->where('id',$id)->first();abort_unless($r,404);abort_if($this->kind==='campaign' && $r->status!=='draft',422);$this->editing=$id;$this->form=(array)$r;}
 public function pauseRule(int $id):void {$s=$this->guard();$r=$s->q('agenda_reminder_rules')->where('id',$id)->first();abort_unless($r,404);$d=(array)$r;$d['enabled']=false;$s->rule(auth()->user(),$d,$id);}
 public function action(int $id,string $action):void {$this->guard()->campaignAction(auth()->user(),$id,$action,$this->scheduled?:null);Notification::make()->success()->title('Campanha atualizada')->send();}
 public function showReport(int $id):void {$s=$this->guard();abort_unless($s->q($this->kind==='reminder'?'agenda_reminder_rules':'agenda_outreach_campaigns')->where('id',$id)->exists(),404);$this->report=$id;}
 public function details(){ $s=$this->guard();if(!$this->report)return collect();return $s->q('agenda_outreach_deliveries as d')->leftJoin('messages as m','m.id','=','d.message_id')->join('contacts as c','c.id','=','d.contact_id')->where($this->kind==='reminder'?'d.rule_id':'d.campaign_id',$this->report)->select('d.*','m.status as message_status','m.agenda_delivery_state','m.erro as delivery_error','c.nome')->orderByDesc('d.id')->limit(200)->get();}
 public function counts():array {$s=$this->guard();if(!$this->report)return [];return $s->q('agenda_outreach_deliveries as d')->leftJoin('messages as m','m.id','=','d.message_id')->where($this->kind==='reminder'?'d.rule_id':'d.campaign_id',$this->report)->selectRaw("CASE WHEN d.state='skipped' THEN 'skipped' WHEN m.agenda_delivery_state='uncertain' THEN 'uncertain' ELSE COALESCE(m.status,d.state) END AS result, count(*) AS total")->groupByRaw('1')->pluck('total','result')->all();}
 public function loadPreference():void {$s=$this->guard();Contact::findOrFail($this->preferenceContact);$p=$s->q('agenda_communication_preferences')->where('contact_id',$this->preferenceContact)->first();$this->marketing=(bool)($p->marketing??false);$this->reminders=(bool)($p->reminders??false);$this->source=$p->source??'';}
 public function savePreference():void {$this->guard()->preference(auth()->user(),(int)$this->preferenceContact,$this->marketing,$this->reminders,$this->source);Notification::make()->success()->title('Preferências registradas')->send();}
 public function preview():string {
  $this->guard();$date=now(\App\Models\AgendaSettings::current()->timezone)->addDay();
  return strtr($this->form['body']??'',['{nome}'=>'Rafael','{empresa}'=>\App\Models\Tenant::findOrFail(auth()->user()->tenant_id)->nome,'{servico}'=>'corte','{profissional}'=>'Bruno','{data}'=>$date->format('d/m/Y'),'{hora}'=>'17:00','{dia_semana}'=>['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'][$date->dayOfWeek]]);
 }
}

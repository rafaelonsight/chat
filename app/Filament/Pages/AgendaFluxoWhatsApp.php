<?php
namespace App\Filament\Pages;
use App\Services\AgendaGuidedFlow;use Filament\Pages\Page;use Illuminate\Support\Facades\DB;
class AgendaFluxoWhatsApp extends Page {
    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

 protected static ?string $slug='fluxo-whatsapp';protected static ?string $title='Agendamento no WhatsApp';protected static string|\UnitEnum|null $navigationGroup='Configurações';protected static string|\BackedEnum|null $navigationIcon='heroicon-o-chat-bubble-left-right';
 protected string $view='filament.pages.agenda-fluxo-whatsapp';public string $channel='';public array $form=[];public string $input='';
 #[\Livewire\Attributes\Locked] public string $simStep='menu';#[\Livewire\Attributes\Locked] public array $simData=[];#[\Livewire\Attributes\Locked] public array $transcript=[];
 public static function canAccess():bool{return (bool)(auth()->user()?->canEnterAgenda() && (auth()->user()->admin || auth()->user()->perfil==='admin'));}
 private function guard():void {app(\App\Services\AgendaCalendar::class)->administrator(auth()->user());}
 public function mount():void {$this->guard();$this->form=['enabled'=>false,'business_name'=>\App\Models\Tenant::find(auth()->user()->tenant_id)?->nome??'Seu estabelecimento','greeting'=>'Olá! Sou a assistente de agendamento. Vamos encontrar um horário para você?','professional_term'=>'barbeiro','assignment_strategy'=>'alphabetical','support_hours'=>[],'handoff_after_hours'=>'Nosso atendimento humano está fora do expediente. Sua mensagem ficou na fila.','address'=>'','handoff'=>'Vou encaminhar seu pedido à equipe. Aguarde o atendimento por aqui.'];}
 public function channels(){ $this->guard();return \App\Models\Channel::withoutGlobalScopes()->where('tenant_id',auth()->user()->tenant_id)->where('tipo','evolution')->orderBy('nome')->get();}
 public function load():void {$this->guard();$c=$this->channels()->firstWhere('id',(int)$this->channel);abort_unless($c,404);$r=app(AgendaGuidedFlow::class)->settings($c->id);if($r)$this->form=(array)collect($r)->only(['enabled','business_name','greeting','address','handoff','professional_term','assignment_strategy','support_hours','handoff_after_hours'])->all();else $this->mount();if(is_string($this->form['support_hours']??null))$this->form['support_hours']=json_decode($this->form['support_hours'],true)?:[];$this->resetSimulator();}
 public function save():void {$this->guard();abort_unless($this->channel,422);app(AgendaGuidedFlow::class)->save(auth()->user(),(int)$this->channel,$this->form);\Filament\Notifications\Notification::make()->success()->title('Fluxo salvo')->send();}
 public function addSupportHours():void {$this->guard();if(count($this->form['support_hours']??[])<14)$this->form['support_hours'][]=['weekday'=>1,'start'=>'09:00','end'=>'18:00'];}
 public function removeSupportHours(int $i):void {$this->guard();unset($this->form['support_hours'][$i]);$this->form['support_hours']=array_values($this->form['support_hours']);}
 public function resetSimulator():void {$this->guard();$this->simStep='menu';$this->simData=[];$this->transcript=[];$this->input='';}
 public function simulate():void {
  $this->guard();$this->validate(['input'=>'required|string|max:200']);
  if($this->simStep==='human'){$text='Simulação transferida. Reinicie para testar novamente.';}
  elseif(in_array($this->simStep,['review','manage','cancel'])){$text='Fim da prévia: esta simulação não grava nem altera agendamentos. Reinicie para testar outro caminho.';}
  else {[$this->simStep,$this->simData,$text]=app(AgendaGuidedFlow::class)->advance(auth()->user(),(object)$this->form,0,$this->simStep,$this->simData,$this->input);}
  $this->transcript=array_slice(array_merge($this->transcript,[['client'=>$this->input,'bot'=>$text]]),-20);$this->input='';
 }
 public function paused(){ $this->guard();return DB::table('agenda_flow_sessions')->where('tenant_id',auth()->user()->tenant_id)->where('step','human')->orderByDesc('updated_at')->limit(30)->get();}
 public function resume(int $id):void {$this->guard();DB::table('agenda_flow_sessions')->where('tenant_id',auth()->user()->tenant_id)->where('id',$id)->where('step','human')->update(['step'=>'menu','data'=>'{}','expires_at'=>null,'updated_at'=>now()->toIso8601String()]);}
}

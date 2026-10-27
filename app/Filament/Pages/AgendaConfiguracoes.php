<?php
namespace App\Filament\Pages;
use App\Models\{AgendaSettings,AgendaProfessional};
use App\Services\AgendaCalendar;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class AgendaConfiguracoes extends Page {
 protected static ?string $slug='configuracoes-agenda';
 protected static ?string $title='Configurações da agenda';
 protected static string|\UnitEnum|null $navigationGroup='Seu estabelecimento';
 protected static string|\BackedEnum|null $navigationIcon=\Filament\Support\Icons\Heroicon::OutlinedClock;
 protected static ?int $navigationSort=3;
 protected string $view='filament.pages.agenda-configuracoes';
 public array $settings=[];
 public array $hours=[];
 public string $professional='';
 public static function canAccess():bool {return (bool)(auth()->user()?->admin || auth()->user()?->perfil==='admin');}
 public static function getNavigationLabel():string {return __('Configurações da agenda');}
 public function getTitle():string {return __('Configurações da agenda');}
 public function mount():void {
  app(AgendaCalendar::class)->administrator(auth()->user());
  $this->settings=AgendaSettings::current()->only(['timezone','min_notice_minutes','horizon_days','cancel_notice_minutes','slot_minutes','professional_manage']);
 }
 public function professionals():array {app(AgendaCalendar::class)->administrator(auth()->user());return AgendaProfessional::orderBy('nome')->pluck('nome','id')->all();}
 public function saveSettings():void {
  $this->resetErrorBag();
  try {app(AgendaCalendar::class)->settings(auth()->user(),$this->settings);Notification::make()->success()->title(__('Regras da agenda salvas'))->send();}
  catch(ValidationException $e){foreach($e->errors() as $key=>$messages)$this->addError('settings.'.$key,$messages[0]);}
 }
 public function loadHours():void {
  $this->resetErrorBag();app(AgendaCalendar::class)->administrator(auth()->user());
  if(!$this->professional){$this->hours=[];return;}
  app(AgendaCalendar::class)->professional(auth()->user(),(int)$this->professional);
  $this->hours=array_map(fn($r)=>['weekday'=>$r['weekday'],'start'=>sprintf('%02d:%02d',intdiv($r['start_minute'],60),$r['start_minute']%60),'end'=>sprintf('%02d:%02d',intdiv($r['end_minute'],60),$r['end_minute']%60)],app(AgendaCalendar::class)->workHours((int)$this->professional));
 }
 public function addHours(int $day):void {
  app(AgendaCalendar::class)->administrator(auth()->user());abort_unless($day>=0 && $day<=6 && count($this->hours)<28,422);
  $this->hours[]=['weekday'=>$day,'start'=>'09:00','end'=>'18:00'];
 }
 public function removeHours(int $index):void {app(AgendaCalendar::class)->administrator(auth()->user());unset($this->hours[$index]);$this->hours=array_values($this->hours);}
 public function saveHours():void {
  $this->resetErrorBag();
  try {if(!$this->professional)throw ValidationException::withMessages(['professional'=>__('Selecione um profissional.')]);app(AgendaCalendar::class)->hours(auth()->user(),(int)$this->professional,$this->hours);Notification::make()->success()->title(__('Jornada salva'))->send();}
  catch(ValidationException $e){foreach($e->errors() as $key=>$messages)$this->addError('hours.'.$key,$messages[0]);}
 }
}

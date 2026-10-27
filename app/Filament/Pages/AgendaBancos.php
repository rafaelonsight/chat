<?php
namespace App\Filament\Pages;
class AgendaBancos extends AgendaFinanceOptions {
    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

 protected static string $optionKind='bank';protected static ?string $slug='financeiro/bancos';protected static ?string $title='Bancos e contas';protected static ?int $navigationSort=3;
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-building-library';
 protected string $view='filament.pages.agenda-bancos';
 public array $form=[];public bool $editing=false;
 #[\Livewire\Attributes\Locked] public ?int $selected=null;
 #[\Livewire\Attributes\Locked] public ?int $version=null;
 public function open(?int $id=null):void {
  app(\App\Services\AgendaCalendar::class)->administrator(auth()->user());$b=app(\App\Services\AgendaBusiness::class);$r=$id?$b->query('agenda_finance_options')->where('type','bank')->where('id',$id)->first():null;if($id)abort_unless($r,404);
  $this->selected=$id;$this->version=$r?->version;$this->resetErrorBag();
  $this->form=$r?['name'=>$r->name,'account_type'=>$r->account_type?:'checking','bank_name'=>$r->bank_name??'','agency'=>$r->agency??'','account_number'=>$r->account_number??'','opening_amount'=>number_format($r->opening_cents/100,2,'.',''),'opening_on'=>$r->opening_on?:now(\App\Models\AgendaSettings::current()->timezone)->toDateString(),'active'=>$r->active]:['name'=>'','account_type'=>'checking','bank_name'=>'','agency'=>'','account_number'=>'','opening_amount'=>'0.00','opening_on'=>now(\App\Models\AgendaSettings::current()->timezone)->toDateString(),'active'=>true];$this->editing=true;
 }
 public function save():void {app(\App\Services\AgendaBusiness::class)->bank(auth()->user(),$this->form,$this->selected,$this->version);$this->editing=false;\Filament\Notifications\Notification::make()->success()->title('Conta salva')->send();}
 public function balance(int $id):int {app(\App\Services\AgendaCalendar::class)->administrator(auth()->user());return app(\App\Services\AgendaBusiness::class)->bankBalance($id);}
 public static function getNavigationLabel():string{return __('Bancos e contas');}
 public function getTitle():string{return __('Bancos e contas');}
}

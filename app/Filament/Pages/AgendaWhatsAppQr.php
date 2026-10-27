<?php
namespace App\Filament\Pages;
use Filament\Pages\Page;
use App\Services\AgendaEvolution;
use Illuminate\Validation\ValidationException;
class AgendaWhatsAppQr extends Page {
    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

 protected static ?string $slug='canais/whatsapp-qr';
 protected static ?string $title='Conectar WhatsApp por QR Code';
 protected static bool $shouldRegisterNavigation=false;
 protected string $view='filament.pages.agenda-whatsapp-qr';
 #[\Livewire\Attributes\Locked] public ?int $connectionId=null;
 #[\Livewire\Attributes\Locked] public ?string $qr=null;
 #[\Livewire\Attributes\Locked] public string $state='connecting';
 public static function canAccess():bool {return (bool)(auth()->user()?->admin || auth()->user()?->perfil==='admin');}
 public function mount():void {$this->connectionId=app(AgendaEvolution::class)->channel(auth()->user())->id;$this->refreshQr();}
 public function refreshQr():void {
  $this->resetErrorBag();try{$r=app(AgendaEvolution::class)->refresh(auth()->user(),$this->connectionId);$this->qr=$r['qr'];$this->state=$r['state'];}
  catch(ValidationException $e){$this->qr=null;$this->state='error';foreach($e->errors() as $key=>$messages)$this->addError($key,$messages[0]);}
 }
 public function disconnect():void {
  try{app(AgendaEvolution::class)->disconnect(auth()->user(),$this->connectionId);$this->qr=null;$this->state='disconnected';}
  catch(\Throwable){$this->addError('qr',__('Não foi possível desconectar. Tente novamente.'));}
 }
}

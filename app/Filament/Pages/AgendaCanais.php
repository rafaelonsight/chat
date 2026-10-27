<?php
namespace App\Filament\Pages;
use App\Models\AgendaChannelConnection as Connection;
use App\Services\{AgendaChannels,AgendaPlan};
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
class AgendaCanais extends Page {
 protected static ?string $slug='canais';
 protected static ?string $title='Canais';
 protected static string|\UnitEnum|null $navigationGroup='Configurações';
 protected static string|\BackedEnum|null $navigationIcon=\Filament\Support\Icons\Heroicon::OutlinedChatBubbleLeftRight;
 protected static ?int $navigationSort=0;
 protected string $view='filament.pages.agenda-canais';
 #[\Livewire\Attributes\Locked] public ?string $editing=null;
 public array $form=[];
 public static function canAccess():bool {return (bool)(auth()->user()?->admin || auth()->user()?->perfil==='admin');}
 public static function getNavigationLabel():string {return __('Canais');}
 public function getTitle():string {return __('Canais');}
 public function connections():array {
  app(AgendaChannels::class)->administrator(auth()->user());
  return Connection::orderBy('id')->get()->keyBy('type')->toArray();
 }
 public function plan():array {app(AgendaChannels::class)->administrator(auth()->user());return app(AgendaPlan::class)->summary(auth()->user()->tenant_id);}
 public function connect(string $type):void {
  app(AgendaChannels::class)->administrator(auth()->user());abort_unless(isset(Connection::TYPES[$type]),422);
  if($type==='evolution'){$this->redirect('/admin/canais/whatsapp-qr',navigate:true);return;}
  Notification::make()->warning()->title(__('Conexão automática em preparação'))->body(__('Em breve você poderá autorizar sua conta diretamente, sem preencher dados técnicos.'))->send();
 }
 public function disconnect(int $id):void {
  app(AgendaChannels::class)->administrator(auth()->user());$c=Connection::findOrFail($id);
  if($c->type==='evolution'){app(\App\Services\AgendaEvolution::class)->disconnect(auth()->user(),$id);return;}
  app(AgendaChannels::class)->disconnect(auth()->user(),$id);Notification::make()->success()->title(__('Canal desconectado neste painel'))->body(__('A credencial local foi removida. Isso não revoga permissões concedidas no provedor.'))->send();
 }
}

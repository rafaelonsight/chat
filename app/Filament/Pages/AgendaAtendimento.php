<?php
namespace App\Filament\Pages;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
class AgendaAtendimento extends Page {
 protected static ?string $slug='atendimento';
 protected static ?string $title='Atendimento';
 protected static ?string $navigationLabel='Atendimento';
 protected static string|\BackedEnum|null $navigationIcon='tabler-message-2-heart';
 protected static ?int $navigationSort=-2;
 protected string $view='filament.pages.atendimento';
 #[\Livewire\Attributes\Locked] public ?int $conversaInicial=null;
 public string $baldeInicial='novos';
 public static function canAccess():bool {return auth()->check() && !auth()->user()->isAgendaProfessional();}
 public function getMaxContentWidth():Width{return Width::Full;}
 public function getHeading():string{return '';}
 public function mount():void {
  abort_unless(static::canAccess(),403);$c=\App\Models\Conversation::find(request()->integer('conversa'));
  if($c){$this->conversaInicial=$c->id;$this->baldeInicial=$c->contact?->tipo==='grupo'?'grupos':($c->status==='arquivada'?'arquivadas':($c->atendente_id===auth()->id()?'meus':($c->atendente_id?'outros':'novos')));}
 }
}

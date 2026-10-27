<?php
namespace App\Filament\Pages;
use Filament\Pages\Page;
use App\Services\AgendaBusiness;
abstract class AgendaFinanceOptions extends Page {
    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

 protected static string|\UnitEnum|null $navigationGroup='Financeiro';
 protected string $view='filament.pages.agenda-finance-options';
 protected static string $optionKind='category';
 public string $name='';
 public static function canAccess():bool {return (bool)(auth()->user()?->canEnterAgenda() && (auth()->user()->admin || auth()->user()->perfil==='admin'));}
 public function mount():void {app(\App\Services\AgendaCalendar::class)->administrator(auth()->user());}
 public function rows(){app(\App\Services\AgendaCalendar::class)->administrator(auth()->user());return app(AgendaBusiness::class)->query('agenda_finance_options')->where('type',static::$optionKind)->orderBy('name')->get();}
 public function save():void {app(AgendaBusiness::class)->option(auth()->user(),static::$optionKind,$this->name);$this->name='';\Filament\Notifications\Notification::make()->success()->title(__('Opção cadastrada'))->send();}
}

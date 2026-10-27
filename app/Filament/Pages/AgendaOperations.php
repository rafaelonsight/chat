<?php
namespace App\Filament\Pages;
use Filament\Pages\Page;
class AgendaOperations extends Page
{
    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

    protected static ?string $slug='saude-backup';
    protected static ?string $title='Saúde e backup';
    protected static string|\UnitEnum|null $navigationGroup='Configurações';
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-shield-check';
    protected string $view='filament.pages.agenda-operations';
    public static function canAccess():bool {return (bool)(auth()->user()?->canEnterAgenda() && (auth()->user()->admin || auth()->user()->perfil==='admin'));}
    public function summary():array {
        app(\App\Services\AgendaCalendar::class)->administrator(auth()->user());
        $s=app(\App\Services\AgendaOperations::class)->state();
        $s['monitor']['checks']=array_filter($s['monitor']['checks']??[],fn($c)=>empty($c['tenant_id']) || (int)$c['tenant_id']===(int)auth()->user()->tenant_id);
        return $s;
    }
}

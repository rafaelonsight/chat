<?php
namespace App\Filament\Pages;
class AgendaFollowUp extends AgendaCommunications {
    use \App\Filament\Concerns\PaginaDaAgenda;

    protected static string $modulo = 'agenda';

    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

 protected static ?string $slug='follow-up';protected static ?string $title='Follow-up';protected static string|\BackedEnum|null $navigationIcon='heroicon-o-bell-alert';
}

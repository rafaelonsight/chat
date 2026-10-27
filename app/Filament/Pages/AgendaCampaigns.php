<?php
namespace App\Filament\Pages;
class AgendaCampaigns extends AgendaCommunications {
    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

 protected static ?string $slug='campanhas';protected static ?string $title='Campanhas';protected static string|\BackedEnum|null $navigationIcon='heroicon-o-megaphone';
}

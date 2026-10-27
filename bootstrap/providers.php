<?php

// A edicao Agenda tem painel proprio no lugar do painel do chat e da revenda. Ate a Agenda
// virar modulo por conta (Fase 2 do blueprint), a escolha segue a mesma chave de config/agenda.php.
$edicaoAgenda = (bool) config('agenda.edition');

return array_values(array_filter([
    App\Providers\AppServiceProvider::class,
    $edicaoAgenda ? App\Providers\Filament\AgendaPanelProvider::class : App\Providers\Filament\AdminPanelProvider::class,
    $edicaoAgenda ? null : App\Providers\Filament\RevendaPanelProvider::class,
    App\Providers\HorizonServiceProvider::class,
]));

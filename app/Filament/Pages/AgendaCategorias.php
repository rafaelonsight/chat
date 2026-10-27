<?php
namespace App\Filament\Pages;
class AgendaCategorias extends AgendaFinanceOptions {
    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

 protected static ?string $slug='financeiro/categorias';protected static ?string $title='Categorias';protected static ?int $navigationSort=2;
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-tag';
 public static function getNavigationLabel():string{return __('Categorias');}
 public function getTitle():string{return __('Categorias');}
}

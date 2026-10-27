<?php
namespace App\Filament\Pages;
class AgendaPaymentMethods extends AgendaFinanceOptions {
 protected static string $optionKind='method';protected static ?string $slug='financeiro/formas-pagamento';protected static ?string $title='Formas de pagamento';protected static ?int $navigationSort=4;
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-credit-card';
 public static function getNavigationLabel():string{return __('Formas de pagamento');}
 public function getTitle():string{return __('Formas de pagamento');}
}

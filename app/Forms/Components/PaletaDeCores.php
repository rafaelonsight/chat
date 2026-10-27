<?php
namespace App\Forms\Components;
use Filament\Forms\Components\Field;
class PaletaDeCores extends Field {
 protected string $view='forms.components.paleta-de-cores';
 protected function setUp(): void {parent::setUp();$this->default('roxo')->nullable()->rule(\Illuminate\Validation\Rule::in(array_keys(\App\Models\User::PALETA_CHAT)));}
 public function getCores(): array {return \App\Models\User::PALETA_CHAT;}
}

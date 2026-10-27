<?php
namespace App\Filament\Resources\Agenda\Services\Pages;
use App\Filament\Resources\Agenda\Services\ServiceResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;
class ListServices extends ListRecords
{
 protected static string $resource=ServiceResource::class;
 protected string $view='filament.agenda.catalog';
 public function catalogDescription(): string { return __('Defina o que seu estabelecimento oferece e o tempo necessário para cada atendimento.'); }
 public function establishmentName(): string { return \App\Models\Tenant::find(auth()->user()?->tenant_id)?->nome ?? config('app.name'); }
 protected function getHeaderActions(): array { return [CreateAction::make()->label(__('Novo serviço'))]; }
}

<?php
namespace App\Filament\Resources\Agenda\Professionals\Pages;
use App\Filament\Resources\Agenda\Professionals\ProfessionalResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;
class ListProfessionals extends ListRecords
{
 protected static string $resource=ProfessionalResource::class;
 protected string $view='filament.agenda.catalog';
 public function catalogDescription(): string { return __('Organize quem atende e os serviços que cada profissional executa.'); }
 public function establishmentName(): string { return \App\Models\Tenant::find(auth()->user()?->tenant_id)?->nome ?? config('app.name'); }
 protected function getHeaderActions(): array { return [CreateAction::make()->label(__('Novo profissional'))]; }
}

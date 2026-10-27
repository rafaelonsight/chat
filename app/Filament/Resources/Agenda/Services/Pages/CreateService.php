<?php
namespace App\Filament\Resources\Agenda\Services\Pages;
use App\Filament\Resources\Agenda\Services\ServiceResource;
use Filament\Resources\Pages\CreateRecord;
class CreateService extends CreateRecord
{
 protected static string $resource=ServiceResource::class;
 protected static bool $canCreateAnother=false;
 public function getTitle(): string { return __('Novo serviço'); }
 protected function getCreateFormAction(): \Filament\Actions\Action { return parent::getCreateFormAction()->label(__('Cadastrar serviço')); }
 protected function getRedirectUrl(): string { return static::getResource()::getUrl('index'); }
 protected function getCreatedNotificationTitle(): ?string { return __('Serviço cadastrado'); }
}

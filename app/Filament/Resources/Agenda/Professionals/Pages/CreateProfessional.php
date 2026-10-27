<?php
namespace App\Filament\Resources\Agenda\Professionals\Pages;
use App\Filament\Resources\Agenda\Professionals\ProfessionalResource;
use Filament\Resources\Pages\CreateRecord;
class CreateProfessional extends CreateRecord
{
 protected static string $resource=ProfessionalResource::class;
 protected static bool $canCreateAnother=false;
 protected ?bool $hasDatabaseTransactions=true;
 protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model {
  return app(\App\Services\AgendaAccess::class)->createProfessional(auth()->user(),$data,$this->data['access_mode']??'new',isset($this->data['access_user_id'])?(int)$this->data['access_user_id']:null);
 }
 public function getTitle(): string { return __('Novo profissional'); }
 protected function getCreateFormAction(): \Filament\Actions\Action { return parent::getCreateFormAction()->label(__('Cadastrar profissional')); }
 protected function getRedirectUrl(): string { return static::getResource()::getUrl('index'); }
 protected function getCreatedNotificationTitle(): ?string { return __('Profissional cadastrado'); }
}

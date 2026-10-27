<?php
namespace App\Filament\Resources\Agenda\Professionals\Pages;
use App\Filament\Resources\Agenda\Professionals\ProfessionalResource;
use Filament\Resources\Pages\EditRecord;
class EditProfessional extends EditRecord
{
 protected static string $resource=ProfessionalResource::class;
 protected ?bool $hasDatabaseTransactions=true;
 protected function beforeSave():void {\Illuminate\Support\Facades\DB::table('tenants')->where('id',auth()->user()->tenant_id)->lockForUpdate()->first();}
 protected function afterSave(): void {
  if(!$this->record->user_id) {
   app(\App\Services\AgendaAccess::class)->link(auth()->user(),$this->record,$this->data['access_mode']??'new',isset($this->data['access_user_id'])?(int)$this->data['access_user_id']:null);
   $this->record->refresh();
  }
 }
 protected function getHeaderActions(): array {
  return [\Filament\Actions\ActionGroup::make(\App\Filament\AgendaProfessionalAccessActions::make())->label(__('Gerenciar acesso'))->button()];
 }
 public function getTitle(): string { return __('Editar profissional'); }
 protected function getSaveFormAction(): \Filament\Actions\Action { return parent::getSaveFormAction()->label(__('Salvar profissional')); }
 
 protected function getRedirectUrl(): string { return static::getResource()::getUrl('index'); }
 protected function getSavedNotificationTitle(): ?string { return __('Profissional atualizado'); }
}

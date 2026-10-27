<?php
namespace App\Filament\Resources\Agenda\Services\Pages;
use App\Filament\Resources\Agenda\Services\ServiceResource;
use Filament\Resources\Pages\EditRecord;
class EditService extends EditRecord
{
 protected static string $resource=ServiceResource::class;
 protected ?bool $hasDatabaseTransactions=true;
 protected function beforeSave():void {\Illuminate\Support\Facades\DB::table('tenants')->where('id',auth()->user()->tenant_id)->lockForUpdate()->first();}
 public function getTitle(): string { return __('Editar serviço'); }
 protected function getSaveFormAction(): \Filament\Actions\Action { return parent::getSaveFormAction()->label(__('Salvar serviço')); }
 
 protected function getRedirectUrl(): string { return static::getResource()::getUrl('index'); }
 protected function getSavedNotificationTitle(): ?string { return __('Serviço atualizado'); }
}

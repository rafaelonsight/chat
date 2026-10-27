<?php
namespace App\Filament\Resources\AttendanceActions\Pages;
class ListAttendanceActions extends \Filament\Resources\Pages\ListRecords {
 protected static string $resource=\App\Filament\Resources\AttendanceActions\AttendanceActionResource::class;
 public function getSubheading():?string{return __('Configure os botões do menu Ações nas conversas. Cada execução pede confirmação e respeita os perfis autorizados.');}
 protected function getHeaderActions():array{return [\Filament\Actions\CreateAction::make()->label(__('Nova ação'))->mutateDataUsing(function(array $data){$data['tenant_id']=\App\Support\TenantContext::get();return $data;})];}
}

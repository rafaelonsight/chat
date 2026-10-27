<?php
namespace App\Filament\Resources\ChatbotIntegrations\Pages;
class ListChatbotIntegrations extends \Filament\Resources\Pages\ListRecords {protected static string $resource=\App\Filament\Resources\ChatbotIntegrations\ChatbotIntegrationResource::class;protected function getHeaderActions():array{return [\Filament\Actions\CreateAction::make()->label(__('ui_fc4956871937'))->mutateDataUsing(function(array $data){$data['tenant_id']=\App\Support\TenantContext::get();return $data;})];}}
